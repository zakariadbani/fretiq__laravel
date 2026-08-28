<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaign\CampaignFeedbackService;
use App\Support\ApiLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SyncMailjetEventsJob — pulls per-message delivery status from Mailjet's
 * GET /v3/REST/message and applies it via CampaignFeedbackService.
 *
 * UNVERIFIED — the /v3/REST/message field shapes (Status enum values, the
 * hard-vs-soft bounce permanence flag) are NOT live-tinker-confirmed. Per the
 * fretiq empirical-verification rule (CLAUDE.md §1), before relying on this
 * job in production: run a live tinker GET against the real API with the
 * live keys, record STATUS 200 + response shape, and correct the mapping
 * below if it disagrees with what Mailjet actually returns.
 *
 * Correlation is by message.ID === campaign_recipients.provider_message_id
 * (exact match, no email fallback needed — stronger than the Zoho sync job's
 * email-based matching).
 */
class SyncMailjetEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    private const MAX_PAGES = 100;

    private const PAGE_LIMIT = 1000;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('campaigns');
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("mailjet-events:{$this->runId}"))->expireAfter(300)];
    }

    public function handle(CampaignFeedbackService $feedback): void
    {
        $run = CampaignRun::with(['campaign', 'recipients'])->find($this->runId);
        if ($run === null || ! $run->isExecuted() || $run->campaign?->effectiveDeliveryChannel() !== 'mailjet') {
            return;
        }

        $recipientsByMessageId = $run->recipients
            ->filter(fn (CampaignRecipient $recipient) => filled($recipient->provider_message_id))
            ->groupBy(fn (CampaignRecipient $recipient) => (string) $recipient->provider_message_id);

        if ($recipientsByMessageId->isEmpty()) {
            return;
        }

        $offset = 0;
        $page = 0;
        $previousFullPage = null;

        do {
            if (++$page > self::MAX_PAGES) {
                throw new \RuntimeException("[SyncMailjetEventsJob] Message pagination exceeded the page limit for run {$this->runId}.");
            }

            $response = Http::withBasicAuth(
                (string) config('services.mailjet.key'),
                (string) config('services.mailjet.secret'),
            )
                ->timeout(30)
                ->get(rtrim((string) config('services.mailjet.api_url'), '/') . '/v3/REST/message', [
                    'CustomCampaign' => 'fretiq-run-' . $run->id,
                    'Limit' => self::PAGE_LIMIT,
                    'Offset' => $offset,
                ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    '[SyncMailjetEventsJob] GET /v3/REST/message échoué (HTTP ' . $response->status() . '): ' . ApiLog::excerpt($response->body(), 300)
                );
            }

            $payload = $response->json();
            if (! is_array($payload) || ! array_key_exists('Data', $payload) || ! is_array($payload['Data'])) {
                throw new \RuntimeException('[SyncMailjetEventsJob] Réponse Mailjet malformée : ' . ApiLog::excerpt($response->body(), 300));
            }

            $rows = $payload['Data'];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new \UnexpectedValueException('[SyncMailjetEventsJob] Ligne de message Mailjet malformée.');
                }

                $messageId = (string) ($row['ID'] ?? '');
                if ($messageId === '') {
                    continue;
                }

                $recipients = $recipientsByMessageId->get($messageId, collect());
                if ($recipients->isEmpty()) {
                    continue;
                }

                $status = strtolower(trim((string) ($row['Status'] ?? '')));
                $at = $this->eventTime($row);

                foreach ($recipients as $recipient) {
                    $this->applyStatus($feedback, $recipient, $status, $at, $row);
                }
            }

            $receivedCount = count($rows);

            if ($receivedCount === self::PAGE_LIMIT) {
                $fullPage = hash('sha256', serialize($rows));
                if ($fullPage === $previousFullPage) {
                    throw new \RuntimeException("[SyncMailjetEventsJob] Page de messages répétée à l'offset {$offset}.");
                }
                $previousFullPage = $fullPage;
            }

            // Advance by the rows actually received, not the requested Limit —
            // a lower server-side page cap must not skip unfetched rows.
            $offset += $receivedCount;

            $total = isset($payload['Total']) && is_numeric($payload['Total']) ? (int) $payload['Total'] : null;
            // Total is the authoritative continuation signal (a page short of the
            // requested Limit no longer means "last page" if Mailjet caps page
            // size below Limit). Without Total, fall back to "was this page
            // saturated at the limit" as a best-effort continuation guess.
            $hasMore = $total !== null
                ? ($receivedCount > 0 && $offset < $total)
                : ($receivedCount > 0 && $receivedCount === self::PAGE_LIMIT);
        } while ($hasMore);

        $run->refresh();
        if (str_starts_with((string) $run->stats_sync_error, 'Mailjet event sync failed')) {
            $run->update(['stats_sync_error' => null]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('campaign')->error('[SyncMailjetEventsJob] Mailjet event sync failed.', [
            'run_id' => $this->runId,
            'exception' => $exception,
        ]);

        CampaignRun::query()
            ->whereKey($this->runId)
            ->update(['stats_sync_error' => 'Mailjet event sync failed. Inspect protected logs.']);
    }

    /** @param array<string, mixed> $row */
    private function applyStatus(
        CampaignFeedbackService $feedback,
        CampaignRecipient $recipient,
        string $status,
        ?CarbonInterface $at,
        array $row,
    ): void {
        switch ($status) {
            case 'sent':
                $feedback->apply($recipient, 'sent', $at, $status, 'mailjet');

                return;
            case 'opened':
                $feedback->apply($recipient, 'opened', $at, $status, 'mailjet');

                return;
            case 'clicked':
                // Mailjet's Status is last-state-only — a click implies the
                // message was also opened. A single 'clicked' apply() call
                // backfills opened_at atomically (see CampaignFeedbackService),
                // so a re-sync can never downgrade an already-clicked recipient
                // to 'opened' by landing between two separate writes.
                $feedback->apply($recipient, 'clicked', $at, $status, 'mailjet');

                return;
            case 'bounce':
            case 'hardbounced':
                // ponytail: permanence flag name (IsHardBounced) is a best guess pending
                // live verification — defaults to soft (the safer failure mode) if absent.
                $permanent = $status === 'hardbounced' || (bool) ($row['IsHardBounced'] ?? false);
                $feedback->apply($recipient, $permanent ? 'hard_bounce' : 'soft_bounce', $at, $status, 'mailjet');

                return;
            case 'softbounced':
            case 'blocked':
                $feedback->apply($recipient, 'soft_bounce', $at, $status, 'mailjet');

                return;
            case 'spam':
                $feedback->apply($recipient, 'complaint', $at, $status, 'mailjet');

                return;
            case 'unsub':
                $feedback->apply($recipient, 'unsubscribe', $at, $status, 'mailjet');

                return;
            case 'queued':
            case 'deferred':
            case '':
                // Benign transient states (or a row missing Status) carry no
                // feedback outcome — skip without logging.
                return;
            default:
                // An unrecognised status must surface, not vanish — a mapping
                // mismatch (e.g. a new Mailjet Status enum value) would otherwise
                // silently drop bounce/complaint data with no trace.
                Log::channel('campaign')->warning('[SyncMailjetEventsJob] Unmapped Mailjet message status.', [
                    'run_id' => $this->runId,
                    'status' => $status,
                ]);

                return;
        }
    }

    /** @param array<string, mixed> $row */
    private function eventTime(array $row): ?CarbonInterface
    {
        $value = $row['ArrivedAt'] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
