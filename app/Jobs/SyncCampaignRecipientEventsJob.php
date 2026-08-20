<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaign\CampaignFeedbackService;
use App\Services\Zoho\ZohoCampaignsClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncCampaignRecipientEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;
    private const MAX_PAGES_PER_ACTION = 100;

    /** @var array<string, string> */
    private const ACTION_OUTCOMES = [
        'sentcontacts' => 'sent',
        'openedcontacts' => 'opened',
        'clickedcontacts' => 'clicked',
        'optoutcontacts' => 'unsubscribe',
        'unsentcontacts' => 'unsent',
        'spamcontacts' => 'complaint',
    ];

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
        return [(new WithoutOverlapping("campaign-recipient-events:{$this->runId}"))->expireAfter(300)];
    }

    public function handle(ZohoCampaignsClient $client, CampaignFeedbackService $feedback): void
    {
        $run = CampaignRun::with('recipients.contact')->find($this->runId);
        if ($run === null || ! $run->isExecuted() || blank($run->zoho_campaign_key)) {
            return;
        }

        $recipientsByEmail = $run->recipients
            ->filter(fn ($recipient) => filled($recipient->contact?->email))
            ->groupBy(fn ($recipient) => strtolower(trim($recipient->contact->email)));

        foreach (self::ACTION_OUTCOMES as $action => $outcome) {
            $fromIndex = 1;
            $range = 100;
            $page = 0;
            $previousFullPage = null;

            do {
                if (++$page > self::MAX_PAGES_PER_ACTION) {
                    throw new \RuntimeException("Zoho recipient pagination exceeded the page limit for {$action}.");
                }
                $rows = $client->getCampaignRecipientsData($run->zoho_campaign_key, $action, $fromIndex, $range);

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        throw new \UnexpectedValueException("Zoho recipient row is malformed for {$action}.");
                    }

                    $email = strtolower(trim((string) ($row['contactemailaddress'] ?? '')));
                    if ($email === '') {
                        throw new \UnexpectedValueException("Zoho recipient row is missing contactemailaddress for {$action}.");
                    }

                    foreach ($recipientsByEmail->get($email, collect()) as $recipient) {
                        $feedback->apply(
                            $recipient,
                            $outcome,
                            $this->eventTime($row, $action),
                            (string) ($row['contactstatus'] ?? ''),
                            'zoho',
                        );
                    }
                }

                if (count($rows) === $range) {
                    $fullPage = hash('sha256', serialize($rows));
                    if ($fullPage === $previousFullPage) {
                        throw new \RuntimeException("Zoho repeated recipient page for {$action} at fromindex {$fromIndex}.");
                    }
                    $previousFullPage = $fullPage;
                }

                $fromIndex += $range;
            } while (count($rows) === $range);
        }

        $run->refresh();
        if (str_starts_with((string) $run->stats_sync_error, 'Recipient event sync failed')) {
            $run->update(['stats_sync_error' => null]);
        }

    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('campaign')->error('[SyncCampaignRecipientEventsJob] Recipient event sync failed.', [
            'run_id' => $this->runId,
            'exception' => $exception,
        ]);

        CampaignRun::query()
            ->whereKey($this->runId)
            ->update(['stats_sync_error' => 'Recipient event sync failed. Inspect protected logs.']);
    }

    /** @param array<string, mixed> $row */
    private function eventTime(array $row, string $action): ?CarbonInterface
    {
        if ($action === 'openedcontacts') {
            $reports = $row['openreports'] ?? [];
            if (is_string($reports)) {
                $reports = preg_split('/,\s*(?=[^,={}]+=)/', trim($reports, '{}')) ?: [];
                $reports = array_map(fn (string $report) => explode('=', $report, 2)[1] ?? '', $reports);
            }

            $times = collect(is_array($reports) ? $reports : [])
                ->map(fn ($value) => $this->parseDate($value))
                ->filter()
                ->sortBy(fn (CarbonInterface $date) => $date->getTimestamp());
            if ($times->isNotEmpty()) {
                return $times->first();
            }
        }

        if ($action !== 'sentcontacts') {
            return null;
        }

        $sentTime = $row['sent_time'] ?? null;
        if (is_numeric($sentTime)) {
            return Carbon::createFromTimestampMs((int) $sentTime);
        }

        return $this->parseDate($row['sentdate'] ?? null);
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
