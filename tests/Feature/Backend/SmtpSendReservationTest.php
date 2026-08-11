<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\SmtpSendReservation;
use App\Services\Campaign\SmtpSendReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmtpSendReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'prospecting.smtp.quota_timezone' => 'Europe/Paris',
            'prospecting.smtp.business_start' => '09:00',
            'prospecting.smtp.business_end' => '18:00',
        ]);
    }

    public function test_two_campaigns_on_one_identity_share_hourly_and_daily_caps(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 2, 'smtp_daily_limit' => 2]);
        $a = $this->campaign($identity, ['smtp_daily_email_limit' => 20]);
        $b = $this->campaign($identity, ['smtp_daily_email_limit' => 20]);
        $now = Carbon::parse('2026-08-10 09:00', 'Europe/Paris');
        $service = app(SmtpSendReservationService::class);

        $one = $service->reserve($identity, $a, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 1, $now);
        $two = $service->reserve($identity, $b, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 2, $now);
        $three = $service->reserve($identity, $a, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 3, $now);

        $this->assertTrue($one['ok']);
        $this->assertTrue($two['ok']);
        $this->assertGreaterThanOrEqual(1800, $one['send_at']->diffInSeconds($two['send_at']));
        $this->assertNotSame($two['send_at']->toDateString(), $three['send_at']->toDateString());
    }

    public function test_different_sender_identities_have_independent_caps(): void
    {
        $now = Carbon::parse('2026-08-10 09:00', 'Europe/Paris');
        $a = $this->identity(['smtp_hourly_limit' => 1]);
        $b = $this->identity(['smtp_hourly_limit' => 1]);
        $service = app(SmtpSendReservationService::class);
        $ra = $service->reserve($a, $this->campaign($a), SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 10, $now);
        $rb = $service->reserve($b, $this->campaign($b), SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 11, $now);

        $this->assertTrue($ra['send_at']->equalTo($rb['send_at']));
    }

    public function test_campaign_daily_email_target_is_enforced_independently_of_company_limit(): void
    {
        $identity = $this->identity(['smtp_daily_limit' => 50]);
        $campaign = $this->campaign($identity, ['daily_company_limit' => 99, 'smtp_daily_email_limit' => 1]);
        $now = Carbon::parse('2026-08-10 09:00', 'Europe/Paris');
        $service = app(SmtpSendReservationService::class);
        $one = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 20, $now);
        $two = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 21, $now);

        $this->assertNotSame($one['send_at']->toDateString(), $two['send_at']->toDateString());
    }

    public function test_duplicate_source_reuses_one_reservation(): void
    {
        $identity = $this->identity();
        $campaign = $this->campaign($identity);
        $service = app(SmtpSendReservationService::class);
        $one = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 30);
        $two = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 30);

        $this->assertSame($one['reservation']->id, $two['reservation']->id);
        $this->assertSame(1, SmtpSendReservation::count());
    }

    public function test_reserved_slots_are_evenly_spread_and_roll_into_the_next_business_window(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 2, 'smtp_daily_limit' => 50]);
        $campaign = $this->campaign($identity, ['smtp_daily_email_limit' => 50]);
        $service = app(SmtpSendReservationService::class);
        $friday = Carbon::parse('2026-08-14 17:50', 'Europe/Paris');
        $one = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 40, $friday);
        $two = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 41, $friday);

        $this->assertSame('2026-08-14', $one['send_at']->setTimezone('Europe/Paris')->toDateString());
        $this->assertSame('2026-08-17 09:00', $two['send_at']->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    }

    public function test_zero_campaign_target_pauses_without_consuming_a_slot(): void
    {
        $identity = $this->identity();
        $campaign = $this->campaign($identity, ['smtp_daily_email_limit' => 0]);
        $result = app(SmtpSendReservationService::class)->reserve(
            $identity,
            $campaign,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            50,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('campaign_paused', $result['reason']);
        $this->assertSame(0, SmtpSendReservation::count());
    }

    public function test_concurrent_reservations_cannot_allocate_the_same_identity_slot(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 1]);
        $campaign = $this->campaign($identity);
        $now = Carbon::parse('2026-08-10 09:00', 'Europe/Paris');
        $service = app(SmtpSendReservationService::class);
        $one = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 60, $now);
        $two = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 61, $now);

        $this->assertNotSame($one['send_at']->timestamp, $two['send_at']->timestamp);
        $this->assertSame(2, SmtpSendReservation::distinct('reserved_for')->count('reserved_for'));
    }

    public function test_overdue_reservations_are_claimed_in_order_and_repaced_from_actual_claim_time(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 10, 'smtp_daily_limit' => 50]);
        $campaign = $this->campaign($identity, ['smtp_daily_email_limit' => 20]);
        $service = app(SmtpSendReservationService::class);
        $scheduledAt = Carbon::parse('2026-08-10 09:00:00', 'Europe/Paris');
        $first = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 70, $scheduledAt)['reservation'];
        $second = $service->reserve($identity, $campaign, SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND, 71, $scheduledAt)['reservation'];
        $recoveryTime = Carbon::parse('2026-08-10 12:00:00', 'Europe/Paris');

        $outOfOrder = $service->claimWhenDue($second, $recoveryTime);
        $this->assertFalse($outOfOrder['ok']);
        $this->assertSame('waiting_turn', $outOfOrder['reason']);

        $claimed = $service->claimWhenDue($first, $recoveryTime);
        $this->assertTrue($claimed['ok']);
        $this->assertSame('sending', $first->fresh()->status);
        $this->assertSame($recoveryTime->copy()->utc()->timestamp, $first->fresh()->reserved_for->timestamp);

        $repaced = $service->claimWhenDue($second, $recoveryTime);
        $this->assertFalse($repaced['ok']);
        $this->assertSame('not_due', $repaced['reason']);
        $this->assertGreaterThan($recoveryTime->timestamp, $second->fresh()->reserved_for->timestamp);
    }

    public function test_uncertain_delivery_conservatively_consumes_the_sender_quota(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 10, 'smtp_daily_limit' => 1]);
        $campaign = $this->campaign($identity, ['smtp_daily_email_limit' => 20]);
        $service = app(SmtpSendReservationService::class);
        $now = Carbon::parse('2026-08-10 09:00:00', 'Europe/Paris');

        $first = $service->reserve(
            $identity,
            $campaign,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            80,
            $now,
        )['reservation'];
        $first->update(['status' => 'uncertain']);

        $second = $service->reserve(
            $identity,
            $campaign,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            81,
            $now,
        )['reservation'];

        $this->assertNotSame(
            $first->reserved_for->setTimezone('Europe/Paris')->toDateString(),
            $second->reserved_for->setTimezone('Europe/Paris')->toDateString(),
        );
    }

    public function test_paused_campaign_reservations_do_not_starve_an_active_campaign_on_the_same_sender(): void
    {
        $identity = $this->identity(['smtp_hourly_limit' => 10, 'smtp_daily_limit' => 1]);
        $paused = $this->campaign($identity, ['smtp_daily_email_limit' => 1]);
        $active = $this->campaign($identity, ['smtp_daily_email_limit' => 1]);
        $service = app(SmtpSendReservationService::class);
        $now = Carbon::parse('2026-08-10 09:00:00', 'Europe/Paris');

        $oldSlot = $service->reserve(
            $identity,
            $paused,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            90,
            $now,
        )['reservation'];
        $paused->update(['smtp_daily_email_limit' => 0]);

        $activeSlot = $service->reserve(
            $identity,
            $active,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            91,
            $now,
        )['reservation'];

        $this->assertSame(
            $oldSlot->reserved_for->setTimezone('Europe/Paris')->toDateString(),
            $activeSlot->reserved_for->setTimezone('Europe/Paris')->toDateString(),
        );
    }

    public function test_execution_releases_a_reservation_when_the_campaign_was_paused_after_allocation(): void
    {
        $identity = $this->identity();
        $campaign = $this->campaign($identity, ['smtp_daily_email_limit' => 20]);
        $service = app(SmtpSendReservationService::class);
        $now = Carbon::parse('2026-08-10 09:00:00', 'Europe/Paris');
        $reservation = $service->reserve(
            $identity,
            $campaign,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            92,
            $now,
        )['reservation'];
        $campaign->update(['smtp_daily_email_limit' => 0]);

        $result = $service->claimWhenDue($reservation, $now);

        $this->assertFalse($result['ok']);
        $this->assertSame('campaign_paused', $result['reason']);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_transport_uncertainty_is_recorded_as_non_retryable_and_clears_the_lease(): void
    {
        $identity = $this->identity();
        $campaign = $this->campaign($identity);
        $reservation = app(SmtpSendReservationService::class)->reserve(
            $identity,
            $campaign,
            SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            93,
        )['reservation'];
        $reservation->update(['status' => 'sending', 'lease_expires_at' => now()->addMinutes(5)]);
        $service = app(SmtpSendReservationService::class);

        $this->assertTrue(method_exists($service, 'markUncertainAfterTransport'));
        $service->markUncertainAfterTransport($reservation);

        $this->assertSame('uncertain', $reservation->fresh()->status);
        $this->assertNull($reservation->fresh()->lease_expires_at);
    }

    private function identity(array $attributes = []): SenderIdentity
    {
        return SenderIdentity::create(array_merge([
            'name' => 'Sender ' . uniqid(),
            'email' => uniqid('sender') . '@example.test',
            'is_active' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sender',
            'smtp_password' => 'password',
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ], $attributes));
    }

    private function campaign(SenderIdentity $identity, array $attributes = []): Campaign
    {
        $segment = Segment::create(['name' => 'Segment ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Template ' . uniqid(), 'subject' => 'Subject', 'html_content' => '<p>Hello</p>']);

        return Campaign::create(array_merge([
            'name' => 'Campaign ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $identity->id,
            'schedule_type' => 'one_shot',
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ], $attributes));
    }
}
