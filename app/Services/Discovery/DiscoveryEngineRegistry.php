<?php

namespace App\Services\Discovery;

use App\Models\Setting;
use App\Services\Discovery\Engines\BingSearchAdapter;
use App\Services\Discovery\Engines\DiscoveryEngineAdapter;
use App\Services\Discovery\Engines\GoogleLocalAdapter;
use App\Services\Discovery\Engines\GoogleMapsAdapter;
use App\Services\Discovery\Engines\GoogleSearchAdapter;
use InvalidArgumentException;

final class DiscoveryEngineRegistry
{
    /** @var array<string, DiscoveryEngineAdapter> */
    private array $adapters;

    public function __construct()
    {
        $this->adapters = collect([
            new GoogleSearchAdapter(),
            new GoogleMapsAdapter(),
            new GoogleLocalAdapter(),
            new BingSearchAdapter(),
        ])->mapWithKeys(fn (DiscoveryEngineAdapter $adapter) => [$adapter->id() => $adapter])->all();
    }

    /** @return list<string> */
    public function ids(): array { return array_keys($this->adapters); }

    /** @return list<string> */
    public function defaults(): array { return ['google', 'google_maps']; }

    /** @return array<string, string> */
    public function options(): array
    {
        return array_map(fn (DiscoveryEngineAdapter $adapter) => $adapter->label(), $this->adapters);
    }

    /** @return list<string> */
    public function sanitize(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $allowed = array_fill_keys($this->ids(), true);
        $clean = [];
        foreach ($ids as $id) {
            if (is_string($id) && isset($allowed[$id]) && ! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        return $clean;
    }

    /** @return list<string> */
    public function selected(): array
    {
        if (! Setting::has('decouverte.discovery_engines')) {
            return $this->defaults();
        }

        return $this->sanitize(Setting::get('decouverte.discovery_engines'));
    }

    /** @return list<'web'|'local'> */
    public function fixtureFamilies(mixed $ids): array
    {
        $ids = $this->sanitize($ids);
        $families = [];

        if (array_intersect($ids, ['google', 'bing']) !== []) {
            $families[] = 'web';
        }
        if (array_intersect($ids, ['google_maps', 'google_local']) !== []) {
            $families[] = 'local';
        }

        return $families;
    }

    public function get(string $id): DiscoveryEngineAdapter
    {
        return $this->adapters[$id] ?? throw new InvalidArgumentException("Unsupported discovery engine: {$id}");
    }
}
