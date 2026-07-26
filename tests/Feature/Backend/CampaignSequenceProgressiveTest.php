<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Jobs\SyncCampaignWaveZohoListJob;
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
use App\Models\User;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\SegmentService;
use App\Services\Zoho\ZohoRecipientListGateway;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame('zoho-wave-pending', $wave->driver_ref);
        $this->assertEqualsCanonicalizing(array_column($highContacts, 'id'), $wave->recipients()->pluck('contact_id')->all());
        Queue::assertPushed(SyncCampaignWaveZohoListJob::class, fn ($job) => $job->runId === $wave->id);
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

    public function test_zoho_driver_rejects_progressive_sequence_activation(): void
    {
        $campaign = $this->campaign($this->segment(), $this->sequence(), [
            'driver' => 'zoho',
            'next_run_at' => now()->subMinute(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pilote local');
        app(PacedSequenceEnrollmentService::class)->activate($campaign);
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
        $this->assertSame(['Campagne Test - Wave 001'], $gateway->names);
        $this->assertSame([$contact->email], $gateway->emails);
        $this->assertSame('wave-list-key', $run->fresh()->zoho_list_key);
        $this->assertNull($campaign->fresh()->zoho_list_key);

        (new SyncCampaignWaveZohoListJob($run->id))->failed(new \RuntimeException('temporary'));
        $this->assertSame('failed', $run->fresh()->status);
        $service->sync($run->fresh());
        $this->assertSame('prepared', $run->fresh()->status);
        $this->assertSame('zoho-wave-synced', $run->fresh()->driver_ref);
        $this->assertSame(1, $gateway->ensures);
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
