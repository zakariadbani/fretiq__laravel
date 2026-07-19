<?php

namespace App\Services\Discovery\Engines;

final class BingSearchAdapter extends AbstractDiscoveryEngineAdapter
{
    public function id(): string { return 'bing'; }
    public function label(): string { return 'Bing'; }
    public function pageSize(): int { return 5; }

    public function params(string $query, int $start, array $cursorParams = []): array
    {
        return [
            'engine' => $this->id(),
            'q' => $query,
            'setlang' => 'fr',
            'first' => $start + 1,
        ];
    }

    public function parse(array $payload, string $query): DiscoveryPage
    {
        $items = is_array($payload['organic_results'] ?? null) ? $payload['organic_results'] : [];
        $nextStart = $items === [] ? null : $this->nextStart($payload, 'first', true);
        return new DiscoveryPage($this->organic($items, $query), $nextStart === null, $nextStart);
    }
}
