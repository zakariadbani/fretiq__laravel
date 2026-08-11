<?php

namespace Tests\Unit;

use App\Services\Discovery\Engines\GoogleMapsAdapter;
use PHPUnit\Framework\TestCase;

class GoogleMapsStagingTest extends TestCase
{
    public function test_maps_result_without_website_is_preserved(): void
    {
        $payload = ['local_results' => [[
            'title' => 'ACME Maroc',
            'address' => 'Casablanca',
            'phone' => '+212500000000',
            'country' => 'ma',
            'type' => 'Transporteur routier',
            'data_cid' => '123',
        ]]];

        $page = (new GoogleMapsAdapter)->parse($payload, 'ACME Casablanca');

        $this->assertCount(1, $page->candidates);
        $this->assertSame([
            'domain' => null,
            'title' => 'ACME Maroc',
            'snippet' => 'Transporteur routier — Casablanca',
            'url' => null,
            'address' => 'Casablanca',
            'phone' => '+212500000000',
            'country' => 'MA',
            'sector_hint' => 'Transporteur routier',
            'provider_key' => '123',
            'discovery_query' => 'ACME Casablanca',
            'engine' => 'google_maps',
        ], $page->candidates[0]);
    }

    public function test_maps_staging_keeps_nullable_optional_fields_and_sanitizes_provider_key(): void
    {
        $page = (new GoogleMapsAdapter)->parse(['local_results' => [[
            'title' => 'Minimal SARL',
            'website' => '',
            'country' => 'Maroc',
            'data_cid' => "unsafe\nkey",
        ]]], 'minimal');

        $this->assertCount(1, $page->candidates);
        $this->assertNull($page->candidates[0]['url']);
        $this->assertNull($page->candidates[0]['country']);
        $this->assertNull($page->candidates[0]['provider_key']);
        $this->assertSame('google_maps', $page->candidates[0]['engine']);
    }
}
