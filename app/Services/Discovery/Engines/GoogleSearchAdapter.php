<?php

namespace App\Services\Discovery\Engines;

final class GoogleSearchAdapter extends AbstractDiscoveryEngineAdapter
{
    public function id(): string { return 'google'; }
    public function label(): string { return 'Google'; }
    public function pageSize(): int { return 10; }

    public function params(string $query, int $start, array $cursorParams = []): array
    {
        $params = ['engine' => $this->id(), 'q' => $query, 'num' => 10, 'filter' => 0];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $params;
    }

    public function parse(array $payload, string $query): DiscoveryPage
    {
        $items = is_array($payload['organic_results'] ?? null) ? $payload['organic_results'] : [];
        $nextStart = $items === [] ? null : $this->nextStart($payload, 'start');
        return new DiscoveryPage($this->organic($items, $query), $nextStart === null, $nextStart);
    }
}
