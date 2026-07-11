<?php

namespace App\Services\Zoho;

/**
 * Narrow boundary for an add-only, campaign-owned Zoho recipient list.
 *
 * This intentionally excludes campaign creation, sending, and recipient removal.
 * Implementations must return complete membership so the caller can verify that
 * every prepared Fretiq email is present while retaining existing members.
 */
interface ZohoRecipientListGateway
{
    /**
     * Ensure exactly one dedicated Zoho list for the given Fretiq campaign and
     * return Zoho's stable, actual list key (not a locally derived placeholder).
     */
    /**
     * @param array<int, array<string, string>> $seedContacts Approved contacts used
     *        only if a new list must be created (Zoho requires at least one).
     */
    public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string;

    /** @return string[] Complete current email membership for the list. */
    public function listEmails(string $listKey): array;

    /** @param array<int, array<string, string>> $contacts */
    public function addContacts(string $listKey, array $contacts): void;
}
