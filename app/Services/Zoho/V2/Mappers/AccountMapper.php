<?php

namespace App\Services\Zoho\V2\Mappers;

class AccountMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Account_Name'] ?? null), 'phone' => $this->value($payload['Phone'] ?? null),
            'country' => $this->value($payload['Billing_Country'] ?? $payload['Shipping_Country'] ?? null),
            'industry' => $this->value($payload['Industry'] ?? null), 'website' => $this->value($payload['Website'] ?? null),
            'account_type' => $this->value($payload['Type'] ?? null), 'parent_account_zoho_id' => $this->lookupId($payload['Parent_Account'] ?? null),
        ]);
    }
}
