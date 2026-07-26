<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Jobs\SyncCampaignWaveZohoListJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\SequenceEnrollment;
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
    ) {}

    /**
     * Enable progressive tracking and process the due batch, if any.
     *
     * @return array{companies: int, enrolled: int, skipped: int, next_run_at: ?Carbon, processed_due: bool}
     */
    public function activate(Campaign $campaign, ?Carbon $now = null): array
    {
        return $this->evaluateDue($campaign, $now ?? now(), true);
    }

    /**
     * Recheck due state and process at most one occurrence under a campaign row lock.
     *
     * @return array{companies: int, enrolled: int, skipped: int, next_run_at: ?Carbon, processed_due: bool}
     */
    public function evaluateDue(Campaign $campaign, Carbon $now, bool $activate = false): array
    {
        return DB::transaction(function () use ($campaign, $now, $activate): array {
            /** @var Campaign $locked */
            $locked = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            $this->assertConfigured($locked);

            if ($activate) {
                $locked->update([
                    'sequence_auto_enroll_enabled' => true,
                    'is_active' => true,
                ]);
            }

            if (! $locked->sequence_auto_enroll_enabled || $locked->next_run_at === null) {
                return $this->result($locked, false);
            }

            $timezone = $locked->scheduleTimezone();
            $localNow = $now->copy()->setTimezone($timezone);
            $effectiveRunAt = $locked->next_run_at->copy();

            // Stale and weekend cursors move to the next valid local business
            // occurrence while retaining the configured local wall time.
            while ($effectiveRunAt->copy()->setTimezone($timezone)->isWeekend()
                || $effectiveRunAt->copy()->setTimezone($timezone)->toDateString() < $localNow->toDateString()) {
                $effectiveRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            }

            if (! $effectiveRunAt->equalTo($locked->next_run_at)) {
                $locked->update(['next_run_at' => $effectiveRunAt]);
            }

            if ($localNow->isWeekend() || $effectiveRunAt->gt($now)) {
                return $this->result($locked, false);
            }

            $locked->loadMissing(['segment', 'sequence']);
            $contacts = $this->segmentService->resolve($locked->segment);
            $existingContactIds = SequenceEnrollment::query()
                ->where('sequence_id', $locked->sequence_id)
                ->pluck('contact_id')
                ->mapWithKeys(fn ($id): array => [(int) $id => true])
                ->all();

            $skipped = $contacts
                ->filter(fn (Contact $contact): bool => isset($existingContactIds[$contact->id]))
                ->count();

            $groups = $contacts
                ->reject(fn (Contact $contact): bool => isset($existingContactIds[$contact->id]) || ! $contact->company_id)
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
                })
                ->take($locked->pacedDailyCompanyLimit());

            $enrolled = 0;
            $waveContacts = collect();
            foreach ($groups as $companyContacts) {
                foreach ($companyContacts as $contact) {
                    $enrollment = $this->sequenceService->enroll($locked->sequence, $contact, $locked);
                    if ($enrollment?->wasRecentlyCreated) {
                        $enrolled++;
                        $waveContacts->push($contact);
                    } else {
                        $skipped++;
                    }
                }
            }

            if ($waveContacts->isNotEmpty()) {
                $lastWaveNumber = $locked->runs()->where('occurrence_key', 'like', 'sequence-wave-%')->pluck('occurrence_key')->map(fn (string $key): int => (int) substr($key, strlen('sequence-wave-')))->max() ?? 0;
                $waveRun = CampaignRun::create([
                    'campaign_id' => $locked->id,
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
                        Log::error('[PacedSequenceEnrollmentService] Unable to dispatch Zoho wave mirror.', ['run_id' => $waveRunId, 'exception' => $exception->getMessage()]);
                    }
                });
            }
            $nextRunAt = $this->computeNextBusinessRun($effectiveRunAt, $timezone);
            while ($nextRunAt->copy()->setTimezone($timezone)->toDateString() <= $localNow->toDateString()) {
                $nextRunAt = $this->computeNextBusinessRun($nextRunAt, $timezone);
            }
            $locked->update(['next_run_at' => $nextRunAt]);

            return [
                'companies' => $groups->count(),
                'enrolled' => $enrolled,
                'skipped' => $skipped,
                'next_run_at' => $nextRunAt,
                'processed_due' => true,
            ];
        }, 3);
    }

    /** Advance one Monday-Friday occurrence while preserving local wall time and DST. */
    public function computeNextBusinessRun(Carbon $from, string $timezone): Carbon
    {
        $next = $from->copy()->setTimezone($timezone)->addDay();
        while ($next->isWeekend()) {
            $next->addDay();
        }

        return $next->utc();
    }

    private function assertConfigured(Campaign $campaign): void
    {
        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence_enrollment_mode !== 'paced') {
            throw new \InvalidArgumentException('Cette campagne n’est pas une séquence progressive.');
        }
        if ($campaign->driver === 'zoho' || config('services.zoho.driver', 'local') === 'zoho') {
            throw new \InvalidArgumentException('Les séquences progressives sont disponibles uniquement avec le pilote local.');
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

    /** @return array{companies: int, enrolled: int, skipped: int, next_run_at: ?Carbon, processed_due: bool} */
    private function result(Campaign $campaign, bool $processedDue): array
    {
        return [
            'companies' => 0,
            'enrolled' => 0,
            'skipped' => 0,
            'next_run_at' => $campaign->next_run_at,
            'processed_due' => $processedDue,
        ];
    }
}
