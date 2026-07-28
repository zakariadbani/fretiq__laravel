<?php

namespace App\Exceptions;

use RuntimeException;

final class ZohoInvalidRecipientException extends RuntimeException
{
    public readonly string $email;

    /**
     * API-level `code` values from POST /json/listsubscribe that are live-proven
     * to mean "this contact's email address is invalid" — trusted per-contact
     * even when a wave has zero other acceptances.
     *
     * Live-verified (prod): 2007 (2026-07-27) and 2005 (2026-07-28) both mean
     * the same thing — Zoho considers the contact's email invalid — under
     * different codes. 2005 was observed for webmaster@securitedabord.ma and
     * support@africare.ma.
     */
    public const VERIFIED_INVALID_EMAIL_CODES = ['2005', '2007'];

    public function __construct(
        string $email,
        public readonly string $zohoCode,
    ) {
        $this->email = mb_strtolower(trim($email));

        parent::__construct("Zoho rejected invalid contact email {$this->email} (code {$zohoCode}).");
    }

    /** Whether this rejection is a live-proven address-level code, not just an unrecognized one. */
    public function isVerifiedInvalidEmail(): bool
    {
        return in_array($this->zohoCode, self::VERIFIED_INVALID_EMAIL_CODES, true);
    }
}
