<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Services\Campaign\CampaignCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $row): string
    {
        return "name;segment_name;sequence_name;template_name;schedule_type;delivery_channel;email_verification_policy;smtp_daily_email_limit;is_active;notes\n{$row}\n";
    }

    public function test_it_forces_is_active_false_and_resolves_the_single_sender_identity(): void
    {
        $sender = SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        $segment = Segment::create(['name' => 'Clients actifs', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Modèle standard', 'subject' => 'Sujet', 'html_content' => '<p>Corps</p>']);

        $importer = app(CampaignCsvImporter::class);
        $rows = $importer->parse($this->csv('Campagne Q3;Clients actifs;;Modèle standard;one_shot;smtp;verified_only;;1;Note interne'), 'campagnes.csv');

        $this->assertSame($segment->id, $rows[0]['segment_id']);
        $this->assertSame($template->id, $rows[0]['template_id']);
        $this->assertNotEmpty($rows[0]['warnings']);
        $this->assertStringContainsString('forcé', $rows[0]['warnings'][0]);

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0], $result);

        $this->assertDatabaseHas('campaigns', [
            'name' => 'Campagne Q3',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'delivery_channel' => 'smtp',
            'is_active' => 0,
        ]);
        $campaign = Campaign::where('name', 'Campagne Q3')->firstOrFail();
        $this->assertSame($sender->id, $campaign->sender_identity_id);
    }

    /**
     * F3 regression: is_active must only ever be forced on the CREATE
     * branch. Re-importing an existing ACTIVE campaign must never deactivate
     * it — not even when the CSV omits activation — and the "forced" warning
     * must fire only when the CSV row itself requests activation.
     */
    public function test_reimporting_an_active_campaign_leaves_it_active_and_warns_only_when_csv_requests_activation(): void
    {
        SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        $segment = Segment::create(['name' => 'Clients actifs', 'scope' => 'client']);
        CampaignTemplate::create(['name' => 'Modèle standard', 'subject' => 'Sujet', 'html_content' => '<p>Corps</p>']);
        $campaign = Campaign::create([
            'name' => 'Campagne Q3',
            'segment_id' => $segment->id,
            'template_id' => CampaignTemplate::firstOrFail()->id,
            'sender_identity_id' => SenderIdentity::firstOrFail()->id,
            'schedule_type' => 'one_shot',
            'delivery_channel' => 'smtp',
            'is_active' => true,
        ]);

        $importer = app(CampaignCsvImporter::class);

        // CSV does not request activation — no warning, and the active
        // campaign is left untouched (the pre-fix code forced it to 0).
        $rows = $importer->parse($this->csv('Campagne Q3;Clients actifs;;Modèle standard;one_shot;smtp;verified_only;;0;'), 'campagnes.csv');
        $this->assertSame([], $rows[0]['warnings']);
        $result = $importer->store($rows);
        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertTrue((bool) $campaign->fresh()->is_active);

        // CSV requests activation — the warning still fires, but the update
        // never forces is_active either way; it stays active as before.
        $rows = $importer->parse($this->csv('Campagne Q3;Clients actifs;;Modèle standard;one_shot;smtp;verified_only;;1;'), 'campagnes.csv');
        $this->assertNotEmpty($rows[0]['warnings']);
        $this->assertStringContainsString('forcé', $rows[0]['warnings'][0]);
        $importer->store($rows);
        $this->assertTrue((bool) $campaign->fresh()->is_active);
    }

    public function test_it_accepts_mailjet_as_a_delivery_channel(): void
    {
        SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        $segment = Segment::create(['name' => 'Clients actifs', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Modèle standard', 'subject' => 'Sujet', 'html_content' => '<p>Corps</p>']);

        $importer = app(CampaignCsvImporter::class);
        $rows = $importer->parse($this->csv('Campagne Mailjet;Clients actifs;;Modèle standard;one_shot;mailjet;verified_only;;0;'), 'campagnes.csv');

        $this->assertSame($segment->id, $rows[0]['segment_id']);
        $this->assertSame($template->id, $rows[0]['template_id']);

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0], $result);

        $this->assertDatabaseHas('campaigns', [
            'name' => 'Campagne Mailjet',
            'delivery_channel' => 'mailjet',
        ]);
    }

    public function test_it_rejects_mailjet_combined_with_a_sequence_schedule(): void
    {
        SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        Segment::create(['name' => 'Clients actifs', 'scope' => 'client']);
        $sequence = Sequence::create(['name' => 'Séquence relance', 'is_active' => true]);

        $importer = app(CampaignCsvImporter::class);

        try {
            $importer->parse(
                "name;segment_name;sequence_name;template_name;schedule_type;delivery_channel;email_verification_policy;smtp_daily_email_limit;is_active;notes\n"
                . "Campagne Mailjet Séquence;Clients actifs;{$sequence->name};;sequence;mailjet;verified_only;;0;\n",
                'campagnes.csv',
            );
            $this->fail('Expected a validation exception for mailjet combined with a sequence schedule.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
            $this->assertStringContainsString('séquences', $exception->errors()['csv'][0]);
        }

        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_it_rejects_an_unresolvable_segment_name(): void
    {
        SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        CampaignTemplate::create(['name' => 'Modèle standard', 'subject' => 'Sujet', 'html_content' => '<p>Corps</p>']);

        $importer = app(CampaignCsvImporter::class);

        try {
            $importer->parse($this->csv('Campagne Q3;Segment Fantome;;Modèle standard;one_shot;smtp;verified_only;;0;'), 'campagnes.csv');
            $this->fail('Expected a validation exception for an unresolvable segment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
            $this->assertStringContainsString('introuvable', $exception->errors()['csv'][0]);
        }

        $this->assertDatabaseCount('campaigns', 0);
    }
}
