<?php

namespace App\Services\Campaign;

use App\Models\Company;
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
    public function qualityReason(
        Contact $contact,
        bool $sendTime = false,
        bool $supportsBounceFeedback = false,
    ): ?string {
        $isProspect = $this->relationship($contact) === 'prospect';

        if ($isProspect && $contact->email_kind === 'personal') {
            return 'personal_email';
        }

        $status = strtolower(trim((string) $contact->email_verification_status));
        $source = strtolower(trim((string) $contact->email_verification_source));
        $checkedAt = $contact->email_verification_checked_at;
        $fresh = $checkedAt?->gte(
            now()->subDays((int) config('prospecting.email_verification_ttl_days', 90)),
        ) ?? false;

        if (! $fresh) {
            return 'verification_required';
        }

        if ($source === 'manual' && in_array($status, ['', 'unknown'], true)) {
            return null;
        }

        if ($status === 'valid') {
            return null;
        }

        if ($status === 'accept_all') {
            return $sendTime && ! $supportsBounceFeedback
                ? 'accept_all_feedback_required'
                : null;
        }

        if ($status === 'invalid') {
            return 'invalid_email';
        }

        if ($status === 'disposable') {
            return 'disposable_email';
        }

        if ($status === 'webmail') {
            return $isProspect ? 'personal_email' : null;
        }

        return 'verification_required';
    }

    /**
     * Pure send-time rule for batch callers that already know suppression.
     */
    public function sendIneligibilityReason(
        Contact $contact,
        bool $supportsBounceFeedback,
        bool $suppressed,
    ): ?string {
        if ($suppressed) {
            return 'suppressed';
        }

        if ($this->relationship($contact) === 'prospect'
            && ! config('prospecting.cold_send_enabled', false)) {
            return 'cold_send_disabled';
        }

        return $this->qualityReason($contact, true, $supportsBounceFeedback);
    }

    /**
     * Convenience wrapper for a job that genuinely handles one recipient.
     * It performs exactly one suppression lookup; batch paths must not use it.
     */
    public function sendIneligibilityReasonForSingle(
        Contact $contact,
        bool $supportsBounceFeedback,
    ): ?string {
        return $this->sendIneligibilityReason(
            $contact,
            $supportsBounceFeedback,
            Suppression::isSuppressed((string) $contact->email),
        );
    }

    /**
     * @return array{label:string,color:string,risk:bool}
     */
    public function badge(Contact $contact): array
    {
        $status = strtolower(trim((string) $contact->email_verification_status));
        $source = strtolower(trim((string) $contact->email_verification_source));

        if ($source === 'manual' && in_array($status, ['', 'unknown'], true)) {
            $key = 'manual';
        } elseif ($status === '') {
            $key = 'missing';
        } elseif (in_array($status, [
            'valid',
            'accept_all',
            'pending',
            'unknown',
            'webmail',
            'invalid',
            'disposable',
        ], true)) {
            $key = $status;
        } else {
            $key = 'unknown';
        }

        $badge = config("global.data.contact_email_verification_statuses.{$key}", [
            'label' => 'Non vérifié',
            'color' => 'secondary',
            'risk' => true,
        ]);

        return [
            'label' => (string) ($badge['label'] ?? 'Non vérifié'),
            'color' => (string) ($badge['color'] ?? 'secondary'),
            'risk' => (bool) ($badge['risk'] ?? true),
        ];
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
