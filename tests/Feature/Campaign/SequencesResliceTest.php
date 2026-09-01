<?php

namespace Tests\Feature\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for `sequences:reslice`'s double-send guard: a run whose driver_ref
 * is one of Campaign::AMBIGUOUS_ZOHO_DRIVER_REFS means Zoho may already have
 * accepted the send, so blanking its keys and re-driving it would risk a
 * provider double-send — that run must be left untouched. A genuinely
 * definitive-failure backlog run (e.g. 'zoho-wave-failed', the 913-license
 * case) must remain reslice-eligible so it can drain across days.
 */
class SequencesResliceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    public function test_reslice_skips_run_with_ambiguous_driver_ref(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $contact = $this->contact($this->company());
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'failed',
            'zoho_list_key' => 'wave-list-1',
            'zoho_campaign_key' => 'zk-1',
            'driver_ref' => 'zoho-send-uncertain',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);

        $this->artisan('sequences:reslice')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('zoho-send-uncertain', $run->driver_ref);
        $this->assertSame('wave-list-1', $run->zoho_list_key);
        $this->assertSame('zk-1', $run->zoho_campaign_key);
    }

    public function test_reslice_does_not_select_fully_sent_run(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $contact = $this->contact($this->company());
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000002',
            'run_at' => now(),
            'status' => 'failed',
            'zoho_list_key' => 'wave-list-2',
            'zoho_campaign_key' => 'zk-2',
            'driver_ref' => 'zoho-wave-failed',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'sent']);

        $this->artisan('sequences:reslice')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('zoho-wave-failed', $run->driver_ref);
        $this->assertSame('wave-list-2', $run->zoho_list_key);
    }

    public function test_reslice_redrives_stalled_backlog_run_without_resending_sent_recipients(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $sentContact = $this->contact($this->company());
        $queuedContact = $this->contact($this->company());
        $sentEnrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $sentContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'completed',
            'last_sent_at' => now(),
        ]);
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $queuedContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000003',
            'run_at' => now(),
            // Non-ambiguous definitive-failure ref — e.g. the 913-license-rejection
            // case (ZohoCampaignsDriver::dispatchRun's $definiteRejection branch),
            // where Zoho rejected before sending. This is the backlog reslice is
            // meant to unblock, so it must remain eligible.
            'status' => 'failed',
            'zoho_list_key' => 'wave-list-3',
            'zoho_campaign_key' => 'zk-3',
            'driver_ref' => 'zoho-wave-failed',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $sentContact->id, 'status' => 'sent']);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $queuedContact->id, 'status' => 'queued']);
        $stepSend = SequenceStepSend::create([
            'enrollment_id' => $sentEnrollment->id,
            'step_no' => 1,
            'campaign_run_id' => $run->id,
            'provider_message_id' => 'zk-3',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->artisan('sequences:reslice')->assertExitCode(0);

        $run->refresh();
        $this->assertSame('prepared', $run->status);
        $this->assertNull($run->zoho_list_key);
        $this->assertNull($run->zoho_campaign_key);
        $this->assertSame('zoho-wave-pending', $run->driver_ref);

        // Already-sent recipient / step-send / enrollment state is untouched — no re-send.
        $this->assertSame('sent', CampaignRecipient::where('campaign_run_id', $run->id)->where('contact_id', $sentContact->id)->value('status'));
        $this->assertSame('sent', $stepSend->fresh()->status);
        $this->assertSame('completed', $sentEnrollment->fresh()->status);

        // The stalled recipient stays queued, ready for the next scheduler pass to re-sync/re-slice.
        $this->assertSame('queued', CampaignRecipient::where('campaign_run_id', $run->id)->where('contact_id', $queuedContact->id)->value('status'));
    }

    // ── Fixture helpers (mirrors SequenceWaveDailyCapTest) ──────────────────────

    private function segment(): Segment
    {
        return Segment::create(['name' => 'Segment ' . uniqid(), 'scope' => 'client']);
    }

    private function sequence(): Sequence
    {
        $sequence = Sequence::create(['name' => 'Sequence ' . uniqid(), 'is_active' => true, 'stop_on_reply' => false]);
        $template = CampaignTemplate::create([
            'name' => 'Template ' . uniqid(),
            'subject' => 'Test',
            'html_content' => '<p>Test</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $template->id,
            'subject' => 'Etape 1',
        ]);

        return $sequence;
    }

    private function company(): Company
    {
        return Company::create([
            'name' => 'Company ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
    }

    private function contact(Company $company): Contact
    {
        return Contact::create([
            'company_id' => $company->id,
            'email' => uniqid('contact_') . '@example.test',
            'name' => 'Contact',
            'source' => 'manual',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ]);
    }

    private function campaign(Segment $segment, Sequence $sequence): Campaign
    {
        $sender = SenderIdentity::create([
            'name' => 'Sender ' . uniqid(),
            'email' => uniqid('sender_') . '@example.test',
            'is_active' => true,
        ]);

        return Campaign::create([
            'name' => 'Campaign ' . uniqid(),
            'segment_id' => $segment->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'daily_company_limit' => 20,
            'next_run_at' => now()->addDay(),
            'timezone' => 'UTC',
            'driver' => 'local',
        ]);
    }
}
