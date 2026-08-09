<?php

namespace App\Services\Zoho\V2\Identity;

use App\Models\Contact;
use App\Models\User;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoMarketingLink;
use App\Models\Zoho\ZohoUser;
use App\Models\Zoho\ZohoUserMapping;
use Illuminate\Support\Facades\DB;

/** Identity matching is deliberately exact-email-only. There is no name fallback. */
final class ZohoIdentityLinker
{
    /** @return array{mapped:int,ambiguous:int} */
    public function autoMapUsers(): array
    {
        $mapped = $ambiguous = 0;
        $zohoUsers = ZohoUser::current()->whereNotNull('normalized_email')->get();
        $fretiqUsers = User::query()->where('is_active', true)->get();
        $zohoByEmail = $zohoUsers->groupBy(fn (ZohoUser $user) => $this->normal($user->normalized_email) ?? '');
        $fretiqByEmail = $fretiqUsers->groupBy(fn (User $user) => $this->normal($user->email) ?? '');

        foreach (ZohoUserMapping::query()->where('is_override', false)->where('is_confirmed', true)->get() as $mapping) {
            $zoho = $zohoUsers->firstWhere('zoho_id', $mapping->zoho_user_id);
            $fretiq = $fretiqUsers->firstWhere('id', $mapping->fretiq_user_id);
            $email = $this->normal($zoho?->normalized_email);
            $reason = match (true) {
                $zoho === null => 'zoho_user_not_current',
                $email === null => 'zoho_email_invalid',
                ($zohoByEmail->get($email) ?? collect())->count() !== 1 => 'zoho_email_not_unique',
                $fretiq === null => 'fretiq_user_not_active',
                $this->normal($fretiq->email) !== $email => 'exact_email_changed',
                ($fretiqByEmail->get($email) ?? collect())->count() !== 1 => 'fretiq_email_not_unique',
                default => null,
            };

            if ($reason !== null) {
                DB::transaction(function () use ($mapping, $reason): void {
                    ZohoUserMapping::query()
                        ->whereKey($mapping->id)
                        ->where('is_override', false)
                        ->where('is_confirmed', true)
                        ->update([
                            'fretiq_user_id' => null,
                            'fretiq_normalized_email' => null,
                            'is_confirmed' => false,
                            'confirmed_by_user_id' => null,
                            'confirmed_at' => null,
                            'audit_metadata' => json_encode([
                                'event' => 'exact_email_auto_match_invalidated',
                                'reason' => $reason,
                                'previous_fretiq_user_id' => $mapping->fretiq_user_id,
                            ], JSON_THROW_ON_ERROR),
                            'updated_at' => now(),
                        ]);
                });
            }
        }

        foreach ($zohoUsers as $zoho) {
            $email = $this->normal($zoho->normalized_email);
            if (! $email) {
                continue;
            }
            $matches = $fretiqByEmail->get($email) ?? collect();
            if ($matches->count() !== 1 || ($zohoByEmail->get($email) ?? collect())->count() !== 1) {
                $ambiguous++;

                continue;
            }
            if (ZohoUserMapping::query()->where('zoho_user_id', $zoho->zoho_id)->where('is_override', true)->exists()) {
                continue;
            }
            DB::transaction(function () use ($zoho, $matches, $email): void {
                ZohoUserMapping::updateOrCreate(['zoho_user_id' => $zoho->zoho_id], ['fretiq_user_id' => $matches->first()->id, 'zoho_normalized_email' => $email, 'fretiq_normalized_email' => $email, 'match_method' => 'exact_email', 'is_confirmed' => true, 'is_override' => false, 'confirmed_at' => now(), 'audit_metadata' => ['event' => 'exact_email_auto_match']]);
            });
            $mapped++;
        }

        return compact('mapped', 'ambiguous');
    }

