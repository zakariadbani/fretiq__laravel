<?php

namespace App\Services\Zoho;

/**
 * Safe default until Zoho recipient-list endpoints have empirical verification.
 * It makes the UI fail closed without issuing any HTTP request.
 */
class UnavailableZohoRecipientListGateway implements ZohoRecipientListGateway
{
    public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
    {
        throw $this->unavailable();
    }

    public function listEmails(string $listKey): array
    {
        throw $this->unavailable();
    }

    public function addContacts(string $listKey, array $contacts): void
    {
        throw $this->unavailable();
    }

    private function unavailable(): \LogicException
    {
        return new \LogicException('La préparation de liste Zoho est bloquée : la création, la lecture et l’ajout de contacts ne sont pas encore vérifiés. Aucune campagne Zoho ni aucun envoi n’a été créé.');
    }
}
