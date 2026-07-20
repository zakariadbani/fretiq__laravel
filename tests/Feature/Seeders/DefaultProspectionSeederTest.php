<?php

namespace Tests\Feature\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Database\Seeders\DefaultProspectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DefaultProspectionSeederTest — verifies the persistent default data seeder.
 *
 * Checks: only shared reference data remains and legacy workflow data is removed.
 * Runs on the test DB under RefreshDatabase; does not touch dev data.
 */
class DefaultProspectionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_prospection_seeder_creates_expected_rows(): void
    {
        $this->seed(DefaultProspectionSeeder::class);

        $this->assertSame(3, Segment::count(), 'Expected 3 default segments.');
        $this->assertSame(1, SenderIdentity::count(), 'Expected one shared sender identity.');
        $this->assertSame(0, CampaignTemplate::count(), 'Default seeding must not create a legacy template.');
        $this->assertSame(0, Sequence::count(), 'Default seeding must not create a legacy sequence.');
        $this->assertSame(0, SequenceStep::count(), 'Default seeding must not create legacy steps.');
        $this->assertSame(0, Campaign::count(), 'Default seeding must not create an example campaign.');
    }

    public function test_default_prospection_seeder_is_idempotent(): void
    {
        // Running twice must not duplicate rows (all firstOrCreate).
        $this->seed(DefaultProspectionSeeder::class);
        $this->seed(DefaultProspectionSeeder::class);

        $this->assertSame(3, Segment::count(), 'Segments must not be duplicated on reseed.');
        $this->assertSame(1, SenderIdentity::count(), 'Sender identity must not be duplicated on reseed.');
        $this->assertSame(0, CampaignTemplate::count());
        $this->assertSame(0, Sequence::count());
        $this->assertSame(0, SequenceStep::count());
        $this->assertSame(0, Campaign::count());
    }

    public function test_default_prospection_seeder_removes_all_legacy_seeded_workflow_rows(): void
    {
        $this->seed(DefaultProspectionSeeder::class);

        $templateNames = [
            'Modèle Prospection — Introduction',
            'Modèle Newsletter — Actualités fret',
            'Modèle Réactivation — Reprise de contact',
            'Modèle Réactivation — Relance avec offre',
            'Modèle Salon — Suite à notre rencontre',
            'Modèle Salon — Relance',
            'Modèle Salon — Dernière proposition',
            'Modèle Annonce — Nouvelle ligne',
        ];
        $templates = collect($templateNames)->map(fn (string $name) => CampaignTemplate::firstOrCreate(
            ['name' => $name],
            ['subject' => 'Legacy subject', 'preview_text' => 'Legacy preview', 'html_content' => '<p>Legacy</p>'],
        ));

        $sequenceNames = [
            'Séquence Prospection Standard',
            'Newsletter mensuelle',
            'Réactivation client dormant',
            'Relance post-salon',
            'Annonce nouvelle ligne',
        ];
        $sequences = collect($sequenceNames)->map(fn (string $name) => Sequence::firstOrCreate(
            ['name' => $name],
            ['is_active' => false, 'stop_on_reply' => true],
        ));

        SequenceStep::whereIn('sequence_id', $sequences->pluck('id'))->delete();
        for ($i = 0; $i < 10; $i++) {
            $sequence = $sequences[$i % $sequences->count()];
            SequenceStep::firstOrCreate(
                ['sequence_id' => $sequence->id, 'step_no' => intdiv($i, $sequences->count()) + 1],
                ['delay_days' => $i, 'template_id' => $templates[$i % $templates->count()]->id],
            );
        }

        Campaign::firstOrCreate(
            ['name' => 'Campagne Exemple — Prospection FR'],
            [
                'segment_id' => Segment::where('name', 'Prospects France')->value('id'),
                'template_id' => $templates[0]->id,
                'sender_identity_id' => SenderIdentity::where('email', 'sales@tcltransport.com')->value('id'),
                'schedule_type' => 'one_shot',
                'driver' => 'local',
            ],
        );

        $this->assertSame(8, CampaignTemplate::whereIn('name', $templateNames)->count());
        $this->assertSame(5, Sequence::whereIn('name', $sequenceNames)->count());
        $this->assertSame(10, SequenceStep::whereIn('sequence_id', $sequences->pluck('id'))->count());

        $this->seed(DefaultProspectionSeeder::class);

        $this->assertSame(0, Campaign::where('name', 'Campagne Exemple — Prospection FR')->count());
        $this->assertSame(0, SequenceStep::whereIn('sequence_id', $sequences->pluck('id'))->count());
        $this->assertSame(0, Sequence::whereIn('name', $sequenceNames)->count());
        $this->assertSame(0, CampaignTemplate::whereIn('name', $templateNames)->count());
    }
}
