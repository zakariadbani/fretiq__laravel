<?php

namespace App\Services\Zoho\V2\Mappers;

class ContactMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'first_name' => $this->value($payload['First_Name'] ?? null), 'last_name' => $this->value($payload['Last_Name'] ?? null),
            'full_name' => $this->value($payload['Full_Name'] ?? trim(($payload['First_Name'] ?? '').' '.($payload['Last_Name'] ?? ''))),
            'email' => $this->value($payload['Email'] ?? null), 'normalized_email' => $this->normalizedEmail($payload['Email'] ?? null), 'phone' => $this->value($payload['Phone'] ?? $payload['Mobile'] ?? null),
            'country' => $this->value($payload['Mailing_Country'] ?? $payload['Other_Country'] ?? null),
            'title' => $this->value($payload['Title'] ?? null), 'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null),
        ]);
    }
}
