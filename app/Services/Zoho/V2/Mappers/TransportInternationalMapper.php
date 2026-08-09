<?php

namespace App\Services\Zoho\V2\Mappers;

class TransportInternationalMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Name'] ?? null), 'status' => $this->value($payload['Record_Status__s'] ?? null),
            'email' => $this->value($payload['Email'] ?? null), 'secondary_email' => $this->value($payload['Secondary_Email'] ?? null),
            'currency_code' => $this->value($payload['Currency'] ?? null), 'exchange_rate' => $this->decimal($payload['Exchange_Rate'] ?? null, true),
        ]);
    }
}
