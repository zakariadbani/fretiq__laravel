<?php

namespace App\Services\Campaign;

use Illuminate\Validation\ValidationException;

/**
 * Shared CSV parsing mechanics for the strategy-import family (segments,
 * sequences, campaigns). Mirrors App\Services\Prospecting\ProspectCriteriaCsvImporter's
 * parse() shape byte-for-byte on the mechanical parts (safe-encoding checks,
 * delimiter sniffing, BOM strip, row-count cap, header validation, per-row
 * error collection) — three real callers need this identical mechanic, so it
 * is extracted here rather than copied three times. Per-row business
 * validation stays with each subclass via normalizeRow().
 *
 * Not used by CampaignTemplateHtmlImporter, which parses multi-file HTML
 * uploads rather than a single delimited CSV.
 */
abstract class AbstractCsvImporter
{
    public const MAX_BYTES = 524288;

    public const MAX_ROWS = 100;

    /** @return list<string> */
    abstract public function headers(): array;

    /**
     * @param  array<string, string|null>  $raw
     * @param  list<string>  $errors
     * @return array<string, mixed>|null  null drops the row (its error(s) already pushed onto $errors)
     */
    abstract protected function normalizeRow(array $raw, int $rowNumber, array &$errors): ?array;

    /**
     * In-file duplicate detection key. Return null from an override to skip
     * the check entirely (e.g. a sequence importer where many rows legitimately
     * share the same sequence name — one row per step).
     */
    protected function dedupKey(array $row): ?string
    {
        return isset($row['name']) ? mb_strtolower((string) $row['name']) : null;
    }

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

        if ($contents === '' || strlen($contents) > static::MAX_BYTES) {
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
            if ($dataRecords > static::MAX_ROWS) {
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
                $normalized['_row'] = $rowNumber;
                $rows[] = $normalized;
            }
        }

        if ($tooManyRecords) {
            array_unshift($errors, 'Le CSV ne peut pas contenir plus de '.static::MAX_ROWS.' lignes de données.');
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Le fichier CSV ne contient aucune ligne de données.';
        }

        $seen = [];
        foreach ($rows as $row) {
            $key = $this->dedupKey($row);
            if ($key === null) {
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = "Ligne {$row['_row']} : « {$row['name']} » est dupliqué dans le CSV.";
            }
            $seen[$key] = true;
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
        return "\xEF\xBB\xBF".implode(';', $this->headers())."\n";
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

        $unknown = array_diff($headers, $this->headers());
        $missing = array_diff($this->headers(), $headers);
        if ($unknown !== [] || $missing !== []) {
            $this->fail('Les en-têtes CSV sont incomplets ou inconnus.');
        }
    }

    protected function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    protected function list(mixed $value): array
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

    /**
     * Blank input returns $default (null unless the caller passes one); a
     * non-blank, non-integer input always returns null so the caller can
     * treat "not a number" as a validation failure regardless of whether a
     * default was supplied.
     */
    protected function number(mixed $value, ?int $default = null): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }

        return preg_match('/^-?\d+$/', $value) ? (int) $value : null;
    }

    /** @return never */
    protected function fail(string $message): void
    {
        throw ValidationException::withMessages(['csv' => [$message]]);
    }

    /** @param list<string> $messages */
    protected function failMany(array $messages): void
    {
        throw ValidationException::withMessages(['csv' => array_slice($messages, 0, 20)]);
    }
}
