<?php

namespace App\Services\Demande;

use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\Demande;
use Illuminate\Support\Facades\Log;

/**
 * DemandeCaptureService — converts a contact interest signal into a Demande.
 *
 * This is the reply/interest → win path. It is triggered by a manual UI action
 * (a commercial marks a contact as "interested"), not by IMAP polling.
 *
 * Steps:
 *  1. Create a Demande with full attribution (campaign/run/sequence ids).
 *  2. Increment CampaignRun.conversion_count if a run is attributed.
 *
 * @see campaign-automation.md §6 (analytics + attribution)
 */
class DemandeCaptureService
{
    /**
     * Capture a demande (reply / interest signal) for a contact.
     *
     * @param  Contact    $contact      The responding contact.
     * @param  array      $attribution  Keys: 'campaign_id', 'campaign_run_id', 'sequence_id' (all optional).
     * @param  string|null $kind        Demande kind (e.g. 'inbound_reply', 'manual'). Stored as-is.
     * @param  string|null $notes       Free-text notes from the commercial.
     * @return Demande                  The persisted Demande record.
     */
    public function capture(
        Contact $contact,
        array $attribution = [],
        ?string $kind = null,
        ?string $notes = null,
    ): Demande {
        // ── 1. Create the Demande with full attribution ────────────────────────
        $demande = Demande::create([
            'contact_id'      => $contact->id,
            'campaign_id'     => $attribution['campaign_id']     ?? null,
            'campaign_run_id' => $attribution['campaign_run_id'] ?? null,
            'sequence_id'     => $attribution['sequence_id']     ?? null,
            'kind'            => $kind,
            'status'          => 'pending',
            'notes'           => $notes,
            'captured_at'     => now(),
        ]);

        Log::info('[DemandeCaptureService] Demande créée.', [
            'demande_id'  => $demande->id,
            'contact_id'  => $contact->id,
            'attribution' => $attribution,
        ]);

        // ── 2. Increment conversion_count on the attributed run ───────────────
        if (! empty($attribution['campaign_run_id'])) {
            CampaignRun::where('id', $attribution['campaign_run_id'])
                ->increment('conversion_count');

            Log::debug('[DemandeCaptureService] Conversion comptabilisée sur la run.', [
                'campaign_run_id' => $attribution['campaign_run_id'],
            ]);
        }

        return $demande;
    }
}
