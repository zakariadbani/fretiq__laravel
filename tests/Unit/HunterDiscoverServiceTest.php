<?php

namespace Tests\Unit;

use App\Models\ProspectCriteria;
use App\Services\Discovery\HunterDiscoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HunterDiscoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_builds_target_exclude_and_context_without_provider_io(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::preventStrayRequests();
        $criteria = new ProspectCriteria(['sectors' => ['Logistique'], 'countries' => ['FR'], 'company_sizes' => ['11-50']]);

        $result = app(HunterDiscoverService::class)->preview($criteria, 'Exportateurs', 'Concurrents');

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['companies']);
        $this->assertSame([], $result['filters']);
        $this->assertStringContainsString('Cible: Exportateurs.', $result['prompt']);
        $this->assertStringContainsString('Exclure: Concurrents.', $result['prompt']);
        $this->assertStringContainsString('Secteurs: Logistique.', $result['prompt']);
        Http::assertNothingSent();
    }

    public function test_filters_are_allowlisted_and_canonicalized_before_persistence(): void
    {
        $filters = app(HunterDiscoverService::class)->normalizeFilters([
            'industry' => ['include' => [' Logistics ', 'sales@example.test', 'https://example.test', 'Logistics']],
            'headquarters_location' => ['include' => [[
                'country' => 'FR',
                'city' => ' Paris ',
                'url' => 'https://example.test',
            ]]],
            'unknown_provider_field' => ['secret' => true],
        ]);

        $this->assertSame([
            'headquarters_location' => ['include' => [['city' => 'Paris', 'country' => 'FR']]],
            'industry' => ['include' => ['Logistics']],
        ], $filters);
    }

    public function test_prompt_hash_is_stable_for_equivalent_whitespace_and_set_order(): void
    {
        $service = app(HunterDiscoverService::class);
        $first = new ProspectCriteria([
            'sectors' => ['Transport', 'Logistique'],
            'countries' => ['FR', 'MA'],
            'company_sizes' => ['11-50'],
        ]);
        $second = new ProspectCriteria([
            'sectors' => [' Logistique ', 'Transport'],
            'countries' => [' MA ', 'FR'],
            'company_sizes' => [' 11-50 '],
        ]);

        $this->assertSame(
            $service->promptHash($first, ' Exportateurs   industriels ', ' Concurrents locaux '),
            $service->promptHash($second, 'Exportateurs industriels', 'Concurrents   locaux'),
        );
    }

    public function test_batch_normalization_keeps_company_without_domain_for_review(): void
    {
        $rows = app(HunterDiscoverService::class)->normalizeBatchRows([
            [
                'organization' => 'Entreprise sans site',
                'country' => 'fr',
                'city' => 'Paris',
                'emails_count' => ['personal' => 2, 'generic' => 1, 'total' => 3],
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Entreprise sans site', $rows[0]['company_name']);
        $this->assertNull($rows[0]['provided_domain']);
        $this->assertSame('FR', $rows[0]['country']);
        $this->assertSame(3, $rows[0]['source_metadata']['emails_count']['total']);
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
