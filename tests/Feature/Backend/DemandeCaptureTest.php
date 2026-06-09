<?php

namespace Tests\Feature\Backend;

use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\Campaign\SequenceService;
use App\Services\Demande\DemandeCaptureService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Service-level tests for DemandeCaptureService::capture().
 */
class DemandeCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Mail::fake();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeContact(string $email = 'j@acme.test'): Contact
    {
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'J',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeTemplate(): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => 'Tpl Demande',
            'subject'      => 'Sujet',
            'html_content' => '<p>Bonjour</p>',
        ]);
    }

    private function makeCampaign(): Campaign
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = SenderIdentity::create(['name' => 'TCL', 'email' => 'noreply@tcl.test']);

        return Campaign::create([
            'name'               => 'Camp Test',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    private function makeCampaignRun(Campaign $campaign): CampaignRun
    {
        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'test-' . now()->format('YmdHis'),
            'run_at'         => now(),
            'status'         => 'sent',
            'conversion_count' => 0,
        ]);
    }

    private function makeStopOnReplySequence(): Sequence
    {
        $seq = Sequence::create([
            'name'          => 'Stop Seq',
            'is_active'     => true,
            'stop_on_reply' => true,
        ]);

        $tpl = $this->makeTemplate();
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl->id,
            'subject'     => 'Étape 1',
        ]);

        return $seq;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * capture() creates a Demande with status=pending, contact_id, and campaign_id.
     * It also marks the contact.status as 'converted'.
     */
    public function test_capture_creates_demande_and_converts_contact(): void
    {
        $contact  = $this->makeContact();
        $campaign = $this->makeCampaign();
        $service  = app(DemandeCaptureService::class);

        $demande = $service->capture(
            $contact,
            ['campaign_id' => $campaign->id],
            'reply',
            'note de test',
        );

        $this->assertInstanceOf(Demande::class, $demande);

        $this->assertDatabaseHas('demandes', [
            'contact_id'  => $contact->id,
            'campaign_id' => $campaign->id,
            'status'      => 'pending',
        ]);

        $contact->refresh();
        $this->assertSame('converted', $contact->status, 'Contact must be marked converted');
    }

    /**
     * capture() stops active enrollments in stop_on_reply sequences.
     */
    public function test_capture_stops_sequence(): void
    {
        $contact  = $this->makeContact('j2@acme.test');
        $seq      = $this->makeStopOnReplySequence();
        $service  = app(DemandeCaptureService::class);

        // Enroll the contact
        $enrollment = app(SequenceService::class)->enroll($seq, $contact);

        $this->assertSame('active', $enrollment->status);

        // Capture triggers stopForReply
        $service->capture($contact, []);

        $enrollment->refresh();

        $this->assertSame('stopped', $enrollment->status, 'Enrollment must be stopped after capture');
    }

    /**
     * capture() increments CampaignRun.conversion_count when campaign_run_id is
     * provided in the attribution array.
     */
    public function test_capture_increments_run_conversion(): void
    {
        $contact  = $this->makeContact('j3@acme.test');
        $campaign = $this->makeCampaign();
        $run      = $this->makeCampaignRun($campaign);
        $service  = app(DemandeCaptureService::class);

        $initialCount = (int) $run->conversion_count;

        $service->capture(
            $contact,
            ['campaign_run_id' => $run->id],
        );

        $run->refresh();

        $this->assertSame(
            $initialCount + 1,
            (int) $run->conversion_count,
            'conversion_count must be incremented by 1',
        );
    }

    /**
     * capture() does NOT increment conversion_count when no campaign_run_id is given.
     */
    public function test_capture_without_run_does_not_increment_conversion(): void
    {
        $contact  = $this->makeContact('j4@acme.test');
        $campaign = $this->makeCampaign();
        $run      = $this->makeCampaignRun($campaign);
        $service  = app(DemandeCaptureService::class);

        $initialCount = (int) $run->conversion_count;

        // No campaign_run_id in attribution
        $service->capture($contact, ['campaign_id' => $campaign->id]);

        $run->refresh();

        $this->assertSame(
            $initialCount,
            (int) $run->conversion_count,
            'conversion_count must remain unchanged when no run is attributed',
        );
    }
}
