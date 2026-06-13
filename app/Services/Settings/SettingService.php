<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SettingService
{
    /**
     * In-process memo so repeated get() calls within one request hit a PHP array,
     * not the cache layer.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $memo = null;

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * Get a setting value by dotted key (e.g. "decouverte.auto_scoring").
     *
     * @param  string $dottedKey
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $dottedKey, mixed $default = null): mixed
    {
        $map = $this->loadMap();

        return array_key_exists($dottedKey, $map) ? $map[$dottedKey] : $default;
    }

    /**
     * Persist a setting value, then clear the cache.
     *
     * @param  string $dottedKey
     * @param  mixed  $value
     * @return void
     */
    public function set(string $dottedKey, mixed $value): void
    {
        [$group, $key] = $this->splitKey($dottedKey);

        Setting::updateOrCreate(
            ['group_name' => $group, 'setting_key' => $key],
            ['value' => $value]
        );

        $this->clearCache();
    }

    /**
     * Return the full settings map as ['group.key' => value].
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->loadMap();
    }

    /**
     * Forget the persistent cache AND the in-process memo.
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget('app_settings');
        $this->memo = null;
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /**
     * Load the full map, using the in-process memo first, then the cache.
     *
     * @return array<string, mixed>
     */
    protected function loadMap(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $this->memo = Cache::remember('app_settings', 3600, function () {
            return $this->buildMap();
        });

        return $this->memo;
    }

    /**
     * Build the ['group.key' => value] map from the database.
     * Guarded by a Schema::hasTable check so a fresh dev environment (not yet
     * migrated) does not throw.
     *
     * @return array<string, mixed>
     */
    protected function buildMap(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        $map = [];

        Setting::all()->each(function (Setting $row) use (&$map) {
            $map["{$row->group_name}.{$row->setting_key}"] = $row->value;
        });

        return $map;
    }

    /**
     * Split a dotted key on the FIRST dot into [group_name, setting_key].
     *
     * @param  string $dottedKey
     * @return array{string, string}
     */
    protected function splitKey(string $dottedKey): array
    {
        $pos = strpos($dottedKey, '.');

        if ($pos === false) {
            return ['default', $dottedKey];
        }

        return [substr($dottedKey, 0, $pos), substr($dottedKey, $pos + 1)];
    }
}
