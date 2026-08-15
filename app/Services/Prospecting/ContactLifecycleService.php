<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Read-only lifecycle projection.  This deliberately has no persisted column:
 * delivery, suppression, reply and verification ledgers remain authoritative.
 */
final class ContactLifecycleService
{
    public const STATES = [
        'unsubscribed', 'blocked', 'bounced', 'invalid_email', 'replied',
        'contacted', 'verified', 'verification_pending', 'needs_verification',
    ];

    public function select(Builder|Relation $query, string $alias = 'lifecycle_state'): Builder|Relation
    {
        return $query->addSelect('contacts.*')
            ->selectRaw($this->caseSql('contacts') . " as {$alias}");
    }

    public function applyState(Builder $query, string|array $states): Builder
    {
        $states = array_values(array_intersect((array) $states, self::STATES));
        if ($states === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(DB::raw($this->caseSql('contacts')), $states);
    }

    public function orderByState(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $rank = 'CASE (' . $this->caseSql('contacts') . ')';
        foreach (self::STATES as $index => $state) {
            $rank .= " WHEN '{$state}' THEN " . ($index + 1);
        }

        return $query->orderByRaw($rank . " ELSE 99 END {$direction}");
    }

    public function stateFor(Contact $contact): string
    {
        return (string) (Contact::query()->whereKey($contact->getKey())
            ->selectRaw($this->caseSql('contacts') . ' as lifecycle_state')
            ->value('lifecycle_state') ?? 'needs_verification');
    }

    public function label(string $state): array
    {
        return config('global.data.contact_lifecycle_states.' . $state, config('global.data.contact_lifecycle_states.needs_verification'));
    }

    public function caseSql(string $table = 'contacts'): string
    {
        $email = "LOWER(TRIM({$table}.email))";

        return "CASE
            WHEN EXISTS (SELECT 1 FROM suppressions s WHERE LOWER(TRIM(s.email)) = {$email} AND s.reason = 'unsubscribe') THEN 'unsubscribed'
            WHEN EXISTS (SELECT 1 FROM suppressions s WHERE LOWER(TRIM(s.email)) = {$email} AND s.reason IN ('manual', 'spam', 'complaint')) THEN 'blocked'
            WHEN EXISTS (SELECT 1 FROM suppressions s WHERE LOWER(TRIM(s.email)) = {$email} AND s.reason IN ('hard_bounce', 'soft_bounce'))
              OR {$table}.email_verification_source = 'bounce' THEN 'bounced'
            WHEN EXISTS (SELECT 1 FROM suppressions s WHERE LOWER(TRIM(s.email)) = {$email} AND s.reason IN ('invalid_email', 'claimed'))
              OR {$table}.email_verification_status IN ('invalid', 'disposable') THEN 'invalid_email'
            WHEN EXISTS (SELECT 1 FROM inbox_emails ie WHERE ie.contact_id = {$table}.id)
              OR EXISTS (SELECT 1 FROM campaign_recipients cr WHERE cr.contact_id = {$table}.id AND (cr.status = 'replied' OR cr.replied_at IS NOT NULL)) THEN 'replied'
            WHEN EXISTS (SELECT 1 FROM campaign_recipients cr WHERE cr.contact_id = {$table}.id AND cr.sent_at IS NOT NULL)
              OR EXISTS (SELECT 1 FROM sequence_step_sends ss INNER JOIN sequence_enrollments se ON se.id = ss.enrollment_id WHERE se.contact_id = {$table}.id AND ss.sent_at IS NOT NULL) THEN 'contacted'
            WHEN {$table}.email_verification_status = 'valid' THEN 'verified'
            WHEN {$table}.email_verification_status = 'pending' THEN 'verification_pending'
            ELSE 'needs_verification' END";
    }
}
