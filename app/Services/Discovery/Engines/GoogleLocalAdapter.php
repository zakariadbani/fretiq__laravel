<?php

namespace App\Services\Discovery\Engines;

final class GoogleLocalAdapter extends AbstractDiscoveryEngineAdapter
{
    public function id(): string { return 'google_local'; }
    public function label(): string { return 'Google Local'; }
    public function pageSize(): int { return 20; }

    public function params(string $query, int $start, array $cursorParams = []): array
    {
        $params = ['engine' => $this->id(), 'q' => $query, 'hl' => 'fr'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $params;
    }

    public function parse(array $payload, string $query): DiscoveryPage
    {
        $items = is_array($payload['local_results'] ?? null) ? $payload['local_results'] : [];
        $nextStart = $items === [] ? null : $this->nextStart($payload, 'start');
        return new DiscoveryPage($this->local($items, $query, true), $nextStart === null, $nextStart);
    }
}
