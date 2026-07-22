<?php

namespace Tests\Unit;

use App\Services\Campaign\UnsubscribeHtmlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure static unit tests for UnsubscribeHtmlNormalizer::normalize()'s three-element
 * return contract: [html, preserved, failed].
 *
 * No network, no DB, no Laravel bootstrap required.
 */
class UnsubscribeHtmlNormalizerTest extends TestCase
{
    public function test_normalize_reports_failed_false_on_clean_input_with_no_link(): void
    {
        [$html, $preserved, $failed] = UnsubscribeHtmlNormalizer::normalize(
            '<p>Contenu sans lien de désabonnement</p>',
            '$[LI:UNSUBSCRIBE]$',
        );

        $this->assertFalse($preserved);
        $this->assertFalse($failed);
        $this->assertSame('<p>Contenu sans lien de désabonnement</p>', $html);
    }

    public function test_normalize_reports_failed_false_on_clean_input_with_preserved_link(): void
    {
        [$html, $preserved, $failed] = UnsubscribeHtmlNormalizer::normalize(
            '<p><a href="$[LI:UNSUBSCRIBE]$">Se désabonner</a></p>',
            '$[LI:UNSUBSCRIBE]$',
        );

        $this->assertTrue($preserved);
        $this->assertFalse($failed);
        $this->assertStringContainsString('href="$[LI:UNSUBSCRIBE]$"', $html);
    }

    public function test_normalize_reports_failed_true_on_pcre_backtrack_failure(): void
    {
        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '100');
            [$html, $preserved, $failed] = UnsubscribeHtmlNormalizer::normalize(
                '<p><a href="$[LI:UNSUBSCRIBE]$">Premier lien</a></p>'
                    . '<a ' . str_repeat('x', 10000)
                    . ' href="$[LI:UNSUBSCRIBE]$">Lien pathologique</a>',
                '$[LI:UNSUBSCRIBE]$',
            );
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }

        $this->assertFalse($preserved);
        $this->assertTrue($failed);
        $this->assertIsString($html);
    }
}
