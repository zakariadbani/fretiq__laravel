<?php

namespace App\Services\Prospecting;

use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProspectCriteriaCsvImporter
{
    public const HEADERS = [
        'name', 'ai_target', 'ai_exclude', 'sectors', 'countries', 'company_sizes',
        'target_positions', 'daily_limit', 'is_active', 'auto_run', 'run_at_hour',
        'contact_limit', 'min_score_enrich', 'auto_enrich',
    ];

    public const MAX_BYTES = 524288;

    public const MAX_ROWS = 100;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function parse(string $contents, string $filename): array
    {
        if (! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            $this->fail('Le fichier doit être au format CSV.');
        }

        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            $this->fail($contents === '' ? 'Le fichier CSV est vide.' : 'Le fichier CSV dépasse 512 Ko.');
        }

        if (! preg_match('//u', $contents) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents)) {
            $this->fail('Le fichier CSV contient des caractères non sûrs ou un encodage invalide.');
        }

        if (str_starts_with($contents, 'MZ') || str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, '%PDF')) {
            $this->fail('Le fichier fourni ne semble pas être un CSV sûr.');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $this->fail('Le fichier CSV ne peut pas être lu.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        $headerLine = fgets($stream);
        if ($headerLine === false || trim($headerLine) === '') {
            $this->fail('La ligne d’en-tête CSV est absente.');
        }

        $delimiter = $this->detectDelimiter($headerLine);
        rewind($stream);
        $headers = fgetcsv($stream, 0, $delimiter, '"', '');
        if ($headers === false) {
            $this->fail('La ligne d’en-tête CSV est absente.');
        }
        $headers = array_map(fn ($value) => trim((string) $value), $headers);
        $this->validateHeaders($headers);

        $rows = [];
        $errors = [];
        $dataRecords = 0;
        $tooManyRecords = false;
        while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rowNumber = $dataRecords + 2;
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            $dataRecords++;
            if ($dataRecords > self::MAX_ROWS) {
                $tooManyRecords = true;
                break;
            }

            if (count($values) !== count($headers)) {
                $errors[] = "Ligne {$rowNumber} : le nombre de colonnes est invalide.";

                continue;
            }

            $raw = array_combine($headers, $values);
            $normalized = $this->normalizeRow($raw, $rowNumber, $errors);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        if ($tooManyRecords) {
            array_unshift($errors, 'Le CSV ne peut pas contenir plus de 100 lignes de données.');
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Le fichier CSV ne contient aucune ligne de données.';
        }

        $seenNames = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['name']);
            if (isset($seenNames[$key])) {
                $errors[] = "Ligne {$row['_row']} : le nom « {$row['name']} » est dupliqué dans le CSV.";
            }
            $seenNames[$key] = true;
        }

        if ($errors !== []) {
            $this->failMany($errors);
        }

        return array_map(function (array $row): array {
            unset($row['_row']);

            return $row;
        }, $rows);
    }

    public function template(): string
    {
        return "\xEF\xBB\xBF".implode(';', self::HEADERS)."\n";
    }

    private function detectDelimiter(string $header): string
    {
        $counts = [];
        foreach ([';', ',', "\t"] as $delimiter) {
            $counts[$delimiter] = count(str_getcsv($header, $delimiter));
        }
        arsort($counts);
        $delimiter = array_key_first($counts);

        if (($counts[$delimiter] ?? 0) < 2) {
            $this->fail('Le séparateur CSV doit être un point-virgule, une virgule ou une tabulation.');
        }

        return $delimiter;
    }

    /** @param list<string> $headers */
    private function validateHeaders(array $headers): void
    {
        if (count($headers) !== count(array_unique($headers))) {
            $this->fail('Les en-têtes CSV ne doivent pas être dupliqués.');
        }

        $unknown = array_diff($headers, self::HEADERS);
        $missing = array_diff(self::HEADERS, $headers);
        if ($unknown !== [] || $missing !== []) {
            $this->fail('Les en-têtes CSV sont incomplets ou inconnus.');
        }
    }

    /**
     * @param  array<string, string|null>  $raw
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $raw, int $rowNumber, array &$errors): ?array
    {
        $booleanErrors = [];
        foreach (['is_active', 'auto_run', 'auto_enrich'] as $field) {
            $value = strtolower(trim((string) ($raw[$field] ?? '')));
            if (! in_array($value, ['', '0', 'false', 'non', 'no', 'off'], true)) {
                $booleanErrors[] = $field;
            }
        }
        if ($booleanErrors !== []) {
            $errors[] = "Ligne {$rowNumber} : les champs ".implode(', ', $booleanErrors).' doivent être vides ou définis à faux.';

            return null;
        }
        if (trim((string) ($raw['run_at_hour'] ?? '')) !== '') {
            $errors[] = "Ligne {$rowNumber} : run_at_hour doit être vide pour cet import sécurisé.";

            return null;
        }

        $row = [
            '_row' => $rowNumber,
            'name' => trim((string) ($raw['name'] ?? '')),
            'ai_target' => $this->nullableText($raw['ai_target'] ?? null),
            'ai_exclude' => $this->nullableText($raw['ai_exclude'] ?? null),
            'sectors' => $this->list($raw['sectors'] ?? null),
            'countries' => $this->list($raw['countries'] ?? null),
            'company_sizes' => $this->list($raw['company_sizes'] ?? null),
            'target_positions' => $this->list($raw['target_positions'] ?? null),
            'daily_limit' => $this->number($raw['daily_limit'] ?? null, 5),
            'contact_limit' => $this->number($raw['contact_limit'] ?? null, 10),
            'min_score_enrich' => $this->number($raw['min_score_enrich'] ?? null, 50),
            'run_at_hour' => null,
            'is_active' => false,
            'auto_run' => false,
            'auto_enrich' => false,
        ];

        if (in_array(null, [$row['daily_limit'], $row['contact_limit'], $row['min_score_enrich']], true)) {
            $errors[] = "Ligne {$rowNumber} : les limites et le score doivent être des nombres entiers.";

            return null;
        }

        $validator = Validator::make($row, (new ProspectCriteria)->rules());
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Ligne {$rowNumber} : {$message}";
            }

            return null;
        }

        return $row;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        $items = [];
        foreach (explode('|', (string) $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[mb_strtolower($item)] ??= $item;
            }
        }

        return array_values($items);
    }

    private function number(mixed $value, int $default): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }

        return preg_match('/^-?\d+$/', $value) ? (int) $value : null;
    }

    /** @return never */
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['csv' => [$message]]);
    }

    /** @param list<string> $messages */
    private function failMany(array $messages): void
    {
        throw ValidationException::withMessages(['csv' => array_slice($messages, 0, 20)]);
    }
}
