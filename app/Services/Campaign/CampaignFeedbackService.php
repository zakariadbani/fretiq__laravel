<?php

namespace App\Services\Campaign;

use App\Models\CampaignRecipient;
use App\Models\Suppression;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class CampaignFeedbackService
{
    public function apply(
        CampaignRecipient $recipient,
        string $outcome,
        ?CarbonInterface $occurredAt = null,
        ?string $detail = null,
        string $source = 'campaign',
    ): void {
        if (! in_array($outcome, ['hard_bounce', 'complaint', 'unsubscribe', 'sent', 'opened', 'clicked', 'unsent'], true)) {
            throw new \InvalidArgumentException("Unsupported campaign feedback outcome: {$outcome}");
        }

        DB::transaction(function () use ($recipient, $outcome, $occurredAt, $detail, $source): void {
            if ($outcome === 'sent') {
                // Provider state is evidence even when no event timestamp was returned; never invent one.
                $attributes = [];
                $sentAt = $recipient->sent_at ?? $occurredAt;
                if ($sentAt !== null || $source !== 'zoho') {
                    $attributes['sent_at'] = $sentAt ?? now();
                }
                if ($recipient->status === 'queued') {
                    $attributes['status'] = 'sent';
                }
                $recipient->update($attributes);

                return;
            }
            if ($outcome === 'opened') {
                $attributes = [];
                $openedAt = $recipient->opened_at ?? $occurredAt;
                if ($openedAt !== null || $source !== 'zoho') {
                    $attributes['opened_at'] = $openedAt ?? now();
                }
                if (in_array($recipient->status, ['queued', 'sent', 'delivered', 'opened'], true)) {
                    $attributes['status'] = 'opened';
                }
                $recipient->update($attributes);

                return;
            }
            if ($outcome === 'clicked') {
                $attributes = [];
                $clickedAt = $recipient->clicked_at ?? $occurredAt;
                if ($clickedAt !== null || $source !== 'zoho') {
                    $attributes['clicked_at'] = $clickedAt ?? now();
                }
                if (in_array($recipient->status, ['queued', 'sent', 'delivered', 'opened', 'clicked'], true)) {
                    $attributes['status'] = 'clicked';
                }
                $recipient->update($attributes);

                return;
            }
            if ($outcome === 'unsent') {
                if (! in_array($recipient->status, ['replied', 'unsubscribed'], true)) {
                    $recipient->update(['status' => 'skipped', 'skip_reason' => 'zoho_unsent']);
                }

                return;
            }
            if ($outcome === 'hard_bounce') {
                $attributes = [
                    'bounce_reason' => $detail,
                    'bounced_at' => $occurredAt ?? now(),
                ];
                if (! in_array($recipient->status, ['replied', 'unsubscribed'], true)) {
                    $attributes['status'] = 'bounced';
                }
                $recipient->update($attributes);
            }
            if ($outcome === 'unsubscribe') {
                $recipient->update(['status' => 'unsubscribed']);
            }

            $contact = $recipient->contact()->firstOrFail();
            Suppression::firstOrCreate(
                ['email' => strtolower(trim($contact->email))],
                ['contact_id' => $contact->id, 'reason' => $outcome, 'source' => $source],
            );
        });
    }
}
