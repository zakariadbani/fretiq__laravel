<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * UnsubscribeController — handles email unsubscribe requests from recipients.
 *
 * Public routes (signed URL, no auth required):
 *   GET  /u/{contact}  → show confirmation page
 *   POST /u/{contact}  → List-Unsubscribe-Post one-click (RFC 8058)
 *
 * Both methods are idempotent: re-requesting unsubscribe for an already-
 * suppressed contact is a no-op (firstOrCreate).
 *
 * After unsubscribing:
 *   1. A Suppression row is created (reason='unsubscribe', source='campaign').
 *   2. All campaign_recipient rows matching this email are marked 'unsubscribed' (best-effort).
 *   3. The user sees a simple French confirmation view.
 */
class UnsubscribeController extends Controller
{
    /**
     * Show the unsubscribe landing page and process the opt-out.
     *
     * Route is protected by the 'signed' middleware — an invalid or expired
     * signature returns a 403 before this method is reached.
     */
    public function show(Request $request, int $contact): View
    {
        /** @var Contact|null $contactModel */
        $contactModel = Contact::find($contact);

        if ($contactModel === null) {
            // Contact deleted (PII scrubbed) — still show the confirmation page.
            return view('public.unsubscribed');
        }

        $this->processUnsubscribe($contactModel);

        return view('public.unsubscribed', ['contact' => $contactModel]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Record the suppression and update any matching recipient rows.
     */
    private function processUnsubscribe(Contact $contact): void
    {
        // 1. Create or retrieve the suppression record.
        Suppression::firstOrCreate(
            ['email' => strtolower(trim($contact->email))],
            [
                'contact_id' => $contact->id,
                'reason'     => 'unsubscribe',
                'source'     => 'campaign',
            ],
        );

        // 2. Mark any open campaign recipient rows as unsubscribed (best-effort).
        //    We match by contact_id so this covers all runs for this contact.
        CampaignRecipient::where('contact_id', $contact->id)
            ->whereNotIn('status', ['unsubscribed', 'bounced'])
            ->update(['status' => 'unsubscribed']);
    }
}
