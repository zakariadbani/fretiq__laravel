<?php

namespace App\Services\Discovery\Engines;

final class GoogleMapsAdapter extends AbstractDiscoveryEngineAdapter
{
    public function id(): string { return 'google_maps'; }
    public function label(): string { return 'Google Maps'; }
    public function pageSize(): int { return 20; }

    public function params(string $query, int $start, array $cursorParams = []): array
    {
        $params = ['engine' => $this->id(), 'type' => 'search', 'q' => $query, 'hl' => 'fr'];
        $safeCursorParams = $this->sanitizeCursorParams($cursorParams);
        if (isset($safeCursorParams['ll'])) {
            $params['ll'] = $safeCursorParams['ll'];
        }
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $params;
    }

    public function parse(array $payload, string $query): DiscoveryPage
    {
        $items = is_array($payload['local_results'] ?? null) ? $payload['local_results'] : [];
        $nextStart = $items === [] ? null : $this->nextStart($payload, 'start');
        $nextParams = $nextStart === null ? [] : $this->sanitizeCursorParams($this->nextQueryParams($payload));

        return new DiscoveryPage(
            $this->stagingCandidates($items, $query),
            $nextStart === null,
            $nextStart,
            $nextParams,
        );
    }

    public function sanitizeCursorParams(array $cursorParams): array
    {
        $ll = $cursorParams['ll'] ?? null;
        if (! is_string($ll)) {
            return [];
        }

        $ll = trim($ll);
        if (preg_match('/^@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?),(\d+(?:\.\d+)?)(z|m)$/', $ll, $matches) !== 1) {
            return [];
        }

        if (abs((float) $matches[1]) > 90 || abs((float) $matches[2]) > 180 || (float) $matches[3] <= 0) {
            return [];
        }

        return ['ll' => $ll];
    }

    /** @return list<array<string, mixed>> */
    private function stagingCandidates(array $items, string $query): array
    {
        $candidates = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $this->nullableText($item['website'] ?? null, 2048);
            $type = $this->nullableText($item['type'] ?? null);
            $address = $this->nullableText($item['address'] ?? null);
            $snippet = trim(implode(' — ', array_filter([$type, $address])));

            $candidates[] = [
                'domain' => $this->domain($url),
                'title' => $this->nullableText($item['title'] ?? null),
                'snippet' => $snippet !== '' ? $snippet : null,
                'url' => $url,
                'address' => $address,
                'phone' => $this->nullableText($item['phone'] ?? null),
                'country' => $this->country($item['country'] ?? null),
                'sector_hint' => $type,
                'provider_key' => $this->providerKey($item),
                'discovery_query' => $query,
                'engine' => $this->id(),
            ];
        }

        return $candidates;
    }

    private function nullableText(mixed $value, int $maxLength = 500): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function country(mixed $value): ?string
    {
        $country = is_string($value) ? strtoupper(trim($value)) : '';

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    /** @param array<string, mixed> $item */
    private function providerKey(array $item): ?string
    {
        foreach (['data_cid', 'place_id', 'data_id'] as $key) {
            $value = $item[$key] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = trim((string) $value);
            if (preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
