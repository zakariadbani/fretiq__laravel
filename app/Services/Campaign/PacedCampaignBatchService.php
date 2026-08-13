<?php

namespace App\Services\Campaign;

use App\Exceptions\PacedCampaignBatchAlreadyExistsException;
use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Materialises one company-limited, contact-frozen batch for a paced campaign. */
class PacedCampaignBatchService
{
    public function __construct(
        private readonly SegmentService $segmentService,
        private readonly BusinessCalendarService $calendar,
    ) {}

    /**
     * Create or retrieve the deterministic batch for one local business date.
     * Returns null when the date is a weekend or there is no retry/new audience.
     */
    public function prepareForDate(Campaign $campaign, Carbon $localBusinessDate): ?CampaignRun
    {
        return DB::transaction(function () use ($campaign, $localBusinessDate): ?CampaignRun {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! $lockedCampaign->is_active || $lockedCampaign->schedule_type !== 'paced') {
                return null;
            }

            $localDate = $localBusinessDate->copy()->setTimezone($lockedCampaign->scheduleTimezone());

            if ($this->calendar->isBlockedDate($localDate)) {
                return null;
            }

            return $this->prepareLockedForDate($lockedCampaign, $localDate);
        }, 3);
    }

    /**
     * Atomically create today's manual occurrence and advance the cursor.
     * Throws typed validation/no-op exceptions so the controller can distinguish errors from an already-created warning.
     */
    public function prepareManualBatch(Campaign $campaign, Carbon $now): CampaignRun
    {
        return DB::transaction(function () use ($campaign, $now): CampaignRun {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if ($lockedCampaign->schedule_type !== 'paced') {
                throw new \InvalidArgumentException('Cette action est réservée aux campagnes progressives.');
            }

            $timezone = $lockedCampaign->scheduleTimezone();
            $localNow = $now->copy()->setTimezone($timezone);
            if ($this->calendar->isBlockedDate($localNow)) {
                throw new \InvalidArgumentException('Les lots progressifs ne peuvent pas être créés un jour non ouvré.');
            }

            $occurrenceKey = 'paced-' . $localNow->format('Ymd');
            if ($lockedCampaign->runs()->where('occurrence_key', $occurrenceKey)->exists()) {
                throw new PacedCampaignBatchAlreadyExistsException('Le lot du jour a déjà été créé pour cette campagne.');
            }

            $lockedCampaign->update(['is_active' => true]);
            $run = $this->prepareLockedForDate($lockedCampaign, $localNow);
            if ($run === null) {
                throw new \InvalidArgumentException('Aucune nouvelle société éligible ni société en échec à traiter aujourd’hui.');
            }

            $configuredLocal = $lockedCampaign->next_run_at->copy()->setTimezone($timezone);
            $todayAtConfiguredTime = $localNow->copy()->setTime(
                $configuredLocal->hour,
                $configuredLocal->minute,
                $configuredLocal->second,
            )->utc();
            $lockedCampaign->update([
                'next_run_at' => $this->computeNextBusinessRun($todayAtConfiguredTime, $timezone),
            ]);

            return $run;
        }, 3);
    }

    /**
     * Atomically recheck and evaluate one scheduler candidate under the campaign lock.
     * Cursor normalization, optional batch creation, and cursor advancement commit together.
     */
    public function evaluateDue(Campaign $campaign, Carbon $now): ?CampaignRun
    {
        return DB::transaction(function () use ($campaign, $now): ?CampaignRun {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! $lockedCampaign->is_active
                || $lockedCampaign->schedule_type !== 'paced'
                || $lockedCampaign->next_run_at === null
                || $lockedCampaign->next_run_at->gt($now)) {
                return null;
            }

            $timezone = $lockedCampaign->scheduleTimezone();
            $localNow = $now->copy()->setTimezone($timezone);

            if ($this->calendar->isBlockedDate($localNow)) {
                return null;
            }

            // Normalize stale cursors to today's business occurrence before
            // comparing wall time. This prevents a Friday outage from sending
            // Monday's 10:00 batch at 08:00.
            $effectiveRunAt = $lockedCampaign->next_run_at->copy();
            while ($effectiveRunAt->copy()->setTimezone($timezone)->toDateString() < $localNow->toDateString()) {
                $effectiveRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            }

            if (! $effectiveRunAt->equalTo($lockedCampaign->next_run_at)) {
                $lockedCampaign->update(['next_run_at' => $effectiveRunAt]);
            }

            if ($effectiveRunAt->gt($now)) {
                return null;
            }

            $run = $this->prepareLockedForDate($lockedCampaign, $localNow);
            $nextRun = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            while ($nextRun->copy()->setTimezone($timezone)->toDateString() <= $localNow->toDateString()) {
                $nextRun = $this->computeNextBusinessRun($nextRun, $timezone);
            }

            // Empty audiences advance too; the active campaign can pick up
            // companies entering its dynamic segment on a later business day.
            $lockedCampaign->update(['next_run_at' => $nextRun]);

            return $run;
        }, 3);
    }

    /**
     * Advance one occurrence forward (business day, skipping weekends/blackout
     * dates) while preserving local wall time.
     *
     * The contract is "advance at least one occurrence" — the addDay() MUST run
     * BEFORE shiftToAllowed(), never after. shiftToAllowed() is idempotent on an
     * already-allowed input (see BusinessCalendarService), so calling it on
     * $from directly (without the addDay() first) would return $from unchanged
     * whenever $from already falls on an allowed day, making the unbounded
     * `while` loops in evaluateDue() (normalizing a stale cursor) spin forever.
     */
    public function computeNextBusinessRun(Carbon $from, string $timezone): Carbon
    {
        $next = $from->copy()->setTimezone($timezone)->addDay();

        return $this->calendar->shiftToAllowed($next, $timezone);
    }

    /** Campaign row must already be locked by the surrounding transaction. */
    private function prepareLockedForDate(Campaign $lockedCampaign, Carbon $localDate): ?CampaignRun
    {
        $occurrenceKey = 'paced-' . $localDate->format('Ymd');
        $existing = CampaignRun::query()
            ->where('campaign_id', $lockedCampaign->id)
            ->where('occurrence_key', $occurrenceKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $limit = $lockedCampaign->pacedDailyCompanyLimit();

        // Terminal failed ledgers have no retry work. Reconcile in one statement,
        // then lock only the bounded set that can consume today's quota.
        CampaignCompanyDispatch::query()
            ->where('campaign_id', $lockedCampaign->id)
            ->where('status', 'failed')
            ->whereDoesntHave('recipients', fn ($query) => $query->where('status', 'queued'))
            ->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

        $retryDispatches = CampaignCompanyDispatch::query()
            ->where('campaign_id', $lockedCampaign->id)
            ->where('status', 'failed')
            ->whereHas('recipients', fn ($query) => $query->where('status', 'queued'))
            ->orderBy('claimed_at')
            ->orderBy('id')
            ->limit($limit)
            ->lockForUpdate()
            ->get();

        $remaining = $limit - $retryDispatches->count();
        $newCompanyGroups = $remaining > 0
            ? $this->newCompanyGroups($lockedCampaign, $remaining)
            : collect();

        if ($retryDispatches->isEmpty() && $newCompanyGroups->isEmpty()) {
            return null;
        }

        $run = CampaignRun::create([
            'campaign_id' => $lockedCampaign->id,
            'occurrence_key' => $occurrenceKey,
            'run_at' => $localDate->copy()->utc(),
            'status' => 'scheduled',
        ]);

        foreach ($retryDispatches as $dispatch) {
            $dispatch->update([
                'current_run_id' => $run->id,
                'status' => 'claimed',
                'attempts' => $dispatch->attempts + 1,
                'last_error' => null,
                'claimed_at' => now(),
                'processed_at' => null,
            ]);

            $dispatch->recipients()
                ->where('status', 'queued')
                ->update(['campaign_run_id' => $run->id]);
        }

        foreach ($newCompanyGroups as $companyId => $contacts) {
            $dispatch = CampaignCompanyDispatch::create([
                'campaign_id' => $lockedCampaign->id,
                'company_id' => $companyId,
                'current_run_id' => $run->id,
                'status' => 'claimed',
                'attempts' => 1,
                'claimed_at' => now(),
            ]);

            /** @var Contact $contact */
            foreach ($contacts as $contact) {
                CampaignRecipient::create([
                    'campaign_run_id' => $run->id,
                    'company_dispatch_id' => $dispatch->id,
                    'contact_id' => $contact->id,
                    'status' => 'queued',
                ]);
            }
        }

        return $run;
    }

    /**
     * Resolve the current compliant audience, exclude every company already in
     * the campaign ledger, then rank company groups by score and stable ID.
     *
     * @return Collection<int, Collection<int, Contact>> keyed by company ID
     */
    private function newCompanyGroups(Campaign $campaign, int $limit): Collection
    {
        $campaign->loadMissing('segment');

        if ($campaign->segment === null) {
            return collect();
        }

        $knownCompanyIds = CampaignCompanyDispatch::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('company_id')
            ->mapWithKeys(fn (int $companyId): array => [$companyId => true])
            ->all();

        return $this->segmentService
            ->resolve($campaign->segment, $campaign->emailVerificationPolicy())
            ->reject(fn (Contact $contact): bool => isset($knownCompanyIds[$contact->company_id]))
            ->groupBy('company_id')
            ->sort(function (Collection $left, Collection $right): int {
                $leftContact = $left->first();
                $rightContact = $right->first();
                $leftScore = $leftContact?->company?->ai_score;
                $rightScore = $rightContact?->company?->ai_score;

                $byScore = ($rightScore ?? PHP_INT_MIN) <=> ($leftScore ?? PHP_INT_MIN);

                return $byScore !== 0
                    ? $byScore
                    : $leftContact->company_id <=> $rightContact->company_id;
            })
            ->take($limit);
    }
}
