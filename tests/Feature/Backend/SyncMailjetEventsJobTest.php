<?php

namespace Tests\Feature\Backend;

use App\Jobs\SyncMailjetEventsJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SyncMailjetEventsJobTest — verifies GET /v3/REST/message correlation and
 * status→outcome mapping. All HTTP is faked — no live Mailjet calls are made.
 */
class SyncMailjetEventsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailjet.key' => 'mj-key',
            'services.mailjet.secret' => 'mj-secret',
            'services.mailjet.api_url' => 'https://api.mailjet.com',
        ]);
    }

    private function makeRun(): CampaignRun
    {
        $segment = Segment::create(['name' => 'Sync segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Sync template',
            'subject' => 'Sync',
            'html_content' => '<p>Sync</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'sync@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Sync campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'delivery_channel' => 'mailjet',
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);

        return CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sync-'.uniqid(),
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'finished_at' => now()->subHour(),
        ]);
    }

    private function makeRecipient(CampaignRun $run, string $providerMessageId, string $email): CampaignRecipient
    {
        $company = Company::create([
            'name' => 'Sync Co',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'Sync Contact',
            'source' => 'manual',
            'email_kind' => 'role',
        ]);

        return CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => now()->subHour(),
            'provider_message_id' => $providerMessageId,
        ]);
    }

    public function test_opened_and_clicked_statuses_record_timestamps(): void
    {
        $run = $this->makeRun();
        $opened = $this->makeRecipient($run, '1001', 'opened@example.test');
        $clicked = $this->makeRecipient($run, '1002', 'clicked@example.test');

        Http::fake([
            '*api.mailjet.com/v3/REST/message*' => Http::response([
                'Count' => 2,
                'Data' => [
                    ['ID' => 1001, 'Status' => 'opened', 'ArrivedAt' => now()->subMinutes(30)->toIso8601String()],
                    ['ID' => 1002, 'Status' => 'clicked', 'ArrivedAt' => now()->subMinutes(20)->toIso8601String()],
                ],
                'Total' => 2,
            ], 200),
        ]);

        SyncMailjetEventsJob::dispatchSync($run->id);

        $opened->refresh();
        $clicked->refresh();

        $this->assertNotNull($opened->opened_at);
        $this->assertSame('opened', $opened->status);

        $this->assertNotNull($clicked->opened_at, 'A click must also record an open (last-state-only status).');
        $this->assertNotNull($clicked->clicked_at);
        $this->assertSame('clicked', $clicked->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'CustomCampaign=fretiq-run-'.$run->id));
    }

    public function test_bounce_suppresses_and_invalidates_the_contact(): void
    {
        $run = $this->makeRun();
        $recipient = $this->makeRecipient($run, '2001', 'bounced@example.test');

        Http::fake([
            '*api.mailjet.com/v3/REST/message*' => Http::response([
                'Count' => 1,
                'Data' => [
                    ['ID' => 2001, 'Status' => 'bounce', 'IsHardBounced' => true, 'ArrivedAt' => now()->toIso8601String()],
                ],
                'Total' => 1,
            ], 200),
        ]);

        SyncMailjetEventsJob::dispatchSync($run->id);

        $recipient->refresh();
        $this->assertSame('bounced', $recipient->status);
        $this->assertSame('hard', $recipient->bounce_type);
        $this->assertDatabaseHas('suppressions', [
            'email' => 'bounced@example.test',
            'reason' => 'hard_bounce',
            'source' => 'mailjet',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => $recipient->contact_id,
            'email_verification_status' => 'invalid',
            'email_verification_source' => 'bounce',
        ]);
    }

    /**
     * UNVERIFIED — IsHardBounced is a best-guess field name pending live
     * verification (see the job's docblock). When it is absent, the mapping
     * must default to soft_bounce (the safer failure mode), never hard.
     */
    public function test_bounce_defaults_to_soft_when_the_permanence_flag_is_absent(): void
    {
        $run = $this->makeRun();
        $recipient = $this->makeRecipient($run, '2002', 'soft-default@example.test');

        Http::fake([
            '*api.mailjet.com/v3/REST/message*' => Http::response([
                'Count' => 1,
                'Data' => [
                    ['ID' => 2002, 'Status' => 'bounce', 'ArrivedAt' => now()->toIso8601String()],
                ],
                'Total' => 1,
            ], 200),
        ]);

        SyncMailjetEventsJob::dispatchSync($run->id);

        $recipient->refresh();
        $this->assertSame('bounced', $recipient->status);
        $this->assertSame('soft', $recipient->bounce_type);
    }

    public function test_spam_status_records_a_complaint(): void
    {
        $run = $this->makeRun();
        $recipient = $this->makeRecipient($run, '3001', 'spam@example.test');

        Http::fake([
            '*api.mailjet.com/v3/REST/message*' => Http::response([
                'Count' => 1,
                'Data' => [
                    ['ID' => 3001, 'Status' => 'spam', 'ArrivedAt' => now()->toIso8601String()],
                ],
                'Total' => 1,
            ], 200),
        ]);

        SyncMailjetEventsJob::dispatchSync($run->id);

        $this->assertDatabaseHas('suppressions', [
            'email' => 'spam@example.test',
            'reason' => 'complaint',
            'source' => 'mailjet',
        ]);
    }

    public function test_pagination_stops_when_the_full_result_set_is_received(): void
    {
        $run = $this->makeRun();
        // At least one recipient must have a matching provider_message_id or
        // the job bails before issuing any HTTP call.
        $this->makeRecipient($run, '90001', 'paginated@example.test');

        $offsets = [];
        $fullPage = collect(range(1, 1000))
            ->map(fn (int $i) => ['ID' => 90000 + $i, 'Status' => 'sent', 'ArrivedAt' => now()->toIso8601String()])
            ->all();

        Http::fake(function ($request) use (&$offsets, $fullPage) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offsets[] = (int) ($query['Offset'] ?? 0);

            return Http::response([
                'Count' => count($fullPage),
                'Data' => $fullPage,
                'Total' => count($fullPage),
            ], 200);
        });

        SyncMailjetEventsJob::dispatchSync($run->id);

        // Total (1000) is fully accounted for after a single page — no wasted
        // second request, unlike the old count(rows)===Limit heuristic.
        $this->assertSame([0], $offsets);
    }

    /**
     * A server-side page cap lower than the requested Limit=1000 must not be
     * mistaken for "last page" — the old count(rows)===Limit termination would
     * have silently truncated the run after page 1. Total is the authoritative
     * continuation signal.
     */
    public function test_pagination_continues_past_a_lower_server_side_page_cap(): void
    {
        $run = $this->makeRun();
        $this->makeRecipient($run, '90001', 'paginated@example.test');

        $offsets = [];
        $pageSizes = [2, 2, 1];
        Http::fake(function ($request) use (&$offsets, $pageSizes) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) ($query['Offset'] ?? 0);
            $offsets[] = $offset;
            $size = $pageSizes[count($offsets) - 1] ?? 0;

            $rows = collect(range(1, $size))
                ->map(fn (int $i) => ['ID' => 90000 + $offset + $i, 'Status' => 'sent', 'ArrivedAt' => now()->toIso8601String()])
                ->all();

            return Http::response(['Count' => count($rows), 'Data' => $rows, 'Total' => 5], 200);
        });

        SyncMailjetEventsJob::dispatchSync($run->id);

        $this->assertSame([0, 2, 4], $offsets);
    }

    public function test_non_executed_run_is_skipped(): void
    {
        $run = $this->makeRun();
        $run->update(['status' => 'sending']);
        $this->makeRecipient($run, '4001', 'skip@example.test');

        Http::fake();
        Http::preventStrayRequests();

        // No exception, no HTTP call: the job bails on isExecuted() before
        // ever reaching config or the network.
        SyncMailjetEventsJob::dispatchSync($run->id);

        Http::assertNothingSent();
    }

    public function test_non_mailjet_channel_run_is_skipped(): void
    {
        $run = $this->makeRun();
        $run->campaign->update(['delivery_channel' => 'smtp']);
        $this->makeRecipient($run, '4002', 'skip-channel@example.test');

        Http::fake();
        Http::preventStrayRequests();

        // No exception, no HTTP call: the job bails because the campaign's
        // effective delivery channel is no longer mailjet.
        SyncMailjetEventsJob::dispatchSync($run->id);

        Http::assertNothingSent();
    }
}
