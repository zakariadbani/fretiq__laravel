<?php

namespace App\Jobs\Zoho;

use App\Models\Zoho\ZohoBulkReadJob;
use App\Services\Zoho\V2\Bulk\ZohoBulkRunTerminator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RecoverZohoBulkTerminalizationsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public readonly string $retryDeadline;

    private ?int $activeRootId = null;

    private ?string $activeOwner = null;

    /** @var list<string> */
    private const REASONS = ['kill_switch', 'delivery_exhausted'];

    private const ROOT_BATCH_SIZE = 5;

    public function __construct(?string $retryDeadline = null)
    {
        $this->tries = max(1, (int) config('zoho-v2.retry.max_attempts', 5));
        $this->timeout = max(60, (int) config('zoho-v2.bulk.delivery_timeout_seconds', 900));
        $this->retryDeadline = $retryDeadline
            ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toIso8601String();
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        return 'bulk-terminalization-recovery';
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.bulk.lease_seconds', 1500));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('zoho-v2.retry.backoff_seconds', [60, 300, 900, 1800]);
    }

    public function retryUntil(): \DateTime
    {
        return CarbonImmutable::parse($this->retryDeadline)->toDateTime();
    }

    public function handle(ZohoBulkRunTerminator $terminator): void
    {
        $rootIds = ZohoBulkReadJob::query()
            ->where('page_key', 'root')
            ->where('status', 'terminalizing')
            ->whereIn('terminalization_reason', self::REASONS)
            ->whereNotNull('terminalization_context')
            ->whereNotNull('retry_after')
            ->where('retry_after', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('retry_after')
            ->orderBy('id')
            ->limit(self::ROOT_BATCH_SIZE)
            ->pluck('id');

        foreach ($rootIds as $rootId) {
            $owner = 'bulk-recovery:'.Str::uuid();
            $claim = $this->claimRoot((int) $rootId, $owner);
            if ($claim === null) {
                continue;
            }

            $this->activeRootId = (int) $rootId;
            $this->activeOwner = $owner;
            try {
                $completed = $terminator->terminate(
                    $claim['batch_id'],
                    $claim['module'],
                    $claim['correlation_id'],
                    $claim['run_owner'],
                    $owner,
                    $claim['delivery_generation'],
                    $claim['reason'],
                );
                if (! $completed) {
                    $this->releaseClaim((int) $rootId, $owner, false);
                }
            } catch (Throwable) {
                try {
                    $this->releaseClaim((int) $rootId, $owner, true);
                } catch (Throwable) {
                    // Keep the active claim identifiers for failed(); its
                    // lease also expires, so evidence is never an orphan.
                }

                throw new RuntimeException('Zoho V2 Bulk recovery failed; see correlation ID.');
            }
            $this->activeRootId = null;
            $this->activeOwner = null;
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->activeRootId !== null && $this->activeOwner !== null) {
            try {
                $this->releaseClaim($this->activeRootId, $this->activeOwner, true);
            } catch (Throwable) {
                Log::channel('zoho')->error('Zoho V2 Bulk recovery claim release failed.', [
                    'root_id' => $this->activeRootId,
                ]);
            }
        }
        Log::channel('zoho')->error('Zoho V2 Bulk terminalization recovery job exhausted.', [
            'root_id' => $this->activeRootId,
            'exception' => $exception::class,
        ]);
    }

    /**
     * @return array{batch_id:int,module:string,correlation_id:string,run_owner:string,delivery_generation:int,reason:string}|null
     */
    private function claimRoot(int $rootId, string $owner): ?array
    {
        return DB::transaction(function () use ($rootId, $owner): ?array {
            $root = ZohoBulkReadJob::query()->lockForUpdate()->find($rootId);
            if ($root === null
                || $root->page_key !== 'root'
                || $root->status !== 'terminalizing'
                || ! in_array($root->terminalization_reason, self::REASONS, true)
                || ! is_array($root->terminalization_context)
                || $root->retry_after === null
                || $root->retry_after->isFuture()
                || $root->lease_expires_at?->isFuture()
                || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', (string) $root->module_lease_owner) !== 1) {
                return null;
            }

            $root->update([
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
                'error_summary' => 'Bulk terminalization recovery is running.',
            ]);

            return [
                'batch_id' => (int) $root->sync_batch_id,
                'module' => (string) $root->module,
                'correlation_id' => (string) $root->correlation_id,
                'run_owner' => (string) $root->module_lease_owner,
                'delivery_generation' => (int) $root->delivery_generation,
                'reason' => (string) $root->terminalization_reason,
            ];
        });
    }

    private function releaseClaim(int $rootId, string $owner, bool $failed): void
    {
        ZohoBulkReadJob::query()
            ->whereKey($rootId)
            ->where('status', 'terminalizing')
            ->where('lease_owner', $owner)
            ->update([
                'lease_owner' => null,
                'lease_expires_at' => null,
                'retry_after' => now()->addSeconds($this->retrySeconds()),
                'heartbeat_at' => now(),
                'error_summary' => $failed
                    ? 'Bulk terminalization recovery failed; retry scheduled.'
                    : 'Bulk terminalization recovery deferred.',
            ]);
    }

    private function leaseSeconds(): int
    {
        return max($this->timeout + 60, (int) config('zoho-v2.bulk.lease_seconds', 1_500));
    }

    private function retrySeconds(): int
    {
        return max(60, min(3_600, (int) config('zoho-v2.bulk.failure_retry_seconds', 300)));
    }
}
