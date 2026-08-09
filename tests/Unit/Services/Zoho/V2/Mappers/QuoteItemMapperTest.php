<?php

namespace Tests\Unit\Services\Zoho\V2\Mappers;

use App\Services\Zoho\V2\Mappers\QuoteItemMapper;
use Tests\TestCase;

class QuoteItemMapperTest extends TestCase
{
    public function test_it_preserves_and_conservatively_parses_a_formatted_line_total(): void
    {
        $payload = [
            'Product_Name' => ['id' => 'product-1', 'name' => 'Synthetic product'],
            'Quantity' => 2,
            'Prix_Total' => 'MAD 1 234,56',
        ];

        $mapped = (new QuoteItemMapper)->map($payload, [
            'quote_zoho_id' => 'quote-1',
            'sequence' => 1,
            'seen_at' => '2026-08-09T10:00:00+00:00',
            'currency_code' => 'MAD',
        ]);

        $this->assertSame('MAD 1 234,56', $mapped['total_raw']);
        $this->assertSame('1234.56', $mapped['total']);
        $this->assertSame($payload, $mapped['raw_payload']);
        $this->assertSame('MAD', $mapped['currency_code']);
    }

    public function test_it_parses_unambiguous_french_and_english_currency_amounts_without_currency_conversion(): void
    {
        $mapper = new QuoteItemMapper;
        $context = ['quote_zoho_id' => 'quote-money', 'sequence' => 1, 'seen_at' => '2026-08-09T10:00:00+00:00'];

        foreach ([
            ['MAD', 'MAD 1 234,56', '1234.56'],
            ['EUR', '1.234,50 €', '1234.5'],
            ['USD', '$1,234.50', '1234.5'],
        ] as [$currency, $raw, $expected]) {
            $mapped = $mapper->map(['Product_Name' => ['id' => 'product-money'], 'Quantity' => 1, 'Prix_Total' => $raw], array_merge($context, ['currency_code' => $currency]));

            $this->assertSame($raw, $mapped['total_raw']);
            $this->assertSame($expected, $mapped['total']);
            $this->assertSame($currency, $mapped['currency_code']);
        }
    }

    public function test_it_rejects_ambiguous_or_arbitrary_formatted_amounts_but_keeps_the_exact_raw_value(): void
    {
        $mapper = new QuoteItemMapper;
        $context = ['quote_zoho_id' => 'quote-ambiguous', 'sequence' => 1, 'seen_at' => '2026-08-09T10:00:00+00:00'];

        foreach (['1,234', 'EUR approximately 1200', '1.23.45'] as $raw) {
            $mapped = $mapper->map(['Product_Name' => ['id' => 'product-ambiguous'], 'Quantity' => 1, 'Prix_Total' => $raw, 'Prix_1x40' => $raw], $context);

            $this->assertNull($mapped['total']);
            $this->assertSame($raw, $mapped['total_raw']);
            $this->assertNull($mapped['unit_price']);
            $this->assertSame($raw, $mapped['unit_price_raw']);
        }
    }

    public function test_it_uses_normalized_prices_or_canonical_raw_prices_for_stable_fallback_ids(): void
    {
        $mapper = new QuoteItemMapper;
        $context = ['quote_zoho_id' => 'quote-stable', 'sequence' => 2, 'seen_at' => '2026-08-09T10:00:00+00:00'];

        $first = $mapper->map(['Product_Name' => ['id' => 'product-stable'], 'Quantity' => '2.00', 'Prix_1x40' => 'EUR 9,99'], $context);
        $second = $mapper->map(['Product_Name' => ['id' => 'product-stable'], 'Quantity' => 2, 'Prix_1x40' => '9.99 €'], $context);
        $ambiguousFirst = $mapper->map(['Product_Name' => ['id' => 'product-raw'], 'Quantity' => 1, 'Prix_1x40' => 'EUR 1,234'], $context);
        $ambiguousSecond = $mapper->map(['Product_Name' => ['id' => 'product-raw'], 'Quantity' => 1, 'Prix_1x40' => 'EUR 1,234'], $context);

        $this->assertSame($first['zoho_line_item_id'], $second['zoho_line_item_id']);
        $this->assertSame($ambiguousFirst['zoho_line_item_id'], $ambiguousSecond['zoho_line_item_id']);
        $this->assertNull($ambiguousFirst['unit_price']);
    }
}
