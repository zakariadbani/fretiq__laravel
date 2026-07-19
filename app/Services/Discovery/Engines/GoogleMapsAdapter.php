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
        return new DiscoveryPage($this->local($items, $query, false), $nextStart === null, $nextStart, $nextParams);
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
}
