<?php

namespace App\Services\Zoho\V2\Mappers;

class LeadMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'first_name' => $this->value($payload['First_Name'] ?? null), 'last_name' => $this->value($payload['Last_Name'] ?? null),
            'full_name' => $this->value($payload['Full_Name'] ?? trim(($payload['First_Name'] ?? '').' '.($payload['Last_Name'] ?? ''))),
            'company_name' => $this->value($payload['Company'] ?? null), 'email' => $this->value($payload['Email'] ?? null),
            'normalized_email' => $this->normalizedEmail($payload['Email'] ?? null), 'phone' => $this->value($payload['Phone'] ?? $payload['Mobile'] ?? null), 'country' => $this->value($payload['Country'] ?? null),
            'industry' => $this->value($payload['Industry'] ?? null), 'lead_source' => $this->value($payload['Lead_Source'] ?? null),
            'status' => $this->value($payload['Lead_Status'] ?? null), 'is_converted' => $this->nullableBoolean($payload['Converted__s'] ?? null),
            'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null),
            'contact_zoho_id' => $this->lookupId($payload['Contact_Name'] ?? null),
        ]);
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
