<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Database\Seeder;

/**
 * DefaultProspectionSeeder — persistent, prod-safe reference data.
 *
 * Seeds shared prospection reference data only: one sender identity and three
 * generic segments. It also removes retired starter workflows by logical name.
 *
 * Cleanup runs in FK-safe order: campaign → steps → sequences → templates.
 */
class DefaultProspectionSeeder extends Seeder
{
    public function run(): void
    {
        $legacyTemplateNames = [
            'Modèle Prospection — Introduction',
            'Modèle Newsletter — Actualités fret',
            'Modèle Réactivation — Reprise de contact',
            'Modèle Réactivation — Relance avec offre',
            'Modèle Salon — Suite à notre rencontre',
            'Modèle Salon — Relance',
            'Modèle Salon — Dernière proposition',
            'Modèle Annonce — Nouvelle ligne',
        ];
        $legacySequenceNames = [
            'Séquence Prospection Standard',
            'Newsletter mensuelle',
            'Réactivation client dormant',
            'Relance post-salon',
            'Annonce nouvelle ligne',
        ];

        Campaign::where('name', 'Campagne Exemple — Prospection FR')->delete();
        $legacySequenceIds = Sequence::whereIn('name', $legacySequenceNames)->pluck('id');
        SequenceStep::whereIn('sequence_id', $legacySequenceIds)->delete();
        Sequence::whereIn('id', $legacySequenceIds)->delete();
        CampaignTemplate::whereIn('name', $legacyTemplateNames)->delete();

        SenderIdentity::firstOrCreate(
            ['email' => 'sales@tcltransport.com'],
            [
                'name' => 'Sales — TCL France',
                'reply_to' => null,
                'signature_html' => '<p>TCL France</p>',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        Segment::firstOrCreate(
            ['name' => 'Prospects France'],
            [
                'scope' => 'prospect',
                'filter' => ['country' => ['FR']],
            ],
        );

        Segment::firstOrCreate(
            ['name' => 'Clients actifs'],
            [
                'scope' => 'client',
                'filter' => null,
            ],
        );

        Segment::firstOrCreate(
            ['name' => 'Tous les prospects'],
            [
                'scope' => 'prospect',
                'filter' => null,
            ],
        );
    }
}
