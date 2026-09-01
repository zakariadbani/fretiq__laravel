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
use App\Models\Setting;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\SequenceWaveService;
use App\Services\Campaign\ZohoCampaignsDriver;
use App\Services\Zoho\ZohoRecipientListGateway;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Coverage for the global daily send cap (Setting: planification.daily_send_cap)
 * gating SequenceWaveService::enforceDailyCap(): default OFF (cap=0) is a
 * total no-op, and once enabled a large backlog drains in capped daily
 * slices with no double-send and no stranded recipient.
 */
class SequenceWaveDailyCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'zoho']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_backlog_drains_in_capped_daily_slices_with_no_double_send_and_no_stranded_recipient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Europe/Paris')->utc());
        Setting::set('planification.daily_send_cap', 95);
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $company = $this->company(50);
        $contactIds = $this->bulkContacts($company, 248);
        $this->bulkEnrollments($sequence, $campaign, $contactIds);

        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'prepared',
        ]);
        $this->bulkRecipients($run, $contactIds);

        $this->app->instance(ZohoRecipientListGateway::class, $this->fakeGateway());
        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('dispatchRun')->andReturn(['campaign_key' => 'zk']));

        $syncService = app(CampaignWaveZohoListSyncService::class);
        $sendService = app(SequenceWaveService::class);

        // Day 1
        $syncService->sync($run);
        $sendService->send($run->fresh());
        $day1Sent = CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count();
        $day2Run = CampaignRun::where('campaign_id', $campaign->id)->where('id', '!=', $run->id)->sole();

        // Day 2
        Carbon::setTestNow(Carbon::parse('2026-09-02 09:00:00', 'Europe/Paris')->utc());
        $syncService->sync($day2Run);
        $sendService->send($day2Run->fresh());
        $day2Sent = CampaignRecipient::where('campaign_run_id', $day2Run->id)->where('status', 'sent')->count();
        $day3Run = CampaignRun::where('campaign_id', $campaign->id)
            ->whereNotIn('id', [$run->id, $day2Run->id])->sole();

        // Day 3
        Carbon::setTestNow(Carbon::parse('2026-09-03 09:00:00', 'Europe/Paris')->utc());
        $syncService->sync($day3Run);
        $sendService->send($day3Run->fresh());
        $day3Sent = CampaignRecipient::where('campaign_run_id', $day3Run->id)->where('status', 'sent')->count();

        $this->assertSame(95, $day1Sent);
        $this->assertSame(95, $day2Sent);
        $this->assertSame(58, $day3Sent);
        $this->assertSame(248, CampaignRecipient::whereIn('contact_id', $contactIds)->where('status', 'sent')->count());

        // No contact sent twice: one 'sent' SequenceStepSend per enrollment/step.
        $enrollmentIds = SequenceEnrollment::whereIn('contact_id', $contactIds)->pluck('id');
        $this->assertSame(248, SequenceStepSend::whereIn('enrollment_id', $enrollmentIds)->where('status', 'sent')->count());
        $this->assertSame(248, $enrollmentIds->count());

        // No active enrollment left without a future run or a send — single-step
        // sequence, so every enrollment must now be terminal ('completed').
        $this->assertSame(0, SequenceEnrollment::whereIn('contact_id', $contactIds)->where('status', 'active')->count());
        $this->assertSame(248, SequenceEnrollment::whereIn('contact_id', $contactIds)->where('status', 'completed')->count());
    }

    public function test_cap_zero_is_total_noop_whole_wave_sends_in_one_shot(): void
    {
        Setting::set('planification.daily_send_cap', 0);
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $company = $this->company(50);
        $contactIds = $this->bulkContacts($company, 40);
        $this->bulkEnrollments($sequence, $campaign, $contactIds);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'scheduled',
            'zoho_list_key' => 'wave-list-key',
        ]);
        $this->bulkRecipients($run, $contactIds);
        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('dispatchRun')->once()->andReturn(['campaign_key' => 'zk']));

        app(SequenceWaveService::class)->send($run->fresh());

        $this->assertSame(40, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count());
        $this->assertSame('sent', $run->fresh()->status);
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
    }

    public function test_remaining_zero_bumps_whole_run_to_next_business_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00', 'Europe/Paris')->utc());
        Setting::set('planification.daily_send_cap', 95);
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);

        // Exhaust today's global budget with 95 already-sent step sends.
        $seedCompany = $this->company(10);
        $seedContactIds = $this->bulkContacts($seedCompany, 95);
        $this->bulkEnrollments($sequence, $campaign, $seedContactIds);
        $seedEnrollmentIds = SequenceEnrollment::whereIn('contact_id', $seedContactIds)->pluck('id');
        DB::table('sequence_step_sends')->insert($seedEnrollmentIds->map(fn ($id) => [
            'enrollment_id' => $id,
            'step_no' => 1,
            'status' => 'sent',
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        $company = $this->company(50);
        $contactIds = $this->bulkContacts($company, 5);
        $this->bulkEnrollments($sequence, $campaign, $contactIds);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000002',
            'run_at' => now(),
            'status' => 'prepared',
        ]);
        $this->bulkRecipients($run, $contactIds);

        $outcome = app(SequenceWaveService::class)->enforceDailyCap($run);

        $this->assertSame('deferred', $outcome);
        $run->refresh();
        $this->assertSame('prepared', $run->status);
        $this->assertSame(5, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'queued')->count());
        $this->assertSame(0, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count());
        $this->assertSame('2026-09-02 09:00', $run->run_at->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        // Bumping the whole run must not fork a sibling deferred run.
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
    }

    public function test_split_keeps_higher_ai_score_and_defers_the_rest(): void
    {
        Setting::set('planification.daily_send_cap', 2);
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $low = $this->contact($this->company(10));
        $high = $this->contact($this->company(90));
        $mid = $this->contact($this->company(50));
        foreach ([$low, $high, $mid] as $contact) {
            SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 0,
                'status' => 'active',
            ]);
        }
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000003',
            'run_at' => now(),
            'status' => 'prepared',
        ]);
        foreach ([$low, $high, $mid] as $contact) {
            CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        }

        $outcome = app(SequenceWaveService::class)->enforceDailyCap($run);

        $this->assertSame('split', $outcome);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $high->id, 'status' => 'queued']);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $mid->id, 'status' => 'queued']);
        $this->assertDatabaseMissing('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $low->id]);
        $deferredRun = CampaignRun::where('campaign_id', $campaign->id)->where('id', '!=', $run->id)->sole();
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $deferredRun->id, 'contact_id' => $low->id, 'status' => 'queued']);
    }

    public function test_enforce_daily_cap_is_idempotent_on_repeat_call(): void
    {
        Setting::set('planification.daily_send_cap', 2);
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence);
        $contacts = [$this->contact($this->company(10)), $this->contact($this->company(90)), $this->contact($this->company(50))];
        foreach ($contacts as $contact) {
            SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 0,
                'status' => 'active',
            ]);
        }
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000004',
            'run_at' => now(),
            'status' => 'prepared',
        ]);
        foreach ($contacts as $contact) {
            CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        }
        $service = app(SequenceWaveService::class);

        $first = $service->enforceDailyCap($run->fresh());
        $second = $service->enforceDailyCap($run->fresh());

        $this->assertSame('split', $first);
        $this->assertSame('proceed', $second);
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->where('id', '!=', $run->id)->count());
        $this->assertSame(2, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'queued')->count());
    }

    // ── Fixture helpers ──────────────────────────────────────────────────────

    /** Per-list-key email tracker mirroring the addContacts-populates,
     * ensureCampaignList-does-not-seed semantics used by the real client and
     * by the other wave-sync tests in this suite family. */
    private function fakeGateway(): ZohoRecipientListGateway
    {
        return new class implements ZohoRecipientListGateway {
            /** @var array<string, array<int, string>> */
            public array $listsByKey = [];
            private int $counter = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                $key = 'wave-list-' . (++$this->counter);
                $this->listsByKey[$key] = [];
                return $key;
            }

            public function listEmails(string $listKey): array
            {
                return $this->listsByKey[$listKey] ?? [];
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                $this->listsByKey[$listKey] = array_values(array_unique(array_merge(
                    $this->listsByKey[$listKey] ?? [],
                    array_column($contacts, 'Contact Email'),
                )));
            }
        };
    }

    /** @return array<int, int> contact ids */
    private function bulkContacts(Company $company, int $count): array
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'company_id' => $company->id,
                'email' => uniqid('bulk_', true) . $i . '@example.test',
                'name' => 'Contact ' . $i,
                'source' => 'manual',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'import',
                'email_verification_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('contacts')->insert($rows);

        return DB::table('contacts')->where('company_id', $company->id)->pluck('id')->all();
    }

    /** @param array<int, int> $contactIds */
    private function bulkEnrollments(Sequence $sequence, Campaign $campaign, array $contactIds): void
    {
        $now = now();
        $rows = array_map(fn (int $contactId): array => [
            'sequence_id' => $sequence->id,
            'contact_id' => $contactId,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], $contactIds);
        DB::table('sequence_enrollments')->insert($rows);
    }

    /** @param array<int, int> $contactIds */
    private function bulkRecipients(CampaignRun $run, array $contactIds): void
    {
        $now = now();
        $rows = array_map(fn (int $contactId): array => [
            'campaign_run_id' => $run->id,
            'contact_id' => $contactId,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ], $contactIds);
        DB::table('campaign_recipients')->insert($rows);
    }

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

    private function company(?int $score): Company
    {
        return Company::create([
            'name' => 'Company ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'ai_score' => $score,
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

    private function campaign(Segment $segment, Sequence $sequence, array $overrides = []): Campaign
    {
        $sender = SenderIdentity::create([
            'name' => 'Sender ' . uniqid(),
            'email' => uniqid('sender_') . '@example.test',
            'is_active' => true,
        ]);

        return Campaign::create(array_merge([
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
        ], $overrides));
    }
}
