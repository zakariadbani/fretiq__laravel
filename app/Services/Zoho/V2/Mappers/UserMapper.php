<?php

namespace App\Services\Zoho\V2\Mappers;

class UserMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'full_name' => $this->value($payload['full_name'] ?? $payload['Full_Name'] ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''))),
            'first_name' => $this->value($payload['first_name'] ?? $payload['First_Name'] ?? null),
            'last_name' => $this->value($payload['last_name'] ?? $payload['Last_Name'] ?? null),
            'email' => $this->value($payload['email'] ?? $payload['Email'] ?? null), 'normalized_email' => $this->normalizedEmail($payload['email'] ?? $payload['Email'] ?? null), 'status' => $this->value($payload['status'] ?? $payload['Status'] ?? null),
        ]);
    }

    /** @param array<string, mixed> $lookup */
    public function mapOwnerLookup(array $lookup, array $context = []): array
    {
        return $this->map(array_merge($lookup, ['id' => $lookup['id'] ?? null, 'full_name' => $lookup['name'] ?? null]), $context);
    }
}
