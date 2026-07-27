<?php

namespace Tests\Unit;

use App\Models\ProspectCriteria;
use App\Services\Discovery\HunterDiscoverService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HunterDiscoverServiceTest extends TestCase
{
    public function test_live_request_contains_target_exclude_and_context_only_once(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::fake(['api.hunter.io/*' => Http::response(['data' => [['domain' => 'Example.com', 'organization' => 'Example', 'emails_count' => ['personal' => 2, 'generic' => 1, 'total' => 3]]]])]);
        $criteria = new ProspectCriteria(['sectors' => ['Logistique'], 'countries' => ['FR'], 'company_sizes' => ['11-50']]);

        $result = app(HunterDiscoverService::class)->preview($criteria, 'Exportateurs', 'Concurrents');

        $this->assertTrue($result['ok']);
        $this->assertSame('example.com', $result['companies'][0]['domain']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.hunter.io/v2/discover'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && array_keys($request->data()) === ['query']
            && str_contains($request['query'], 'Cible: Exportateurs')
            && str_contains($request['query'], 'Exclure: Concurrents')
            && str_contains($request['query'], 'Secteurs: Logistique')
            && ! str_contains($request->body(), 'limit')
            && ! str_contains($request->body(), 'offset'));
    }

    public function test_provider_failure_is_structured_and_not_retried(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::fake(['api.hunter.io/*' => Http::response([], 503)]);

        $result = app(HunterDiscoverService::class)->preview(new ProspectCriteria, 'transport', null);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
        Http::assertSentCount(1);
    }
    public function test_provider_errors_are_mapped_to_safe_http_statuses(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);

        Http::fakeSequence()
            ->push([], 401)
            ->push([], 403)
            ->push([], 400)
            ->push([], 422)
            ->push([], 429)
            ->push([], 503);

        foreach ([503, 503, 422, 422, 429, 503] as $expectedStatus) {
            $result = app(HunterDiscoverService::class)->preview(new ProspectCriteria, 'transport', null);
            $this->assertFalse($result['ok']);
            $this->assertSame($expectedStatus, $result['status']);
        }
    }
    public function test_local_fixture_excludes_default_and_counts_emails(): void
    {
        config(['services.hunter.driver' => 'local']);
        $result = app(HunterDiscoverService::class)->preview(new ProspectCriteria, 'transport', null);

        $this->assertTrue($result['ok']);
        $this->assertNotContains('__default__', array_column($result['companies'], 'domain'));
        $this->assertContains('geodis.com', array_column($result['companies'], 'domain'));
        $this->assertSame(2, collect($result['companies'])->firstWhere('domain', 'geodis.com')['emails_count']['total']);
    }

    public function test_normalization_dedupes_and_blocks_non_company_domains(): void
    {
        $rows = app(HunterDiscoverService::class)->normalize([
            ['domain' => 'EXAMPLE.COM', 'organization' => 'First', 'emails_count' => ['personal' => 1]],
            ['domain' => 'example.com', 'organization' => 'Duplicate'],
            ['domain' => 'facebook.com', 'organization' => 'Blocked'],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('example.com', $rows[0]['domain']);
        $this->assertSame(['personal' => 1, 'generic' => 0, 'total' => 1], $rows[0]['emails_count']);
    }
}
