<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * SectorSeeder — seeds the canonical sector taxonomy (structure/specs/sector-taxonomy.md)
 * from config('global.data.company_sectors').
 *
 * use_in_discovery = true for the 32 labels that also appear in
 * config('global.data.prospect_sectors') (the discovery-keyword vocabulary);
 * false for the 8 classification-only buckets that never feed a SerpAPI query.
 *
 * Idempotent: updateOrCreate by label, safe to run repeatedly.
 * Separate from database/seeders/Acl/PermissionsSeeder.php — sectors are data, not ACL.
 */
class SectorSeeder extends Seeder
{
    public function run(): void
    {
        $discoverySectors = config('global.data.prospect_sectors', []);

        foreach (array_values(config('global.data.company_sectors', [])) as $index => $label) {
            Sector::updateOrCreate(
                ['label' => $label],
                [
                    'is_active' => true,
                    'use_in_discovery' => in_array($label, $discoverySectors, true),
                    'sort_order' => $index,
                ]
            );
        }
    }
}