    public function overrideUserMapping(string $zohoUserId, ?int $fretiqUserId, int $actorUserId): ZohoUserMapping
    {
        $previous = ZohoUserMapping::where('zoho_user_id', $zohoUserId)->first();
        $fretiq = $fretiqUserId ? User::findOrFail($fretiqUserId) : null;
        $zoho = ZohoUser::where('zoho_id', $zohoUserId)->first();

        return DB::transaction(fn () => ZohoUserMapping::updateOrCreate(['zoho_user_id' => $zohoUserId], ['fretiq_user_id' => $fretiq?->id, 'zoho_normalized_email' => $this->normal($zoho?->normalized_email), 'fretiq_normalized_email' => $this->normal($fretiq?->email), 'match_method' => 'admin_override', 'is_confirmed' => true, 'is_override' => true, 'confirmed_by_user_id' => $actorUserId, 'confirmed_at' => now(), 'audit_metadata' => ['event' => 'admin_override', 'previous_fretiq_user_id' => $previous?->fretiq_user_id]]));
    }

    /** @return array{created:int,ambiguous:int} */
    public function linkMarketingContacts(): array
    {
        $created = $ambiguous = 0;
        $fretiqContacts = Contact::query()->get();
        $fretiqById = $fretiqContacts->keyBy(fn (Contact $contact) => (int) $contact->getKey());
        $fretiqByEmail = $fretiqContacts
            ->filter(fn (Contact $contact): bool => $this->normal($contact->email) !== null)
            ->groupBy(fn (Contact $contact): string => (string) $this->normal($contact->email));
        $zohoRecords = [
            'leads' => ZohoLead::current()->get(),
            'contacts' => ZohoContact::current()->get(),
        ];
        $zohoById = [];
        $zohoByEmail = [];
        foreach ($zohoRecords as $module => $records) {
            $zohoById[$module] = $records->keyBy(fn ($record) => (string) $record->zoho_id);
            $zohoByEmail[$module] = $records
                ->filter(fn ($record): bool => $this->normal($record->normalized_email) !== null)
                ->groupBy(fn ($record): string => (string) $this->normal($record->normalized_email));
        }

        foreach (ZohoMarketingLink::query()->activeExactEmail()->get() as $link) {
            $reason = $this->marketingLinkInvalidationReason(
                $link,
                $fretiqById,
                $fretiqByEmail,
                $zohoById,
                $zohoByEmail,
            );
            if ($reason !== null) {
                $this->invalidateMarketingLink($link, $reason);
            }
        }

        foreach ($zohoRecords as $module => $records) {
            foreach ($records as $zoho) {
                $email = $this->normal($zoho->normalized_email);
                if ($email === null) {
                    continue;
                }
                $matches = $fretiqByEmail->get($email) ?? collect();
                if ($matches->count() !== 1 || ($zohoByEmail[$module]->get($email) ?? collect())->count() !== 1) {
                    $ambiguous++;

                    continue;
                }

                $identity = [
                    'zoho_module' => $module,
                    'zoho_record_id' => (string) $zoho->zoho_id,
                    'fretiq_entity_type' => 'contact',
                    'fretiq_entity_id' => (int) $matches->first()->getKey(),
                    'match_type' => 'exact_email',
                ];
                if ($this->activateMarketingLink($identity, $email)) {
                    $created++;
                }
            }
        }

        return compact('created', 'ambiguous');
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Contact>  $fretiqById
     * @param  \Illuminate\Support\Collection<string,\Illuminate\Support\Collection<int,Contact>>  $fretiqByEmail
     * @param  array<string,\Illuminate\Support\Collection<string,mixed>>  $zohoById
     * @param  array<string,\Illuminate\Support\Collection<string,\Illuminate\Support\Collection<int,mixed>>>  $zohoByEmail
     */
    private function marketingLinkInvalidationReason(
        ZohoMarketingLink $link,
        $fretiqById,
        $fretiqByEmail,
        array $zohoById,
        array $zohoByEmail,
    ): ?string {
        if (strtolower((string) $link->fretiq_entity_type) !== 'contact') {
            return 'fretiq_entity_not_supported';
        }
        $fretiq = $fretiqById->get((int) $link->fretiq_entity_id);
        if (! $fretiq instanceof Contact) {
            return 'fretiq_contact_not_current';
        }

        $module = strtolower(trim((string) $link->zoho_module));
        if (! isset($zohoById[$module])) {
            return 'zoho_module_not_supported';
        }
        $zoho = $zohoById[$module]->get((string) $link->zoho_record_id);
        if ($zoho === null) {
            return 'zoho_record_not_current';
        }

        $fretiqEmail = $this->normal($fretiq->email);
        if ($fretiqEmail === null) {
            return 'fretiq_email_invalid';
        }
        $zohoEmail = $this->normal($zoho->normalized_email);
        if ($zohoEmail === null) {
            return 'zoho_email_invalid';
        }
        if ($fretiqEmail !== $zohoEmail) {
            return 'exact_email_changed';
        }
        if (($fretiqByEmail->get($fretiqEmail) ?? collect())->count() !== 1) {
            return 'fretiq_email_not_unique';
        }
        if (($zohoByEmail[$module]->get($zohoEmail) ?? collect())->count() !== 1) {
            return 'zoho_email_not_unique';
        }

        return null;
    }

    private function invalidateMarketingLink(ZohoMarketingLink $link, string $reason): void
    {
        DB::transaction(function () use ($link, $reason): void {
            $current = ZohoMarketingLink::query()->whereKey($link->getKey())->lockForUpdate()->first();
            if (! $current?->is_active || $current->match_type !== 'exact_email') {
                return;
            }

            $invalidationCount = max(0, (int) $current->invalidation_count) + 1;
            $metadata = is_array($current->audit_metadata) ? $current->audit_metadata : [];
            $current->forceFill([
                'is_active' => false,
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
                'invalidation_count' => $invalidationCount,
                'audit_metadata' => array_merge($metadata, [
                    'event' => 'exact_email_link_invalidated',
                    'reason' => $reason,
                    'match_method' => 'exact_email',
                    'attribution' => 'non_causal',
                    'invalidation_count' => $invalidationCount,
                ]),
            ])->save();
        });
    }

    /** @param array{zoho_module:string,zoho_record_id:string,fretiq_entity_type:string,fretiq_entity_id:int,match_type:string} $identity */
    private function activateMarketingLink(array $identity, string $email): bool
    {
        return DB::transaction(function () use ($identity, $email): bool {
            $link = ZohoMarketingLink::query()->where($identity)->lockForUpdate()->first();
            $hash = $this->emailMatchHash($email);
            $now = now();

            if ($link === null) {
                ZohoMarketingLink::query()->create([...$identity, ...[
                    'match_key_hash' => $hash,
                    'is_active' => true,
                    'matched_at' => $now,
                    'validated_at' => $now,
                    'validation_count' => 1,
                    'invalidation_count' => 0,
                    'audit_metadata' => [
                        'event' => 'exact_email_link_created',
                        'match_method' => 'exact_email',
                        'attribution' => 'non_causal',
                        'validation_count' => 1,
                        'invalidation_count' => 0,
                    ],
                ]]);

                return true;
            }

            if ($link->is_active && $link->match_key_hash === $hash && $link->validated_at !== null) {
                return false;
            }

            $validationCount = max(1, (int) $link->validation_count) + 1;
            $metadata = is_array($link->audit_metadata) ? $link->audit_metadata : [];
            $link->forceFill([
                'match_key_hash' => $hash,
                'is_active' => true,
                'matched_at' => $link->matched_at ?? $now,
                'validated_at' => $now,
                'validation_count' => $validationCount,
                'audit_metadata' => array_merge($metadata, [
                    'event' => 'exact_email_link_revalidated',
                    'match_method' => 'exact_email',
                    'attribution' => 'non_causal',
                    'validation_count' => $validationCount,
                    'invalidation_count' => max(0, (int) $link->invalidation_count),
                    'last_invalidation_reason' => $link->invalidation_reason,
                ]),
            ])->save();

            return false;
        });
    }

    private function emailMatchHash(string $email): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('Application key is required for identity-link hashing.');
        }
        $binary = hash_hmac('sha256', 'zoho-email-link|'.$email, $key, true);

        return 'v1:'.rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function normal(?string $email): ?string
    {
        $email = is_string($email) ? strtolower(trim($email)) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
