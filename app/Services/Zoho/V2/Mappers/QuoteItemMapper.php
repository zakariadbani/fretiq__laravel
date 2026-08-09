<?php

namespace App\Services\Zoho\V2\Mappers;

use InvalidArgumentException;

class QuoteItemMapper extends AbstractZohoMapper
{
    public function map(array $payload, array $context = []): array
    {
        $quoteId = $context['quote_zoho_id'] ?? null;
        if ($quoteId === null || $quoteId === '') {
            throw new InvalidArgumentException('Quote line items require quote_zoho_id in mapper context.');
        }

        $sequence = $context['sequence'] ?? 0;
        $productId = $this->lookupId($payload['product'] ?? $payload['Product_Name'] ?? null);
        $quantity = $payload['quantity'] ?? $payload['Quantity'] ?? null;
        $machineUnitPrice = array_key_exists('list_price', $payload) || array_key_exists('Unit_Price', $payload) || array_key_exists('unit_price', $payload);
        $unitPriceRaw = $payload['list_price'] ?? $payload['Unit_Price'] ?? $payload['unit_price'] ?? $payload['Prix_1x40'] ?? null;
        $unitPrice = $this->decimal($unitPriceRaw, $machineUnitPrice);
        $totalRaw = $payload['total'] ?? $payload['Total'] ?? $payload['Prix_Total'] ?? null;
        $total = $this->decimal($totalRaw);
        $nativeId = $payload['id'] ?? null;
        $identitySource = $nativeId === null || $nativeId === '' ? 'fallback' : 'native';
        $lineId = $identitySource === 'fallback'
            ? hash('sha256', implode('|', [(string) $quoteId, (string) $productId, (string) $sequence, $this->number($quantity), $unitPrice ?? $this->canonicalRaw($unitPriceRaw)]))
            : (string) $nativeId;

        return [
            'zoho_quote_id' => (string) $quoteId, 'zoho_line_item_id' => $lineId, 'identity_source' => $identitySource, 'product_zoho_id' => $productId,
            'product_name' => $this->lookupName($payload['product'] ?? $payload['Product_Name'] ?? null),
            'sequence' => (int) $sequence, 'quantity' => $this->decimal($quantity, true), 'list_price' => $this->decimal($payload['list_price'] ?? $payload['List_Price'] ?? null, true), 'unit_price' => $unitPrice, 'unit_price_raw' => $this->rawScalar($unitPriceRaw),
            'description' => $this->value($payload['Description'] ?? $payload['description'] ?? null), 'unit_of_measure' => $this->value($payload['Unit_de_Mesure'] ?? $payload['Unit_of_Measure'] ?? null),
            'discount' => $this->decimal($payload['discount'] ?? $payload['Discount'] ?? null, true), 'tax' => $this->decimal($payload['tax'] ?? $payload['Tax'] ?? null, true),
            'total' => $total, 'total_raw' => $this->rawScalar($totalRaw), 'currency_code' => $this->value($context['currency_code'] ?? $payload['Currency'] ?? null),
            'raw_payload' => $payload, 'payload_hash' => $this->payloadHash($payload), 'field_schema_hash' => $context['schema_hash'] ?? null,
            'zoho_created_at' => $this->timestamp($payload['Created_Time'] ?? null), 'zoho_modified_at' => $this->timestamp($payload['Modified_Time'] ?? null),
            'last_seen_at' => $this->timestamp($context['seen_at'] ?? null), 'last_synced_at' => $this->timestamp($context['synced_at'] ?? $context['seen_at'] ?? null),
            'zoho_deleted_at' => null, 'zoho_deletion_type' => null, 'sync_batch_id' => $context['sync_batch_id'] ?? null,
        ];
    }

    private function lookupName(mixed $value): ?string
    {
        return is_array($value) ? $this->value($value['name'] ?? null) : null;
    }

    private function number(mixed $value): string
    {
        return $this->decimal($value, true) ?? $this->canonicalRaw($value);
    }

    private function rawScalar(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function canonicalRaw(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', (string) $value));
    }
}
