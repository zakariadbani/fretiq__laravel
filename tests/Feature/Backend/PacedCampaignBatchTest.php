<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Services\Campaign\CampaignSchedulerService;
use App\Services\Campaign\PacedCampaignBatchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PacedCampaignBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_batch_limits_companies_orders_by_score_and_snapshots_every_contact(): void
    {
        $campaign = $this->makeCampaign(limit: 2);

        $low = $this->makeCompanyWithContacts(70, 2);
        $firstHigh = $this->makeCompanyWithContacts(90, 2);
        $secondHigh = $this->makeCompanyWithContacts(90, 3);

        $run = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris'),
        );

        $this->assertNotNull($run);
        $this->assertSame('paced-20260720', $run->occurrence_key);
        $this->assertSame(
            [$firstHigh->id, $secondHigh->id],
            $run->companyDispatches()->orderBy('id')->pluck('company_id')->all(),
        );
        $this->assertSame(5, $run->recipients()->count());
        $this->assertDatabaseMissing('campaign_company_dispatches', [
            'campaign_id' => $campaign->id,
            'company_id' => $low->id,
        ]);
    }

    public function test_failed_companies_are_retried_before_higher_scored_new_companies(): void
    {
        $campaign = $this->makeCampaign(limit: 1);
        $failedCompany = $this->makeCompanyWithContacts(50, 2);

        $firstRun = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris'),
        );
        $dispatch = $firstRun->companyDispatches()->firstOrFail();
        $sentRecipient = $dispatch->recipients()->firstOrFail();
        $sentRecipient->update(['status' => 'sent', 'sent_at' => now()]);
        $dispatch->update(['status' => 'failed', 'last_error' => 'transport timeout']);

        $newCompany = $this->makeCompanyWithContacts(100, 1);

        $retryRun = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-21 10:00:00', 'Europe/Paris'),
        );

        $dispatch->refresh();
        $this->assertSame($retryRun->id, $dispatch->current_run_id);
        $this->assertSame('claimed', $dispatch->status);
        $this->assertSame(2, $dispatch->attempts);
        $this->assertSame(1, $retryRun->recipients()->count(), 'Only queued recipients move to the retry run.');
        $this->assertSame($firstRun->id, $sentRecipient->fresh()->campaign_run_id, 'Sent recipients remain in their original run.');
        $this->assertDatabaseMissing('campaign_company_dispatches', [
            'campaign_id' => $campaign->id,
            'company_id' => $newCompany->id,
        ]);
        $this->assertSame($failedCompany->id, $dispatch->company_id);
    }

    public function test_processed_company_never_reopens_but_new_matching_company_joins_later(): void
    {
        $campaign = $this->makeCampaign(limit: 1);
        $processedCompany = $this->makeCompanyWithContacts(90, 1);
        $newCompany = $this->makeCompanyWithContacts(80, 1);

        $firstRun = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris'),
        );
        $firstRun->companyDispatches()->firstOrFail()->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $lateContact = Contact::factory()->create(['company_id' => $processedCompany->id, 'email_verification_status' => 'valid']);

        $secondRun = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-21 10:00:00', 'Europe/Paris'),
        );

        $this->assertSame([$newCompany->id], $secondRun->companyDispatches()->pluck('company_id')->all());
        $this->assertFalse(
            CampaignRecipient::where('contact_id', $lateContact->id)->exists(),
            'Contacts added after a company was processed are not enrolled later.',
        );
    }

    public function test_empty_audience_creates_no_run(): void
    {
        $campaign = $this->makeCampaign(limit: 20);

        $run = app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris'),
        );

        $this->assertNull($run);
        $this->assertSame(0, CampaignRun::where('campaign_id', $campaign->id)->count());
    }

    public function test_duplicate_date_returns_existing_run_without_duplicate_dispatches_or_recipients(): void
    {
        $campaign = $this->makeCampaign(limit: 20);
        $this->makeCompanyWithContacts(80, 2);
        $date = Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris');
        $service = app(PacedCampaignBatchService::class);

        $first = $service->prepareForDate($campaign, $date);
        $second = $service->prepareForDate($campaign->fresh(), $date);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CampaignCompanyDispatch::where('campaign_id', $campaign->id)->count());
        $this->assertSame(2, CampaignRecipient::count());
    }

    public function test_next_business_run_skips_weekend_and_preserves_paris_wall_time_across_dst(): void
    {
        $service = app(CampaignSchedulerService::class);
        $friday = Carbon::parse('2026-03-27 09:00:00', 'UTC'); // 10:00 Europe/Paris

        $next = $service->computeNextBusinessRun($friday, 'Europe/Paris');

        $this->assertSame('2026-03-30 10:00', $next->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertSame('2026-03-30 08:00', $next->format('Y-m-d H:i'));
        $this->assertSame('UTC', $next->timezoneName);
    }

    public function test_scheduler_materializes_one_due_batch_and_advances_cursor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:05:00', 'UTC'));
        $campaign = $this->makeCampaign(
            limit: 1,
            nextRunAt: Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris')->utc(),
        );
        $this->makeCompanyWithContacts(80, 1);
        $service = app(CampaignSchedulerService::class);

        $this->assertSame(1, $service->generateDueRuns());
        $this->assertSame(0, $service->generateDueRuns());

        $campaign->refresh();
        $this->assertSame(
            '2026-07-21 10:00',
            $campaign->next_run_at->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
        );
        $this->assertTrue($campaign->is_active);
        $this->assertSame(1, $campaign->runs()->count());
    }

    public function test_scheduler_does_not_send_paced_campaign_on_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 08:05:00', 'UTC')); // Saturday, 10:05 Paris
        $campaign = $this->makeCampaign(
            limit: 1,
            nextRunAt: Carbon::parse('2026-07-17 10:00:00', 'Europe/Paris')->utc(),
        );
        $this->makeCompanyWithContacts(80, 1);

        $this->assertSame(0, app(CampaignSchedulerService::class)->generateDueRuns());
        $this->assertSame(0, $campaign->runs()->count());
    }

    public function test_weekend_overdue_cursor_waits_for_monday_wall_time_across_dst(): void
    {
        // Friday 10:00 Paris is 09:00 UTC before the spring DST transition.
        // Monday 10:00 Paris is 08:00 UTC after the transition.
        Carbon::setTestNow(Carbon::parse('2026-03-30 06:00:00', 'UTC')); // Monday 08:00 Paris
        $campaign = $this->makeCampaign(
            limit: 1,
            nextRunAt: Carbon::parse('2026-03-27 09:00:00', 'UTC'),
        );
        $this->makeCompanyWithContacts(80, 1);
        $service = app(CampaignSchedulerService::class);

        $this->assertSame(0, $service->generateDueRuns(), 'Monday must not send before the configured 10:00 wall time.');
        $this->assertSame(0, $campaign->runs()->count());
        $this->assertSame(
            '2026-03-30 10:00',
            $campaign->fresh()->next_run_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
        );

        Carbon::setTestNow(Carbon::parse('2026-03-30 08:05:00', 'UTC')); // Monday 10:05 Paris

        $this->assertSame(1, $service->generateDueRuns());
        $this->assertSame(1, $campaign->runs()->count());
        $this->assertSame(
            '2026-03-31 10:00',
            $campaign->fresh()->next_run_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
        );
    }

    /**
     * A blackout date behaves exactly like a weekend in evaluateDue() — mirrors
     * test_scheduler_does_not_send_paced_campaign_on_weekend() above, but with
     * a mid-week blackout date instead, isolating the blackout rule from the
     * weekend rule.
     */
    public function test_scheduler_does_not_send_paced_campaign_on_blackout_date(): void
    {
        Setting::set('planification.blackout_dates', '2026-07-22'); // Wednesday
        Carbon::setTestNow(Carbon::parse('2026-07-22 08:05:00', 'UTC')); // Wednesday, 10:05 Paris
        $campaign = $this->makeCampaign(
            limit: 1,
            nextRunAt: Carbon::parse('2026-07-22 10:00:00', 'Europe/Paris')->utc(),
        );
        $this->makeCompanyWithContacts(80, 1);

        $this->assertSame(0, app(CampaignSchedulerService::class)->generateDueRuns());
        $this->assertSame(0, $campaign->runs()->count());
    }

    /**
     * A stale cursor can span BOTH a weekend AND a blocked weekday (a
     * blackout/holiday) in the same normalisation pass — mirrors
     * test_weekend_overdue_cursor_waits_for_monday_wall_time_across_dst()
     * above, but with Monday also blacked out, so the cursor must normalise
     * all the way to Tuesday (not stop at Monday) while preserving wall time.
     */
    public function test_stale_cursor_spanning_weekend_and_monday_holiday_normalises_to_tuesday_wall_time(): void
    {
        Setting::set('planification.blackout_dates', '2026-07-20'); // Monday holiday
        Carbon::setTestNow(Carbon::parse('2026-07-21 07:55:00', 'UTC')); // Tuesday, 09:55 Paris (before wall time)
        $campaign = $this->makeCampaign(
            limit: 1,
            nextRunAt: Carbon::parse('2026-07-17 10:00:00', 'Europe/Paris')->utc(), // stale Friday cursor
        );
        $this->makeCompanyWithContacts(80, 1);
        $service = app(CampaignSchedulerService::class);

        $this->assertSame(0, $service->generateDueRuns(), 'Tuesday must not send before the configured 10:00 wall time.');
        $this->assertSame(0, $campaign->runs()->count());
        $this->assertSame(
            '2026-07-21 10:00',
            $campaign->fresh()->next_run_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
            'Stale Friday cursor normalises past the weekend AND the Monday holiday, landing on Tuesday.',
        );

        Carbon::setTestNow(Carbon::parse('2026-07-21 08:05:00', 'UTC')); // Tuesday, 10:05 Paris

        $this->assertSame(1, $service->generateDueRuns());
        $this->assertSame(1, $campaign->runs()->count());
    }

    /** prepareManualBatch() on a blackout day throws the reworded French message. */
    public function test_prepare_manual_batch_on_blackout_day_throws_with_new_message(): void
    {
        Setting::set('planification.blackout_dates', '2026-07-22'); // Wednesday
        Carbon::setTestNow(Carbon::parse('2026-07-22 08:00:00', 'UTC')); // Wednesday, 10:00 Paris
        $campaign = $this->makeCampaign(
            limit: 20,
            nextRunAt: Carbon::parse('2026-07-22 10:00:00', 'Europe/Paris')->utc(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Les lots progressifs ne peuvent pas être créés un jour non ouvré.');

        app(PacedCampaignBatchService::class)->prepareManualBatch($campaign, now());
    }

    public function test_daily_company_limit_defaults_to_twenty_in_database_and_allows_explicit_null(): void
    {
        $campaign = $this->makeCampaign(limit: null, includeLimit: false);

        $this->assertSame(20, $campaign->fresh()->daily_company_limit);

        $campaign->update(['daily_company_limit' => null]);

        $this->assertNull($campaign->fresh()->daily_company_limit);
        $this->assertSame(20, $campaign->fresh()->pacedDailyCompanyLimit());
    }

    public function test_due_evaluation_rechecks_locked_campaign_and_skips_stale_paused_model(): void
    {
        $dueAt = Carbon::parse('2026-07-20 08:00:00', 'UTC');
        $campaign = $this->makeCampaign(limit: 1, nextRunAt: $dueAt);
        $this->makeCompanyWithContacts(80, 1);
        $staleActiveModel = $campaign;

        Campaign::whereKey($campaign->id)->update(['is_active' => false]);

        $manualRun = app(PacedCampaignBatchService::class)->prepareForDate(
            $staleActiveModel,
            Carbon::parse('2026-07-20 10:05:00', 'Europe/Paris'),
        );

        $run = app(PacedCampaignBatchService::class)->evaluateDue(
            $staleActiveModel,
            Carbon::parse('2026-07-20 08:05:00', 'UTC'),
        );

        $this->assertNull($manualRun);
        $this->assertNull($run);
        $this->assertSame(0, $campaign->runs()->count());
        $this->assertTrue($campaign->fresh()->next_run_at->equalTo($dueAt));
    }

    public function test_due_evaluation_leaves_future_cursor_then_atomically_advances_due_cursor(): void
    {
        $dueAt = Carbon::parse('2026-07-20 08:00:00', 'UTC'); // 10:00 Paris
        $campaign = $this->makeCampaign(limit: 1, nextRunAt: $dueAt);
        $this->makeCompanyWithContacts(80, 1);
        $service = app(PacedCampaignBatchService::class);

        $early = $service->evaluateDue($campaign, Carbon::parse('2026-07-20 07:55:00', 'UTC'));

        $this->assertNull($early);
        $this->assertTrue($campaign->fresh()->next_run_at->equalTo($dueAt));
        $this->assertSame(0, $campaign->runs()->count());

        $due = $service->evaluateDue($campaign, Carbon::parse('2026-07-20 08:05:00', 'UTC'));

        $this->assertNotNull($due);
        $this->assertSame('paced-20260720', $due->occurrence_key);
        $this->assertSame(
            '2026-07-21 10:00',
            $campaign->fresh()->next_run_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'),
        );
    }

    public function test_active_paced_campaign_requires_first_run_and_positive_company_limit(): void
    {
        $campaign = new Campaign();
        $data = [
            'name' => 'Paced validation',
            'segment_id' => 999,
            'template_id' => 999,
            'sender_identity_id' => 999,
            'schedule_type' => 'paced',
            'is_active' => true,
            'daily_company_limit' => 0,
        ];

        $validator = $campaign->validator($data);

        $this->assertTrue($validator->errors()->has('next_run_at'));
        $this->assertTrue($validator->errors()->has('daily_company_limit'));
        $this->assertSame(20, (new Campaign())->pacedDailyCompanyLimit());
    }

    private function makeCampaign(?int $limit, ?Carbon $nextRunAt = null, bool $includeLimit = true): Campaign
    {
        $segment = Segment::create(['name' => 'Paced audience ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Paced template ' . uniqid(),
            'subject' => 'Hello',
            'html_content' => '<p>Hello</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => 'TCL',
            'email' => uniqid() . '@tcl.test',
        ]);

        $attributes = [
            'name' => 'Paced campaign ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'paced',
            'daily_company_limit' => $limit,
            'next_run_at' => $nextRunAt,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
            'driver' => 'local',
        ];

        if (! $includeLimit) {
            unset($attributes['daily_company_limit']);
        }

        return Campaign::create($attributes);
    }

    private function makeCompanyWithContacts(int $score, int $contactCount): Company
    {
        $company = Company::factory()->client()->create(['ai_score' => $score]);
        Contact::factory()->count($contactCount)->create(['company_id' => $company->id, 'email_verification_status' => 'valid']);

        return $company;
    }
}
