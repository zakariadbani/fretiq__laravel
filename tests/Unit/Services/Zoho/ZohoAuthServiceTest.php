<?php

namespace Tests\Unit\Services\Zoho;

use App\Services\Zoho\ZohoAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ZohoAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_failures_never_expose_response_bodies_or_credentials(): void
    {
        config()->set('services.zoho.crm.refresh_token', 'refresh-secret');
        config()->set('services.zoho.crm.client_id', 'client-secret-id');
        config()->set('services.zoho.crm.client_secret', 'client-secret-value');
        Http::fakeSequence()
            ->push(['error' => 'invalid_client', 'error_description' => 'private@example.test client-secret-value'], 400)
            ->push(['message' => 'private@example.test refresh-secret'], 200);

        foreach (['invalid_client', 'invalid_oauth_response'] as $expectedCode) {
            try {
                app(ZohoAuthService::class)->getAccessToken('crm');
                $this->fail('OAuth refresh should have failed.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($expectedCode, $exception->getMessage());
                $this->assertStringNotContainsString('private@example.test', $exception->getMessage());
                $this->assertStringNotContainsString('client-secret-value', $exception->getMessage());
                $this->assertStringNotContainsString('refresh-secret', $exception->getMessage());
            }
        }
    }
}
