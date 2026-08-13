<?php

namespace App\Services\Campaign;

use App\Models\Company;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Suppression;

class ContactEligibilityService
{
    /**
     * Return a stable reason when the contact's email quality is not eligible.
     *
     * This method deliberately reads only attributes and an already-loaded
     * company relation, so collection callers never trigger an N+1 query.
     */
    public function qualityReason(Contact $contact, string $policy = Campaign::VERIFICATION_VERIFIED_ONLY): ?string
    {
        if (strtolower(trim((string) $contact->email_verification_source)) === 'bounce') {
            return 'bounced';
        }

        $lifecycleState = strtolower(trim((string) $contact->getAttribute('lifecycle_state')));
        if ($lifecycleState === 'bounced') {
            return 'bounced';
        }
        if ($lifecycleState === 'invalid_email') {
            return 'invalid_email';
        }

        $status = strtolower(trim((string) $contact->email_verification_status));

        if ($status === 'invalid') {
            return 'invalid_email';
        }

        if ($status === 'disposable') {
            return 'disposable_email';
        }

        if ($status === 'webmail') {
            return $policy === Campaign::VERIFICATION_ALL_SENDABLE ? null : 'verification_required';
        }

        if ($status === 'pending') {
            return 'verification_pending';
        }

        if ($status === 'valid') {
            return null;
        }

        if ($policy === Campaign::VERIFICATION_ALL_SENDABLE
            && in_array($status, ['', 'unknown', 'accept_all'], true)) {
            return null;
        }

        return 'verification_required';
    }

    /**
     * Audience previews retain pending addresses for all_sendable campaigns so
     * the run can wait for their terminal result instead of silently dropping them.
     */
    public function audienceQualityReason(Contact $contact, string $policy): ?string
    {
        if ($policy === Campaign::VERIFICATION_ALL_SENDABLE
            && strtolower(trim((string) $contact->email_verification_status)) === 'pending') {
            return null;
        }

        return $this->qualityReason($contact, $policy);
    }

    /**
     * Pure send-time rule for batch callers that already know suppression.
     */
    public function sendIneligibilityReason(
        Contact $contact,
        string $policy,
        bool $suppressed,
    ): ?string {
        if ($suppressed) {
            return 'suppressed';
        }

        if ($this->relationship($contact) === 'prospect'
            && ! config('prospecting.cold_send_enabled', false)) {
            return 'cold_send_disabled';
        }

        return $this->qualityReason($contact, $policy);
    }

    /**
     * Convenience wrapper for a job that genuinely handles one recipient.
     * It performs exactly one suppression lookup; batch paths must not use it.
     */
    public function sendIneligibilityReasonForSingle(
        Contact $contact,
        string $policy = Campaign::VERIFICATION_VERIFIED_ONLY,
    ): ?string {
        return $this->sendIneligibilityReason(
            $contact,
            $policy,
            Suppression::isSuppressed((string) $contact->email),
        );
    }

    private function relationship(Contact $contact): ?string
    {
        if (! $contact->relationLoaded('company')) {
            // Fail closed without triggering a lazy-load. Batch and send callers
            // are expected to eager-load the company; treating missing context as
            // a prospect keeps both the cold-send and personal-email guards active.
            return 'prospect';
        }

        $company = $contact->getRelation('company');

        return $company instanceof Company
            ? strtolower(trim((string) $company->relationship))
            : 'prospect';
    }
}
