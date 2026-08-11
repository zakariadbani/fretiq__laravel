<?php

namespace App\Services\Prospecting;

use App\Services\Discovery\DomainCanonicalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class CompanyListParser
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_ROWS = 10_000;

    private const MAX_ORIGINAL_INPUT_BYTES = 4 * 1024;

    /** @var list<string> */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /** @var list<string> */
    private const EXECUTABLE_EXTENSIONS = [
        'bat', 'bin', 'cmd', 'com', 'cpl', 'dll', 'exe', 'hta', 'jar', 'js', 'jse',
        'msi', 'msp', 'pif', 'ps1', 'scr', 'sh', 'vbe', 'vbs', 'wsf', 'php', 'phar',
    ];

    /** @var list<string> */
    private const EXECUTABLE_MIMES = [
        'application/x-dosexec',
        'application/x-executable',
        'application/x-msdownload',
        'application/x-msdos-program',
        'application/x-mach-binary',
        'application/x-php',
        'application/x-sh',
        'application/vnd.microsoft.portable-executable',
        'text/x-php',
        'text/x-shellscript',
    ];

    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'company' => 'company_name',
        'company_name' => 'company_name',
        'entreprise' => 'company_name',
        'nom' => 'company_name',
        'nom_entreprise' => 'company_name',
        'name' => 'company_name',
        'organization' => 'company_name',
        'organisation' => 'company_name',
        'raison_sociale' => 'company_name',
        'country' => 'country',
        'country_code' => 'country',
        'pays' => 'country',
        'city' => 'city',
        'ville' => 'city',
        'locality' => 'city',
        'localite' => 'city',
        'domain' => 'provided_domain',
        'website' => 'provided_domain',
        'site' => 'provided_domain',
        'site_web' => 'provided_domain',
        'url' => 'provided_domain',
    ];

    public function __construct(private readonly DomainCanonicalizer $domains) {}

    /**
     * @return array{rows: list<array{row_number:int, original_input:string, company_name:string, country:?string, city:?string, provided_domain:?string}>, errors: list<array{row_number:?int, code:string, message:string}>}
     */
    public function parseText(string $input): array
    {
        if (strlen($input) > self::MAX_BYTES) {
            return $this->failed('input_too_large');
        }

        $input = $this->stripBom($input);

        if (! mb_check_encoding($input, 'UTF-8') || $this->containsUnsafeControlData($input)) {
            return $this->failed('unsafe_content');
        }

        if (trim($input) === '') {
            return ['rows' => [], 'errors' => []];
        }

        $delimiter = $this->detectDelimiter($input);
        $records = $this->readRecords($input, $delimiter);
        $header = $this->headerMap($records[0] ?? []);
        $hasHeader = array_search('company_name', $header, true) !== false;

        if ($hasHeader) {
            array_shift($records);
        }

        $rows = [];
        $errors = [];
        $rowNumber = 0;

        foreach ($records as $record) {
            if ($this->isBlankRecord($record)) {
                continue;
            }

            $rowNumber++;

            if ($rowNumber > self::MAX_ROWS) {
                return $this->failed('too_many_rows');
            }

            $fields = $hasHeader
                ? $this->fieldsFromHeader($record, $header)
                : $this->fieldsFromPosition($record);

            $originalInput = implode(' | ', array_map($this->normalizeText(...), $record));
            $companyName = $this->normalizeText($fields['company_name'] ?? '');
            $countryInput = $this->normalizeText($fields['country'] ?? '');
            $city = $this->nullableText($fields['city'] ?? null);
            $domainInput = $this->normalizeText($fields['provided_domain'] ?? '');
            $fatal = false;

            if ($companyName === '') {
                $errors[] = $this->error($rowNumber, 'company_name_required');
                $fatal = true;
            } elseif (mb_strlen($companyName) > 255) {
                $errors[] = $this->error($rowNumber, 'company_name_too_long');
                $fatal = true;
            }

            if ($city !== null && mb_strlen($city) > 120) {
                $errors[] = $this->error($rowNumber, 'city_too_long');
                $fatal = true;
            }

            if (strlen($originalInput) > self::MAX_ORIGINAL_INPUT_BYTES) {
                $errors[] = $this->error($rowNumber, 'original_input_too_long');
                $fatal = true;
            }

            $country = $this->normalizeCountry($countryInput);

            if ($countryInput !== '' && $country === null) {
                $errors[] = $this->error($rowNumber, 'country_invalid');
            }

            $providedDomain = null;

            if ($domainInput !== '') {
                $canonicalInput = ! str_contains($domainInput, '://') && str_contains($domainInput, '/')
                    ? 'https://'.$domainInput
                    : $domainInput;
                $canonical = $this->domains->canonicalize($canonicalInput);

                if ($canonical === null) {
                    $errors[] = $this->error($rowNumber, 'domain_invalid');
                } elseif ($canonical->isPlatform) {
                    $errors[] = $this->error($rowNumber, 'platform_domain');
                } else {
                    $providedDomain = $canonical->host;
                }
            }

            if ($fatal) {
                continue;
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'original_input' => $originalInput,
                'company_name' => $companyName,
                'country' => $country,
                'city' => $city,
                'provided_domain' => $providedDomain,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return array{rows: list<array{row_number:int, original_input:string, company_name:string, country:?string, city:?string, provided_domain:?string}>, errors: list<array{row_number:?int, code:string, message:string}>}
     */
    public function parseCsv(UploadedFile $file): array
    {
        $size = $file->getSize();

        if (is_int($size) && $size > self::MAX_BYTES) {
            return $this->failed('file_too_large');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mimes = array_filter([
            strtolower(trim((string) $file->getMimeType())),
            strtolower(trim((string) $file->getClientMimeType())),
        ]);

        if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)
            || array_intersect($mimes, self::EXECUTABLE_MIMES) !== []) {
            return $this->failed('unsafe_file');
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            return $this->failed('file_unreadable');
        }

        $contents = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if (! is_string($contents)) {
            return $this->failed('file_unreadable');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            return $this->failed('file_too_large');
        }

        if ($this->hasExecutableMagic($contents)) {
            return $this->failed('unsafe_file');
        }

        return $this->parseText($contents);
    }

    private function stripBom(string $input): string
    {
        return str_starts_with($input, "\xEF\xBB\xBF") ? substr($input, 3) : $input;
    }

    private function containsUnsafeControlData(string $input): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input) === 1;
    }

    private function hasExecutableMagic(string $contents): bool
    {
        $prefix = ltrim(substr($contents, 0, 32));

        return str_starts_with($contents, 'MZ')
            || str_starts_with($contents, "\x7FELF")
            || str_starts_with($prefix, '#!')
            || preg_match('/^<\?(?:php|=)/i', $prefix) === 1;
    }

    private function detectDelimiter(string $input): string
    {
        $bestDelimiter = ',';
        $bestScore = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $records = array_slice($this->readRecords($input, $delimiter), 0, 6);
            $widths = array_map('count', $records);
            $maxWidth = $widths === [] ? 0 : max($widths);
            $consistent = $maxWidth > 1 ? count(array_filter($widths, static fn (int $width): bool => $width === $maxWidth)) : 0;
            $headerHits = count(array_filter($this->headerMap($records[0] ?? [])));
            $score = ($headerHits * 100) + ($maxWidth * 10) + $consistent;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    /** @return list<list<string|null>> */
    private function readRecords(string $input, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return [];
        }

        fwrite($stream, $input);
        rewind($stream);
        $records = [];

        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $records[] = $record;
        }

        fclose($stream);

        return $records;
    }

    /** @param list<string|null> $record */
    private function isBlankRecord(array $record): bool
    {
        return count(array_filter($record, static fn (?string $value): bool => trim((string) $value) !== '')) === 0;
    }

    /**
     * @param  list<string|null>  $record
     * @return array<int, string|null>
     */
    private function headerMap(array $record): array
    {
        $map = [];

        foreach ($record as $index => $value) {
            $map[$index] = self::HEADER_ALIASES[$this->normalizeHeader((string) $value)] ?? null;
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $record
     * @param  array<int, string|null>  $header
     * @return array<string, string|null>
     */
    private function fieldsFromHeader(array $record, array $header): array
    {
        $fields = [];

        foreach ($header as $index => $field) {
            if ($field !== null && ! array_key_exists($field, $fields)) {
                $fields[$field] = $record[$index] ?? null;
            }
        }

        return $fields;
    }

    /**
     * @param  list<string|null>  $record
     * @return array{company_name:string|null, country:string|null, city:string|null, provided_domain:string|null}
     */
    private function fieldsFromPosition(array $record): array
    {
        return [
            'company_name' => $record[0] ?? null,
            'country' => $record[1] ?? null,
            'city' => $record[2] ?? null,
            'provided_domain' => $record[3] ?? null,
        ];
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(Str::ascii($this->normalizeText($value)));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->normalizeText($value);

        return $value === '' ? null : $value;
    }

    private function normalizeCountry(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $countries = config('global.data.company_countries', []);
        $code = strtoupper($value);

        if (strlen($code) === 2 && array_key_exists($code, $countries)) {
            return $code;
        }

        $needle = $this->countryToken($value);

        foreach ($countries as $countryCode => $label) {
            if ($needle === $this->countryToken((string) $label)) {
                return strtoupper((string) $countryCode);
            }
        }

        return null;
    }

    private function countryToken(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value)));
    }

    /** @return array{row_number:?int, code:string, message:string} */
    private function error(?int $rowNumber, string $code): array
    {
        return ['row_number' => $rowNumber, 'code' => $code, 'message' => $code];
    }

    /**
     * @return array{rows: array{}, errors: list<array{row_number:?int, code:string, message:string}>}
     */
    private function failed(string $code): array
    {
        return ['rows' => [], 'errors' => [$this->error(null, $code)]];
    }
}
