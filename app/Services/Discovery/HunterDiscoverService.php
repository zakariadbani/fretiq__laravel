<?php

namespace App\Services\Discovery;

use App\Models\ProspectCriteria;

class HunterDiscoverService
{
    /** @var list<string> */
    private const DISCOVER_FILTER_KEYS = [
        'organization',
        'similar_to',
        'headquarters_location',
        'industry',
        'headcount',
        'company_type',
        'year_founded',
        'keywords',
        'technology',
        'funding',
    ];

    public function __construct(private readonly CompanyDiscoveryService $domains = new CompanyDiscoveryService) {}

    public function preview(ProspectCriteria $criteria, ?string $target, ?string $exclude): array
    {
        $prompt = $this->buildPrompt($criteria, $target, $exclude);

        return ['ok' => true, 'prompt' => $prompt, 'companies' => [], 'filters' => []];
    }

    public function buildPrompt(ProspectCriteria $criteria, ?string $target, ?string $exclude): string
    {
        $snapshot = $this->targetingSnapshot($criteria, $target, $exclude);
        $parts = [];
        if ($snapshot['target'] !== '') {
            $parts[] = 'Cible: '.$snapshot['target'].'.';
        }
        if ($snapshot['exclude'] !== null) {
            $parts[] = 'Exclure: '.$snapshot['exclude'].'.';
        }

        foreach (['Secteurs' => 'sectors', 'Pays' => 'countries', 'Tailles' => 'sizes'] as $label => $key) {
            $values = $snapshot[$key];
            if ($values) {
                $parts[] = $label.': '.implode(', ', $values).'.';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{target:string,exclude:?string,sectors:list<string>,countries:list<string>,sizes:list<string>}
     */
    public function targetingSnapshot(ProspectCriteria $criteria, ?string $target, ?string $exclude): array
    {
        return ProspectCriteria::canonicalDiscoverTargeting(
            $target,
            $exclude,
            $criteria->sectors,
            $criteria->countries,
            $criteria->company_sizes,
        );
    }

    public function promptHash(ProspectCriteria $criteria, ?string $target, ?string $exclude): string
    {
        return hash('sha256', $this->canonicalJson($this->targetingSnapshot($criteria, $target, $exclude)));
    }

    /** @param array<string, mixed> $filters */
    public function filtersHash(array $filters): string
    {
        return hash('sha256', $this->canonicalJson($filters));
    }

    /**
     * Keep only Hunter's documented Discover filters. The returned value is safe
     * to persist and can be sent back unchanged for subsequent pages.
     *
     * @return array<string, mixed>
     */
    public function normalizeFilters(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $filters = [];
        foreach (self::DISCOVER_FILTER_KEYS as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $normalized = match ($key) {
                'organization', 'similar_to' => $this->normalizeNamedFilter($value[$key]),
                'industry', 'company_type' => $this->normalizeIncludeExcludeFilter($value[$key]),
                'headcount' => $this->normalizeScalarList($value[$key]),
                'headquarters_location' => $this->normalizeLocationFilter($value[$key]),
                'year_founded' => $this->normalizeYearFoundedFilter($value[$key]),
                'keywords', 'technology' => $this->normalizeKeywordFilter($value[$key]),
                'funding' => $this->normalizeFundingFilter($value[$key]),
            };

            if ($normalized !== null && $normalized !== []) {
                $filters[$key] = $normalized;
            }
        }

        ksort($filters, SORT_STRING);

        return $filters;
    }

    /**
     * Normalize only the company fields needed by staging. No raw response,
     * personal email, authenticated URL or arbitrary provider field escapes.
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeBatchRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($rows) as $position => $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            $domain = preg_replace('/^www\./i', '', $domain) ?: '';
            if ($domain === ''
                || strlen($domain) > 253
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                $domain = null;
            }

            $name = $this->safeText($row['organization'] ?? null, 255);
            if ($name === null && $domain === null) {
                continue;
            }

            $counts = is_array($row['emails_count'] ?? null) ? $row['emails_count'] : [];
            $personal = max(0, min(1_000_000, (int) ($counts['personal'] ?? 0)));
            $generic = max(0, min(1_000_000, (int) ($counts['generic'] ?? 0)));
            $total = max($personal + $generic, max(0, min(1_000_000, (int) ($counts['total'] ?? 0))));
            $country = strtoupper((string) ($row['country'] ?? data_get($row, 'headquarters.country_code', '')));
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                $country = null;
            }

            $normalized[] = [
                'position' => $position,
                'company_name' => $name ?? $domain,
                'provided_domain' => $domain,
                'country' => $country,
                'city' => $this->safeText($row['city'] ?? data_get($row, 'headquarters.city'), 120),
                'source_metadata' => array_filter([
                    'source' => 'hunter_discover',
                    'reported_domain' => $domain,
                    'emails_count' => [
                        'personal' => $personal,
                        'generic' => $generic,
                        'total' => $total,
                    ],
                ], static fn (mixed $part): bool => $part !== null),
            ];
        }

        return $normalized;
    }

    public function normalize(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawDomain = strtolower(trim((string) ($row['domain'] ?? '')));
            $domain = $rawDomain === '' ? null : $this->domains->extractDomain('https://'.$rawDomain);
            if (! $domain || isset($normalized[$domain])) {
                continue;
            }

            $counts = is_array($row['emails_count'] ?? null) ? $row['emails_count'] : [];
            $personal = max(0, (int) ($counts['personal'] ?? 0));
            $generic = max(0, (int) ($counts['generic'] ?? 0));
            $name = trim((string) ($row['organization'] ?? ''));
            $normalized[$domain] = [
                'domain' => $domain,
                'organization' => $name !== '' ? $name : null,
                'emails_count' => [
                    'personal' => $personal,
                    'generic' => $generic,
                    'total' => max($personal + $generic, (int) ($counts['total'] ?? 0)),
                ],
            ];
        }

        return array_values($normalized);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $values = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            usort($values, fn (mixed $left, mixed $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));

            return $values;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function normalizeNamedFilter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $filter = [];
        foreach (['domain', 'name'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $part = is_array($value[$key])
                ? $this->normalizeScalarList($value[$key])
                : $this->safeScalar($value[$key]);
            if ($part !== null && $part !== []) {
                $filter[$key] = $part;
            }
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return array<string, list<int|float|string>>|null */
    private function normalizeIncludeExcludeFilter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $filter = [];
        foreach (['include', 'exclude'] as $key) {
            $list = $this->normalizeScalarList($value[$key] ?? null);
            if ($list !== null && $list !== []) {
                $filter[$key] = $list;
            }
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return array<string, list<array<string, int|float|string>>>|null */
    private function normalizeLocationFilter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $filter = [];
        foreach (['include', 'exclude'] as $direction) {
            if (! is_array($value[$direction] ?? null)) {
                continue;
            }
            $locations = [];
            foreach ($value[$direction] as $location) {
                if (! is_array($location)) {
                    continue;
                }
                $safe = [];
                foreach (['continent', 'business_region', 'country', 'state', 'city'] as $key) {
                    $part = $this->safeScalar($location[$key] ?? null);
                    if ($part !== null) {
                        $safe[$key] = $part;
                    }
                }
                if ($safe !== []) {
                    ksort($safe, SORT_STRING);
                    $locations[] = $safe;
                }
            }
            if ($locations !== []) {
                $filter[$direction] = $this->canonicalize($locations);
            }
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return array<string, mixed>|null */
    private function normalizeYearFoundedFilter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $filter = [];
        foreach (['include', 'exclude'] as $key) {
            $list = $this->normalizeScalarList($value[$key] ?? null);
            if ($list !== null && $list !== []) {
                $filter[$key] = $list;
            }
        }
        foreach (['from', 'to'] as $key) {
            $part = $this->safeScalar($value[$key] ?? null);
            if ($part !== null) {
                $filter[$key] = $part;
            }
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return array<string, mixed>|null */
    private function normalizeKeywordFilter(mixed $value): ?array
    {
        $filter = $this->normalizeIncludeExcludeFilter($value) ?? [];
        if (is_array($value) && in_array($value['match'] ?? null, ['any', 'all'], true)) {
            $filter['match'] = $value['match'];
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return array<string, mixed>|null */
    private function normalizeFundingFilter(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $filter = [];
        $series = $this->normalizeScalarList($value['series'] ?? null);
        if ($series !== null && $series !== []) {
            $filter['series'] = $series;
        }
        foreach (['amount', 'date'] as $range) {
            if (! is_array($value[$range] ?? null)) {
                continue;
            }
            $safe = [];
            foreach (['from', 'to'] as $key) {
                $part = $this->safeScalar($value[$range][$key] ?? null);
                if ($part !== null) {
                    $safe[$key] = $part;
                }
            }
            if ($safe !== []) {
                $filter[$range] = $safe;
            }
        }
        ksort($filter, SORT_STRING);

        return $filter;
    }

    /** @return list<int|float|string>|null */
    private function normalizeScalarList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $safe = [];
        foreach ($value as $part) {
            $part = $this->safeScalar($part);
            if ($part !== null) {
                $safe[json_encode($part, JSON_THROW_ON_ERROR)] = $part;
            }
        }
        $safe = array_values($safe);
        usort($safe, static fn (mixed $left, mixed $right): int => strcmp((string) $left, (string) $right));

        return $safe;
    }

    private function safeScalar(mixed $value): int|float|string|null
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || mb_strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_contains($value, '://')
            || str_contains($value, '@')) {
            return null;
        }

        return $value;
    }

    private function safeText(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }
}
