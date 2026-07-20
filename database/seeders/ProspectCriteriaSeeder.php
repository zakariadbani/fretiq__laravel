<?php

namespace Database\Seeders;

use App\Models\ProspectCriteria;
use Illuminate\Database\Seeder;

/**
 * ProspectCriteriaSeeder — persistent, prod-safe reference data.
 *
 * Seeds the 3 "familles" de prospection définies par TCL Transport. The prospects
 * are MOROCCAN IMPORTERS (countries = ['MA']) sourcing their goods from Europe —
 * not European shippers. Each famille maps 1:1 to a sequence + segment + campaign
 * seeded by TclFamilleSequenceSeeder.
 *
 * TCL Transport is a freight forwarder, so transporteurs / transitaires /
 * logisticiens are competitors — never customers. Every criterion therefore
 * carries the same ai_exclude description and curated, competitor-free
 * ai_queries (Google negative operators) so discovery runs clean out of the
 * box, even without a GEMINI_API_KEY.
 *
 * updateOrCreate keyed on 'name' keeps it idempotent across re-seeds. ai_queries
 * is written via saveQuietly() because ProspectCriteria::updating() nulls
 * ai_queries whenever ai_target/ai_exclude is dirty — a normal save on an updated
 * row would wipe the seeded queries. saveQuietly() bypasses that hook.
 *
 * The 3 legacy criteria (Chargeurs Agro & Cosmétique, Industrie & Automobile,
 * E-commerce & Distribution) are NOT deleted — companies FK-reference them via
 * criteria_id. They are deactivated instead, idempotently, at the end of run().
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
                'name'             => 'Famille 1 — Urgence & Réglementation (Importateurs MA)',
                'ai_target'        => 'Importateurs et distributeurs marocains de produits à forte valeur ou soumis à réglementation stricte : matériel médical, dispositifs dentaires, optique, laboratoires pharmaceutiques, matériel informatique, instruments de mesure et équipementiers aéronautique. Ils importent leurs marchandises depuis l\'Europe et sont soumis à des contraintes de délai, de traçabilité et de conformité douanière.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'importateur matériel médical Maroc -transitaire -logistique -transporteur',
                    'distributeur matériel informatique Casablanca importateur -transitaire',
                    'laboratoire pharmaceutique Maroc importation -logistique -transporteur',
                    'distributeur dispositifs médicaux Maroc -"commissionnaire de transport"',
                    'importateur instruments de mesure Maroc -transitaire -logistique',
                ],
                'sectors'          => [
                    'Informatique',
                    'Matériel médical',
                    'Laboratoires pharmaceutiques',
                    'Équipementiers aéronautique',
                    'Instruments de mesure',
                    'Dentaire',
                    'Optique',
                ],
                'countries'        => ['MA'],
                'company_sizes'    => ['11-50', '51-200', '201-500'],
                'target_positions' => [
                    'Responsable Import/Export',
                    'Responsable Logistique',
                    'Directeur Supply Chain',
                    'Responsable Douane',
                    'Responsable Achats',
                ],
                'daily_limit'      => 25,
                'is_active'        => true,
            ],
            [
                'name'             => 'Famille 2 — Volume & Récurrence (Importateurs MA)',
                'ai_target'        => 'Importateurs marocains à flux réguliers, récurrents et à fort volume depuis l\'Europe : matériel industriel, isolation thermique et panneaux sandwich, équipementiers automobiles, climatisation, mobilier, électroménager, lubrifiants et produits pétroliers. Ils expédient en groupage routier ou en lot complet et cherchent avant tout à optimiser leur coût de transport unitaire.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'importateur matériel industriel Maroc -transitaire -logistique -transporteur',
                    'importateur électroménager Casablanca -transitaire -logistique',
                    'équipementier automobile Tanger importateur -transporteur',
                    'distributeur climatisation Maroc importation -"commissionnaire de transport"',
                    'importateur lubrifiants Maroc -transitaire -logistique',
                ],
                'sectors'          => [
                    'Matériel industriel',
                    'Isolation thermique & panneaux sandwich',
                    'Équipementiers automobiles',
                    'Climatisation',
                    'Mobilier',
                    'Électroménager',
                    'Lubrifiants & pétrole',
                ],
                'countries'        => ['MA'],
                'company_sizes'    => ['51-200', '201-500', '500+'],
                'target_positions' => [
                    'Directeur Logistique',
                    'Responsable Transport',
                    'Acheteur Transport',
                    'Directeur Achats',
                    'Responsable Import/Export',
                ],
                'daily_limit'      => 30,
                'is_active'        => true,
            ],
            [
                'name'             => 'Famille 3 — Projets & Chantiers (Importateurs MA)',
                'ai_target'        => 'Entreprises marocaines pilotant des projets à date fixe et important leurs équipements depuis l\'Europe : traitement des eaux, matériel d\'hôtellerie, cosmétique et parfumerie. Intégrateurs, promoteurs et industriels dont la livraison doit être synchronisée avec un planning de chantier ou une date de mise en service.',
                'ai_exclude'       => $exclude,
                'ai_queries'       => [
                    'société traitement des eaux Maroc équipement importé -transitaire -logistique',
                    'fournisseur matériel hôtellerie Maroc importateur -transporteur',
                    'équipement hôtelier Marrakech importateur -transitaire -logistique',
                    'fabricant cosmétique Maroc importation matières premières -transitaire',
                    'projet station épuration Maroc équipementier -logistique -transporteur',
                ],
                'sectors'          => [
                    'Traitement des eaux',
                    'Matériel d\'hôtellerie',
                    'Cosmétique & Parfumerie',
                ],
                'countries'        => ['MA'],
                'company_sizes'    => ['11-50', '51-200', '201-500'],
                'target_positions' => [
                    'Directeur des Opérations',
                    'Responsable Achats',
                    'Responsable Logistique',
                    'Responsable Import/Export',
                ],
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

        // ── Legacy criteria — deactivate, never delete ────────────────────────────
        // companies.criteria_id still points at these rows; deleting them would
        // break the FK. Deactivating hides them from discovery while preserving
        // the historical attribution of already-discovered companies.
        ProspectCriteria::whereIn('name', [
            'Chargeurs Agro & Cosmétique — France',
            'Industrie & Automobile — Export UE',
            'E-commerce & Distribution — Axe France–Maroc',
        ])->update(['is_active' => false]);
    }
}
