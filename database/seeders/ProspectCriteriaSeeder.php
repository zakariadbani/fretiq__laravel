<?php

namespace Database\Seeders;

use App\Models\ProspectCriteria;
use Illuminate\Database\Seeder;

/**
 * ProspectCriteriaSeeder — persistent, prod-safe reference data.
 *
 * Seeds 3 starter prospect-criteria records for TCL France.
 * All inserts use firstOrCreate with the logical key 'name' → idempotent
 * across migrate:fresh --seed runs. Native PHP arrays are passed for JSON-cast
 * columns (sectors, countries, company_sizes, target_positions).
 */
class ProspectCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name'             => 'Chargeurs Agro & Cosmétique — France',
                'sectors'          => ['Agroalimentaire', 'Cosmétique & Parfumerie'],
                'countries'        => ['FR'],
                'company_sizes'    => ['51-200', '201-500'],
                'target_positions' => ['Directeur Supply Chain', 'Responsable Transport', 'Responsable Achats'],
                'daily_limit'      => 25,
                'is_active'        => true,
            ],
            [
                'name'             => 'Industrie & Automobile — Export UE',
                'sectors'          => ['Industrie manufacturière', 'Automobile', 'Machines & Équipements industriels'],
                'countries'        => ['FR', 'DE', 'IT', 'ES'],
                'company_sizes'    => ['201-500', '500+'],
                'target_positions' => ['Directeur Logistique', 'Responsable Import/Export', 'Directeur Achats'],
                'daily_limit'      => 30,
                'is_active'        => true,
            ],
            [
                'name'             => 'E-commerce & Distribution — Axe France–Maroc',
                'sectors'          => ['E-commerce', 'Grande distribution', 'Textile & Habillement'],
                'countries'        => ['FR', 'MA'],
                'company_sizes'    => ['11-50', '51-200'],
                'target_positions' => ['Responsable Logistique', 'Acheteur Transport', 'Responsable Douane'],
                'daily_limit'      => 20,
                'is_active'        => true,
            ],
        ];

        foreach ($rows as $row) {
            ProspectCriteria::firstOrCreate(['name' => $row['name']], $row);
        }
    }
}
