<?php

namespace App\Services\Zoho\V2\Mappers;

class ProductMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        $vendor = $payload['Vendor_Name'] ?? null;

        return array_merge($this->base($payload, $context), [
            'name' => $this->value($payload['Product_Name'] ?? null), 'product_code' => $this->value($payload['Product_Code'] ?? null),
            'unit_price' => $this->decimal($payload['Unit_Price'] ?? null, true), 'currency_code' => $this->value($payload['Currency'] ?? null),
            'product_category' => $this->value($payload['Product_Category'] ?? null),
            'vendor_name' => $this->lookupName($vendor), 'vendor_zoho_id' => $this->lookupId($vendor),
        ]);
    }

    private function lookupName(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->value($value['name'] ?? null);
        }

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }
}
