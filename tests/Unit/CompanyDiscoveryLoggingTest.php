<?php

namespace Tests\Unit;

use Tests\TestCase;

class CompanyDiscoveryLoggingTest extends TestCase
{
    public function test_http_exception_logs_cannot_include_exception_messages_or_api_keys(): void
    {
        $source = file_get_contents(app_path('Services/Discovery/CompanyDiscoveryService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->getMessage()', $source);
        $this->assertStringContainsString("'exception_class'", $source);
    }
}
