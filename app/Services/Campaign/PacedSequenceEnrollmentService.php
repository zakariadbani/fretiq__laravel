<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Jobs\SyncCampaignWaveZohoListJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\SequenceEnrollment;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Atomically enrolls one company-limited business-day batch into a sequence. */
class PacedSequenceEnrollmentService
{
    public function __construct(
        private readonly SegmentService $segmentService,
        private readonly SequenceService $sequenceService,
        private readonly BusinessCalendarService $calendar,
        private readonly CampaignProspectingEligibilityService $prospectingEligibility,
    ) {}

    /**
     * Enable progressive tracking and process the due batch, if any.
     *
     * @return array{companies: int, enrolled: int, skipped: int, excluded: array<string,int>, next_run_at: ?Carbon, processed_due: bool}
     */
    public function activate(Campaign $campaign, ?Carbon $now = null): array
    {
        return $this->evaluateDue($campaign, $now ?? now(), true);
    }

    /**
     * Recheck due state and process at most one occurrence under a campaign row lock.
     *
     * @return array{companies: int, enrolled: int, skipped: int, excluded: array<string,int>, next_run_at: ?Carbon, processed_due: bool}
     */
    public function evaluateDue(Campaign $campaign, Carbon $now, bool $activate = false): array
    {
        return DB::transaction(function () use ($campaign, $now, $activate): array {
            /** @var Campaign $locked */
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            // Serialize the shared sequence before any consistent audience/history
            // read (MySQL REPEATABLE READ), including campaigns sharing a sequence.
            if ($locked->delivery_channel === 'smtp' && $locked->sequence_id !== null) {
                \App\Models\Sequence::query()->lockForUpdate()->findOrFail($locked->sequence_id);
            }

            $this->assertConfigured($locked);

            if ($activate) {
                $locked->update([
                    'sequence_auto_enroll_enabled' => true,
                    'is_active' => true,
                ]);
            }

            if (! $locked->is_active || ! $locked->sequence_auto_enroll_enabled || $locked->next_run_at === null) {
                return $this->result($locked, false);
            }

            $timezone = $locked->scheduleTimezone();
            $localNow = $now->copy()->setTimezone($timezone);
            $effectiveRunAt = $locked->next_run_at->copy();

            // Stale and blocked-day (weekend/blackout) cursors move to the next
            // valid local business occurrence while retaining the configured
            // local wall time.
            while ($this->calendar->isBlockedDate($effectiveRunAt->copy()->setTimezone($timezone))
                || $effectiveRunAt->copy()->setTimezone($timezone)->toDateString() < $localNow->toDateString()) {
                $effectiveRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            }

            if (! $effectiveRunAt->equalTo($locked->next_run_at)) {
                $locked->update(['next_run_at' => $effectiveRunAt]);
            }

            if ($this->calendar->isBlockedDate($localNow) || $effectiveRunAt->gt($now)) {
                return $this->result($locked, false);
            }

            $locked->loadMissing(['segment', 'sequence']);
            $firstStep = $locked->sequence->steps()->orderBy('step_no')->firstOrFail();

            $contacts = $this->segmentService->resolve($locked->segment, $locked->emailVerificationPolicy());
            $batch = $this->selectBatch($locked, $contacts, $locked->pacedDailyCompanyLimit());
            $skipped = $batch['already_enrolled_contacts'];
            $excluded = $batch['excluded'];

            $enrolled = 0;
            $waveContacts = collect();
            foreach ($batch['contacts'] as $contact) {
                $enrollment = $this->sequenceService->enroll($locked->sequence, $contact, $locked);
                if ($enrollment?->wasRecentlyCreated) {
                    $usesLiveZohoWave = in_array($locked->delivery_channel, [null, 'zoho'], true)
                        && config('services.zoho.driver', 'local') === 'zoho';
                    // Even under Zoho-wave management, next_send_at must stay a real,
                    // honest timestamp — never null — so a stalled wave pipeline
                    // remains visible to SequenceService::processDue() and
                    // sequences:repair-stalled instead of disappearing forever.
                    // SMTP dispatch is still guarded off for these enrollments via
                    // SequenceService::canSendViaSmtp() and SendSequenceStepJob's own
                    // zoho-wave check, so this is safe.
                    $enrollment->update([
                        'next_send_at' => $usesLiveZohoWave ? $effectiveRunAt : now(),
                    ]);
                    $enrolled++;
                    $waveContacts->push($contact);
                } else {
                    $skipped++;
                }
            }

            if ($waveContacts->isNotEmpty() && in_array($locked->delivery_channel, [null, 'zoho'], true)
                && config('services.zoho.driver', 'local') === 'zoho') {
                $lastWaveNumber = $locked->runs()->where('occurrence_key', 'like', 'sequence-wave-%')->pluck('occurrence_key')->map(fn (string $key): int => (int) substr($key, strlen('sequence-wave-')))->max() ?? 0;
                $waveRun = CampaignRun::create([
                    'campaign_id' => $locked->id,
                    'sequence_step_id' => $firstStep->id,
                    'occurrence_key' => 'sequence-wave-' . str_pad((string) ($lastWaveNumber + 1), 6, '0', STR_PAD_LEFT),
                    'run_at' => $effectiveRunAt,
                    'status' => 'prepared',
                    'driver_ref' => 'zoho-wave-pending',
                ]);
                foreach ($waveContacts as $contact) {
                    CampaignRecipient::create(['campaign_run_id' => $waveRun->id, 'contact_id' => $contact->id, 'status' => 'queued']);
                }
                $waveRunId = $waveRun->id;
                DB::afterCommit(function () use ($waveRunId): void {
                    try {
                        SyncCampaignWaveZohoListJob::dispatch($waveRunId);
                    } catch (\Throwable $exception) {
                        Log::channel('campaign')->error('[PacedSequenceEnrollmentService] Unable to dispatch Zoho wave mirror.', ['run_id' => $waveRunId, 'exception' => $exception->getMessage()]);
                    }
                });
            }
            $nextRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            while ($nextRunAt->copy()->setTimezone($timezone)->toDateString() <= $localNow->toDateString()) {
                $nextRunAt = $this->computeNextBusinessRun($nextRunAt, $timezone);
            }
            $locked->update(['next_run_at' => $nextRunAt]);

            Log::channel('campaign')->info('[PacedSequenceEnrollmentService] Batch evaluated.', [
                'campaign_id' => $locked->id,
                'excluded' => $excluded,
                'scanned_companies' => $batch['scanned_companies'],
            ]);

            return [
                'companies' => $batch['contacts']->pluck('company_id')->unique()->count(),
                'enrolled' => $enrolled,
                'skipped' => $skipped,
                'excluded' => $excluded,
                'next_run_at' => $nextRunAt,
                'processed_due' => true,
            ];
        }, 3);
    }

