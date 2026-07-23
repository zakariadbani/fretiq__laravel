<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CampaignTemplateContactDetailsTest extends TestCase
{
    public function test_detailed_footer_uses_tcl_transport_sales_email(): void
    {
        $footer = file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/emails/builder/sections/footer-detailed.blade.php'
        );

        $this->assertIsString($footer);
        $this->assertStringContainsString('mailto:sales@tcltransport.com', $footer);
        $this->assertStringContainsString('>sales@tcltransport.com</a>', $footer);
        $this->assertStringNotContainsString('sales@tcl.ma', $footer);
    }
}
