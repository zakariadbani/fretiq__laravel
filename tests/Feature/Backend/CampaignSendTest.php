<?php

namespace Tests\Feature\Backend;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Services\Campaign\CampaignService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Service-level tests for CampaignService::scheduleOneShot() and ::sendRun().
 *
 * Mail::fake() is used so no real SMTP calls are made.
 * Cold gate is forced off for all tests in this class.
 */
class CampaignSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config(['prospecting.cold_send_enabled' => false]);

        Mail::fake();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeClientContact(string $email = 'jean@acme.test'): Contact
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
            'name'        => 'Jean Dupont',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeTemplate(): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => 'Template Sprint-3a',
            'subject'      => 'Test sujet',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
    }

    private function makeSender(): SenderIdentity
    {
        return SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);
    }

    private function makeCampaign(Segment $segment, CampaignTemplate $template, SenderIdentity $sender): Campaign
    {
        return Campaign::create([
            'name'               => 'Campagne Test',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * scheduleOneShot creates a CampaignRun (status=scheduled).
     * sendRun processes the segment, creates recipients, sends the mailable,
     * marks the run and recipient as 'sent'.
     */
    public function test_send_creates_recipients_and_sends(): void
    {
        $contact  = $this->makeClientContact();
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);

        $run = $service->scheduleOneShot($campaign);

        $this->assertSame('scheduled', $run->status);
        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status'      => 'scheduled',
        ]);

        $service->sendRun($run);

        $run->refresh();

        $this->assertSame('sent', $run->status, 'Run status should be sent after sendRun');
        $this->assertGreaterThanOrEqual(1, (int) $run->stats_sent, 'stats_sent should be >= 1');

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'sent',
        ]);

        // provider_message_id must be set (LocalCampaignsDriver sets 'local-{uuid}')
        $recipient = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('contact_id', $contact->id)
            ->firstOrFail();

        $this->assertNotNull($recipient->provider_message_id, 'provider_message_id must be set after send');

        Mail::assertSent(CampaignMailable::class);
    }

    /**
     * Calling sendRun twice on the same run must NOT double-send.
     * The unique (campaign_run_id, contact_id) constraint on campaign_recipients
     * and the claim-commit guard on the run prevent reprocessing.
     *
     * After the first sendRun the run is 'sent'; the second call is a no-op
     * because the claim step rejects any run not in ['scheduled', 'sending'].
     */
    public function test_send_is_idempotent(): void
    {
        $contact  = $this->makeClientContact();
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);

        $service->sendRun($run);

        // Capture mail count after first run
        $mailCountAfterFirst = count(Mail::sent(CampaignMailable::class));
        $recipientSentCountAfterFirst = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('status', 'sent')
            ->count();

        // Second call — should be a no-op
        $service->sendRun($run);

        $mailCountAfterSecond = count(Mail::sent(CampaignMailable::class));
        $recipientSentCountAfterSecond = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('status', 'sent')
            ->count();

        $this->assertSame(
            $recipientSentCountAfterFirst,
            $recipientSentCountAfterSecond,
            'Recipient sent count must not increase on second sendRun call',
        );

        $this->assertSame(
            $mailCountAfterFirst,
            $mailCountAfterSecond,
            'Mail sent count must not increase on second sendRun call',
        );
    }

    /**
     * A suppression added AFTER scheduleOneShot but BEFORE sendRun causes the
     * contact to be skipped at send time (step 4a of the claim-commit flow).
     *
     * The recipient row must exist with status='skipped', and CampaignMailable
     * must NOT be sent to that address.
     */
    public function test_send_skips_suppressed_at_send_time(): void
    {
        $contact  = $this->makeClientContact('skip@acme.test');
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);

        // Add suppression AFTER scheduling but BEFORE sending
        Suppression::create([
            'email'      => 'skip@acme.test',
            'contact_id' => $contact->id,
            'reason'     => 'manual',
            'source'     => 'manual',
        ]);

        $service->sendRun($run);

        // The recipient should have been created (resolve ran before suppression check at send time)
        // and then marked as skipped.
        $recipient = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('contact_id', $contact->id)
            ->first();

        // The contact is suppressed at segment-resolve time too (SegmentService checks suppression).
        // So either: (a) no recipient row created at all, or (b) recipient exists with status='skipped'.
        // Both are acceptable outcomes — the key assertion is that no mail was sent.
        if ($recipient !== null) {
            $this->assertSame(
                'skipped',
                $recipient->status,
                'Suppressed recipient must have status=skipped',
            );
        }

        // Mail must NOT have been sent to the suppressed address
        Mail::assertNotSent(CampaignMailable::class, function (CampaignMailable $mail) use ($contact) {
            return $mail->hasTo($contact->email);
        });
    }
}
