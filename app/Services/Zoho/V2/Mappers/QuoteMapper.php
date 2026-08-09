<?php

namespace App\Services\Zoho\V2\Mappers;

class QuoteMapper extends AbstractZohoMapper
{
    public function __construct(private readonly QuoteItemMapper $items = new QuoteItemMapper) {}

    public function map(array $payload, array $context = []): array
    {
        return array_merge($this->base($payload, $context), [
            'subject' => $this->value($payload['Subject'] ?? $payload['Quote_Name'] ?? null), 'quote_number' => $this->value($payload['Quote_Number'] ?? null), 'status' => $this->value($payload['Suivie_d_Affaire'] ?? $payload['Quote_Stage'] ?? $payload['Status'] ?? null), 'follow_up_status' => $this->value($payload['Suivie_d_Affaire'] ?? null),
            'valid_till' => $this->value($payload['Valid_Till'] ?? null), 'grand_total' => $this->items->decimal($payload['Grand_Total'] ?? $payload['Total'] ?? null),
            'sub_total' => $this->items->decimal($payload['Sub_Total'] ?? null), 'discount' => $this->items->decimal($payload['Discount'] ?? null),
            'tax' => $this->items->decimal($payload['Tax'] ?? null), 'currency_code' => $this->value($payload['Currency'] ?? null), 'exchange_rate' => $this->items->decimal($payload['Exchange_Rate'] ?? null, true),
            'deal_zoho_id' => $this->lookupId($payload['Deal_Name'] ?? null), 'account_zoho_id' => $this->lookupId($payload['Account_Name'] ?? null),
            'contact_zoho_id' => $this->lookupId($payload['Contact_Name'] ?? null), 'origin' => $this->value($payload['Origine'] ?? null), 'destination' => $this->value($payload['Destination'] ?? null),
            'transport_type' => $this->transportType($payload['Type_de_Transport'] ?? null), 'quote_date' => $this->value($payload['Date_de_Cotation'] ?? null), 'country' => $this->value($payload['Pays'] ?? null),
        ]);
    }

    /**
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>, quoted_items_authoritative: bool}
     */
    public function mapAggregate(array $payload, array $context = []): array
    {
        $quote = $this->map($payload, $context);
        $itemContext = array_merge($context, ['quote_zoho_id' => $quote['zoho_id'], 'currency_code' => $quote['currency_code']]);
        $items = [];
        $quotedItemsPresent = array_key_exists('Quoted_Items', $payload);
        $quotedItems = $payload['Quoted_Items'] ?? null;
        // Zoho explicitly returning an empty subform is authoritative: every
        // previously mirrored line was removed. An omitted or malformed value
        // is not deletion evidence and must preserve the existing lines.
        $quotedItemsAuthoritative = $quotedItemsPresent && is_array($quotedItems);

        foreach (is_array($quotedItems) ? $quotedItems : [] as $sequence => $item) {
            if (is_array($item)) {
                $items[] = $this->items->map($item, array_merge($itemContext, ['sequence' => $sequence + 1]));
            } else {
                $quotedItemsAuthoritative = false;
            }
        }

        $lineItemsTotal = $quotedItemsAuthoritative ? $this->sumItemTotals($items) : null;
        $quote['line_items_total'] = $lineItemsTotal;
        $quote['line_items_total_complete'] = $lineItemsTotal !== null;

        return ['quote' => $quote, 'items' => $items, 'quoted_items_authoritative' => $quotedItemsAuthoritative];
    }

    /** @param list<array<string, mixed>> $items */
    private function sumItemTotals(array $items): ?string
    {
        $cents = '0';

        foreach ($items as $item) {
            $itemCents = $this->toCents($item['total'] ?? null);
            if ($itemCents === null) {
                return null;
            }

            $cents = $this->addUnsignedIntegers($cents, $itemCents);
        }

        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);

        $whole = ltrim(substr($cents, 0, -2), '0');

        return ($whole === '' ? '0' : $whole).'.'.substr($cents, -2);
    }

    private function toCents(mixed $total): ?string
    {
        if (! is_string($total) || preg_match('/^(\d+)(?:\.(\d+))?$/', $total, $matches) !== 1) {
            return null;
        }

        $cents = ltrim($matches[1].str_pad(substr($matches[2] ?? '', 0, 2), 2, '0'), '0') ?: '0';

        return isset($matches[2][2]) && (int) $matches[2][2] >= 5
            ? $this->addUnsignedIntegers($cents, '1')
            : $cents;
    }

    private function addUnsignedIntegers(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry + ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0) + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0);
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private function transportType(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        return $this->value($value);
    }
}
