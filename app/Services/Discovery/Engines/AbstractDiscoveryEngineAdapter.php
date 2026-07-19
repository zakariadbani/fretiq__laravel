<?php

namespace App\Services\Discovery\Engines;

abstract class AbstractDiscoveryEngineAdapter implements DiscoveryEngineAdapter
{
    public function sanitizeCursorParams(array $cursorParams): array
    {
        return [];
    }

    protected function domain(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', strtolower(rtrim($host, '.'))) ?: null;
    }

    /** @return list<array<string, mixed>> */
    protected function organic(array $items, string $query): array
    {
        $candidates = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $item['link'] ?? null;
            $domain = $this->domain(is_string($url) ? $url : null);
            if ($domain === null) {
                continue;
            }

            $candidates[] = [
                'domain' => $domain,
                'title' => $item['title'] ?? null,
                'snippet' => $item['snippet'] ?? null,
                'url' => $url,
                'discovery_query' => $query,
            ];
        }

        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    protected function local(array $items, string $query, bool $nestedWebsite): array
    {
        $candidates = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $nestedWebsite ? data_get($item, 'links.website') : ($item['website'] ?? null);
            $domain = $this->domain(is_string($url) ? $url : null);
            if ($domain === null) {
                continue;
            }

            $type = is_string($item['type'] ?? null) ? trim($item['type']) : '';
            $address = is_string($item['address'] ?? null) ? trim($item['address']) : '';
            $snippet = trim(implode(' — ', array_filter([$type, $address])));
            $candidate = [
                'domain' => $domain,
                'title' => $item['title'] ?? null,
                'snippet' => $snippet !== '' ? $snippet : null,
                'url' => $url,
                'discovery_query' => $query,
            ];

            $phone = is_string($item['phone'] ?? null) ? trim($item['phone']) : '';
            if ($phone !== '') {
                $candidate['phone'] = $phone;
            }
            $country = is_string($item['country'] ?? null) ? strtoupper(trim($item['country'])) : '';
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $candidate['country'] = $country;
            }
            if ($type !== '') {
                $candidate['sector_hint'] = $type;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    protected function nextStart(array $payload, string $parameter, bool $oneBased = false): ?int
    {
        $params = $this->nextQueryParams($payload);
        $value = $params[$parameter] ?? null;
        if (! is_numeric($value)) {
            return null;
        }

        $cursor = (int) $value - ($oneBased ? 1 : 0);

        return $cursor >= 0 ? $cursor : null;
    }

    /** @return array<string, mixed> */
    protected function nextQueryParams(array $payload): array
    {
        $next = data_get($payload, 'serpapi_pagination.next');
        if (! is_string($next) || $next === '') {
            return [];
        }

        $query = parse_url($next, PHP_URL_QUERY);
        if (! is_string($query)) {
            return [];
        }

        parse_str($query, $params);

        return is_array($params) ? $params : [];
    }
}
