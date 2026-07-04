<?php

namespace Database\Seeders;

use App\Models\ProspectCriteria;
use Illuminate\Database\Seeder;

/**
 * ProspectCriteriaSeeder — persistent, prod-safe reference data.
 *
 * Seeds 3 starter prospect-criteria records for TCL France, now including the
 * AI targeting fields (ai_target / ai_exclude / ai_queries).
 *
 * TCL France is a freight forwarder, so transporteurs / transitaires /
 * logisticiens are competitors — never customers. Every criterion therefore
 * carries the same ai_exclude description and curated, competitor-free
 * ai_queries (Google negative operators) so discovery runs clean out of the
 * box, even without a GEMINI_API_KEY.
 *
 * updateOrCreate keyed on 'name' keeps it idempotent across re-seeds. ai_queries
 * is written via saveQuietly() because ProspectCriteria::updating() nulls
 * ai_queries whenever ai_target/ai_exclude is dirty — a normal save on an updated
 * row would wipe the seeded queries. saveQuietly() bypasses that hook.
 */
class ProspectCriteriaSeeder extends Seeder
{
    public function run(): void
    {
        $exclude = 'Concurrents de TCL à écarter : transporteurs, transitaires, '
            . 'commissionnaires de transport, logisticiens, entreprises de fret et '
            . 'prestataires logistiques (3PL/4PL).';

        $rows = [
            [
                'name'             => 'Chargeurs Agro & Cosmétique — France',
                'ai_target'        => 'Chargeurs de l\'agroalimentaire et de la cosmétique/parfumerie en France : fabricants, industriels et marques qui expédient leurs produits.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'fabricant agroalimentaire France -transitaire -logistique -transporteur',
                    'industriel agroalimentaire France exportateur -transitaire -logistique',
                    'fabricant cosmétique France -"commissionnaire de transport"',
                    'marque parfumerie France fabricant -transitaire -logistique',
                    'producteur agroalimentaire France export -transporteur -logistique',
                ],
                'sectors'          => ['Agroalimentaire', 'Cosmétique & Parfumerie'],
                'countries'        => ['FR'],
                'company_sizes'    => ['51-200', '201-500'],
                'target_positions' => ['Directeur Supply Chain', 'Responsable Transport', 'Responsable Achats'],
                'daily_limit'      => 25,
                'is_active'        => true,
            ],
            [
                'name'             => 'Industrie & Automobile — Export UE',
                'ai_target'        => 'Chargeurs industriels exportateurs (UE) : industrie manufacturière, équipementiers automobiles, fabricants de machines et d\'équipements industriels.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'fabricant équipement automobile France -transitaire -logistique -transporteur',
                    'industrie manufacturière Allemagne exportateur -transitaire -logistique',
                    'fabricant machines industrielles Italie -"commissionnaire de transport"',
                    'équipementier automobile Espagne -transitaire -transporteur',
                    'constructeur machines France export -logistique -transporteur',
                ],
                'sectors'          => ['Industrie manufacturière', 'Automobile', 'Machines & Équipements industriels'],
                'countries'        => ['FR', 'DE', 'IT', 'ES'],
                'company_sizes'    => ['201-500', '500+'],
                'target_positions' => ['Directeur Logistique', 'Responsable Import/Export', 'Directeur Achats'],
                'daily_limit'      => 30,
                'is_active'        => true,
            ],
            [
                'name'             => 'E-commerce & Distribution — Axe France–Maroc',
                'ai_target'        => 'Chargeurs e-commerce, grande distribution et textile/habillement sur l\'axe France–Maroc qui expédient des marchandises.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'e-commerce France marchand expéditeur -transitaire -logistique -transporteur',
                    'grande distribution France importateur -"commissionnaire de transport"',
                    'grossiste textile habillement France -transitaire -logistique',
                    'importateur habillement Maroc -transporteur -logistique',
                    'fabricant textile Maroc -transitaire -logistique',
                ],
                'sectors'          => ['E-commerce', 'Grande distribution', 'Textile & Habillement'],
                'countries'        => ['FR', 'MA'],
                'company_sizes'    => ['11-50', '51-200'],
                'target_positions' => ['Responsable Logistique', 'Acheteur Transport', 'Responsable Douane'],
                'daily_limit'      => 20,
                'is_active'        => true,
            ],
        ];

        foreach ($rows as $row) {
            $queries = array_map(
                static fn (string $q): array => ['q' => $q, 'enabled' => true],
                $row['ai_queries']
            );
            unset($row['ai_queries']);

            $criteria = ProspectCriteria::updateOrCreate(['name' => $row['name']], $row);

            // saveQuietly() bypasses the updating() hook that would null ai_queries.
            $criteria->ai_queries = $queries;
            $criteria->saveQuietly();
        }
    }
}
