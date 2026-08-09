<?php

namespace App\Services\Zoho\V2\Mappers;

class ActionsCommercialsMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Name'] ?? null), 'status' => $this->value($payload['tat'] ?? $payload['Record_Status__s'] ?? null),
            'priority' => $this->value($payload['Priorit'] ?? null), 'action_at' => $this->timestamp($payload['Date_Relance'] ?? null), 'due_at' => $this->timestamp($payload['Date_Relance'] ?? null),
            'comment' => $this->value($payload['Commentaire'] ?? null), 'contact_name' => $this->value($payload['Nom_du_Contact'] ?? null),
            'account_name' => $this->value($payload['Nom_du_Compte'] ?? null), 'prospect_name' => $this->value($payload['Nom_du_Prospect'] ?? null),
            'phone' => $this->value($payload['T_l_phone1'] ?? null), 'mobile' => $this->value($payload['GSM1'] ?? null),
        ]);
    }
}
