<?php

namespace App\Support;

use App\Models\Sector;
use Illuminate\Support\Str;

/**
 * SectorClassifier — normalizes raw companies.sector values (Hunter EN
 * industries, Google Maps FR place types, free-text) into the canonical
 * sector vocabulary (structure/specs/sector-taxonomy.md).
 *
 * Named SectorClassifier (spec calls for App\Support\Sector::canonical(),
 * but App\Models\Sector now owns that basename — same-basename classes in
 * different namespaces would be confusing to `use`).
 */
class SectorClassifier
{
    /**
     * Canonical labels, cached once per request/process.
     *
     * @var array<int, string>|null
     */
    private static ?array $canonical = null;

    /**
     * Normalize a raw sector string to its canonical label.
     *
     * ponytail: an input that matches nothing (not an exact canonical label,
     * not a sector_map rule) is returned UNCHANGED, never nulled — that is
     * the ceiling of this classifier. Unknown-but-real values must surface
     * in the companies:normalize-sectors UNMAPPED report for a human to
     * extend config('global.data.sector_map'), not silently disappear.
     */
    public static function canonical(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $folded = Str::lower(Str::ascii($raw));

        foreach (self::canonicalSet() as $label) {
            if (Str::lower(Str::ascii($label)) === $folded) {
                return $label;
            }
        }

        foreach ((array) config('global.data.sector_map', []) as $pattern => $label) {
            if (preg_match('/'.$pattern.'/u', $folded) === 1) {
                return $label;
            }
        }

        return $raw;
    }

    /**
     * Reset the per-process canonical-label cache. Tests call this so the
     * config-fallback path is exercised even without a seeded/migrated table.
     */
    public static function resetCache(): void
    {
        self::$canonical = null;
    }

    /**
     * @return array<int, string>
     */
    private static function canonicalSet(): array
    {
        if (self::$canonical !== null) {
            return self::$canonical;
        }

        $labels = [];

        try {
            $labels = Sector::query()->pluck('label')->all();
        } catch (\Throwable $e) {
            // table not migrated yet, or a pure unit test with no DB — fall through
        }

        if ($labels === []) {
            $labels = (array) config('global.data.company_sectors', []);
        }

        return self::$canonical = $labels;
    }
}
