<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\SenderIdentity;
use Illuminate\Support\Facades\DB;

/** Serializes a live Zoho transport claim with campaign delivery-setting edits. */
class CampaignDeliveryFence
{
    /** @param array<int, string> $allowedStatuses */
    public function claimZohoTransport(CampaignRun $run, array $allowedStatuses): ?CampaignRun
    {
        $snapshot = CampaignRun::with('campaign')->find($run->id);
        $snapshotSenderId = (int) ($snapshot?->campaign?->sender_identity_id ?? 0);
        if ($snapshot === null || $snapshotSenderId < 1) {
            return null;
        }

        return DB::transaction(function () use ($snapshot, $snapshotSenderId, $allowedStatuses): ?CampaignRun {
            SenderIdentity::query()->whereKey($snapshotSenderId)->lockForUpdate()->firstOrFail();
            $campaign = Campaign::query()->lockForUpdate()->find($snapshot->campaign_id);
            $lockedRun = CampaignRun::query()->lockForUpdate()->find($snapshot->id);

            if ($campaign === null || $lockedRun === null
                || (int) $campaign->sender_identity_id !== $snapshotSenderId
                || ! $campaign->is_active
                || config('services.zoho.driver', 'local') !== 'zoho'
                || ! in_array($campaign->delivery_channel, [null, 'zoho'], true)
                || ! in_array($lockedRun->status, $allowedStatuses, true)) {
                return null;
            }

            $lockedRun->update([
                'status' => 'sending',
                'started_at' => $lockedRun->started_at ?? now(),
                'failure_reason' => null,
            ]);
            $lockedRun->setRelation('campaign', $campaign);

            return $lockedRun;
        }, 3);
    }
}
