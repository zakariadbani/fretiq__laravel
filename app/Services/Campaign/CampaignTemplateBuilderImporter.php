<?php

namespace App\Services\Campaign;

use App\Models\CampaignTemplate;
use App\Models\SenderIdentity;
use App\Services\Campaign\TemplateBuilder\BuilderStateValidator;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Multi-file JSON upload importer for builder-authored templates — the
 * "maquette" counterpart to CampaignTemplateHtmlImporter, same safety
 * posture (byte cap, encoding check, blocking-error list, encrypted preview
 * token) and the same fail()/failMany() error shape (list<string> under one
 * error-bag key — 'json' here instead of 'html') so the shared
 * HandlesImportPreviewToken trait and the import.blade.php error-list
 * markup work unchanged.
 *
 * Each file is a JSON object:
 *   {"name": "...", "subject": "...", "preview_text": "...", "builder_state": {...}}
 * `preview_text` may live at the top level or be mirrored inside
 * `builder_state.preview_text` (the DB column is a mirror of that key) —
 * the top-level value wins when both are present. `name` and `subject` are
 * required (CampaignTemplate::rules()); `builder_state` is validated by
 * BuilderStateValidator (single validation authority — rejects unknown
 * variants, out-of-bounds slots, and merge tags outside
 * SectionCatalog::ALLOWED_MERGE_TAGS, which — unlike the classic HTML
 * import — never includes {{unsubscribe_url}} or {{company.sector}}: the
 * builder always renders the standard footer).
 *
 * The validated state is composed to HTML at parse time (same
 * TemplateComposer::compose() call site as CampaignTemplateController's
 * beforeSave(), same SenderIdentity::defaultContactEmail() resolution — see
 * TemplateComposer's DB-free contract) so the confirm step's encrypted
 * preview token round-trips ready-to-store HTML, not raw JSON.
 */
class CampaignTemplateBuilderImporter
{
    public const MAX_FILES = 20;

    public const MAX_BYTES = 524288;

    /**
     * Cap on the SUM of the COMPOSED html_content bytes PLUS the encoded
     * builder_state bytes across one import batch — not the source JSON
     * bytes. Both travel in the encrypted import_token round-tripped on the
     * confirm POST (see HandlesImportPreviewToken and each row's 'size'
     * below), and the composed HTML alone is typically several times larger
     * than the JSON source that produced it.
     */
    public const MAX_TOTAL_BYTES = 2097152;

    /**
     * @param  list<array{filename: string, contents: string}>  $files
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function parse(array $files): array
    {
        if ($files === []) {
            $this->fail('Aucun fichier JSON fourni.');
        }
        if (count($files) > self::MAX_FILES) {
            $this->fail('Maximum '.self::MAX_FILES.' fichiers par import.');
        }

        $composer = app(TemplateComposer::class);
        $validator = app(BuilderStateValidator::class);
        $contactEmail = SenderIdentity::defaultContactEmail();

        $rows = [];
        $errors = [];
        $seenNames = [];

        foreach ($files as $file) {
            $filename = (string) $file['filename'];
            $contents = (string) $file['contents'];

            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
                $errors[] = "Fichier {$filename} : doit être un fichier .json.";

                continue;
            }

            if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
                $errors[] = "Fichier {$filename} : ".($contents === '' ? 'vide.' : 'dépasse 512 Ko.');

                continue;
            }

            if (! preg_match('//u', $contents)) {
                $errors[] = "Fichier {$filename} : encodage invalide.";

                continue;
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors[] = "Fichier {$filename} : JSON malformé.";

                continue;
            }

            if (! is_array($decoded) || array_is_list($decoded)) {
                $errors[] = "Fichier {$filename} : structure JSON inattendue (objet attendu).";

                continue;
            }

            $name = is_string($decoded['name'] ?? null) ? trim($decoded['name']) : '';
            $subject = is_string($decoded['subject'] ?? null) ? trim($decoded['subject']) : '';
            $builderState = $decoded['builder_state'] ?? null;

            if ($name === '' || $subject === '' || ! is_array($builderState)) {
                $errors[] = "Fichier {$filename} : name, subject et builder_state sont requis.";

                continue;
            }

            // Same control-character guard as CampaignTemplateHtmlImporter, applied
            // to the DECODED name/subject rather than the raw JSON source: an
            // escaped control character in the JSON (e.g. a literal bell byte)
            // decodes into a real control byte here even though the raw JSON
            // passed the '//u' UTF-8 check above. Not an XSS risk (Blade
            // escapes), but the byte would otherwise reach the DB unfiltered.
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name.$subject)) {
                $errors[] = "Fichier {$filename} : caractères non sûrs dans le nom ou le sujet.";

                continue;
            }

            $topLevelPreview = is_string($decoded['preview_text'] ?? null) ? trim($decoded['preview_text']) : '';
            if ($topLevelPreview !== '') {
                $builderState['preview_text'] = $topLevelPreview;
            }

            $modelValidator = Validator::make(
                ['name' => $name, 'subject' => $subject],
                collect((new CampaignTemplate)->rules())->only(['name', 'subject'])->all(),
            );
            if ($modelValidator->fails()) {
                foreach ($modelValidator->errors()->all() as $message) {
                    $errors[] = "Fichier {$filename} : {$message}";
                }

                continue;
            }

            try {
                $validatedState = $validator->validate($builderState);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $errors[] = "Fichier {$filename} : {$message}";
                    }
                }

                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seenNames[$key])) {
                $errors[] = "Fichier {$filename} : nom « {$name} » déjà utilisé par un autre fichier de cet import — renommez l'un des deux.";

                continue;
            }
            $seenNames[$key] = true;

            try {
                $html = $composer->compose($validatedState, 'fr', $contactEmail);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $messages) {
                    foreach ($messages as $message) {
                        $errors[] = "Fichier {$filename} : {$message}";
                    }
                }

                continue;
            }

            // 'size' counts BOTH the composed HTML and the encoded builder_state —
            // both round-trip in the encrypted import_token (see MAX_TOTAL_BYTES
            // docblock above), so a size that only counted html_content would
            // undercount what the token actually carries.
            $rows[] = [
                'filename' => $filename,
                'name' => $name,
                'subject' => $subject,
                'size' => strlen($html) + strlen((string) json_encode($validatedState)),
                'warnings' => [],
                'will_update' => CampaignTemplate::whereRaw('LOWER(name) = ?', [$key])->exists(),
                'builder_state' => $validatedState,
                'html_content' => $html,
                'preview_text' => $validatedState['preview_text'],
                'mode' => 'builder',
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Aucun fichier JSON valide.';
        }

        $totalBytes = array_sum(array_column($rows, 'size'));
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            $errors[] = 'Le total des maquettes composées dépasse 2 Mo — réduisez le nombre ou la taille des fichiers.';
        }

        if ($errors !== []) {
            $this->failMany($errors);
        }

        return $rows;
    }

    /**
     * Upsert campaign_templates by case-insensitive name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int}
     */
    public function store(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $attrs = [
                    'subject' => $row['subject'],
                    'html_content' => $row['html_content'],
                    'preview_text' => $row['preview_text'],
                    'builder_state' => $row['builder_state'],
                ];

                $existing = CampaignTemplate::whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->first();
                if ($existing !== null) {
                    $existing->fill($attrs)->save();
                    $updated++;
                } else {
                    CampaignTemplate::create($attrs + ['name' => $row['name']]);
                    $created++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /** @return never */
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['json' => [$message]]);
    }

    /** @param list<string> $messages */
    private function failMany(array $messages): void
    {
        throw ValidationException::withMessages(['json' => array_slice($messages, 0, 20)]);
    }
}