    /**
     * Advance one occurrence forward (business day, skipping weekends/blackout
     * dates) while preserving local wall time and DST.
     *
     * The addDay() MUST run BEFORE shiftToAllowed(), never after — see the
     * identical warning on PacedCampaignBatchService::computeNextBusinessRun().
     * shiftToAllowed() is idempotent on an already-allowed input, so skipping
     * the addDay() would make the unbounded `while` loop in evaluateDue() spin
     * forever whenever the cursor already sits on an allowed day.
     */
    public function computeNextBusinessRun(Carbon $from, string $timezone): Carbon
    {
        $next = $from->copy()->setTimezone($timezone)->addDay();

        return $this->calendar->shiftToAllowed($next, $timezone);
    }

    /**
     * Rank resolved segment contacts by company (excluding contacts already
     * enrolled in this sequence, any status), then delegate to the eligibility
     * scan when the campaign is opted into continuous prospecting, or simply
     * cap the ranked groups at $limit companies otherwise — the pre-existing
     * plain paced-sequence behaviour of enrolling every matching contact in
     * the top N companies, unfiltered.
     *
     * Ranking runs once; both evaluateDue() and previewDailyBatch() consume
     * the same ranked groups, and dispatchPreflight() reuses this method too
     * so the "audience" preview never re-implements the sort.
     *
     * @return array{contacts:Collection<int,Contact>,excluded:array<string,int>,scanned_companies:int,already_enrolled_contacts:int,scan_capped:bool}
     */
    public function selectBatch(Campaign $campaign, Collection $contacts, int $limit): array
    {
        $existingContactIds = SequenceEnrollment::query()
            ->where('sequence_id', $campaign->sequence_id)
            ->pluck('contact_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
        $alreadyEnrolledContacts = $contacts->filter(fn (Contact $c): bool => isset($existingContactIds[$c->id]))->count();

        $rankedGroups = $contacts
            ->reject(fn (Contact $c): bool => isset($existingContactIds[$c->id]) || ! $c->company_id)
            ->groupBy('company_id')
            ->sort(function (Collection $left, Collection $right): int {
                $leftContact = $left->first();
                $rightContact = $right->first();
                $leftScore = $leftContact?->company?->ai_score;
                $rightScore = $rightContact?->company?->ai_score;

                if ($leftScore === null && $rightScore !== null) {
                    return 1;
                }
                if ($leftScore !== null && $rightScore === null) {
                    return -1;
                }

                $byScore = $rightScore <=> $leftScore;

                return $byScore !== 0
                    ? $byScore
                    : $leftContact->company_id <=> $rightContact->company_id;
            });

        if ($this->prospectingEligibility->optedIn($campaign)) {
            $selection = $this->prospectingEligibility->select($campaign, $rankedGroups, $limit);

            return [
                'contacts' => $selection['contacts'],
                'excluded' => $selection['excluded'],
                'scanned_companies' => $selection['scanned_companies'],
                'already_enrolled_contacts' => $alreadyEnrolledContacts,
                'scan_capped' => $selection['scan_capped'],
            ];
        }

        $limited = $rankedGroups->take($limit);

        return [
            'contacts' => $limited->flatten(1)->values(),
            'excluded' => [],
            'scanned_companies' => $limited->count(),
            'already_enrolled_contacts' => $alreadyEnrolledContacts,
            'scan_capped' => false,
        ];
    }

    /**
     * Read-only preview of what the next paced sequence enrollment batch would
     * select right now. No enroll, no CampaignRun/CampaignRecipient creation,
     * no next_run_at write, no lock.
     *
     * @return array{company_count: int, contact_count: int, research_pool_count:int, next_batch_company_count:int, next_batch_contact_count:int, excluded_reasons:array<string,int>, scanned_companies:int, next_processing_at:?Carbon, scan_capped:bool}
     */
    public function previewDailyBatch(Campaign $campaign): array
    {
        $campaign->loadMissing(['segment', 'sequence']);
        if ($campaign->segment === null || $campaign->sequence === null || $campaign->next_run_at === null) {
            return ['company_count' => 0, 'contact_count' => 0, 'research_pool_count' => 0, 'next_batch_company_count' => 0, 'next_batch_contact_count' => 0, 'excluded_reasons' => [], 'scanned_companies' => 0, 'next_processing_at' => null, 'scan_capped' => false];
        }

        // sendNow() calls activate() -> evaluateDue(..., true). The $activate
        // flag only forces is_active/sequence_auto_enroll_enabled true and
        // thereby bypasses the early-return gate built on those two columns
        // (~:58) — it does NOT bypass the blocked-date / due-time gate below
        // (~:78: `isBlockedDate($localNow) || $effectiveRunAt->gt($now)`),
        // which runs unconditionally after cursor normalization. Preview must
        // apply that same gate or it will show N while sendNow() returns
        // zeros right now.
        $now = now();
        $timezone = $campaign->scheduleTimezone();
        $localNow = $now->copy()->setTimezone($timezone);
        $effectiveRunAt = $campaign->next_run_at->copy();

        while ($this->calendar->isBlockedDate($effectiveRunAt->copy()->setTimezone($timezone))
            || $effectiveRunAt->copy()->setTimezone($timezone)->toDateString() < $localNow->toDateString()) {
            $effectiveRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
        }

        $dueNow = ! $this->calendar->isBlockedDate($localNow) && $effectiveRunAt->lte($now);
        if (! $dueNow && ! $this->prospectingEligibility->optedIn($campaign)) {
            return ['company_count' => 0, 'contact_count' => 0, 'research_pool_count' => 0, 'next_batch_company_count' => 0, 'next_batch_contact_count' => 0, 'excluded_reasons' => [], 'scanned_companies' => 0, 'next_processing_at' => $effectiveRunAt, 'scan_capped' => false];
        }

        $research = $this->segmentService->resolve($campaign->segment, $campaign->emailVerificationPolicy());
        $limit = $campaign->pacedDailyCompanyLimit();
        $batch = $this->selectBatch($campaign, $research, $limit);
        $companyCount = $batch['contacts']->pluck('company_id')->unique()->count();
        $contactCount = $batch['contacts']->count();

        return [
            'company_count' => $dueNow ? $companyCount : 0,
            'contact_count' => $dueNow ? $contactCount : 0,
            'next_batch_company_count' => $companyCount,
            'next_batch_contact_count' => $contactCount,
            'research_pool_count' => $research->count(),
            'excluded_reasons' => $batch['excluded'],
            'scanned_companies' => $batch['scanned_companies'],
            'next_processing_at' => $effectiveRunAt,
            'scan_capped' => $batch['scan_capped'],
        ];
    }

    private function assertConfigured(Campaign $campaign): void
    {
        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence_enrollment_mode !== 'paced') {
            throw new \InvalidArgumentException('Cette campagne n’est pas une séquence progressive.');
        }
        if ($campaign->next_run_at === null || (int) $campaign->daily_company_limit < 1) {
            throw new \InvalidArgumentException('Définissez le premier lot et le nombre de sociétés par jour.');
        }

        $campaign->loadMissing(['segment', 'senderIdentity', 'sequence']);
        if ($campaign->segment === null || $campaign->sequence === null || $campaign->senderIdentity === null) {
            throw new \InvalidArgumentException('Sélectionnez un segment, un expéditeur et une séquence avant de démarrer.');
        }
        if (! $campaign->sequence->is_active) {
            throw new \InvalidArgumentException('La séquence est inactive.');
        }
        if ($campaign->sequence->steps()->count() === 0) {
            throw new \InvalidArgumentException("La séquence n'a aucune étape.");
        }
    }

    /** @return array{companies: int, enrolled: int, skipped: int, excluded: array<string,int>, next_run_at: ?Carbon, processed_due: bool} */
    private function result(Campaign $campaign, bool $processedDue): array
    {
        return [
            'companies' => 0,
            'enrolled' => 0,
            'skipped' => 0,
            'excluded' => [],
            'next_run_at' => $campaign->next_run_at,
            'processed_due' => $processedDue,
        ];
    }
}
