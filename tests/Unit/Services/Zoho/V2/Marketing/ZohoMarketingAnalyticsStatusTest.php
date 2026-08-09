<?php

namespace Tests\Unit\Services\Zoho\V2\Marketing;

use App\Services\Zoho\V2\Marketing\ZohoMarketingAnalytics;
use PHPUnit\Framework\TestCase;

class ZohoMarketingAnalyticsStatusTest extends TestCase
{
    public function test_only_verified_deal_terminal_stages_are_classified(): void
    {
        $this->assertSame('won', ZohoMarketingAnalytics::dealOutcome(' Affaire gagné '));
        $this->assertSame('lost', ZohoMarketingAnalytics::dealOutcome('Closed Lost to Competition'));
        $this->assertSame('lost', ZohoMarketingAnalytics::dealOutcome('Affaire  perdu'));
        $this->assertNull(ZohoMarketingAnalytics::dealOutcome('Devis confirmé'));
    }

    public function test_only_exact_quote_follow_up_statuses_are_outcomes(): void
    {
        $this->assertSame('won', ZohoMarketingAnalytics::quoteOutcome('Affaire gagnée'));
        $this->assertSame('lost', ZohoMarketingAnalytics::quoteOutcome(' affaire perdue '));
        $this->assertNull(ZohoMarketingAnalytics::quoteOutcome('Devis confirmé'));
    }
}
