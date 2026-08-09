<?php

namespace App\Services\Zoho\V2\Mappers;

class DealMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Deal_Name'] ?? null), 'stage' => $this->value($payload['Stage'] ?? null),
            'amount' => $this->decimal($payload['Amount'] ?? null, true), 'currency_code' => $this->value($payload['Currency'] ?? null),
            'probability' => $this->decimal($payload['Probability'] ?? null, true), 'weighted_amount' => $this->weightedAmount($payload['Amount'] ?? null, $payload['Probability'] ?? null), 'closing_date' => $this->value($payload['Closing_Date'] ?? null),
            'lead_source' => $this->value($payload['Lead_Source'] ?? null), 'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null),
            'contact_zoho_id' => $this->lookupId($payload['Contact_Name'] ?? null),
        ]);
    }
}
