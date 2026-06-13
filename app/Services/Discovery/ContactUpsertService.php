<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;

/**
 * ContactUpsertService — upserts contacts discovered via Hunter enrichment.
 *
 * Extracted from DiscoveryPipelineService (pure refactor — no logic change).
 *
 * email_kind mapping:
 *   Hunter type = 'generic'  →  email_kind = 'role'
 *   Hunter type = 'personal' →  email_kind = 'personal'
 *
 * legal_basis is set to 'legitimate_interest' on every discovered contact per
 * the compliance spec (CNIL B2B cold-discovery basis).
 */
class ContactUpsertService
{
    /**
     * Upsert contacts for a company from Hunter enrichment data.
     *
     * @param  Company  $company  The company these contacts belong to.
     * @param  string   $domain   Used as source_url.
     * @param  array    $emails   Hunter emails array (each entry has value, type, first_name, …).
     * @return int Number of contacts processed (created or updated).
     */
    public function upsertFromHunter(Company $company, string $domain, array $emails): int
    {
        $count = 0;

        foreach ($emails as $emailData) {
            $emailAddress = $emailData['value'] ?? null;

            if (! $emailAddress) {
                continue;
            }

            $firstName  = $emailData['first_name']  ?? '';
            $lastName   = $emailData['last_name']   ?? '';
            $name       = trim("{$firstName} {$lastName}");

            if ($name === '') {
                // Fall back to the local-part of the address
                $name = strstr($emailAddress, '@', true) ?: $emailAddress;
            }

            $emailKind = ($emailData['type'] ?? '') === 'generic' ? 'role' : 'personal';

            $verificationResult = data_get($emailData, 'verification.result');

            /** @var Contact|null $existing */
            $existing = Contact::where('email', $emailAddress)->first();

            if ($existing) {
                // Idempotent update — keep existing status
                $existing->fill([
                    'company_id'               => $company->id,
                    'name'                     => $name,
                    'position'                 => $emailData['position'] ?? $existing->position,
                    'source'                   => 'discovered',
                    'legal_basis'              => 'legitimate_interest',
                    'email_kind'               => $emailKind,
                    'source_url'               => $domain,
                    'source_captured_at'       => now(),
                    'email_verification_status'=> $verificationResult,
                ]);
                $existing->save();
            } else {
                Contact::create([
                    'company_id'               => $company->id,
                    'email'                    => $emailAddress,
                    'name'                     => $name,
                    'position'                 => $emailData['position'] ?? null,
                    'source'                   => 'discovered',
                    'status'                   => 'new',
                    'legal_basis'              => 'legitimate_interest',
                    'email_kind'               => $emailKind,
                    'source_url'               => $domain,
                    'source_captured_at'       => now(),
                    'email_verification_status'=> $verificationResult,
                ]);
            }

            $count++;
        }

        return $count;
    }
}
