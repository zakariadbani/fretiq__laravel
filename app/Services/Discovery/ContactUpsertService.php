<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;
use App\Services\Prospecting\HunterVerificationStatusNormalizer;

/**
 * ContactUpsertService — upserts contacts discovered via Hunter enrichment.
 *
 * Extracted from DiscoveryPipelineService (pure refactor — no logic change).
 *
 * email_kind mapping (domain-based — Hunter's 'type' flag is ignored):
 *   Free-webmail domain (gmail.com, orange.fr, …) → email_kind = 'personal'
 *   Corporate domain (named or role address)       → email_kind = 'role'
 *
 * legal_basis is set to 'legitimate_interest' on every discovered contact per
 * the compliance spec (CNIL B2B cold-discovery basis).
 */
class ContactUpsertService
{
    private readonly HunterVerificationStatusNormalizer $verification;

    public function __construct(
        ?HunterVerificationStatusNormalizer $verification = null,
    ) {
        $this->verification = $verification ?? new HunterVerificationStatusNormalizer;
    }

    /**
     * Upsert contacts for a company from Hunter enrichment data.
     *
     * @param  Company  $company  The company these contacts belong to.
     * @param  string  $domain  Used as source_url.
     * @param  array  $emails  Hunter emails array (each entry has value, type, first_name, …).
     * @return int Number of contacts newly created. Updates are idempotent and
     *             do not inflate discovery-run contact creation statistics.
     */
    public function upsertFromHunter(Company $company, string $domain, array $emails): int
    {
        $count = 0;

        foreach ($emails as $emailData) {
            $emailAddress = $emailData['value'] ?? null;

            if (! $emailAddress) {
                continue;
            }

            $firstName = $emailData['first_name'] ?? '';
            $lastName = $emailData['last_name'] ?? '';
            $name = trim("{$firstName} {$lastName}");

            if ($name === '') {
                // Fall back to the local-part of the address
                $name = strstr($emailAddress, '@', true) ?: $emailAddress;
            }

            $emailKind = \App\Support\EmailKind::classify($emailAddress);

            $capturedAt = now();
            $verificationAttributes = [];

            if (($verificationStatus = $this->verification->normalize($emailData)) !== null) {
                $verificationAttributes = [
                    'email_verification_status' => $verificationStatus,
                    'email_verification_source' => 'hunter',
                    'email_verification_checked_at' => $this->verification->checkedAt($emailData) ?? $capturedAt,
                ];
            }

            $shared = [
                'company_id' => $company->id,
                'name' => $name,
                'position' => $emailData['position'] ?? null,
                'source' => 'discovered',
                'legal_basis' => 'legitimate_interest',
                'email_kind' => $emailKind,
                'source_url' => $domain,
                'source_captured_at' => $capturedAt,
                ...$verificationAttributes,
                'updated_at' => $capturedAt,
            ];

            // The unique email index is the concurrency arbiter. Exactly one
            // worker can insert and count the contact; racing workers fall
            // through to the live-row update without inflating created totals.
            $inserted = Contact::query()->insertOrIgnore([
                ...$shared,
                'email' => $emailAddress,
                'status' => 'new',
                'created_at' => $capturedAt,
                'deleted_at' => null,
            ]);

            if ($inserted === 1) {
                $count++;

                continue;
            }

            // Preserve the existing lifecycle status. The SoftDeletes global
            // scope deliberately excludes tombstones: a unique conflict with a
            // soft-deleted address is not silently resurrected or reassigned.
            if (($emailData['position'] ?? null) === null) {
                unset($shared['position']);
            }

            // Global email uniqueness must never move a contact from another
            // company. On a conflict, enrich only the row already owned by this
            // company; an address attached elsewhere is ignored.
            unset($shared['company_id']);

            Contact::query()
                ->where('email', $emailAddress)
                ->where('company_id', $company->id)
                ->update($shared);
        }

        return $count;
    }
}
