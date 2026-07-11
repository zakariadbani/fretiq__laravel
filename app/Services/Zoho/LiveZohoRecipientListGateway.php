<?php

namespace App\Services\Zoho;

/**
 * Live, no-send gateway for one add-only mailing list per Fretiq campaign.
 * This boundary deliberately exposes neither Zoho campaign creation nor sending.
 */
class LiveZohoRecipientListGateway implements ZohoRecipientListGateway
{
    public function __construct(private readonly ZohoCampaignsClient $client) {}

    public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
    {
        $seedEmails = collect($seedContacts)
            ->map(fn (array $contact) => trim((string) ($contact['Contact Email'] ?? $contact['email'] ?? '')))
            ->filter()
            ->values()
            ->all();

        if ($seedEmails === []) {
            throw new \LogicException('La préparation de liste Zoho nécessite au moins un destinataire approuvé pour créer la liste dédiée. Aucun envoi n’a été créé.');
        }

        return $this->client->createRecipientList($listName, $seedEmails);
    }

    public function listEmails(string $listKey): array
    {
        return $this->client->listRecipientEmails($listKey);
    }

    public function addContacts(string $listKey, array $contacts): void
    {
        $this->client->addRecipientContacts($listKey, $contacts);
    }
}
