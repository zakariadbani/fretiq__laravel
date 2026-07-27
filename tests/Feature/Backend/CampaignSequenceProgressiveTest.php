<?php

namespace Tests\Feature\Backend;

use App\Exceptions\ZohoInvalidRecipientException;
use App\Models\Campaign;
use App\Jobs\SyncCampaignWaveZohoListJob;
use App\Jobs\SendSequenceStepJob;
use App\Jobs\SendSequenceWaveStepJob;
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
use App\Models\User;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\SegmentService;
use App\Services\Campaign\SequenceWaveService;
use App\Services\Campaign\ZohoCampaignsDriver;
use App\Services\Zoho\ZohoCampaignsClient;
use App\Services\Zoho\ZohoRecipientListGateway;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use Tests\TestCase;

class CampaignSequenceProgressiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'local']);
        Queue::fake();
    }

    public function test_default_mode_keeps_existing_campaigns_immediate(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), ['sequence_enrollment_mode' => null]);

        $this->assertSame('immediate', $campaign->sequence_enrollment_mode);
    }

    public function test_zoho_model_validation_only_accepts_paced_sequence_enrollment(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $sender = SenderIdentity::create([
            'name' => 'Zoho validation sender',
            'email' => 'zoho-validation@example.test',
            'is_active' => true,
        ]);
        $payload = [
            'name' => 'Zoho sequence validation',
            'segment_id' => $segment->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'immediate',
            'driver' => 'local',
        ];

        config(['services.zoho.driver' => 'zoho']);
        $this->assertTrue((new Campaign())->validator($payload)->errors()->has('sequence_enrollment_mode'));

        config(['services.zoho.driver' => 'local']);
        $payload['driver'] = 'zoho';
        $this->assertTrue((new Campaign())->validator($payload)->errors()->has('sequence_enrollment_mode'));

        $payload['driver'] = 'local';
        $this->assertFalse((new Campaign())->validator($payload)->errors()->has('sequence_enrollment_mode'));
    }

    public function test_zoho_campaign_form_only_offers_paced_sequence_enrollment(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/campaigns/create')
            ->assertOk()
            ->assertDontSee('<option value="immediate"', false)
            ->assertSee('<option value="paced" selected>', false);
    }

    public function test_due_batch_caps_companies_and_enrolls_every_contact_in_each_selected_company(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $high = $this->company(90);
        $low = $this->company(10);
        $highContacts = [$this->contact($high), $this->contact($high)];
        $this->contact($low);
        $campaign = $this->campaign($segment, $sequence, [
            'daily_company_limit' => 1,
            'next_run_at' => '2026-07-21 08:00:00',
        ]);

        $result = app(PacedSequenceEnrollmentService::class)->activate(
            $campaign,
            Carbon::parse('2026-07-21 09:00:00', 'UTC'),
        );

        $this->assertTrue($result['processed_due']);
        $this->assertSame(1, $result['companies']);
        $this->assertSame(2, $result['enrolled']);
        foreach ($highContacts as $contact) {
            $this->assertDatabaseHas('sequence_enrollments', [
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
            ]);
        }
        $this->assertSame(0, SequenceEnrollment::whereHas('contact', fn ($query) => $query->where('company_id', $low->id))->count());
        $wave = CampaignRun::where('campaign_id', $campaign->id)->where('occurrence_key', 'sequence-wave-000001')->firstOrFail();
        $this->assertSame('prepared', $wave->status);
        $this->assertSame(1, $wave->sequenceStep->step_no);
        $this->assertSame('zoho-wave-pending', $wave->driver_ref);
        $this->assertEqualsCanonicalizing(array_column($highContacts, 'id'), $wave->recipients()->pluck('contact_id')->all());
        Queue::assertPushed(SyncCampaignWaveZohoListJob::class, fn ($job) => $job->runId === $wave->id);
        Queue::assertNotPushed(SendSequenceStepJob::class);
        $this->assertSame(0, SequenceEnrollment::where('campaign_id', $campaign->id)->whereNotNull('next_send_at')->count());
    }

    public function test_second_call_for_same_occurrence_is_a_no_op(): void
    {
        $company = $this->company(50);
        $this->contact($company);
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'daily_company_limit' => 1,
            'next_run_at' => '2026-07-21 08:00:00',
        ]);
        $service = app(PacedSequenceEnrollmentService::class);
        $now = Carbon::parse('2026-07-21 09:00:00', 'UTC');

        $first = $service->activate($campaign, $now);
        $second = $service->evaluateDue($campaign->fresh(), $now);

        $this->assertTrue($first['processed_due']);
        $this->assertFalse($second['processed_due']);
        $this->assertSame(1, SequenceEnrollment::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->where('occurrence_key', 'like', 'sequence-wave-%')->count());
    }


    public function test_inactive_campaign_cannot_process_a_due_paced_sequence(): void
    {
        $this->contact($this->company(50));
        $nextRunAt = Carbon::parse('2026-07-21 08:00:00', 'UTC');
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'is_active' => false,
            'sequence_auto_enroll_enabled' => true,
            'next_run_at' => $nextRunAt,
        ]);

        $result = app(PacedSequenceEnrollmentService::class)->evaluateDue(
            $campaign,
            Carbon::parse('2026-07-21 09:00:00', 'UTC'),
        );

        $this->assertFalse($result['processed_due']);
        $this->assertTrue($result['next_run_at']->equalTo($nextRunAt));
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
        $this->assertDatabaseMissing('sequence_enrollments', ['campaign_id' => $campaign->id]);
    }
    public function test_empty_due_audience_advances_cursor_and_can_pick_up_future_company(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $campaign = $this->campaign($segment, $sequence, [
            'next_run_at' => '2026-07-21 08:00:00',
        ]);
        $service = app(PacedSequenceEnrollmentService::class);

        $empty = $service->activate($campaign, Carbon::parse('2026-07-21 09:00:00', 'UTC'));
        $contact = $this->contact($this->company(50));
        $next = $service->evaluateDue($campaign->fresh(), Carbon::parse('2026-07-22 09:00:00', 'UTC'));

        $this->assertTrue($empty['processed_due']);
        $this->assertSame(0, $empty['enrolled']);
        $this->assertTrue($next['processed_due']);
        $this->assertDatabaseHas('sequence_enrollments', ['contact_id' => $contact->id, 'campaign_id' => $campaign->id]);
    }

    public function test_weekend_cursor_defers_to_monday_and_preserves_local_wall_time_across_dst(): void
    {
        $service = app(PacedSequenceEnrollmentService::class);
        $friday = Carbon::parse('2026-10-23 10:00:00', 'Europe/Paris')->utc();

        $monday = $service->computeNextBusinessRun($friday, 'Europe/Paris');

        $this->assertSame('2026-10-26 10:00', $monday->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertSame('09:00', $monday->format('H:i'));
    }

    public function test_user_without_send_permission_cannot_activate_progressive_sequence(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'next_run_at' => now()->addDay(),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post("/admin/campaigns/{$campaign->id}/send")
            ->assertForbidden();

        $this->assertFalse($campaign->fresh()->sequence_auto_enroll_enabled);
    }

    public function test_progressive_sequence_requires_first_batch_limit_and_timezone(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $sender = SenderIdentity::create([
            'name' => 'Sender validation',
            'email' => 'validation@example.test',
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->postJson('/admin/campaigns', [
            'name' => 'Progressive invalid',
            'segment_id' => $segment->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'sequence_first_batch_at' => '',
            'sequence_daily_company_limit' => '',
            'timezone' => '',
        ])->assertStatus(406)
            ->assertJsonValidationErrors(['next_run_at', 'daily_company_limit', 'timezone']);
    }

    public function test_crud_payload_cannot_enable_tracking_and_mode_change_stops_it(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'sequence_auto_enroll_enabled' => true,
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->putJson("/admin/campaigns/{$campaign->id}", [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'sequence_id' => $campaign->sequence_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'immediate',
            'sequence_auto_enroll_enabled' => true,
            'timezone' => 'UTC',
        ])->assertOk();

        $campaign->refresh();
        $this->assertSame('immediate', $campaign->sequence_enrollment_mode);
        $this->assertFalse($campaign->sequence_auto_enroll_enabled);
        $this->assertNull($campaign->next_run_at);
        $this->assertNull($campaign->daily_company_limit);
    }

    public function test_zoho_driver_accepts_progressive_sequence_activation(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'driver' => 'zoho',
            'next_run_at' => now()->subMinute(),
        ]);

        $result = app(PacedSequenceEnrollmentService::class)->activate($campaign);

        $this->assertTrue($campaign->fresh()->is_active);
    }

    public function test_progressive_view_uses_limited_batch_confirmation_and_next_batch_cta(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'daily_company_limit' => 7,
            'next_run_at' => '2026-07-23 14:30:00',
            'sequence_auto_enroll_enabled' => true,
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $response = $this->actingAs($admin)->get("/admin/campaigns/{$campaign->id}");

        $response->assertOk()
            ->assertSee('data-sequence-enrollment-mode="paced"', false)
            ->assertSee('data-daily-company-limit="7"', false)
            ->assertSee('data-next-batch-at="23/07/2026 14:30"', false)
            ->assertSee('limité à <strong>', false)
            ->assertSee('Prochain lot planifié', false)
            ->assertSee('Vérifier le lot · 23/07 14:30');
    }

    public function test_sequence_form_help_is_neutral_about_enrollment_timing(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $response = $this->actingAs($admin)->get('/admin/campaigns/create');

        $response->assertOk()
            ->assertSee('Le mode d’inscription ci-contre détermine quand les contacts éligibles commencent l’étape 1.')
            ->assertDontSee('Au premier lancement, le suivi continu du segment est activé.');
    }

    public function test_sync_command_does_not_resolve_paced_audience_before_due_time(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'next_run_at' => now()->addDay(),
            'sequence_auto_enroll_enabled' => true,
        ]);
        $this->mock(SegmentService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('resolve');
        });

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();

        $this->assertSame(0, SequenceEnrollment::where('campaign_id', $campaign->id)->count());
        $this->assertTrue($campaign->fresh()->next_run_at->isFuture());
    }

    public function test_consecutive_batches_use_stable_wave_numbers(): void
    {
        $segment = $this->segment();
        $sequence = $this->sequence();
        $firstContact = $this->contact($this->company(90));
        $secondContact = $this->contact($this->company(10));
        $campaign = $this->campaign($segment, $sequence, [
            'daily_company_limit' => 1,
            'next_run_at' => '2026-07-21 08:00:00',
        ]);
        $service = app(PacedSequenceEnrollmentService::class);

        $service->activate($campaign, Carbon::parse('2026-07-21 09:00:00', 'UTC'));
        $service->evaluateDue($campaign->fresh(), Carbon::parse('2026-07-22 09:00:00', 'UTC'));

        $this->assertSame(
            ['sequence-wave-000001', 'sequence-wave-000002'],
            CampaignRun::where('campaign_id', $campaign->id)->orderBy('id')->pluck('occurrence_key')->all(),
        );
        $runs = CampaignRun::where('campaign_id', $campaign->id)->orderBy('id')->get();
        $this->assertSame([$firstContact->id], $runs[0]->recipients()->pluck('contact_id')->all());
        $this->assertSame([$secondContact->id], $runs[1]->recipients()->pluck('contact_id')->all());
    }

    public function test_wave_zoho_sync_uses_frozen_membership_and_reuses_key_after_failure(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), ['name' => 'Campagne Test']);
        $contact = $this->contact($this->company(50));
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'failed',
            'driver_ref' => 'zoho-wave-list',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);

        $gateway = new class implements ZohoRecipientListGateway {
            public array $emails = [];
            public array $names = [];
            public int $ensures = 0;
            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                $this->ensures++;
                $this->names[] = $listName;
                return 'wave-list-key';
            }
            public function listEmails(string $listKey): array { return $this->emails; }
            public function addContacts(string $listKey, array $contacts): void
            {
                $this->emails = array_values(array_unique(array_merge($this->emails, array_column($contacts, 'Contact Email'))));
            }
        };
        $this->app->instance(ZohoRecipientListGateway::class, $gateway);
        $service = app(CampaignWaveZohoListSyncService::class);

        $service->sync($run);
        $this->assertCount(1, $gateway->names);
        $this->assertMatchesRegularExpression('/^Campagne Test - Wave 001 - A[a-f0-9]{8}$/', $gateway->names[0]);
        $this->assertSame([$contact->email], $gateway->emails);
        $this->assertSame('wave-list-key', $run->fresh()->zoho_list_key);
        $this->assertNull($campaign->fresh()->zoho_list_key);

        (new SyncCampaignWaveZohoListJob($run->id))->failed(new \RuntimeException('temporary'));
        $this->assertSame('failed', $run->fresh()->status);
        (new SyncCampaignWaveZohoListJob($run->id))->handle($service);
        $this->assertSame('scheduled', $run->fresh()->status);
        Queue::assertPushed(SendSequenceWaveStepJob::class, fn ($job) => $job->runId === $run->id);
        $this->assertSame('zoho-wave-synced', $run->fresh()->driver_ref);
        $this->assertSame(1, $gateway->ensures);
    }

    public function test_wave_sync_skips_and_suppresses_invalid_contact_then_schedules_remaining_audience(): void
    {
        $sequence = $this->sequence();
        $campaign = $this->campaign($this->segment(), $sequence);
        $valid = $this->contact($this->company(50));
        $invalid = $this->contact($this->company(50));
        foreach ([$valid, $invalid] as $contact) {
            SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 0,
                'status' => 'active',
            ]);
        }
        $step = $sequence->steps()->firstOrFail();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'failed',
            'zoho_list_key' => 'wave-list-key',
            'driver_ref' => 'zoho-wave-list',
            'failure_reason' => 'invalid contact email',
        ]);
        foreach ([$valid, $invalid] as $contact) {
            CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        }

        $gateway = new class($invalid->email) implements ZohoRecipientListGateway
        {
            public array $emails = [];

            public function __construct(private readonly string $invalidEmail) {}

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                return 'wave-list-key';
            }

            public function listEmails(string $listKey): array
            {
                return $this->emails;
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                if (in_array($this->invalidEmail, array_column($contacts, 'Contact Email'), true)) {
                    throw new ZohoInvalidRecipientException($this->invalidEmail, '2007');
                }
                $this->emails = array_values(array_unique(array_merge($this->emails, array_column($contacts, 'Contact Email'))));
            }
        };
        $this->app->instance(ZohoRecipientListGateway::class, $gateway);

        (new SyncCampaignWaveZohoListJob($run->id))->handle(app(CampaignWaveZohoListSyncService::class));

        $this->assertSame([$valid->email], $gateway->emails);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $invalid->id, 'status' => 'skipped', 'skip_reason' => 'invalid_email']);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $valid->id, 'status' => 'queued']);
        $this->assertDatabaseHas('suppressions', ['email' => $invalid->email, 'reason' => 'invalid_email', 'source' => 'sequence']);
        $this->assertDatabaseHas('sequence_enrollments', ['campaign_id' => $campaign->id, 'contact_id' => $invalid->id, 'status' => 'stopped', 'stopped_reason' => 'invalid_email', 'next_send_at' => null]);
        $this->assertDatabaseHas('sequence_step_sends', ['campaign_run_id' => $run->id, 'step_no' => $step->step_no, 'status' => 'skipped']);
        $run->refresh();
        $this->assertSame('scheduled', $run->status);
        $this->assertSame('zoho-wave-synced', $run->driver_ref);
        $this->assertNull($run->failure_reason);
        Queue::assertPushed(SendSequenceWaveStepJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_wave_sync_with_only_invalid_contacts_finishes_without_send(): void
    {
        $sequence = $this->sequence();
        $campaign = $this->campaign($this->segment(), $sequence);
        $invalid = $this->contact($this->company(50));
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $invalid->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
        ]);
        $step = $sequence->steps()->firstOrFail();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'failed',
            'zoho_list_key' => 'wave-list-key',
            'driver_ref' => 'zoho-wave-list',
            'failure_reason' => 'invalid contact email',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $invalid->id, 'status' => 'queued']);

        $gateway = new class($invalid->email) implements ZohoRecipientListGateway
        {
            public function __construct(private readonly string $invalidEmail) {}

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                return 'wave-list-key';
            }

            public function listEmails(string $listKey): array
            {
                return [];
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                throw new ZohoInvalidRecipientException($this->invalidEmail, '2007');
            }
        };
        $this->app->instance(ZohoRecipientListGateway::class, $gateway);

        (new SyncCampaignWaveZohoListJob($run->id))->handle(app(CampaignWaveZohoListSyncService::class));

        $run->refresh();
        $this->assertSame('sent', $run->status);
        $this->assertSame(0, (int) $run->stats_sent);
        $this->assertSame('zoho-wave-empty', $run->driver_ref);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->failure_reason);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $invalid->id, 'status' => 'skipped', 'skip_reason' => 'invalid_email']);
        $this->assertDatabaseHas('suppressions', ['email' => $invalid->email, 'reason' => 'invalid_email', 'source' => 'sequence']);
        $this->assertDatabaseHas('sequence_enrollments', ['campaign_id' => $campaign->id, 'contact_id' => $invalid->id, 'status' => 'stopped', 'stopped_reason' => 'invalid_email']);
        $this->assertDatabaseHas('sequence_step_sends', ['campaign_run_id' => $run->id, 'step_no' => $step->step_no, 'status' => 'skipped']);
        Queue::assertNotPushed(SendSequenceWaveStepJob::class);
    }

    public function test_empty_wave_sync_finishes_without_calling_zoho(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        SequenceEnrollment::create(['sequence_id' => $sequence->id, 'contact_id' => $contact->id, 'campaign_id' => $campaign->id, 'current_step' => 1, 'status' => 'active']);
        $run = CampaignRun::create(['campaign_id' => $campaign->id, 'sequence_step_id' => $sequence->steps()->firstOrFail()->id, 'occurrence_key' => 'sequence-wave-000001', 'run_at' => now(), 'status' => 'failed', 'driver_ref' => 'zoho-wave-pending', 'failure_reason' => 'ancienne erreur']);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        $gateway = new class implements ZohoRecipientListGateway {
            public int $calls = 0;
            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string { $this->calls++; return 'unexpected'; }
            public function listEmails(string $listKey): array { $this->calls++; return []; }
            public function addContacts(string $listKey, array $contacts): void { $this->calls++; }
        };
        $this->app->instance(ZohoRecipientListGateway::class, $gateway);
        app(CampaignWaveZohoListSyncService::class)->sync($run);
        $run->refresh();
        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'status' => 'skipped', 'skip_reason' => 'enrollment_ineligible']);
        $this->assertSame('sent', $run->status);
        $this->assertSame(0, (int) $run->stats_sent);
        $this->assertSame('zoho-wave-empty', $run->driver_ref);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->failure_reason);
    }

    public function test_send_time_empty_wave_finishes_without_dispatching_zoho(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        SequenceEnrollment::create(['sequence_id' => $sequence->id, 'contact_id' => $contact->id, 'campaign_id' => $campaign->id, 'current_step' => 1, 'status' => 'active']);
        $run = CampaignRun::create(['campaign_id' => $campaign->id, 'sequence_step_id' => $sequence->steps()->firstOrFail()->id, 'occurrence_key' => 'sequence-wave-000001', 'run_at' => now(), 'status' => 'scheduled', 'zoho_list_key' => 'prepared-list', 'driver_ref' => 'zoho-wave-synced', 'failure_reason' => 'ancienne erreur']);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock->shouldNotReceive('dispatchRun'));
        app(SequenceWaveService::class)->send($run);
        $run->refresh();
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'status' => 'skipped', 'skip_reason' => 'enrollment_ineligible']);
        $this->assertSame('sent', $run->status);
        $this->assertSame(0, (int) $run->stats_sent);
        $this->assertSame('zoho-wave-empty', $run->driver_ref);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->failure_reason);
    }

    public function test_wave_view_shows_explicit_and_legacy_membership(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $explicit = $this->contact($this->company(50));
        $legacy = $this->contact($this->company(40));
        foreach ([$explicit, $legacy] as $contact) {
            SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 0,
                'status' => 'active',
                'next_send_at' => now()->addDay(),
            ]);
        }
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'prepared',
            'driver_ref' => 'zoho-wave-list',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $explicit->id, 'status' => 'queued']);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get("/admin/campaigns/{$campaign->id}?wave_id={$run->id}#campaign_vagues")
            ->assertOk()
            ->assertSee('href="#campaign_vagues"', false)
            ->assertSee($campaign->name . ' - Wave 001', false)
            ->assertSee($explicit->email)
            ->assertSee('Vagues historiques')
            ->assertSee($legacy->email);
    }
    public function test_wave_view_explains_legacy_empty_wave_and_skipped_recipient(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $template = CampaignTemplate::create([
            'name' => 'Template step 2',
            'subject' => 'Second',
            'html_content' => '<p>Second step</p>',
        ]);
        $step = SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 2,
            'delay_days' => 1,
            'template_id' => $template->id,
            'subject' => 'Etape 2',
        ]);
        $contact = $this->contact($this->company(50));
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'sent',
            'stats_sent' => 0,
            'driver_ref' => 'zoho-wave-pending',
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'skipped',
            'skip_reason' => 'enrollment_ineligible',
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $waveResponse = $this->actingAs($admin)
            ->get("/admin/campaigns/{$campaign->id}?wave_id={$run->id}#campaign_vagues")
            ->assertOk()
            ->assertSee('Aucun contact éligible');
        $waveResponse->assertSeeInOrder(['Etape 2', 'Ignoré', 'Étape de séquence non éligible']);

        $this->get("/admin/campaigns/{$campaign->id}?run_id={$run->id}#campaign_destinataires")
            ->assertOk()
            ->assertSee('Ignoré')
            ->assertSee('Étape de séquence non éligible');
    }

    public function test_genuine_zero_send_zoho_wave_is_not_presented_as_empty(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'sent',
            'stats_sent' => 0,
            'zoho_campaign_key' => 'zoho-campaign-zero',
            'driver_ref' => 'zoho',
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->get("/admin/campaigns/{$campaign->id}?wave_id={$run->id}#campaign_vagues")
            ->assertOk()
            ->assertSee('Envoyée')
            ->assertDontSee('Aucun contact éligible')
            ->assertDontSee('En attente Zoho');
    }
    public function test_legacy_paced_enrollment_with_queued_step_send_is_adopted_without_smtp(): void
    {
        Mail::fake();
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => now()->subMinute(),
        ]);
        SequenceStepSend::create(['enrollment_id' => $enrollment->id, 'step_no' => 1, 'status' => 'queued']);

        (new SendSequenceStepJob($enrollment->id))->handle();
        app(SequenceWaveService::class)->recover();

        Mail::assertNothingSent();
        $run = CampaignRun::where('campaign_id', $campaign->id)->where('occurrence_key', 'like', 'sequence-wave-legacy-%')->firstOrFail();
        $this->assertSame($sequence->steps()->firstOrFail()->id, $run->sequence_step_id);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        $this->assertNull($enrollment->fresh()->next_send_at);
    }

    public function test_attempted_campaign_key_finalizes_without_duplicate_zoho_calls(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => null,
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000099',
            'run_at' => now(),
            'status' => 'scheduled',
            'zoho_list_key' => 'list-existing',
            'zoho_campaign_key' => 'campaign-existing',
            'driver_ref' => 'zoho-send-attempted',
        ]);
        CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        $client = \Mockery::mock(ZohoCampaignsClient::class);
        $client->shouldNotReceive('createCampaign');
        $client->shouldNotReceive('sendCampaign');
        $client->shouldNotReceive('addListSubscribers');
        $this->app->instance(ZohoCampaignsDriver::class, new ZohoCampaignsDriver($client));

        app(SequenceWaveService::class)->send($run);

        $this->assertSame('sent', $run->fresh()->status);
        $this->assertSame('zoho', $run->fresh()->driver_ref);
    }

    public function test_definite_send_failure_retries_but_ambiguous_failure_requires_manual_reconciliation(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $contact = $this->contact($this->company(50));
        $step = $sequence->steps()->firstOrFail();
        $makeRun = fn (string $key) => CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => $key,
            'run_at' => now(),
            'status' => 'scheduled',
            'zoho_list_key' => 'list-existing',
            'zoho_campaign_key' => 'campaign-existing',
            'driver_ref' => 'zoho-send-failed',
        ]);

        $retryRun = $makeRun('sequence-wave-000097');
        $retryClient = \Mockery::mock(ZohoCampaignsClient::class);
        $attempts = 0;
        $retryClient->shouldReceive('sendCampaign')->twice()->andReturnUsing(function () use (&$attempts): array {
            if (++$attempts === 1) {
                throw new \RuntimeException('sendCampaign échoué (HTTP 400): rejected');
            }
            return [];
        });
        $driver = new ZohoCampaignsDriver($retryClient);
        try { $driver->dispatchRun($retryRun, collect([$contact])); } catch (\RuntimeException) {}
        $this->assertSame('zoho-send-failed', $retryRun->fresh()->driver_ref);
        $driver->dispatchRun($retryRun->fresh(), collect([$contact]));
        $this->assertSame('zoho-send-attempted', $retryRun->fresh()->driver_ref);

        $uncertainRun = $makeRun('sequence-wave-000098');
        $uncertainClient = \Mockery::mock(ZohoCampaignsClient::class);
        $uncertainClient->shouldReceive('sendCampaign')->once()->andThrow(new \RuntimeException('socket timeout'));
        $uncertainDriver = new ZohoCampaignsDriver($uncertainClient);
        try { $uncertainDriver->dispatchRun($uncertainRun, collect([$contact])); } catch (\RuntimeException) {}
        $this->assertSame('zoho-send-uncertain', $uncertainRun->fresh()->driver_ref);
        $this->expectException(\RuntimeException::class);
        $uncertainDriver->dispatchRun($uncertainRun->fresh(), collect([$contact]));
    }
    public function test_signed_zoho_content_uses_the_run_step_template(): void
    {
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $step = $sequence->steps()->firstOrFail();
        $step->template->update(['html_content' => '<p>Immutable step content</p>']);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'prepared',
        ]);

        $url = URL::temporarySignedRoute('campaigns.zoho-content', now()->addMinute(), ['run' => $run->id]);

        $this->get($url)->assertOk()->assertSee('Immutable step content');
    }
    public function test_two_step_wave_sends_through_zoho_and_never_smtp(): void
    {
        Mail::fake();
        $segment = $this->segment();
        $sequence = $this->sequence();
        $template = CampaignTemplate::create([
            'name' => 'Template step 2',
            'subject' => 'Second',
            'html_content' => '<p>Second step</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 2,
            'delay_days' => 2,
            'template_id' => $template->id,
            'subject' => 'Etape 2',
        ]);
        $contact = $this->contact($this->company(50));
        $campaign = $this->campaign($segment, $sequence, ['next_run_at' => '2026-07-21 08:00:00']);
        app(PacedSequenceEnrollmentService::class)->activate($campaign, Carbon::parse('2026-07-21 09:00:00', 'UTC'));
        $first = CampaignRun::where('campaign_id', $campaign->id)->where('occurrence_key', 'sequence-wave-000001')->firstOrFail();
        $first->update(['zoho_list_key' => 'list-step-1', 'status' => 'scheduled']);

        $this->mock(ZohoCampaignsDriver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatchRun')->twice()->andReturn(
                ['campaign_key' => 'zoho-step-1'],
                ['campaign_key' => 'zoho-step-2'],
            );
        });
        $service = app(SequenceWaveService::class);
        $service->send($first);

        $child = CampaignRun::where('campaign_id', $campaign->id)
            ->where('occurrence_key', 'sequence-wave-000001-step-002')
            ->firstOrFail();
        $this->assertSame(2, $child->sequenceStep->step_no);
        $this->assertTrue($child->run_at->greaterThanOrEqualTo(now()->addDays(2)->subMinute()));
        $child->update(['zoho_list_key' => 'list-step-2', 'status' => 'scheduled', 'run_at' => now()]);
        $service->send($child);

        Mail::assertNothingSent();
        $this->assertSame('completed', SequenceEnrollment::where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->firstOrFail()->status);
        $this->assertSame(2, SequenceStepSend::whereHas('enrollment', fn ($query) => $query->where('campaign_id', $campaign->id))->where('status', 'sent')->count());
        $this->assertSame(2, CampaignRun::where('campaign_id', $campaign->id)->count());
    }
    public function test_legacy_paced_enrollment_is_adopted_and_existing_job_skips_smtp(): void
    {
        Mail::fake();
        Queue::fake();
        $contact = $this->contact($this->company(50));
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => now()->subMinute(),
        ]);

        app(SequenceWaveService::class)->recover();
        (new SendSequenceStepJob($enrollment->id))->handle();

        $this->assertNull($enrollment->fresh()->next_send_at);
        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'status' => 'prepared',
        ]);
        Queue::assertPushed(SyncCampaignWaveZohoListJob::class);
        Mail::assertNothingSent();
    }

    public function test_paused_or_inactive_wave_is_deferred_without_send(): void
    {
        $contact = $this->contact($this->company(50));
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence(), [
            'next_run_at' => now()->subMinute(),
        ]);
        app(PacedSequenceEnrollmentService::class)->activate($campaign);
        $run = CampaignRun::where('campaign_id', $campaign->id)->firstOrFail();
        $run->update(['zoho_list_key' => 'deferred-list', 'status' => 'scheduled']);
        $enrollment = SequenceEnrollment::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->firstOrFail();
        $enrollment->update(['status' => 'paused']);

        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock->shouldNotReceive('dispatchRun'));
        $service = app(SequenceWaveService::class);
        $service->send($run);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertSame('queued', $run->recipients()->firstOrFail()->status);

        $enrollment->update(['status' => 'active']);
        $sequence->update(['is_active' => false]);
        $service->send($run->fresh());
        $this->assertSame('scheduled', $run->fresh()->status);
    }

    public function test_existing_attempted_zoho_campaign_is_not_created_or_sent_again(): void
    {
        $contact = $this->contact($this->company(50));
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $sequence->steps()->firstOrFail()->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'sending',
            'zoho_list_key' => 'list-existing',
            'zoho_campaign_key' => 'campaign-existing',
            'driver_ref' => 'zoho-send-attempted',
        ]);
        $client = \Mockery::mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $client->shouldNotReceive('createCampaign');
        $client->shouldNotReceive('sendCampaign');

        $summary = (new ZohoCampaignsDriver($client))->dispatchRun($run, collect([$contact->load('company')]));

        $this->assertSame('send_already_attempted', $summary['status']);
        $this->assertSame('campaign-existing', $summary['campaign_key']);
    }
    public function test_audience_rebuild_clears_old_campaign_key_and_sends_only_the_new_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign($this->segment(), $sequence = $this->sequence());
        $step = $sequence->steps()->firstOrFail();
        $contacts = collect([$this->contact($this->company(50)), $this->contact($this->company(40))]);
        foreach ($contacts as $contact) {
            SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 0,
                'status' => 'active',
                'next_send_at' => null,
            ]);
        }
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000096',
            'run_at' => now(),
            'status' => 'scheduled',
            'zoho_list_key' => 'old-list',
            'zoho_campaign_key' => 'old-campaign',
            'driver_ref' => 'zoho-send-failed',
        ]);
        foreach ($contacts as $contact) {
            CampaignRecipient::create(['campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        }
        \App\Models\Suppression::create(['email' => $contacts[0]->email, 'contact_id' => $contacts[0]->id]);
        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock->shouldNotReceive('dispatchRun'));

        app(SequenceWaveService::class)->send($run);

        $run->refresh();
        $this->assertNull($run->zoho_list_key);
        $this->assertNull($run->zoho_campaign_key);
        $client = \Mockery::mock(ZohoCampaignsClient::class);
        $client->shouldReceive('createCampaign')->once()->andReturn(['campaignkey' => 'new-campaign']);
        $client->shouldReceive('sendCampaign')->once()->with('new-campaign')->andReturn([]);
        $client->shouldNotReceive('addListSubscribers');
        $this->app->instance(ZohoCampaignsDriver::class, new ZohoCampaignsDriver($client));
        $run->update(['zoho_list_key' => 'new-list', 'status' => 'scheduled']);

        app(SequenceWaveService::class)->send($run->fresh());

        $this->assertSame('new-campaign', $run->fresh()->zoho_campaign_key);
        $this->assertSame('sent', $run->fresh()->status);
    }
    public function test_suppressed_contact_is_removed_before_the_next_wave_step(): void
    {
        $contact = $this->contact($this->company(50));
        $sequence = $this->sequence();
        $template = CampaignTemplate::create([
            'name' => 'Suppression step 2',
            'subject' => 'Second',
            'html_content' => '<p>Second step</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 2,
            'delay_days' => 1,
            'template_id' => $template->id,
        ]);
        $campaign = $this->campaign($this->segment(), $sequence, ['next_run_at' => now()->subMinute()]);
        app(PacedSequenceEnrollmentService::class)->activate($campaign);
        $first = CampaignRun::where('campaign_id', $campaign->id)->firstOrFail();
        $first->update(['zoho_list_key' => 'step-1-list', 'status' => 'scheduled']);
        $this->mock(ZohoCampaignsDriver::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('dispatchRun')->once()->andReturn(['campaign_key' => 'step-1-key']));
        $service = app(SequenceWaveService::class);
        $service->send($first);
        $child = CampaignRun::where('campaign_id', $campaign->id)
            ->where('occurrence_key', 'like', '%-step-002')
            ->firstOrFail();
        \App\Models\Suppression::create(['email' => $contact->email, 'contact_id' => $contact->id]);

        $eligible = $service->eligibleContacts($child);

        $this->assertTrue($eligible->isEmpty());
        $this->assertSame('stopped', SequenceEnrollment::where('campaign_id', $campaign->id)->firstOrFail()->status);
        $this->assertSame('skipped', $child->recipients()->firstOrFail()->status);
        $this->assertDatabaseHas('sequence_step_sends', [
            'campaign_run_id' => $child->id,
            'step_no' => 2,
            'status' => 'skipped',
        ]);
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
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
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
