<?php

namespace App\Jobs;

use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnrichCriteriaContactsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 25;

    public int $timeout = 540;

    public int $uniqueFor = 3600;

    public readonly int $approvedAttempts;

    public readonly int $successTarget;

    public readonly string $batchId;

    public readonly string $admissionOwner;

    public function __construct(
        public readonly int $criteriaId,
        int $approvedAttempts = CriteriaContactEnrichmentService::BATCH_SAFETY_MAX,
        string $admissionOwner = '',
        ?string $batchStartedAt = null,
        ?int $successTarget = null,
        ?string $batchId = null,
        ?int $approvedMax = null,
    ) {
        // approvedMax/batchStartedAt are accepted only so already-queued payloads
        // from the previous job shape can be deserialized safely during deploy.
        $this->approvedAttempts = max(0, min(
            $approvedMax ?? $approvedAttempts,
            CriteriaContactEnrichmentService::BATCH_SAFETY_MAX,
        ));
        $this->successTarget = max(0, min(
            $successTarget ?? $this->approvedAttempts,
            CriteriaContactEnrichmentService::BATCH_SAFETY_MAX,
        ));
        $this->batchId = $batchId !== null && Str::isUuid($batchId)
            ? $batchId
            : (string) Str::uuid();
        $this->admissionOwner = $admissionOwner;
    }

    public static function admissionKey(int $criteriaId): string
    {
        return "criteria-contact-enrichment:admission:{$criteriaId}";
    }

    public function uniqueId(): string
    {
        return "criteria-contact-enrichment:{$this->criteriaId}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("criteria-enrichment:{$this->criteriaId}"))
            ->shared()
            ->releaseAfter(30)
            ->expireAfter(570)];
    }

    public function handle(
        CriteriaContactEnrichmentService $eligibility,
        CompanyEnrichmentService $enrichment,
    ): void {
        $releaseAdmission = true;

        try {
            $criteria = ProspectCriteria::find($this->criteriaId);
            if ($criteria === null) {
                return;
            }

            $batchRuns = $this->batchRuns();
            $alreadyAttempted = $batchRuns->pluck('company_id')->map(fn ($id): int => (int) $id);
            $remainingApproved = max(0, $this->approvedAttempts - $batchRuns->count());
            $remainingSuccesses = max(0, $this->successTarget - (int) $batchRuns->sum('successful_enrichments'));
            $callable = min($remainingApproved, $eligibility->snapshot($criteria)['callable_count']);
            if ($callable <= 0 || $remainingSuccesses <= 0) {
                return;
            }

            $candidateIds = $eligibility->eligibleIds($criteria)
                ->reject(fn ($companyId): bool => $alreadyAttempted->contains((int) $companyId));
            foreach ($candidateIds as $companyId) {
                try {
                    $batchRuns = $this->batchRuns();
                    if ($batchRuns->count() >= $this->approvedAttempts
                        || (int) $batchRuns->sum('successful_enrichments') >= $this->successTarget) {
                        break;
                    }

                    $criteria->refresh();
                    $result = $enrichment->enrichForCriteria(
                        (int) $companyId,
                        $criteria,
                        $this->batchId,
                        $this->approvedAttempts,
                        $this->successTarget,
                    );
                    if ($result['outcome'] === 'provider_failed') {
                        break;
                    }
                } catch (CriteriaCompanyNoLongerEligibleException) {
                    continue;
                } catch (EnrichmentInFlightException) {
                    continue;
                } catch (QuotaExhaustedException) {
                    break;
                } catch (QuotaLockUnavailableException $e) {
                    Log::warning('[EnrichCriteriaContactsJob] Quota lock unavailable; batch stopped.', [
                        'criteria_id' => $this->criteriaId,
                    ]);
                    $releaseAdmission = false;
                    throw $e;
                } catch (\Throwable $e) {
                    Log::error('[EnrichCriteriaContactsJob] Systemic enrichment error; batch stopped.', [
                        'criteria_id' => $this->criteriaId,
                        'company_id' => $companyId,
                        'error' => $e->getMessage(),
                    ]);
                    $releaseAdmission = false;
                    throw $e;
                }
            }
        } catch (\Throwable $e) {
            // A queued retry still owns this batch admission. Releasing it here
            // would let another dispatch report success before ShouldBeUnique
            // rejects that newer job in favour of this retry.
            $releaseAdmission = false;

            throw $e;
        } finally {
            if ($releaseAdmission) {
                $this->clearAdmission();
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->clearAdmission();
    }

    private function clearAdmission(): void
    {
        if ($this->admissionOwner !== '') {
            Cache::restoreLock(self::admissionKey($this->criteriaId), $this->admissionOwner)->release();
        }
    }

    private function batchRuns(): \Illuminate\Support\Collection
    {
        return DiscoveryRun::query()
            ->where('type', 'manual')
            ->where('prospect_criteria_id', $this->criteriaId)
            ->where('enrichment_batch_id', $this->batchId)
            ->get(['id', 'company_id', 'successful_enrichments']);
    }
}
