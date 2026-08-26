<?php

namespace App\Services\Prospecting;

use App\Models\Company;
use App\Services\Discovery\CanonicalDomain;
use App\Services\Discovery\DiscoveredContactImportService;
use App\Services\Discovery\DomainCanonicalizer;
use App\Support\CountryResolver;
use App\Support\SectorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports Hunter.io "companies" and "leads" CSV exports into companies +
 * contacts, deduping/merging against existing rows.
 *
 * Flow: parseCompanies()/parseLeads() (pure, read the upload) → preview()
 * (read-only, counts per outcome) → commit() (chunked writes). The cache
 * payload between preview and commit is the RAW parsed rows, never resolved
 * company ids — resolution always happens fresh against the DB (in preview
 * as a dry-run, in commit for real) so a stale cache entry can never point
 * at the wrong company.
 */
final class HunterCsvImportService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_ROWS = 10_000;

    private const CHUNK_SIZE = 500;

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
    private const COMPANY_HEADER_ALIASES = [
        'company' => 'company_name',
        'company_name' => 'company_name',
        'name' => 'company_name',
        'organization' => 'company_name',
        'domain' => 'domain',
        'website' => 'domain',
        'site' => 'domain',
        'url' => 'domain',
        'industry' => 'sector',
        'sector' => 'sector',
        'description' => 'description',
        'about' => 'description',
        'country' => 'country',
        'country_code' => 'country',
        'headcount' => 'estimated_size',
        'company_size' => 'estimated_size',
        'size' => 'estimated_size',
        'employees' => 'estimated_size',
        'number_of_employees' => 'estimated_size',
    ];

    /** @var array<string, string> */
    private const LEAD_HEADER_ALIASES = [
        'first_name' => 'first_name',
        'firstname' => 'first_name',
        'last_name' => 'last_name',
        'lastname' => 'last_name',
        'email' => 'email',
        'e_mail' => 'email',
        'email_address' => 'email',
        'position' => 'position',
        'title' => 'position',
        'job_title' => 'position',
        'phone' => 'phone',
        'phone_number' => 'phone',
        'company' => 'company_name',
        'company_name' => 'company_name',
        'organization' => 'company_name',
        'website' => 'domain',
        'domain' => 'domain',
        'url' => 'domain',
        'industry' => 'sector',
        'sector' => 'sector',
        'company_size' => 'estimated_size',
        'headcount' => 'estimated_size',
        // The lead's own "Country" column is the CONTACT's personal location,
        // not the company's — deliberately unaliased so it's ignored rather
        // than mis-stored as the company's country. "Company Country" is the
        // right source for that.
        'company_country' => 'country',
        'verification_status' => 'verification_status',
        'email_status' => 'verification_status',
        'verification_date' => 'verification_date',
        'email_verification_date' => 'verification_date',
    ];

    public function __construct(
        private readonly DomainCanonicalizer $domains,
        private readonly DiscoveredContactImportService $contacts,
    ) {}

    // ── Parsing ──────────────────────────────────────────────────────────────

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row_number:?int, code:string}>}
     */
    public function parseCompanies(UploadedFile $file): array
    {
        return $this->parseUpload($file, self::COMPANY_HEADER_ALIASES, 'company_name', function (array $fields, int $rowNumber, array &$errors): ?array {
            $name = $this->normalizeText($fields['company_name'] ?? '');
            $domainInput = $this->normalizeText($fields['domain'] ?? '');

            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = $this->error($rowNumber, $name === '' ? 'company_name_required' : 'company_name_too_long');

                return null;
            }

            if ($domainInput === '') {
                $errors[] = $this->error($rowNumber, 'domain_missing');

                return null;
            }

            $canonical = $this->canonicalizeField($domainInput);

            if ($canonical === null) {
                $errors[] = $this->error($rowNumber, 'domain_invalid');

                return null;
            }

            if ($canonical->isPlatform) {
                $errors[] = $this->error($rowNumber, 'platform_domain');

                return null;
            }

            $countryInput = $this->normalizeText($fields['country'] ?? '');
            if ($countryInput !== '' && $this->normalizeCountry($countryInput) === null) {
                $errors[] = $this->error($rowNumber, 'country_invalid');
            }

            return [
                'row_number' => $rowNumber,
                'company_name' => mb_substr($name, 0, 255),
                'domain_host' => $canonical->host,
                'domain_registrable' => $canonical->registrableDomain,
                'sector' => $this->nullableText($fields['sector'] ?? null, 100),
                'description' => $this->nullableText($fields['description'] ?? null, 2000),
                'country' => $countryInput !== '' ? $this->normalizeCountry($countryInput) : null,
                'estimated_size' => $this->nullableText($fields['estimated_size'] ?? null, 20),
            ];
        });
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row_number:?int, code:string}>}
     */
    public function parseLeads(UploadedFile $file): array
    {
        return $this->parseUpload($file, self::LEAD_HEADER_ALIASES, 'email', function (array $fields, int $rowNumber, array &$errors): ?array {
            $email = strtolower(trim((string) ($fields['email'] ?? '')));

            if ($email === '') {
                $errors[] = $this->error($rowNumber, 'email_required');

                return null;
            }

            $domainInput = $this->normalizeText($fields['domain'] ?? '');
            if ($domainInput === '') {
                $errors[] = $this->error($rowNumber, 'domain_missing');

                return null;
            }

            $canonical = $this->canonicalizeField($domainInput);

            if ($canonical === null) {
                $errors[] = $this->error($rowNumber, 'domain_invalid');

                return null;
            }

            if ($canonical->isPlatform) {
                $errors[] = $this->error($rowNumber, 'platform_domain');

                return null;
            }

            $countryInput = $this->normalizeText($fields['country'] ?? '');
            if ($countryInput !== '' && $this->normalizeCountry($countryInput) === null) {
                $errors[] = $this->error($rowNumber, 'country_invalid');
            }

            return [
                'row_number' => $rowNumber,
                'email' => $email,
                'first_name' => $this->nullableText($fields['first_name'] ?? null, 120),
                'last_name' => $this->nullableText($fields['last_name'] ?? null, 120),
                'position' => $this->nullableText($fields['position'] ?? null, 120),
                'phone' => $this->nullableText($fields['phone'] ?? null, 50),
                'verification_status' => $this->normalizeVerificationStatus($fields['verification_status'] ?? null),
                'verification_date' => $this->nullableText($fields['verification_date'] ?? null, 40),
                'company_name' => $this->nullableText($fields['company_name'] ?? null, 255),
                'domain_host' => $canonical->host,
                'domain_registrable' => $canonical->registrableDomain,
                'sector' => $this->nullableText($fields['sector'] ?? null, 100),
                'estimated_size' => $this->nullableText($fields['estimated_size'] ?? null, 20),
                'country' => $countryInput !== '' ? $this->normalizeCountry($countryInput) : null,
            ];
        });
    }

    // ── Preview (read-only) ─────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $companyRows
     * @param  list<array<string, mixed>>  $leadRows
     * @return array{companies: array<string,int>, leads: array<string,int>, unmapped_industries: list<string>}
     */
    public function preview(array $companyRows, array $leadRows): array
    {
        $companyHostSet = [];
        foreach ($companyRows as $row) {
            $companyHostSet[$row['domain_host']] = true;
        }

        $groups = $this->groupLeadsByDomain($leadRows);

        $candidates = [];
        foreach ($companyRows as $row) {
            $candidates[] = ['host' => $row['domain_host'], 'registrable' => $row['domain_registrable']];
        }
        foreach ($groups as $group) {
            $candidates[] = ['host' => $group['host'], 'registrable' => $group['registrable']];
        }
        $resolved = $this->resolveManyReadOnly($candidates);

        $companiesSummary = ['create' => 0, 'merge' => 0, 'registrable_domain_conflict' => 0];
        foreach ($companyRows as $row) {
            $result = $resolved[$row['domain_host']];
            $companiesSummary[$result['outcome']] = ($companiesSummary[$result['outcome']] ?? 0) + 1;
        }

        $leadSummary = [
            'contacts_total' => count($leadRows),
            'domains_total' => count($groups),
            'will_create_stub_companies' => 0,
            'will_attach_existing' => 0,
            'skipped_registrable_domain_conflict_domains' => 0,
            'contacts_skipped_registrable_domain_conflict' => 0,
            'contacts_skipped_invalid_verification' => 0,
            'contacts_pending_verification' => 0,
            'contacts_valid' => 0,
            'contacts_accept_all' => 0,
            'contacts_other_status' => 0,
        ];

        foreach ($leadRows as $row) {
            match ($row['verification_status']) {
                'valid' => $leadSummary['contacts_valid']++,
                'accept_all' => $leadSummary['contacts_accept_all']++,
                'invalid' => $leadSummary['contacts_skipped_invalid_verification']++,
                null => $leadSummary['contacts_pending_verification']++,
                default => $leadSummary['contacts_other_status']++,
            };
        }

        foreach ($groups as $group) {
            $result = $resolved[$group['host']];

            if ($result['outcome'] === 'registrable_domain_conflict') {
                $leadSummary['skipped_registrable_domain_conflict_domains']++;
                $leadSummary['contacts_skipped_registrable_domain_conflict'] += count($group['rows']);

                continue;
            }

            if ($result['company'] !== null || isset($companyHostSet[$group['host']])) {
                $leadSummary['will_attach_existing']++;

                continue;
            }

            $leadSummary['will_create_stub_companies']++;
        }

        return [
            'companies' => $companiesSummary,
            'leads' => $leadSummary,
            'unmapped_industries' => $this->unmappedIndustries($companyRows, $leadRows),
        ];
    }

    // ── Commit (writes, chunked transactions) ────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $companyRows
     * @param  list<array<string, mixed>>  $leadRows
     * @return array<string, mixed>
     */
    public function commit(array $companyRows, array $leadRows): array
    {
        $stats = [
            'companies_created' => 0,
            'companies_merged' => 0,
            'companies_skipped_conflict' => 0,
            'stub_companies_created' => 0,
            'contacts_created' => 0,
            'contacts_updated' => 0,
            'contacts_skipped' => [],
        ];

        foreach (array_chunk($companyRows, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk, &$stats): void {
                foreach ($chunk as $row) {
                    $result = $this->resolveCompany($row['domain_host'], $row['domain_registrable'], true);

                    if ($result['outcome'] === 'registrable_domain_conflict') {
                        $stats['companies_skipped_conflict']++;

                        continue;
                    }

                    if ($result['company'] !== null) {
                        $this->fillBlankCompanyFields($result['company'], $row);
                        $stats['companies_merged']++;

                        continue;
                    }

                    $this->createCompany($row);
                    $stats['companies_created']++;
                }
            });
        }

        $groups = $this->groupLeadsByDomain($leadRows, $stats['contacts_skipped']);

        foreach ($this->chunkGroupsByRowCount($groups, self::CHUNK_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk, &$stats): void {
                foreach ($chunk as $group) {
                    $result = $this->resolveCompany($group['host'], $group['registrable'], true);

                    if ($result['outcome'] === 'registrable_domain_conflict') {
                        $this->increment($stats['contacts_skipped'], 'registrable_domain_conflict', count($group['rows']));

                        continue;
                    }

                    $company = $result['company'];
                    if ($company === null) {
                        $company = $this->createStubCompany($group);
                        $stats['stub_companies_created']++;
                    }

                    $importRows = array_map($this->toContactImportRow(...), $group['rows']);
                    $imported = $this->contacts->import($company, $importRows, null, 'hunter');

                    $stats['contacts_created'] += $imported->created;
                    $stats['contacts_updated'] += $imported->updated;
                    foreach ($imported->skipped as $reason => $count) {
                        $this->increment($stats['contacts_skipped'], $reason, $count);
                    }
                }
            });
        }

        return $stats;
    }

    // ── Company resolution / writes ─────────────────────────────────────────

    /**
     * Read-only batched resolution for preview() — mirrors resolveCompany()'s
     * outcome semantics with 2 whereIn queries total instead of 2 per row
     * (measured 1288 queries / 2.42s pre-fix; see grill NEEDS WORK #7).
     *
     * @param  list<array{host:string, registrable:string}>  $candidates
     * @return array<string, array{outcome:string, company:?Company}> keyed by host
     */
    private function resolveManyReadOnly(array $candidates): array
    {
        $hosts = [];
        $registrables = [];
        foreach ($candidates as $candidate) {
            $hosts[$candidate['host']] = true;
            $registrables[$candidate['registrable']] = true;
        }
        $hosts = array_keys($hosts);
        $registrables = array_keys($registrables);

        if ($hosts === []) {
            return [];
        }

        $exactByDomain = Company::withRejected()->whereIn('domain', $hosts)->get()->keyBy('domain');

        // Query the FULL registrables set, not just the ones left after
        // diffing against exact-matched hosts — a candidate's registrable
        // can equal another candidate's exact-matched host (different value
        // spaces), and array_diff silently dropped that fallback match,
        // making preview say "create" while commit (which re-resolves per
        // row) says "merge".
        $fallbackCandidates = $registrables === []
            ? collect()
            : Company::withRejected()
                ->where(function ($builder) use ($registrables): void {
                    $builder->whereIn('registrable_domain', $registrables)
                        ->orWhereIn('domain', $registrables);
                })
                ->get();

        $results = [];
        foreach ($candidates as $candidate) {
            $host = $candidate['host'];
            if (array_key_exists($host, $results)) {
                continue;
            }

            if ($exactByDomain->has($host)) {
                $results[$host] = ['outcome' => 'merge', 'company' => $exactByDomain->get($host)];

                continue;
            }

            $registrable = $candidate['registrable'];
            $matches = $fallbackCandidates->filter(
                fn (Company $c): bool => $c->registrable_domain === $registrable || $c->domain === $registrable
            );

            $results[$host] = match ($matches->count()) {
                0 => ['outcome' => 'create', 'company' => null],
                1 => ['outcome' => 'merge', 'company' => $matches->first()],
                default => ['outcome' => 'registrable_domain_conflict', 'company' => null],
            };
        }

        return $results;
    }

    /** @return array{outcome:string, company:?Company} */
    private function resolveCompany(string $host, string $registrable, bool $lock): array
    {
        $exactQuery = Company::withRejected()->where('domain', $host);
        if ($lock) {
            $exactQuery->lockForUpdate();
        }
        $exact = $exactQuery->first();

        if ($exact !== null) {
            return ['outcome' => 'merge', 'company' => $exact];
        }

        // ponytail: no leading-wildcard LIKE leg here — registrable_domain and
        // domain are both indexed exact matches; the LIKE leg used to force a
        // full-table scan under lockForUpdate() (see grill BLOCK #5).
        $fallbackQuery = Company::withRejected()->where(function ($builder) use ($registrable): void {
            $builder->where('registrable_domain', $registrable)
                ->orWhere('domain', $registrable);
        });
        if ($lock) {
            $fallbackQuery->lockForUpdate();
        }
        $candidates = $fallbackQuery->get();

        if ($candidates->count() === 1) {
            return ['outcome' => 'merge', 'company' => $candidates->first()];
        }

        if ($candidates->count() > 1) {
            return ['outcome' => 'registrable_domain_conflict', 'company' => null];
        }

        return ['outcome' => 'create', 'company' => null];
    }

    /** @param array<string, mixed> $row */
    private function createCompany(array $row): Company
    {
        $attributes = [
            'domain' => $row['domain_host'],
            'registrable_domain' => $row['domain_registrable'],
            'name' => $row['company_name'],
            'sector' => $row['sector'] ?? null,
            'description' => $row['description'] ?? null,
            'country' => $row['country'] ?? null,
            'estimated_size' => $row['estimated_size'] ?? null,
            'source' => 'hunter',
            'relationship' => 'prospect',
            'qualification_status' => 'pending',
            'is_active' => true,
        ];

        try {
            return Company::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = Company::withRejected()->where('domain', $row['domain_host'])->lockForUpdate()->first();
            if ($existing === null) {
                throw $exception;
            }

            $this->fillBlankCompanyFields($existing, $row);

            return $existing;
        }
    }

    /** @param array<string, mixed> $group */
    private function createStubCompany(array $group): Company
    {
        $rows = $group['rows'];
        $companyName = $this->firstNonBlank($rows, 'company_name') ?? $this->nameFromDomain($group['registrable']);

        return $this->createCompany([
            'domain_host' => $group['host'],
            'domain_registrable' => $group['registrable'],
            'company_name' => mb_substr($companyName, 0, 255),
            'sector' => $this->firstNonBlank($rows, 'sector'),
            'description' => null,
            'country' => $this->firstNonBlank($rows, 'country'),
            'estimated_size' => $this->firstNonBlank($rows, 'estimated_size'),
        ]);
    }

    /**
     * Fill-blank-only merge — never overwrites an existing value, never
     * touches relationship/qualification_status/criteria_id.
     *
     * @param  array<string, mixed>  $row
     */
    private function fillBlankCompanyFields(Company $company, array $row): bool
    {
        $candidates = [
            'name' => $row['company_name'] ?? null,
            'sector' => $row['sector'] ?? null,
            'description' => $row['description'] ?? null,
            'country' => $row['country'] ?? null,
            'estimated_size' => $row['estimated_size'] ?? null,
            'registrable_domain' => $row['domain_registrable'] ?? null,
        ];

        $changes = [];
        foreach ($candidates as $field => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $current = $company->getAttribute($field);
            if ($current === null || (is_string($current) && trim($current) === '')) {
                $changes[$field] = $value;
            }
        }

        if ($changes === []) {
            return false;
        }

        $company->forceFill($changes)->save();

        return true;
    }

    private function nameFromDomain(string $registrableDomain): string
    {
        $label = explode('.', $registrableDomain)[0] ?? $registrableDomain;
        $label = trim(str_replace(['-', '_'], ' ', $label));

        return $label === '' ? $registrableDomain : Str::title($label);
    }

    // ── Lead grouping / contact row shaping ──────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $leadRows
     * @param  array<string,int>  $skipped  by-ref bucket incremented for 'invalid' verification rows
     * @return array<string, array{host:string, registrable:string, rows: list<array<string,mixed>>}>
     */
    private function groupLeadsByDomain(array $leadRows, array &$skipped = []): array
    {
        $groups = [];
        foreach ($leadRows as $row) {
            if ($row['verification_status'] === 'invalid') {
                $this->increment($skipped, 'invalid_verification', 1);

                continue;
            }

            $host = $row['domain_host'];
            if (! isset($groups[$host])) {
                $groups[$host] = ['host' => $host, 'registrable' => $row['domain_registrable'], 'rows' => []];
            }
            $groups[$host]['rows'][] = $row;
        }

        return $groups;
    }

    /**
     * @param  array<string, array{host:string, registrable:string, rows: list<array<string,mixed>>}>  $groups
     * @return list<list<array{host:string, registrable:string, rows: list<array<string,mixed>>}>>
     */
    private function chunkGroupsByRowCount(array $groups, int $limit): array
    {
        $chunks = [];
        $current = [];
        $count = 0;

        foreach ($groups as $group) {
            $current[] = $group;
            $count += count($group['rows']);

            if ($count >= $limit) {
                $chunks[] = $current;
                $current = [];
                $count = 0;
            }
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function toContactImportRow(array $row): array
    {
        return [
            'email' => $row['email'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'position' => $row['position'],
            'phone' => $row['phone'],
            'verification' => [
                'status' => $row['verification_status'],
                'date' => $row['verification_date'],
            ],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function firstNonBlank(array $rows, string $key): ?string
    {
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    // ── Sector taxonomy hint ─────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $companyRows
     * @param  list<array<string, mixed>>  $leadRows
     * @return list<string>
     */
    private function unmappedIndustries(array $companyRows, array $leadRows): array
    {
        $raw = [];
        foreach ([...$companyRows, ...$leadRows] as $row) {
            $sector = $row['sector'] ?? null;
            if (is_string($sector) && trim($sector) !== '') {
                $raw[trim($sector)] = true;
            }
        }

        $unmapped = array_values(array_filter(array_keys($raw), $this->isUnmappedSector(...)));
        sort($unmapped);

        return array_slice($unmapped, 0, 30);
    }

    /**
     * ponytail: heuristic reusing SectorClassifier's own resolution — a raw
     * value that round-trips unchanged never matched the canonical set or a
     * sector_map rule. Rare false positive: an input already byte-identical
     * to a canonical label also round-trips unchanged and would show up here
     * too — harmless, this is an informational hint list, not a write gate.
     */
    private function isUnmappedSector(string $raw): bool
    {
        return SectorClassifier::canonical($raw) === $raw;
    }

    // ── CSV parsing engine (safety checks borrowed from CompanyListParser) ──

    /**
     * @param  array<string, string>  $headerAliases
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row_number:?int, code:string}>}
     */
    private function parseUpload(UploadedFile $file, array $headerAliases, string $requiredHeaderKey, \Closure $mapRow): array
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

        $contents = $this->stripBom($contents);
        $contents = $this->normalizeEncoding($contents);

        if (! mb_check_encoding($contents, 'UTF-8') || $this->containsUnsafeControlData($contents)) {
            return $this->failed('unsafe_content');
        }

        if (trim($contents) === '') {
            return ['rows' => [], 'errors' => []];
        }

        $delimiter = $this->detectDelimiter($contents, $headerAliases);
        $records = $this->readRecords($contents, $delimiter);
        $header = $this->headerMap($records[0] ?? [], $headerAliases);

        if (array_search($requiredHeaderKey, $header, true) === false) {
            return $this->failed('header_required');
        }

        array_shift($records);

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

            $fields = [];
            foreach ($header as $index => $field) {
                if ($field !== null && ! array_key_exists($field, $fields)) {
                    $fields[$field] = $record[$index] ?? null;
                }
            }

            $row = $mapRow($fields, $rowNumber, $errors);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Prepend a scheme to bare hosts that carry a path (e.g. "example.com/x")
     * so DomainCanonicalizer routes them through its scheme-provided branch
     * instead of rejecting them as an invalid bare host.
     */
    private function canonicalizeField(string $domainInput): ?CanonicalDomain
    {
        $canonicalInput = ! str_contains($domainInput, '://') && str_contains($domainInput, '/')
            ? 'https://'.$domainInput
            : $domainInput;

        return $this->domains->canonicalize($canonicalInput);
    }

    private function stripBom(string $input): string
    {
        return str_starts_with($input, "\xEF\xBB\xBF") ? substr($input, 3) : $input;
    }

    /**
     * UTF-8 gate rejects non-UTF-8 bytes outright — but Hunter exports opened
     * and re-saved through Excel FR locale often land as Windows-1252. Try a
     * transcode fallback before the safety gate would otherwise hard-fail the
     * whole file as unsafe_content.
     */
    private function normalizeEncoding(string $input): string
    {
        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        $converted = @mb_convert_encoding($input, 'UTF-8', 'Windows-1252');

        return is_string($converted) && mb_check_encoding($converted, 'UTF-8') ? $converted : $input;
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

    /** @param array<string, string> $headerAliases */
    private function detectDelimiter(string $input, array $headerAliases): string
    {
        // ponytail: probe on a small sample instead of parsing the whole file
        // once per candidate delimiter (was 4x full-file fgetcsv passes on
        // every upload — see grill NEEDS WORK #11).
        $sample = $this->firstLines($input, 8);
        $bestDelimiter = ',';
        $bestScore = -1;

        foreach (self::DELIMITERS as $delimiter) {
            $records = array_slice($this->readRecords($sample, $delimiter), 0, 6);
            $widths = array_map('count', $records);
            $maxWidth = $widths === [] ? 0 : max($widths);
            $consistent = $maxWidth > 1 ? count(array_filter($widths, static fn (int $width): bool => $width === $maxWidth)) : 0;
            $headerHits = count(array_filter($this->headerMap($records[0] ?? [], $headerAliases)));
            $score = ($headerHits * 100) + ($maxWidth * 10) + $consistent;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestDelimiter = $delimiter;
            }
        }

        return $bestDelimiter;
    }

    private function firstLines(string $input, int $limit): string
    {
        $lines = explode("\n", $input, $limit + 1);

        return implode("\n", array_slice($lines, 0, $limit));
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
     * @param  array<string, string>  $headerAliases
     * @return array<int, string|null>
     */
    private function headerMap(array $record, array $headerAliases): array
    {
        $map = [];
        foreach ($record as $index => $value) {
            $map[$index] = $headerAliases[$this->normalizeHeader((string) $value)] ?? null;
        }

        return $map;
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

    private function nullableText(mixed $value, int $limit): ?string
    {
        $text = $this->normalizeText($value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    private function normalizeVerificationStatus(mixed $value): ?string
    {
        $text = strtolower($this->normalizeText($value));

        return $text === '' ? null : $text;
    }

    private function normalizeCountry(string $value): ?string
    {
        return CountryResolver::resolve($value);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 1062, 1555, 2067], true));
    }

    /** @param array<string, int> $bucket */
    private function increment(array &$bucket, string $reason, int $by = 1): void
    {
        $bucket[$reason] = ($bucket[$reason] ?? 0) + $by;
    }

    /** @return array{row_number:?int, code:string} */
    private function error(?int $rowNumber, string $code): array
    {
        return ['row_number' => $rowNumber, 'code' => $code];
    }

    /** @return array{rows: array{}, errors: list<array{row_number:?int, code:string}>} */
    private function failed(string $code): array
    {
        return ['rows' => [], 'errors' => [$this->error(null, $code)]];
    }
}
