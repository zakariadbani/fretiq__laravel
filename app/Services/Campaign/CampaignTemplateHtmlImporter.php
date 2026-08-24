<?php

namespace App\Services\Campaign;

use App\Models\CampaignTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Multi-file HTML upload importer — not CSV, so it does not extend
 * AbstractCsvImporter, but mirrors its safety posture (byte cap, encoding
 * check, magic-byte rejection, blocking vs warning error split, encrypted
 * preview token) and its fail()/failMany() error shape (list<string> under
 * one error-bag key) so the shared HandlesImportPreviewToken trait and the
 * import.blade.php error-list markup work unchanged.
 *
 * Each file's leading HTML comment carries a header like:
 *   <!--
 *   Nom : Relance clients inactifs — Email 1
 *   Campagne : Relance clients inactifs
 *   Objet : On vous a manqué ?
 *   -->
 * `Objet :` supplies the subject (required — a file without it is rejected).
 * `Nom :` supplies the name if present (highest priority — lets a multi-email
 * campaign sharing one `Campagne :` line give each email its own template
 * name); else `Campagne :` supplies it; absent both, the name is derived
 * from the filename (slug → title case). A name colliding with another
 * file's name WITHIN the same upload batch is a hard error (see parse()) —
 * cross-batch, an existing template with the same name is upserted.
 */
class CampaignTemplateHtmlImporter
{
    public const MAX_FILES = 20;

    public const MAX_BYTES = 524288;

    /**
     * Cap on the SUM of all valid files' bytes in one import — not just the
     * per-file MAX_BYTES. Every parsed row's html_content is embedded in the
     * encrypted import_token round-tripped as a hidden field on the confirm
     * POST (see HandlesImportPreviewToken), so a large multi-file batch can
     * exceed post_max_size on that second request even though each
     * individual file passed its own cap.
     */
    public const MAX_TOTAL_BYTES = 2097152;

    public const ALLOWED_MERGE_TAGS = [
        '{{contact.first_name}}',
        '{{contact.name}}',
        '{{contact.email}}',
        '{{company.name}}',
        '{{company.sector}}',
        '{{unsubscribe_url}}',
    ];

    /**
     * @param  list<array{filename: string, contents: string}>  $files
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function parse(array $files): array
    {
        if ($files === []) {
            $this->fail('Aucun fichier HTML fourni.');
        }
        if (count($files) > self::MAX_FILES) {
            $this->fail('Maximum '.self::MAX_FILES.' fichiers par import.');
        }

        $rows = [];
        $errors = [];
        $seenNames = [];

        foreach ($files as $file) {
            $filename = (string) $file['filename'];
            $contents = (string) $file['contents'];

            if (! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                $errors[] = "Fichier {$filename} : doit être un fichier .html.";

                continue;
            }

            if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
                $errors[] = "Fichier {$filename} : ".($contents === '' ? 'vide.' : 'dépasse 512 Ko.');

                continue;
            }

            if (! preg_match('//u', $contents) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents)) {
                $errors[] = "Fichier {$filename} : encodage invalide ou caractères non sûrs.";

                continue;
            }

            if (str_starts_with($contents, 'MZ') || str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, '%PDF')) {
                $errors[] = "Fichier {$filename} : ne semble pas être un fichier HTML sûr.";

                continue;
            }

            $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

            [$subject, $nameHint] = $this->parseHeader($contents);
            $subject = $subject !== null ? trim($subject) : '';
            if ($subject === '') {
                $errors[] = "Fichier {$filename} : en-tête « Objet : » manquant — le sujet est requis.";

                continue;
            }

            $name = $nameHint !== null && trim($nameHint) !== '' ? trim($nameHint) : $this->nameFromFilename($filename);

            $unknownTags = $this->unknownMergeTags($contents);
            if ($unknownTags !== []) {
                $errors[] = "Fichier {$filename} : balises de fusion non supportées : ".implode(', ', $unknownTags).'.';

                continue;
            }

            $validator = Validator::make(
                ['name' => $name, 'subject' => $subject, 'html_content' => $contents],
                collect((new CampaignTemplate)->rules())->only(['name', 'subject', 'html_content'])->all(),
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Fichier {$filename} : {$message}";
                }

                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seenNames[$key])) {
                $errors[] = "Fichier {$filename} : nom « {$name} » déjà utilisé par un autre fichier de cet import — renommez l'un des deux (ajoutez un en-tête « Nom : » distinct).";

                continue;
            }
            $seenNames[$key] = true;

            $rows[] = [
                'filename' => $filename,
                'name' => $name,
                'subject' => $subject,
                'html_content' => $contents,
                'size' => strlen($contents),
                'warnings' => [],
                'will_update' => CampaignTemplate::whereRaw('LOWER(name) = ?', [$key])->exists(),
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Aucun fichier HTML valide.';
        }

        $totalBytes = array_sum(array_column($rows, 'size'));
        if ($totalBytes > self::MAX_TOTAL_BYTES) {
            $errors[] = 'Le total des fichiers importés dépasse 2 Mo — réduisez le nombre ou la taille des fichiers.';
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
                    // A classic/HTML import always overwrites a stale builder_state
                    // from a previous builder-mode row of the same name — the newly
                    // imported HTML is no longer re-openable in the builder.
                    'builder_state' => null,
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

    /**
     * @return array{0: ?string, 1: ?string} [subject, nameHint]
     */
    private function parseHeader(string $html): array
    {
        if (! preg_match('/^\s*<!--(.*?)-->/s', $html, $matches)) {
            return [null, null];
        }

        $subject = null;
        $name = null;
        $campagneName = null;
        foreach (preg_split('/\r\n|\r|\n/', $matches[1]) ?: [] as $line) {
            if (preg_match('/^\s*Objet\s*:\s*(.+?)\s*$/ui', $line, $lineMatch)) {
                $subject = $lineMatch[1];
            } elseif (preg_match('/^\s*Nom\s*:\s*(.+?)\s*$/ui', $line, $lineMatch)) {
                $name = $lineMatch[1];
            } elseif (preg_match('/^\s*Campagne\s*:\s*(.+?)\s*$/ui', $line, $lineMatch)) {
                $campagneName = $lineMatch[1];
            }
        }

        // `Nom :` takes priority over `Campagne :` — see class docblock.
        return [$subject, $name ?? $campagneName];
    }

    private function nameFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return Str::of($base)->replace(['_', '-'], ' ')->squish()->title()->toString();
    }

    /** @return list<string> */
    private function unknownMergeTags(string $html): array
    {
        preg_match_all('/\{\{[^{}]*\}\}/', $html, $matches);

        return array_values(array_unique(array_diff($matches[0] ?? [], self::ALLOWED_MERGE_TAGS)));
    }

    /** @return never */
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['html' => [$message]]);
    }

    /** @param list<string> $messages */
    private function failMany(array $messages): void
    {
        throw ValidationException::withMessages(['html' => array_slice($messages, 0, 20)]);
    }
}
