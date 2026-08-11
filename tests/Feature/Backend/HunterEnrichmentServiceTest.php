<?php

namespace Tests\Feature\Backend;

use App\Services\Discovery\HunterEnrichmentService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HunterEnrichmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'hunter-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hunter.driver' => 'hunter',
            'services.hunter.api_key' => self::API_KEY,
        ]);
    }

    public function test_local_driver_keeps_fixture_shape_and_makes_no_http_requests(): void
    {
        config(['services.hunter.driver' => 'local']);
        Http::fake();

        $result = $this->service()->domainSearch('geodis.com');

        $fixture = json_decode(
            file_get_contents(base_path('database/fixtures/discovery/hunter.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['geodis.com'];

        $this->assertSame([
            'organization' => 'GEODIS',
            'industry' => 'Logistics',
            'country' => 'FR',
            'emails' => $fixture['emails'],
            'raw' => $fixture,
        ], $result);
        Http::assertNothingSent();
    }

    public function test_local_fallback_scopes_generic_emails_to_requested_domain(): void
    {
        config(['services.hunter.driver' => 'local']);
        Http::fake();

        $result = $this->service()->domainSearch('acme.test');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['emails']);
        foreach ($result['emails'] as $email) {
            $this->assertStringEndsWith('@acme.test', $email['value']);
        }
        Http::assertNothingSent();
    }

    public function test_missing_api_key_returns_null_without_an_http_request(): void
    {
        config(['services.hunter.api_key' => null]);
        Http::fake();

        $this->assertNull($this->service()->domainSearch('acme.test'));
        Http::assertNothingSent();
    }

    public function test_live_domain_search_maps_hunter_response(): void
    {
        $domainData = $this->domainSearchData();
        $companyData = $this->companyData();
        $this->fakeResponses(200, 200, $domainData, $companyData);

        $result = $this->service()->domainSearch('acme.test', 7);

        $this->assertSame([
            'organization' => 'Acme Logistics SAS',
            'industry' => 'Logistics & Supply Chain',
            'country' => 'FR',
            'emails' => $domainData['emails'],
            'raw' => [
                'company' => $companyData,
                'domain_search' => $domainData,
            ],
        ], $result);
        $this->assertExactLiveRequests('acme.test', 7);
    }

    public function test_live_bundle_sends_both_requests_through_the_central_client(): void
    {
        $this->fakeResponses(200, 200, $this->domainSearchData(), $this->companyData());

        $this->assertNotNull($this->service()->domainSearch('acme.test'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
    }

    public function test_live_bundle_forwards_the_seven_second_timeout_to_both_client_calls(): void
    {
        $domainResponse = new Response(new PsrResponse(200, [], json_encode([
            'data' => $this->domainSearchData(),
        ], JSON_THROW_ON_ERROR)));
        $companyResponse = new Response(new PsrResponse(200, [], json_encode([
            'data' => $this->companyData(),
        ], JSON_THROW_ON_ERROR)));

        $domainRequest = Mockery::mock(PendingRequest::class);
        $domainRequest->shouldReceive('acceptJson')->once()->andReturnSelf();
        $domainRequest->shouldReceive('timeout')->once()->with(7)->andReturnSelf();
        $domainRequest->shouldReceive('get')->once()->with(
            'https://api.hunter.io/v2/domain-search',
            ['domain' => 'acme.test', 'limit' => 10, 'offset' => 0],
        )->andReturn($domainResponse);

        $companyRequest = Mockery::mock(PendingRequest::class);
        $companyRequest->shouldReceive('acceptJson')->once()->andReturnSelf();
        $companyRequest->shouldReceive('timeout')->once()->with(7)->andReturnSelf();
        $companyRequest->shouldReceive('get')->once()->with(
            'https://api.hunter.io/v2/companies/find',
            ['domain' => 'acme.test'],
        )->andReturn($companyResponse);

        Http::shouldReceive('withToken')
            ->twice()
            ->with(self::API_KEY)
            ->andReturn($domainRequest, $companyRequest);

        $this->assertNotNull($this->service()->domainSearch('acme.test', 10, 7));
    }

    public function test_company_failure_falls_back_to_domain_organization_and_emails(): void
    {
        $domainData = $this->domainSearchData();
        $this->fakeResponses(200, 503, $domainData, []);

        $result = $this->service()->domainSearch('acme.test');

        $this->assertSame('Acme Domain Org', $result['organization']);
        $this->assertNull($result['industry']);
        $this->assertNull($result['country']);
        $this->assertSame($domainData['emails'], $result['emails']);
        $this->assertSame([
            'company' => null,
            'domain_search' => $domainData,
        ], $result['raw']);
        Http::assertSentCount(2);
    }

    public function test_domain_search_failure_keeps_company_metadata_with_no_emails(): void
    {
        $companyData = $this->companyData();
        $this->fakeResponses(500, 200, [], $companyData);

        $result = $this->service()->domainSearch('acme.test');

        $this->assertSame('Acme Logistics SAS', $result['organization']);
        $this->assertSame('Logistics & Supply Chain', $result['industry']);
        $this->assertSame('FR', $result['country']);
        $this->assertSame([], $result['emails']);
        $this->assertSame([
            'company' => $companyData,
            'domain_search' => null,
        ], $result['raw']);
        Http::assertSentCount(2);
    }

    public function test_both_live_requests_failing_returns_null(): void
    {
        $this->fakeResponses(503, 500, [], []);

        $this->assertNull($this->service()->domainSearch('acme.test'));
        Http::assertSentCount(2);
    }

    public function test_domain_search_exception_does_not_discard_successful_company_metadata(): void
    {
        $companyData = $this->companyData();
        $attemptedUrls = [];
        Http::fake(function (Request $request) use ($companyData, &$attemptedUrls) {
            $attemptedUrls[] = $request->url();

            if (str_contains($request->url(), '/v2/domain-search')) {
                throw new RuntimeException('domain-search unavailable');
            }

            return Http::response(['data' => $companyData], 200);
        });

        $result = $this->service()->domainSearch('acme.test');

        $this->assertSame('Acme Logistics SAS', $result['organization']);
        $this->assertSame('Logistics & Supply Chain', $result['industry']);
        $this->assertSame('FR', $result['country']);
        $this->assertSame([], $result['emails']);
        $this->assertSame([
            'company' => $companyData,
            'domain_search' => null,
        ], $result['raw']);
        $this->assertCount(2, $attemptedUrls);
    }

    public function test_company_exception_does_not_discard_successful_domain_search_data(): void
    {
        $domainData = $this->domainSearchData();
        $attemptedUrls = [];
        Http::fake(function (Request $request) use ($domainData, &$attemptedUrls) {
            $attemptedUrls[] = $request->url();

            if (str_contains($request->url(), '/v2/companies/find')) {
                throw new RuntimeException('company enrichment unavailable');
            }

            return Http::response(['data' => $domainData], 200);
        });

        $result = $this->service()->domainSearch('acme.test');

        $this->assertSame('Acme Domain Org', $result['organization']);
        $this->assertNull($result['industry']);
        $this->assertNull($result['country']);
        $this->assertSame($domainData['emails'], $result['emails']);
        $this->assertSame([
            'company' => null,
            'domain_search' => $domainData,
        ], $result['raw']);
        $this->assertCount(2, $attemptedUrls);
    }

    public function test_exceptions_are_isolated_and_do_not_log_the_api_key(): void
    {
        Log::spy();
        $attemptedUrls = [];
        Http::fake(function (Request $request) use (&$attemptedUrls) {
            $attemptedUrls[] = $request->url();
            throw new RuntimeException('provider exception containing '.self::API_KEY);
        });

        $this->assertNull($this->service()->domainSearch('acme.test'));

        $this->assertCount(2, $attemptedUrls);
        $this->assertStringContainsString('/v2/domain-search', $attemptedUrls[0]);
        $this->assertStringContainsString('/v2/companies/find', $attemptedUrls[1]);
        Log::shouldHaveReceived('error')
            ->twice()
            ->withArgs(function (string $message, array $context): bool {
                return ! str_contains($message.json_encode($context), self::API_KEY);
            });
    }

    public function test_status_aware_search_marks_missing_key_as_provider_failure(): void
    {
        config(['services.hunter.api_key' => null]);
        Http::fake();

        $result = $this->service()->domainSearchResult('acme.test');

        $this->assertSame('provider_failed', $result['status']);
        $this->assertNull($result['data']);
        Http::assertNothingSent();
    }

    public function test_status_aware_search_marks_not_found_as_legitimate_empty(): void
    {
        $this->fakeResponses(404, 404, [], []);

        $result = $this->service()->domainSearchResult('unknown.test');

        $this->assertSame('empty', $result['status']);
        $this->assertNull($result['data']);
    }

    public function test_status_aware_search_marks_rate_or_server_failures_as_provider_failure(): void
    {
        $this->fakeResponses(429, 503, [], []);

        $result = $this->service()->domainSearchResult('acme.test');

        $this->assertSame('provider_failed', $result['status']);
        $this->assertNull($result['data']);
    }

    public function test_status_aware_search_marks_systemic_domain_failure_even_when_company_metadata_succeeds(): void
    {
        $this->fakeResponses(503, 200, [], $this->companyData());

        $result = $this->service()->domainSearchResult('acme.test', 10, 7);

        $this->assertSame('provider_failed', $result['status']);
        $this->assertNotNull($result['data']);
        Http::assertSentCount(2);
    }

    public function test_status_aware_search_marks_timeout_as_provider_failure_even_with_company_metadata(): void
    {
        $this->fakeResponses(408, 200, [], $this->companyData());

        $result = $this->service()->domainSearchResult('acme.test');

        $this->assertSame('provider_failed', $result['status']);
        $this->assertNotNull($result['data']);
        Http::assertSentCount(2);
    }

    private function service(): HunterEnrichmentService
    {
        return app(HunterEnrichmentService::class);
    }

    private function fakeResponses(
        int $domainStatus,
        int $companyStatus,
        array $domainData,
        array $companyData,
    ): void {
        Http::fake(function (Request $request) use ($domainStatus, $companyStatus, $domainData, $companyData) {
            if (str_contains($request->url(), '/v2/domain-search')) {
                return Http::response([
                    'data' => $domainData,
                    'meta' => [
                        'results' => count($domainData['emails'] ?? []),
                        'limit' => 10,
                        'offset' => 0,
                        'params' => ['domain' => 'acme.test'],
                    ],
                ], $domainStatus);
            }

            return Http::response([
                'data' => $companyData,
                'meta' => ['params' => ['domain' => 'acme.test']],
            ], $companyStatus);
        });
    }

    private function assertExactLiveRequests(string $domain, int $limit): void
    {
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($domain, $limit): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.hunter.io/v2/domain-search?domain='.urlencode($domain)
                    .'&limit='.$limit.'&offset=0'
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY);
        });
        Http::assertSent(function (Request $request) use ($domain): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.hunter.io/v2/companies/find?domain='.urlencode($domain)
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY);
        });
    }

    private function domainSearchData(): array
    {
        return [
            'domain' => 'acme.test',
            'disposable' => false,
            'webmail' => false,
            'accept_all' => false,
            'pattern' => '{first}.{last}',
            'organization' => 'Acme Domain Org',
            'description' => 'International freight forwarding.',
            'industry' => null,
            'twitter' => null,
            'facebook' => null,
            'linkedin' => 'https://www.linkedin.com/company/acme',
            'instagram' => null,
            'youtube' => null,
            'technologies' => ['Microsoft 365'],
            'country' => null,
            'state' => null,
            'city' => null,
            'postal_code' => null,
            'street' => null,
            'headcount' => '51-200',
            'emails' => [[
                'value' => 'sales@acme.test',
                'type' => 'generic',
                'confidence' => 92,
                'sources' => [[
                    'domain' => 'acme.test',
                    'uri' => 'https://acme.test/contact',
                    'extracted_on' => '2026-07-01',
                    'last_seen_on' => '2026-07-18',
                    'still_on_page' => true,
                ]],
                'first_name' => null,
                'last_name' => null,
                'position' => null,
                'seniority' => null,
                'department' => 'sales',
                'linkedin' => null,
                'twitter' => null,
                'phone_number' => null,
                'verification' => [
                    'date' => '2026-07-18',
                    'status' => 'valid',
                ],
            ]],
            'linked_domains' => [],
        ];
    }

    private function companyData(): array
    {
        return [
            'name' => 'Acme Logistics SAS',
            'legalName' => 'Acme Logistics Société par actions simplifiée',
            'domain' => 'acme.test',
            'foundedYear' => 1998,
            'site' => [
                'url' => 'https://acme.test',
                'title' => 'Acme Logistics',
                'h1' => 'Freight without borders',
                'metaDescription' => 'International freight forwarding.',
            ],
            'category' => [
                'sector' => 'Industrials',
                'industryGroup' => 'Transportation',
                'industry' => 'Logistics & Supply Chain',
                'subIndustry' => 'Freight and Logistics Services',
            ],
            'metrics' => [
                'employees' => 120,
                'employeesRange' => '51-200',
                'estimatedAnnualRevenue' => '$10M-$50M',
            ],
            'geo' => [
                'streetNumber' => '10',
                'streetName' => 'Rue du Fret',
                'subPremise' => null,
                'city' => 'Paris',
                'postalCode' => '75001',
                'state' => 'Ile-de-France',
                'stateCode' => 'IDF',
                'country' => 'France',
                'countryCode' => 'FR',
                'lat' => 48.8566,
                'lng' => 2.3522,
            ],
            'facebook' => null,
            'linkedin' => ['handle' => 'company/acme'],
            'twitter' => null,
            'crunchbase' => null,
            'emailProvider' => false,
            'type' => 'private',
            'ticker' => null,
            'identifiers' => [],
            'phone' => '+33102030405',
            'tech' => ['Microsoft 365'],
            'techCategories' => ['email'],
            'parent' => null,
            'ultimateParent' => null,
        ];
    }
}
