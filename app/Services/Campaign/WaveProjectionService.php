<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Models\Campaign;
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
    public function projectNext(Campaign $campaign, ?int $segmentId = null, ?int $dailyLimit = null): array
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

        $eligibleCompanies = $this->segmentService->resolve($segment)
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
            });

        $nextWave = $eligibleCompanies->take($limit);
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
