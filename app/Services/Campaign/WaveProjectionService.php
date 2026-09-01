<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SequenceEnrollment;
use Illuminate\Support\Collection;

/** Projects the next progressive-sequence company batch without enrolling it. */
class WaveProjectionService
{
    public function __construct(private readonly SegmentService $segmentService) {}

    /**
     * @return array{
     *     eligible_remaining_companies: int,
     *     next_wave_companies: int,
     *     next_wave_contacts: int,
     *     projected_remaining_waves: int,
     *     daily_limit: int,
     *     next_run_at: ?string
     * }
     */
    public function projectNext(Campaign $campaign, ?int $segmentId = null, ?int $dailyLimit = null, ?string $policy = null): array
    {
        $segment = $segmentId === null ? $campaign->segment : Segment::find($segmentId);
        $limit = $dailyLimit === null ? $campaign->pacedDailyCompanyLimit() : max(1, $dailyLimit);

        if ($segment === null) {
            return [
                'eligible_remaining_companies' => 0,
                'next_wave_companies' => 0,
                'next_wave_contacts' => 0,
                'projected_remaining_waves' => 0,
                'daily_limit' => $limit,
                'next_run_at' => $this->formatNextRunAt($campaign),
            ];
        }

        $existingContactIds = $campaign->sequence_id
            ? SequenceEnrollment::query()
                ->where('sequence_id', $campaign->sequence_id)
                ->pluck('contact_id')
                ->mapWithKeys(fn ($id): array => [(int) $id => true])
                ->all()
            : [];

        // Plain paced campaigns (non-sequence) track "already claimed" per
        // company via CampaignCompanyDispatch, not SequenceEnrollment —
        // exclude those too so the projection reflects the real next wave.
        $knownCompanyIds = CampaignCompanyDispatch::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('company_id')
            ->mapWithKeys(fn (int $companyId): array => [$companyId => true])
            ->all();

        // Mirror PacedCampaignBatchService::previewManualBatch()'s retry
        // accounting: the real batch reserves quota for failed dispatches
        // with a queued retry recipient before enrolling new companies.
        // Sequence campaigns never create CampaignCompanyDispatch rows, so
        // this naturally yields 0 there and behavior is unchanged.
        // limit()->count() would strip the limit (Laravel aggregate queries
        // drop orders/limit/offset), so cap via get()->count() to match
        // previewManualBatch() exactly.
        $retryCount = CampaignCompanyDispatch::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'failed')
            ->whereHas('recipients', fn ($query) => $query->where('status', 'queued'))
            ->limit($limit)
            ->get(['id'])
            ->count();

        $remaining = max(0, $limit - $retryCount);

        $eligibleCompanies = $this->segmentService->resolve($segment, $policy ?? $campaign->emailVerificationPolicy())
            ->reject(fn (Contact $contact): bool => isset($existingContactIds[$contact->id]) || isset($knownCompanyIds[$contact->company_id]) || ! $contact->company_id)
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

        $nextWave = $eligibleCompanies->take($remaining);
        $remainingCompanies = $eligibleCompanies->count();

        return [
            'eligible_remaining_companies' => $remainingCompanies,
            'next_wave_companies' => $nextWave->count(),
            'next_wave_contacts' => $nextWave->sum(fn (Collection $contacts): int => $contacts->count()),
            'projected_remaining_waves' => (int) ceil($remainingCompanies / $limit),
            'daily_limit' => $limit,
            'next_run_at' => $this->formatNextRunAt($campaign),
        ];
    }

    private function formatNextRunAt(Campaign $campaign): ?string
    {
        return $campaign->next_run_at
            ? $campaign->next_run_at->copy()->setTimezone($campaign->scheduleTimezone())->format('d/m/Y H:i')
            : null;
    }
}
