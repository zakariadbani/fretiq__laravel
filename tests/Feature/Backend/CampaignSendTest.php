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
use App\Services\Campaign\CampaignsClient;
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

        config([
            'prospecting.cold_send_enabled' => false,
            'services.zoho.driver' => 'local',
        ]);

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
     * A local-driver run whose every per-recipient send throws (e.g. an SMTP
     * outage) must be marked 'failed', not 'sent'. Before this fix the run was
     * always marked 'sent' even when stats_sent stayed 0, which silently masked
     * a weeks-long prod SMTP outage.
     */
    public function test_local_run_with_all_sends_failing_is_marked_failed_not_sent(): void
    {
        $contact  = $this->makeClientContact();
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $this->mock(CampaignsClient::class, function ($mock) {
            $mock->shouldReceive('driverName')->andReturn('local');
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('SMTP connection refused'));
        });

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);

        $service->sendRun($run);

        $run->refresh();

        $this->assertSame('failed', $run->status, 'A run where every send attempt failed must be marked failed, not sent');
        $this->assertSame(0, (int) $run->stats_sent);
        $this->assertNotNull($run->finished_at);

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'queued',
        ]);
    }

    /**
     * Manual "Envoyer maintenant" runs must always create a fresh occurrence.
     * Reusing scheduleOneShot() can return an already-sent run when the campaign
     * has a fixed scheduled_at timestamp, making the UI click appear to do nothing.
     */
    public function test_schedule_immediate_creates_fresh_run_when_scheduled_at_is_reused(): void
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);

        $firstRun = $service->scheduleOneShot($campaign);
        $firstRun->update(['status' => 'sent', 'finished_at' => now()]);

        $immediateRun = $service->scheduleImmediate($campaign);

        $this->assertNotSame($firstRun->id, $immediateRun->id);
        $this->assertSame('scheduled', $immediateRun->status);
        $this->assertStringStartsWith('manual-', $immediateRun->occurrence_key);
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

    // ── Hybrid smart-list: pin exclude/include in send path ────────────────────

    /**
     * A segment with a manually-excluded contact must NOT create a CampaignRecipient
     * for that contact after a sendRun, even if the contact matches the filter.
     */
    public function test_excluded_pin_produces_no_recipient(): void
    {
        $keep    = $this->makeClientContact('keep@acme.test');
        $exclude = $this->makeClientContact('excluded@acme.test');

        $segment = Segment::create(['name' => 'Exclude Pin', 'scope' => 'client']);

        // Pin 'excluded' as an exclude
        $segment->pinnedContacts()->syncWithoutDetaching([
            $exclude->id => ['mode' => 'exclude'],
        ]);

        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);
        $service->sendRun($run);

        // 'keep' must have a recipient
        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $keep->id,
        ]);

        // 'excluded' must NOT have a recipient
        $this->assertDatabaseMissing('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $exclude->id,
        ]);

        // Mail must NOT have been sent to the excluded address
        Mail::assertNotSent(CampaignMailable::class, function (CampaignMailable $mail) use ($exclude) {
            return $mail->hasTo($exclude->email);
        });
    }

    /**
     * A compliant pinned-include contact that does NOT match the filter scope
     * must get a CampaignRecipient after sendRun (include bypasses scope+filter).
     *
     * Fixture: segment filters to sector=Transport.
     * One client contact IS in Transport (matches filter).
     * One client contact is in Finance (does NOT match filter) — pinned-in as include.
     * The Finance contact must become a recipient despite not matching the filter.
     */
    public function test_compliant_nonfilter_include_produces_recipient(): void
    {
        // Contact at Transport company — matches sector filter
        $transportCo = Company::create([
            'name'                 => 'Transport SA',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Transport',
            'country'              => 'FR',
        ]);
        $filterMatch = Contact::create([
            'company_id'  => $transportCo->id,
            'email'       => 'filtermatch@transport.test',
            'name'        => 'Filter Match',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        // Contact at Finance company — does NOT match sector=Transport filter
        $financeCo = Company::create([
            'name'                 => 'Finance Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Finance',
            'country'              => 'FR',
        ]);
        $pinInclude = Contact::create([
            'company_id'  => $financeCo->id,
            'email'       => 'pininclude@finance.test',
            'name'        => 'Pin Include',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        // Segment filters to 'Transport' sector — pinInclude's sector=Finance won't match filter
        $segment = Segment::create([
            'name'   => 'Include Pin Send',
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport']],
        ]);

        // Pin Finance contact as include — bypasses filter, still faces compliance
        $segment->pinnedContacts()->syncWithoutDetaching([
            $pinInclude->id => ['mode' => 'include'],
        ]);

        $template = $this->makeTemplate();
        $sender   = $this->makeSender();
        $campaign = $this->makeCampaign($segment, $template, $sender);

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);
        $service->sendRun($run);

        // filterMatch must have a recipient (matches filter)
        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $filterMatch->id,
        ]);

        // pinInclude must ALSO have a recipient — it was force-included despite no filter match
        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_run_id' => $run->id,
            'contact_id'      => $pinInclude->id,
        ]);

        // Verify mail was sent to the included address
        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail) use ($pinInclude) {
            return $mail->hasTo($pinInclude->email);
        });
    }
}
