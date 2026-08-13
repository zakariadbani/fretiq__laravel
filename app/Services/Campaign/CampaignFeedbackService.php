<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Models\Suppression;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampaignFeedbackService
{
    /**
     * Apply a provider or DSN delivery outcome. The delivery row is always the
     * first lock, followed by its contact, then the owning campaign/run or
     * enrollment. Keeping this order makes mixed campaign/sequence bounces
     * deterministic at the soft-bounce threshold.
     */
    public function apply(
        CampaignRecipient|SequenceStepSend $delivery,
        string $outcome,
        ?CarbonInterface $occurredAt = null,
        ?string $detail = null,
        string $source = 'campaign',
    ): void {
        if (! in_array($outcome, ['hard_bounce', 'soft_bounce', 'complaint', 'unsubscribe', 'sent', 'opened', 'clicked', 'unsent'], true)) {
            throw new \InvalidArgumentException("Unsupported campaign feedback outcome: {$outcome}");
        }

        $at = ($occurredAt ?? now())->copy();

        DB::transaction(function () use ($delivery, $outcome, $at, $detail, $source, $occurredAt): void {
            if ($delivery instanceof CampaignRecipient) {
                $locked = CampaignRecipient::query()->lockForUpdate()->findOrFail($delivery->id);
                $this->applyRecipient($locked, $outcome, $at, $detail, $source, $occurredAt !== null);

                return;
            }

            $locked = SequenceStepSend::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->applySequenceSend($locked, $outcome, $at, $detail, $source);
        });
    }

    private function applyRecipient(
        CampaignRecipient $recipient,
        string $outcome,
        CarbonInterface $at,
        ?string $detail,
        string $source,
        bool $hasOccurredAt,
    ): void {
        if ($this->applyRecipientNonBounce($recipient, $outcome, $at, $detail, $source, $hasOccurredAt)) {
            return;
        }

        $contact = Contact::query()->lockForUpdate()->findOrFail($recipient->contact_id);
        $run = CampaignRun::query()->lockForUpdate()->find($recipient->campaign_run_id);
        $campaign = $run === null ? null : Campaign::query()->lockForUpdate()->find($run->campaign_id);

        $this->recordRecipientBounce($recipient, $outcome, $at, $detail);
        $this->applyBounceSuppression($contact, $outcome, $at, $source);

        if ($run !== null && $campaign !== null) {
            $this->pauseCampaignForAnomalousBounceRate($run, $campaign);
        }
    }

    private function applySequenceSend(
        SequenceStepSend $send,
        string $outcome,
        CarbonInterface $at,
        ?string $detail,
        string $source,
    ): void {
        if (! in_array($outcome, ['hard_bounce', 'soft_bounce'], true)) {
            throw new \InvalidArgumentException('Sequence feedback only supports bounce outcomes.');
        }

        // Read only the foreign key before locks, then take durable locks in the
        // shared delivery -> contact -> enrollment order.
        $contactId = SequenceEnrollment::query()->whereKey($send->enrollment_id)->value('contact_id');
        $contact = Contact::query()->lockForUpdate()->findOrFail($contactId);
        $enrollment = SequenceEnrollment::query()->lockForUpdate()->findOrFail($send->enrollment_id);
        $campaign = $enrollment->campaign_id === null
            ? null
            : Campaign::query()->lockForUpdate()->find($enrollment->campaign_id);

        $send->update([
            'status' => 'bounced',
            'bounce_type' => $outcome === 'hard_bounce' ? 'hard' : 'soft',
            'bounce_reason' => $this->detail($detail, 500),
            'bounced_at' => $send->bounced_at ?? $at,
        ]);
        $enrollment->update([
            'status' => 'stopped',
            'stopped_reason' => $outcome,
            'next_send_at' => null,
        ]);

        $this->applyBounceSuppression($contact, $outcome, $at, $source);

        if ($campaign !== null) {
            $this->pauseCampaignForSequenceBounce($campaign);
        }
    }

    private function applyRecipientNonBounce(
        CampaignRecipient $recipient,
        string $outcome,
        CarbonInterface $at,
        ?string $detail,
        string $source,
        bool $hasOccurredAt,
    ): bool {
        if ($outcome === 'sent') {
            $attributes = [];
            $sentAt = $recipient->sent_at ?? ($hasOccurredAt ? $at : null);
            if ($sentAt !== null || $source !== 'zoho') {
                $attributes['sent_at'] = $sentAt ?? now();
            }
            if ($recipient->status === 'queued') {
                $attributes['status'] = 'sent';
            }
            $recipient->update($attributes);

            return true;
        }
        if ($outcome === 'opened' || $outcome === 'clicked') {
            $column = $outcome . '_at';
            $attributes = [];
            $eventAt = $recipient->{$column} ?? ($hasOccurredAt ? $at : null);
            if ($eventAt !== null || $source !== 'zoho') {
                $attributes[$column] = $eventAt ?? now();
            }
            if (in_array($recipient->status, ['queued', 'sent', 'delivered', 'opened', 'clicked'], true)) {
                $attributes['status'] = $outcome;
            }
            $recipient->update($attributes);

            return true;
        }
        if ($outcome === 'unsent') {
            if (! in_array($recipient->status, ['replied', 'unsubscribed'], true)) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'zoho_unsent']);
            }

            return true;
        }
        if (in_array($outcome, ['complaint', 'unsubscribe'], true)) {
            if ($outcome === 'unsubscribe') {
                $recipient->update(['status' => 'unsubscribed']);
            }
            $contact = Contact::query()->lockForUpdate()->findOrFail($recipient->contact_id);
            $this->suppress($contact, $outcome, $source, $at);

            return true;
        }

        return false;
    }

    private function recordRecipientBounce(CampaignRecipient $recipient, string $outcome, CarbonInterface $at, ?string $detail): void
    {
        $attributes = [
            'bounce_type' => $outcome === 'hard_bounce' ? 'hard' : 'soft',
            'bounce_reason' => $this->detail($detail, 255),
            // Preserve the original event timestamp on replay.
            'bounced_at' => $recipient->bounced_at ?? $at,
        ];
        if (! in_array($recipient->status, ['replied', 'unsubscribed'], true)) {
            $attributes['status'] = 'bounced';
        }
        $recipient->update($attributes);
    }

    private function applyBounceSuppression(Contact $contact, string $outcome, CarbonInterface $at, string $source): void
    {
        // Any bounce replaces a prior valid proof. Soft-bounce suppression keeps
        // its existing threshold, but the address is immediately non-sendable.
        $contact->forceFill([
            'email_verification_status' => 'invalid',
            'email_verification_source' => 'bounce',
            'email_verification_checked_at' => $at,
        ])->save();

        if ($outcome === 'hard_bounce') {
            $this->suppress($contact, 'hard_bounce', $source, $at);

            return;
        }

        $windowStart = $at->copy()->subDays(max(1, (int) config('prospecting.bounce.window_days', 30)));
        $softCount = CampaignRecipient::query()
            ->where('contact_id', $contact->id)
            ->where('bounce_type', 'soft')
            ->where('bounced_at', '>=', $windowStart)
            ->count()
            + SequenceStepSend::query()
                ->where('bounce_type', 'soft')
                ->where('bounced_at', '>=', $windowStart)
                ->whereHas('enrollment', fn ($query) => $query->where('contact_id', $contact->id))
                ->count();

        if ($softCount >= max(1, (int) config('prospecting.bounce.soft_limit', 2))) {
            $this->suppress($contact, 'soft_bounce', $source, $at);
        }
    }

    private function suppress(Contact $contact, string $reason, string $source, CarbonInterface $at, bool $invalidate = false): void
    {
        if ($invalidate) {
            $contact->update([
                'email_verification_status' => 'invalid',
                'email_verification_source' => 'bounce',
                'email_verification_checked_at' => $at,
            ]);
        }

        Suppression::firstOrCreate(
            ['email' => strtolower(trim((string) $contact->email))],
            ['contact_id' => $contact->id, 'reason' => $reason, 'source' => $source],
        );
    }

    private function pauseCampaignForAnomalousBounceRate(CampaignRun $run, Campaign $campaign): void
    {
        $counts = CampaignRecipient::query()
            ->where('campaign_run_id', $run->id)
            ->selectRaw("COUNT(*) as denominator, SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced")
            ->where(function ($query): void {
                $query->whereNotNull('sent_at')
                    ->orWhereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed']);
            })
            ->first();

        $this->pauseCampaignWhenThresholdReached($campaign, (int) ($counts?->denominator ?? 0), (int) ($counts?->bounced ?? 0), $run->id);
    }

    private function pauseCampaignForSequenceBounce(Campaign $campaign): void
    {
        $recipientCounts = CampaignRecipient::query()
            ->whereHas('run', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->selectRaw("COUNT(*) as denominator, SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced")
            ->where(function ($query): void {
                $query->whereNotNull('sent_at')
                    ->orWhereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed']);
            })
            ->first();

        $sequenceCounts = SequenceStepSend::query()
            ->whereHas('enrollment', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->selectRaw("COUNT(*) as denominator, SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced")
            ->where(function ($query): void {
                $query->whereNotNull('sent_at')
                    ->orWhereIn('status', ['sent', 'bounced']);
            })
            ->first();

        $this->pauseCampaignWhenThresholdReached(
            $campaign,
            (int) ($recipientCounts?->denominator ?? 0) + (int) ($sequenceCounts?->denominator ?? 0),
            (int) ($recipientCounts?->bounced ?? 0) + (int) ($sequenceCounts?->bounced ?? 0),
            null,
        );
    }

    private function pauseCampaignWhenThresholdReached(Campaign $campaign, int $denominator, int $bounced, ?int $runId): void
    {
        $minimum = max(1, (int) config('prospecting.bounce.pause_min_recipients', 20));
        $threshold = max(1, (float) config('prospecting.bounce.pause_rate_percent', 10));
        if (! $campaign->is_active || $denominator < $minimum || $bounced === 0 || ($bounced / $denominator * 100) < $threshold) {
            return;
        }

        $campaign->update(['is_active' => false]);
        Log::warning('Campaign paused after anomalous bounce rate.', [
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $runId,
            'recipient_count' => $denominator,
            'bounce_count' => $bounced,
        ]);
    }

    private function detail(?string $detail, int $limit): ?string
    {
        $detail = trim((string) $detail);

        return $detail === '' ? null : mb_substr($detail, 0, $limit);
    }
}
