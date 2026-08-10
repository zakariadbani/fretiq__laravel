<?php

namespace App\Support;

class EmailKind
{
    /**
     * Classify an email address by domain into a deliverability kind.
     *   free-webmail / private inbox  → 'personal'
     *   corporate domain (named or role) → 'role'    (deliverable)
     */
    public static function classify(?string $email): string
    {
        $at = strrchr((string) $email, '@');
        $domain = $at ? strtolower(trim(substr($at, 1))) : '';

        if ($domain === '') {
            return 'role'; // unknown domain → deliverable default (matches contacts column default)
        }

        return in_array($domain, (array) config('prospecting.freemail_domains', []), true)
            ? 'personal'
            : 'role';
    }
}
