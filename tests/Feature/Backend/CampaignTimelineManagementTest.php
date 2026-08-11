<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Jobs\SendSmtpReservationJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\SmtpSendReservation;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SmtpCampaignsDriver;
use App\Services\Campaign\SmtpSendReservationService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignTimelineManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->editor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->editor->givePermissionTo([
            'backend.access',
            'view campaigns',
            'edit campaigns',
        ]);

        config(['services.zoho.driver' => 'local']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pause_releases_future_reserved_smtp_work_without_losing_the_queued_recipient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810',
            'run_at' => now()->addHours(2),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addHours(2),
            'status' => 'reserved',
        ]);

        $response = $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign->id),
            ['field' => 'is_active', 'state' => '0'],
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFalse($campaign->fresh()->is_active);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->skip_reason);
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_pause_keeps_an_unproven_claimed_run_in_sending_for_audit_safety(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-unproven-pause',
            'run_at' => now()->addHours(2),
            'status' => 'sending',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign->id),
            ['field' => 'is_active', 'state' => '0'],
        )->assertOk()->assertJson(['success' => true]);

        $this->assertFalse($campaign->fresh()->is_active);
        $this->assertSame('sending', $run->fresh()->status);
        $this->assertNull($run->fresh()->driver_ref);
        $this->assertNotNull($run->fresh()->started_at);
        $this->assertSame('queued', $recipient->fresh()->status);
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_resume_replans_an_expired_pending_lot_before_the_next_campaign_batch_without_dispatching(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $campaign->update([
            'is_active' => false,
            'next_run_at' => now()->subHours(2),
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810',
            'run_at' => now()->subHour(),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign->id),
            ['field' => 'is_active', 'state' => '1'],
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertGreaterThan(now()->getTimestamp(), $run->fresh()->run_at->getTimestamp());
        $this->assertGreaterThan(
            $run->fresh()->run_at->getTimestamp(),
            $campaign->fresh()->next_run_at->getTimestamp(),
        );
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_resume_moves_a_stale_one_shot_lot_behind_a_safety_buffer_and_preserves_a_future_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $staleCampaign = $this->makePacedSmtpCampaign();
        $staleCampaign->update([
            'schedule_type' => 'one_shot',
            'is_active' => false,
            'next_run_at' => null,
        ]);
        $staleRun = CampaignRun::create([
            'campaign_id' => $staleCampaign->id,
            'occurrence_key' => 'one-shot-stale-resume',
            'run_at' => now()->subHour(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $staleCampaign->id),
            ['field' => 'is_active', 'state' => '1'],
        )->assertOk()->assertJson(['success' => true]);

        $this->assertTrue($staleCampaign->fresh()->is_active);
        $this->assertTrue($staleRun->fresh()->run_at->equalTo(now()->addMinutes(5)));

        $futureCampaign = $this->makePacedSmtpCampaign();
        $futureCampaign->update([
            'schedule_type' => 'one_shot',
            'is_active' => false,
            'next_run_at' => null,
        ]);
        $futureAt = now()->addDay();
        $futureRun = CampaignRun::create([
            'campaign_id' => $futureCampaign->id,
            'occurrence_key' => 'one-shot-future-resume',
            'run_at' => $futureAt,
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $futureCampaign->id),
            ['field' => 'is_active', 'state' => '1'],
        )->assertOk()->assertJson(['success' => true]);

        $this->assertTrue($futureCampaign->fresh()->is_active);
        $this->assertTrue($futureRun->fresh()->run_at->equalTo($futureAt));
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_resume_refuses_to_rewrite_a_scheduled_lot_with_provider_evidence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $campaign->update([
            'schedule_type' => 'one_shot',
            'is_active' => false,
            'next_run_at' => null,
        ]);
        $originalRunAt = now()->subHour();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'one-shot-provider-evidence',
            'run_at' => $originalRunAt,
            'status' => 'scheduled',
            'zoho_list_key' => 'existing-provider-list',
        ]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 1],
        )->assertUnprocessable()->assertJson(['success' => false]);

        $this->assertFalse($campaign->fresh()->is_active);
        $this->assertTrue($run->fresh()->run_at->equalTo($originalRunAt));
    }

    public function test_editor_can_cancel_a_sending_lot_that_only_has_a_future_reserved_smtp_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $nextRunAt = $campaign->next_run_at->copy();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-cancel',
            'run_at' => now()->addHours(2),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addHours(2),
            'status' => 'reserved',
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.cancel', [$campaign->id, $run->id]),
        );

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame('canceled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame('Annulé par un opérateur.', $run->fresh()->failure_reason);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('skipped', $recipient->fresh()->status);
        $this->assertSame('run_canceled', $recipient->fresh()->skip_reason);
        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertTrue($campaign->fresh()->next_run_at->equalTo($nextRunAt));
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_editor_cannot_cancel_a_claimed_sending_lot_without_proven_smtp_reservation_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-claimed-unsafe',
            'run_at' => now()->addHours(2),
            'status' => 'sending',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);

        $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.cancel', [$campaign->id, $run->id]),
        )->assertUnprocessable()->assertJson([
            'success' => false,
            'msg' => 'Ce lot ne peut plus être annulé car il a déjà été transmis au fournisseur.',
        ]);

        $this->assertSame('sending', $run->fresh()->status);
        $this->assertNull($run->fresh()->driver_ref);
        $this->assertNotNull($run->fresh()->started_at);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertSame(0, SmtpSendReservation::count());
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_editor_cannot_cancel_a_lot_already_accepted_by_the_smtp_provider(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-accepted',
            'run_at' => now()->addHours(2),
            'status' => 'scheduled',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addHours(2),
            'status' => 'accepted',
            'accepted_at' => now()->subMinute(),
            'provider_message_id' => 'accepted-before-cancel',
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.cancel', [$campaign->id, $run->id]),
        );

        $response->assertUnprocessable()
            ->assertJson([
                'success' => false,
                'msg' => 'Ce lot ne peut plus être annulé car il a déjà été transmis au fournisseur.',
            ]);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
        $this->assertNull($run->fresh()->failure_reason);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->skip_reason);
        $this->assertSame('accepted', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->accepted_at);
        $this->assertSame('accepted-before-cancel', $reservation->fresh()->provider_message_id);
        Bus::assertNotDispatched(SendCampaignJob::class);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_editor_cannot_cancel_a_lot_from_another_campaign(): void
    {
        $campaign = $this->makePacedSmtpCampaign();
        $otherCampaign = $this->makePacedSmtpCampaign();
        $otherRun = CampaignRun::create([
            'campaign_id' => $otherCampaign->id,
            'occurrence_key' => 'paced-20260810-other',
            'run_at' => now()->addHours(2),
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.cancel', [$campaign->id, $otherRun->id]),
        );

        $response->assertNotFound();
        $this->assertSame('scheduled', $otherRun->fresh()->status);
        $this->assertNull($otherRun->fresh()->finished_at);
    }

    public function test_canceled_run_cannot_create_a_new_smtp_reservation_when_a_delayed_continuation_arrives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-canceled-continuation',
            'run_at' => now()->addHours(2),
            'status' => 'canceled',
            'finished_at' => now(),
            'failure_reason' => 'Annulé par un opérateur.',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);

        app(CampaignService::class)->continueSmtpRun($run);

        $this->assertDatabaseMissing('smtp_send_reservations', [
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
        ]);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertSame('canceled', $run->fresh()->status);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_released_reservation_cannot_be_rearmed_after_its_run_is_canceled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-released-canceled',
            'run_at' => now()->addHours(2),
            'status' => 'canceled',
            'finished_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'skipped',
            'skip_reason' => 'run_canceled',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addHours(2),
            'status' => 'released',
        ]);

        $result = app(SmtpSendReservationService::class)->reserve(
            $campaign->senderIdentity,
            $campaign,
            SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            $recipient->id,
            now(),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('run_canceled', $result['reason']);
        $this->assertSame($reservation->id, $result['reservation']?->id);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertTrue($reservation->fresh()->reserved_for->equalTo(now()->addHours(2)));
        $this->assertSame(1, SmtpSendReservation::query()
            ->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
            ->where('source_id', $recipient->id)
            ->count());
    }

    public function test_sender_can_start_a_reserved_lot_now_without_creating_a_second_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-start-now',
            'run_at' => now()->addDay(),
            'status' => 'sending',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addDay(),
            'status' => 'reserved',
        ]);
        $oldReservedFor = $reservation->reserved_for->copy();

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign->id, $run->id]),
        );

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonPath('scheduled_for', now()->toIso8601String());
        $this->assertSame(1, SmtpSendReservation::query()
            ->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
            ->where('source_id', $recipient->id)
            ->count());
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertTrue($reservation->fresh()->reserved_for->equalTo(now()));
        $this->assertTrue($run->fresh()->run_at->equalTo($reservation->fresh()->reserved_for));
        Bus::assertDispatched(SendSmtpReservationJob::class, function (SendSmtpReservationJob $job) use ($reservation): bool {
            return $job->reservationId === $reservation->id
                && $job->reservedFor->equalTo(now())
                && str_contains($job->uniqueId(), now()->toIso8601String());
        });

        $oldJob = new SendSmtpReservationJob($reservation->id, $oldReservedFor);
        $expeditedJob = new SendSmtpReservationJob($reservation->id, now());
        $this->assertNotSame($oldJob->uniqueId(), $expeditedJob->uniqueId());
        $this->assertSame('smtp', $run->fresh()->driver_ref);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 0],
        )->assertOk();
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 1],
        )->assertOk();
        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertSame('scheduled', $run->fresh()->status);
    }

    public function test_sender_can_start_a_programmed_lot_now_before_its_audience_is_materialized(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $campaign->update([
            'schedule_type' => 'one_shot',
            'next_run_at' => null,
        ]);
        $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'scheduled-20260811-start-now',
            'run_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $this->assertSame(0, $run->recipients()->count());
        $this->assertSame(0, SmtpSendReservation::count());

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign->id, $run->id]),
        );

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('scheduled_for', now()->toIso8601String());
        $run->refresh();
        $this->assertTrue($run->run_at->equalTo(now()));
        $this->assertSame('scheduled', $run->status);
        $this->assertNull($run->started_at);
        $this->assertNull($run->driver_ref);
        $this->assertSame(0, $run->recipients()->count());
        $this->assertSame(0, SmtpSendReservation::count());
        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->runId === $run->id
            && $job->delay !== null
            && $job->delay->equalTo(now()));
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_sender_can_start_a_materialized_programmed_lot_before_its_first_reservation_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-materialized-start-now',
            'run_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);

        $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign, $run]),
        )->assertOk()->assertJsonPath('scheduled_for', now()->toIso8601String());

        $this->assertTrue($run->fresh()->run_at->equalTo(now()));
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertSame(0, SmtpSendReservation::count());
        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->runId === $run->id
            && $job->delay !== null
            && $job->delay->equalTo(now()));
    }

    public function test_sender_can_start_a_resumed_lot_whose_previous_reservation_was_released_by_pause(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-paused-resumed-start-now',
            'run_at' => now()->addDay(),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addDay(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 0],
        )->assertOk();
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);

        $replacementSender = SenderIdentity::create([
            'name' => 'Timeline replacement sender',
            'email' => uniqid('timeline_replacement_sender_') . '@tcl.test',
        ]);
        $campaign->update(['sender_identity_id' => $replacementSender->id]);

        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 1],
        )->assertOk();

        $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign, $run]),
        )->assertOk()->assertJsonPath('scheduled_for', now()->toIso8601String());

        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertNotSame($campaign->fresh()->sender_identity_id, $reservation->fresh()->sender_identity_id);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertTrue($run->fresh()->run_at->equalTo(now()));
        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->runId === $run->id);
    }

    public function test_released_reservation_with_provider_evidence_cannot_be_started_or_rearmed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-released-provider-evidence',
            'run_at' => now()->addDay(),
            'status' => 'scheduled',
            'driver_ref' => 'smtp',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addDay(),
            'status' => 'released',
            'accepted_at' => now(),
            'provider_message_id' => 'provider-evidence-must-survive',
        ]);

        $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign, $run]),
        )->assertUnprocessable();

        $result = app(SmtpSendReservationService::class)->reserve(
            $campaign->senderIdentity,
            $campaign,
            SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            $recipient->id,
            now(),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('transport_evidence', $result['reason']);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('provider-evidence-must-survive', $reservation->fresh()->provider_message_id);
        $this->assertNotNull($reservation->fresh()->accepted_at);
        Bus::assertNothingDispatched();

        $campaign->update(['is_active' => false]);
        $this->actingAs($this->editor)->putJson(
            route('admin.campaigns.executeSwitch', $campaign),
            ['field' => 'is_active', 'state' => 1],
        )->assertUnprocessable();
        $this->assertFalse($campaign->fresh()->is_active);
    }

    public function test_old_reservation_job_hands_off_to_the_current_slot_version_without_mutating_send_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-old-slot-handoff',
            'run_at' => now()->addHours(2),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $currentSlot = now()->addHours(2);
        $oldSlot = now()->addDay();
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => $currentSlot,
            'status' => 'reserved',
        ]);

        (new SendSmtpReservationJob($reservation->id, $oldSlot))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Bus::assertDispatchedTimes(SendSmtpReservationJob::class, 1);
        Bus::assertDispatched(SendSmtpReservationJob::class, fn (SendSmtpReservationJob $job): bool => $job->reservationId === $reservation->id
            && $job->reservedFor?->equalTo($currentSlot));
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertTrue($reservation->fresh()->reserved_for->equalTo($currentSlot));
        $this->assertSame(0, $reservation->fresh()->attempt_count);
        $this->assertNull($reservation->fresh()->attempted_at);
        $this->assertSame('queued', $recipient->fresh()->status);
        $this->assertSame('sending', $run->fresh()->status);
    }

    public function test_accepted_reservation_ignores_an_old_slot_version_and_finalizes_without_a_provider_call(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $driver = $this->mock(SmtpCampaignsDriver::class);
        $driver->shouldNotReceive('send');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-accepted-old-slot',
            'run_at' => now(),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now(),
            'status' => 'accepted',
            'accepted_at' => now(),
            'provider_message_id' => 'accepted-before-local-finalize',
        ]);

        (new SendSmtpReservationJob($reservation->id, now()->addDay()))->handle(
            app(SmtpSendReservationService::class),
            $driver,
            app(CampaignService::class),
            app(SequenceService::class),
        );

        $this->assertSame('sent', $reservation->fresh()->status);
        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame('accepted-before-local-finalize', $recipient->fresh()->provider_message_id);
        $this->assertNotNull($recipient->fresh()->sent_at);
        $this->assertSame('sent', $run->fresh()->status);
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_accepted_reservation_finalizes_after_the_campaign_sender_changes_without_another_provider_call(): void
    {
        $this->assertAcceptedReservationFinalizesAfterCampaignMutation('sender');
    }

    public function test_accepted_reservation_finalizes_after_the_campaign_channel_changes_without_another_provider_call(): void
    {
        $this->assertAcceptedReservationFinalizesAfterCampaignMutation('channel');
    }

    public function test_sender_cannot_start_a_lot_now_while_its_campaign_is_paused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $campaign->update(['is_active' => false]);
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-paused-start-now',
            'run_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addDay(),
            'status' => 'reserved',
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.startNow', [$campaign->id, $run->id]),
        );

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'msg' => 'Reprenez la campagne avant de démarrer ce lot.',
        ]);
        $this->assertFalse($campaign->fresh()->is_active);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertTrue($run->fresh()->run_at->equalTo(now()->addDay()));
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertTrue($reservation->fresh()->reserved_for->equalTo(now()->addDay()));
        Bus::assertNotDispatched(SendSmtpReservationJob::class);
    }

    public function test_sender_can_resend_a_completed_lot_as_a_new_audited_run_using_only_currently_eligible_source_recipients(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $eligibleSourceContact = $this->makeClientContact();
        $suppressedSourceContact = $this->makeClientContact();
        $currentButNotSourceContact = $this->makeClientContact();
        $sourceRun = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260809-sent',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        $eligibleSourceRecipient = CampaignRecipient::create([
            'campaign_run_id' => $sourceRun->id,
            'contact_id' => $eligibleSourceContact->id,
            'status' => 'sent',
            'provider_message_id' => 'source-eligible-message',
            'sent_at' => now()->subDay(),
        ]);
        $suppressedSourceRecipient = CampaignRecipient::create([
            'campaign_run_id' => $sourceRun->id,
            'contact_id' => $suppressedSourceContact->id,
            'status' => 'sent',
            'provider_message_id' => 'source-suppressed-message',
            'sent_at' => now()->subDay(),
        ]);
        Suppression::create([
            'email' => $suppressedSourceContact->email,
            'contact_id' => $suppressedSourceContact->id,
            'reason' => 'manual',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.resend', [$campaign->id, $sourceRun->id]),
        );

        $response->assertOk()->assertJson(['success' => true]);
        $newRun = CampaignRun::findOrFail($response->json('run_id'));
        $this->assertNotSame($sourceRun->id, $newRun->id);
        $this->assertSame('scheduled', $newRun->status);
        $this->assertSame($sourceRun->id, $newRun->source_run_id);
        $this->assertStringStartsWith('resend-' . $sourceRun->id . '-', $newRun->occurrence_key);
        $this->assertTrue($newRun->run_at->equalTo(now()));

        $newRecipients = $newRun->recipients()->get();
        $this->assertCount(1, $newRecipients);
        $this->assertSame($eligibleSourceContact->id, $newRecipients->sole()->contact_id);
        $this->assertSame('queued', $newRecipients->sole()->status);
        $this->assertNull($newRecipients->sole()->provider_message_id);
        $this->assertNull($newRecipients->sole()->sent_at);
        $this->assertFalse($newRecipients->contains('contact_id', $suppressedSourceContact->id));
        $this->assertFalse($newRecipients->contains('contact_id', $currentButNotSourceContact->id));

        $this->assertSame('sent', $sourceRun->fresh()->status);
        $this->assertSame('source-eligible-message', $eligibleSourceRecipient->fresh()->provider_message_id);
        $this->assertSame('source-suppressed-message', $suppressedSourceRecipient->fresh()->provider_message_id);
        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->runId === $newRun->id);
        Bus::assertNotDispatched(SendCampaignJob::class, fn (SendCampaignJob $job): bool => $job->runId === $sourceRun->id);
    }

    public function test_queued_resend_rechecks_current_eligibility_without_expanding_its_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $sourceContact = $this->makeClientContact();
        $sourceRun = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260809-late-suppression',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $sourceRun->id,
            'contact_id' => $sourceContact->id,
            'status' => 'sent',
            'provider_message_id' => 'source-late-suppression-message',
            'sent_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.resend', [$campaign->id, $sourceRun->id]),
        );
        $response->assertOk();
        $resendRun = CampaignRun::findOrFail($response->json('run_id'));

        Suppression::create([
            'email' => $sourceContact->email,
            'contact_id' => $sourceContact->id,
            'reason' => 'manual',
            'source' => 'manual',
        ]);

        $preflight = app(CampaignService::class)->dispatchPreflight($campaign->fresh(), $resendRun->fresh());

        $this->assertFalse($preflight['ok']);
        $this->assertSame(0, $preflight['count']);
        $this->assertEmpty($preflight['contacts']);
        $this->assertContains(
            'Aucun destinataire éligible après exclusions, suppressions et règles de conformité.',
            $preflight['messages'],
        );
    }

    public function test_sender_cannot_resend_a_lot_while_its_campaign_is_paused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $campaign->update(['is_active' => false]);
        $sourceRun = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260809-paused-resend',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'finished_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.resend', [$campaign->id, $sourceRun->id]),
        );

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'msg' => 'Reprenez la campagne avant de renvoyer ce lot.',
        ]);
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
        Bus::assertNotDispatched(SendCampaignJob::class);
    }

    public function test_sender_cannot_resend_a_lot_that_was_not_fully_sent(): void
    {
        Bus::fake();
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $sourceRun = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260810-failed-resend',
            'run_at' => now(),
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.resend', [$campaign->id, $sourceRun->id]),
        );

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'msg' => 'Seuls les lots envoyés peuvent être renvoyés.',
        ]);
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
        Bus::assertNotDispatched(SendCampaignJob::class);
    }

    public function test_sender_cannot_resend_a_lot_from_another_campaign(): void
    {
        $this->editor->givePermissionTo('send campaigns');

        $campaign = $this->makePacedSmtpCampaign();
        $otherCampaign = $this->makePacedSmtpCampaign();
        $otherRun = CampaignRun::create([
            'campaign_id' => $otherCampaign->id,
            'occurrence_key' => 'paced-20260809-other-resend',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'finished_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->editor)->postJson(
            route('admin.campaigns.runs.resend', [$campaign->id, $otherRun->id]),
        );

        $response->assertNotFound();
        $this->assertSame(1, CampaignRun::where('campaign_id', $otherCampaign->id)->count());
    }

    private function makePacedSmtpCampaign(): Campaign
    {
        $segment = Segment::create([
            'name' => 'Timeline segment ' . uniqid(),
            'scope' => 'client',
        ]);
        $template = CampaignTemplate::create([
            'name' => 'Timeline template ' . uniqid(),
            'subject' => 'Objet timeline',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => 'Timeline sender',
            'email' => uniqid('timeline_sender_') . '@tcl.test',
        ]);

        return Campaign::create([
            'name' => 'Campagne timeline ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'paced',
            'daily_company_limit' => 1,
            'next_run_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'send_window' => [
                'days' => [1, 2, 3, 4, 5],
                'start' => '09:00',
                'end' => '18:00',
            ],
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'is_active' => true,
        ]);
    }

    private function makeClientContact(): Contact
    {
        $company = Company::create([
            'name' => 'Timeline client ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => uniqid('timeline_contact_') . '@client.test',
            'name' => 'Contact timeline',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => now(),
            'email_verification_source' => 'manual',
        ]);
    }

    private function assertAcceptedReservationFinalizesAfterCampaignMutation(string $mutation): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));
        Bus::fake();
        $driver = $this->mock(SmtpCampaignsDriver::class);
        $driver->shouldNotReceive('send');

        $campaign = $this->makePacedSmtpCampaign();
        $contact = $this->makeClientContact();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-accepted-after-' . $mutation,
            'run_at' => now(),
            'status' => 'sending',
            'driver_ref' => 'smtp',
            'started_at' => now(),
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now(),
            'status' => 'accepted',
            'accepted_at' => now(),
            'provider_message_id' => 'accepted-before-' . $mutation . '-change',
        ]);

        if ($mutation === 'sender') {
            $replacement = SenderIdentity::create([
                'name' => 'Accepted replacement sender',
                'email' => uniqid('accepted_replacement_') . '@tcl.test',
            ]);
            $campaign->update(['sender_identity_id' => $replacement->id]);
        } else {
            $campaign->update(['delivery_channel' => 'local']);
        }

        (new SendSmtpReservationJob($reservation->id, now()))->handle(
            app(SmtpSendReservationService::class),
            $driver,
            app(CampaignService::class),
            app(SequenceService::class),
        );

        $this->assertSame('sent', $reservation->fresh()->status);
        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame('accepted-before-' . $mutation . '-change', $recipient->fresh()->provider_message_id);
        $this->assertNotNull($recipient->fresh()->sent_at);
        $this->assertSame('sent', $run->fresh()->status);
        Bus::assertNothingDispatched();
    }
}
