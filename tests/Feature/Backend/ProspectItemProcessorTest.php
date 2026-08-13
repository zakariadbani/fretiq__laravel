<?php

namespace Tests\Feature\Backend;

use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectItemProcessor;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ProspectItemProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hunter.driver' => 'hunter',
            'services.hunter.api_key' => 'test-hunter-key',
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-serp-key',
            'cache.default' => 'array',
        ]);
        Http::preventStrayRequests();
    }

    public function test_provided_valid_non_platform_domain_skips_domain_finder(): void
    {
        $item = $this->item(['provided_domain' => 'https://www.acme.fr']);
        $baselineTransactionLevel = DB::transactionLevel();
        $transportTransactionLevels = [];

        Http::fake(function (Request $request) use (&$transportTransactionLevels, $baselineTransactionLevel) {
            $transportTransactionLevels[] = DB::transactionLevel();
            $this->assertSame($baselineTransactionLevel, DB::transactionLevel());

            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => $this->companyEnrichment()], 200);
            }

            if (str_contains($request->url(), '/domain-search')) {
                return Http::response([
                    'data' => ['domain' => 'acme.fr', 'emails' => []],
                    'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
                ], 200);
            }

            return Http::response([], 500);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertSame('acme.fr', $item->selected_domain);
        $this->assertSame('acme.fr', $item->registrable_domain);
        $this->assertSame('provided_domain', $item->domain_reason);
        $this->assertSame([$baselineTransactionLevel, $baselineTransactionLevel], $transportTransactionLevels);
        $this->assertSame(0, ProviderCall::query()->where('operation', 'domain_finder')->count());
        $this->assertSame(2, ProviderCall::query()->where('status', 'succeeded')->count());
        $companyCall = ProviderCall::query()->where('operation', 'company_enrichment')->sole();
        $this->assertSame('0.20', $companyCall->reserved_units);
        $this->assertSame('0.20', $companyCall->consumed_units);
        $domainCall = ProviderCall::query()->where('operation', 'domain_search')->sole();
        $this->assertSame('10.00', $domainCall->reserved_units);
        $this->assertSame('0.00', $domainCall->consumed_units);
    }

    public function test_company_link_lock_contention_is_retried_instead_of_becoming_domain_review(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->with(3)->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        try {
            app(ProspectItemProcessor::class)->process($item);
            $this->fail('Lock contention must release the item for a retry.');
        } catch (ProviderRequestException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('prospect_item_promotion_lock_timeout', $exception->safeCode);
        }

        $item->refresh();
        $this->assertSame('pending', $item->status);
        $this->assertSame('prospect_item_promotion_lock_timeout', $item->error_code);
        $this->assertSame('provided_domain', $item->domain_reason);
    }

    public function test_perfect_domain_finder_match_is_auto_selected_when_name_and_country_agree(): void
    {
        $item = $this->item();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-finder')) {
                return Http::response(['data' => [[
                    'domain' => 'acme.fr',
                    'company_name' => 'Acme Logistics SAS',
                    'country' => 'FR',
                    'perfect_match' => true,
                ]]], 200);
            }

            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => $this->companyEnrichment()], 200);
            }

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => []],
                'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertSame('acme.fr', $item->selected_domain);
        $this->assertSame('hunter_perfect_match', $item->domain_reason);
        $this->assertDatabaseHas('provider_calls', [
            'prospect_batch_item_id' => $item->id,
            'operation' => 'domain_finder',
            'status' => 'succeeded',
        ]);
    }

    public function test_official_finder_response_without_match_flag_uses_requested_perfect_mode_and_country_tld(): void
    {
        $item = $this->item();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-finder')) {
                $this->assertSame('1', (string) $request->data()['perfect_match']);

                return Http::response(['data' => [[
                    'domain' => 'acme.fr',
                    'company_name' => 'Acme Logistics SAS',
                ]]], 200);
            }
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => []],
                'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertSame('acme.fr', $item->fresh()->selected_domain);
        $this->assertSame('hunter_perfect_match', $item->fresh()->domain_reason);
    }

    public function test_finder_without_country_on_generic_tld_stays_in_review(): void
    {
        $item = $this->item();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-finder')) {
                return Http::response(['data' => [[
                    'domain' => 'acme.com',
                    'company_name' => 'Acme Logistics SAS',
                ]]], 200);
            }
            if (($request->data()['engine'] ?? null) === 'google') {
                return Http::response(['organic_results' => []], 200);
            }

            return Http::response(['local_results' => []], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('review', $item->fresh()->status);
        $this->assertNull($item->fresh()->selected_domain);
        $this->assertSame('ambiguous_domain', $item->fresh()->domain_reason);
    }

    public function test_ambiguous_domain_finder_result_falls_back_to_google_then_maps(): void
    {
        $item = $this->item();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-finder')) {
                return Http::response(['data' => [
                    ['domain' => 'acme-logistics.fr', 'company_name' => 'Acme Logistics', 'country' => 'FR', 'perfect_match' => false],
                    ['domain' => 'acme-group.fr', 'company_name' => 'Acme Group', 'country' => 'FR', 'perfect_match' => false],
                ]], 200);
            }

            $engine = $request->data()['engine'] ?? null;
            if ($engine === 'google') {
                return Http::response(['organic_results' => [[
                    'title' => 'Acme Logistics',
                    'link' => 'https://www.acme-transport.fr/services',
                ]]], 200);
            }

            return Http::response(['local_results' => [[
                'title' => 'Acme Logistics',
                'website' => 'https://acme-fret.fr',
                'address' => '10 rue du Fret, Paris',
                'phone' => '+33102030405',
                'place_id' => 'place-safe-1',
            ]]], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertSame('ambiguous_domain', $item->domain_reason);
        $this->assertNull($item->selected_domain);
        $this->assertSame([
            'acme-fret.fr',
            'acme-group.fr',
            'acme-logistics.fr',
            'acme-transport.fr',
        ], $item->domain_alternatives);
        $this->assertSame(
            ['domain_finder', 'google', 'google_maps'],
            ProviderCall::query()->orderBy('id')->pluck('operation')->all(),
        );
    }

    public function test_maps_without_site_remains_reviewable_with_name_address_and_phone(): void
    {
        $item = $this->item(['company_name' => 'Beta Transit', 'normalized_name' => 'beta transit']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-finder')) {
                return Http::response(['data' => []], 200);
            }

            if (($request->data()['engine'] ?? null) === 'google') {
                return Http::response(['organic_results' => []], 200);
            }

            return Http::response(['local_results' => [[
                'title' => 'Beta Transit',
                'address' => '5 quai du Port, Marseille',
                'phone' => '+33401020304',
                'data_id' => 'maps-safe-42',
            ]]], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertSame('missing_domain', $item->domain_reason);
        $this->assertSame([], $item->domain_alternatives);
        $this->assertSame([
            'name' => 'Beta Transit',
            'address' => '5 quai du Port, Marseille',
            'phone' => '+33401020304',
            'provider_key' => 'maps-safe-42',
            'engine' => 'google_maps',
            'domain' => null,
        ], data_get($item->source_metadata, 'resolution.maps.0'));
    }

    public function test_platform_and_rejected_registrable_domain_collision_require_review(): void
    {
        $platform = $this->item([
            'row_number' => 1,
            'provided_domain' => 'https://linkedin.com/company/acme',
        ]);
        Company::factory()->create([
            'name' => 'Other Company',
            'domain' => 'portal.acme.fr',
            'registrable_domain' => 'acme.fr',
            'qualification_status' => 'rejected',
        ]);
        $collision = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $platform->prospect_batch_id,
            'row_number' => 2,
            'company_name' => 'Acme Logistics',
            'normalized_name' => 'acme logistics',
            'country' => 'FR',
            'provided_domain' => 'shop.acme.fr',
        ]);
        Http::fake();

        $processor = app(ProspectItemProcessor::class);
        $processor->process($platform);
        $processor->process($collision);

        $this->assertSame('platform_domain', $platform->fresh()->domain_reason);
        $this->assertSame('review', $platform->fresh()->status);
        $this->assertSame('registrable_domain_collision', $collision->fresh()->domain_reason);
        $this->assertSame('review', $collision->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_domain_search_paginates_one_hundred_plus_twenty_and_imports_verification_evidence(): void
    {
        $batch = ProspectBatch::factory()->create([
            'quality_preset' => 'deep',
            'quality_settings' => [
                'department' => ['sales'],
                'seniority' => ['senior', 'executive'],
                'decision_maker' => true,
                'verification_status' => ['valid', 'accept_all'],
            ],
        ]);
        $item = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'provided_domain' => 'acme.fr',
        ]);
        $domainSearchRequests = [];

        Http::fake(function (Request $request) use (&$domainSearchRequests) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => $this->companyEnrichment([
                    'site' => [
                        'url' => 'https://acme.fr',
                        'emailAddresses' => [' Contact@Acme.fr ', 'not-an-email'],
                    ],
                ])], 200);
            }

            if (str_contains($request->url(), '/domain-search')) {
                $domainSearchRequests[] = $request->data();
                $offset = (int) ($request->data()['offset'] ?? 0);
                $count = $offset === 0 ? 100 : 20;

                return Http::response([
                    'data' => [
                        'domain' => 'acme.fr',
                        'emails' => $this->hunterEmails($offset, $count),
                    ],
                    'meta' => ['results' => 120, 'limit' => 100, 'offset' => $offset],
                ], 200);
            }

            return Http::response([], 500);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertCount(2, $domainSearchRequests);
        $this->assertSame([0, 100], array_map(static fn (array $query): int => (int) $query['offset'], $domainSearchRequests));
        $this->assertSame('sales', $domainSearchRequests[0]['department']);
        $this->assertSame('senior,executive', $domainSearchRequests[0]['seniority']);
        $this->assertSame('1', (string) $domainSearchRequests[0]['decision_maker']);
        $this->assertSame('valid,accept_all', $domainSearchRequests[0]['verification_status']);
        $this->assertSame(121, Contact::query()->where('company_id', $item->company_id)->count());
        $this->assertSame(121, ProspectBatchContact::query()->where('prospect_batch_item_id', $item->id)->count());
        $this->assertDatabaseHas('contacts', [
            'email' => 'person119@acme.fr',
            'source' => 'discovered',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
        ]);
        $this->assertSame(
            '2026-08-01',
            Contact::query()
                ->where('email', 'person119@acme.fr')
                ->sole()
                ->email_verification_checked_at
                ->toDateString(),
        );
        $siteContact = Contact::query()->where('email', 'contact@acme.fr')->sole();
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_item_id' => $item->id,
            'contact_id' => $siteContact->id,
            'provider_source' => 'hunter_company_enrichment',
        ]);
        $domainCalls = ProviderCall::query()
            ->where('operation', 'domain_search')
            ->orderBy('id')
            ->get();
        $this->assertSame(['10.00', '10.00'], $domainCalls->pluck('reserved_units')->all());
        $this->assertSame(['10.00', '2.00'], $domainCalls->pluck('consumed_units')->all());
    }

    public function test_domain_search_falls_back_to_free_plan_window_after_pagination_error(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $domainSearchRequests = [];

        Http::fake(function (Request $request) use (&$domainSearchRequests) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            if (str_contains($request->url(), '/domain-search')) {
                $domainSearchRequests[] = $request->data();
                $limit = (int) ($request->data()['limit'] ?? 0);

                if ($limit === 100) {
                    return Http::response(['errors' => [['id' => 'pagination_error']]], 400);
                }

                return Http::response([
                    'data' => ['domain' => 'acme.fr', 'emails' => $this->hunterEmails(0, 10)],
                    'meta' => ['results' => 50, 'limit' => 10, 'offset' => 0],
                ], 200);
            }

            return Http::response([], 500);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertSame([100, 10], array_map(static fn (array $query): int => (int) $query['limit'], $domainSearchRequests));
        $this->assertSame([0, 0], array_map(static fn (array $query): int => (int) $query['offset'], $domainSearchRequests));
        $this->assertSame(10, data_get($item->source_metadata, 'processing.domain_search_limit_cap'));
        $this->assertTrue((bool) data_get($item->source_metadata, 'processing.domain_search_done'));
        $this->assertSame(10, data_get($item->source_metadata, 'processing.domain_search_offset'));
        $this->assertDatabaseHas('provider_calls', [
            'operation' => 'domain_search',
            'status' => 'failed',
            'http_status' => 400,
        ]);
        $this->assertDatabaseHas('provider_calls', [
            'operation' => 'domain_search',
            'status' => 'succeeded',
            'result_count' => 10,
        ]);
    }

    public function test_existing_failed_domain_search_pagination_call_recovers_with_a_new_ten_result_request(): void
    {
        $item = $this->item([
            'provided_domain' => 'acme.fr',
            'selected_domain' => 'acme.fr',
            'registrable_domain' => 'acme.fr',
            'source_metadata' => ['processing' => ['company_enrichment_done' => true]],
        ]);
        $filtersHash = hash('sha256', json_encode([], JSON_THROW_ON_ERROR));
        $failedKey = hash('sha256', implode('|', [
            'prospect_item_v1',
            (string) $item->id,
            'domain_search',
            hash('sha256', "acme.fr|{$filtersHash}|0|100"),
        ]));
        $failedCall = ProviderCall::query()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => $failedKey,
            'status' => 'failed',
            'http_status' => 400,
            'reserved_units' => 10,
            'consumed_units' => 0,
            'attempt_count' => 1,
            'metadata' => ['error_code' => 'pagination_error', 'retryable' => false],
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);
        $domainSearchRequests = [];

        Http::fake(function (Request $request) use (&$domainSearchRequests) {
            $domainSearchRequests[] = $request->data();

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => $this->hunterEmails(0, 3)],
                'meta' => ['results' => 3, 'limit' => 10, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertCount(1, $domainSearchRequests);
        $this->assertSame(10, (int) $domainSearchRequests[0]['limit']);
        $this->assertSame(0, (int) $domainSearchRequests[0]['offset']);
        $failedCall->refresh();
        $this->assertSame('failed', $failedCall->status);
        $this->assertSame(1, $failedCall->attempt_count);
        $this->assertSame('pagination_error', data_get($failedCall->metadata, 'error_code'));
        $this->assertSame(2, ProviderCall::query()->where('operation', 'domain_search')->count());
    }

    public function test_domain_search_attempts_the_ten_result_fallback_only_once(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $domainSearchRequests = [];

        Http::fake(function (Request $request) use (&$domainSearchRequests) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            $domainSearchRequests[] = $request->data();

            return Http::response(['errors' => [['id' => 'pagination_error']]], 400);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('pagination_error', $item->error_code);
        $this->assertSame([100, 10], array_map(static fn (array $query): int => (int) $query['limit'], $domainSearchRequests));
        $this->assertSame(2, ProviderCall::query()->where('operation', 'domain_search')->count());
    }

    public function test_unrelated_domain_search_failure_remains_blocked_without_fallback(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $domainSearchRequests = [];

        Http::fake(function (Request $request) use (&$domainSearchRequests) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => $this->companyEnrichment([
                    'site' => ['emailAddresses' => ['retained@acme.fr']],
                ])], 200);
            }

            $domainSearchRequests[] = $request->data();

            return Http::response(['errors' => [['id' => 'invalid_request']]], 400);
        });

        app(ProspectItemProcessor::class)->process($item);

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('invalid_request', $item->error_code);
        $this->assertCount(1, $domainSearchRequests);
        $this->assertNull(data_get($item->source_metadata, 'processing.domain_search_limit_cap'));
        $this->assertSame(1, ProviderCall::query()->where('operation', 'domain_search')->count());
        $this->assertNotNull($item->company_id);
        $retained = Contact::query()->where('email', 'retained@acme.fr')->sole();
        $this->assertSame($item->company_id, $retained->company_id);
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_item_id' => $item->id,
            'contact_id' => $retained->id,
            'provider_source' => 'hunter_company_enrichment',
        ]);
    }

    public function test_company_enrichment_not_found_is_empty_and_domain_search_continues(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['errors' => [['id' => 'not_found']]], 404);
            }

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => []],
                'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $companyCall = ProviderCall::query()->where('operation', 'company_enrichment')->sole();
        $this->assertSame('failed', $companyCall->status);
        $this->assertSame(404, $companyCall->http_status);
        $this->assertSame('0.00', $companyCall->consumed_units);
        $this->assertTrue((bool) data_get($item->fresh()->source_metadata, 'processing.company_enrichment_done'));
        $this->assertDatabaseHas('provider_calls', ['operation' => 'domain_search', 'status' => 'succeeded']);
    }

    public function test_domain_search_not_found_is_empty_and_item_becomes_ready(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['errors' => [['id' => 'not_found']]], 404);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $domainCall = ProviderCall::query()->where('operation', 'domain_search')->sole();
        $this->assertSame('failed', $domainCall->status);
        $this->assertSame(404, $domainCall->http_status);
        $this->assertSame('0.00', $domainCall->consumed_units);
        $this->assertTrue((bool) data_get($item->fresh()->source_metadata, 'processing.domain_search_done'));
        $this->assertDatabaseCount('prospect_batch_contacts', 0);
    }

    public function test_not_found_settlement_is_scoped_by_provider_operation_and_key(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $key = hash('sha256', implode('|', [
            'prospect_item_v1',
            (string) $item->id,
            'company_enrichment',
            hash('sha256', 'acme.fr'),
        ]));
        $unrelated = ProviderCall::query()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'serpapi',
            'operation' => 'search',
            'engine' => 'google',
            'idempotency_key' => $key,
            'status' => 'failed',
            'http_status' => 404,
            'result_count' => 7,
            'reserved_units' => 7,
            'consumed_units' => 7,
            'attempt_count' => 1,
            'metadata' => ['error_code' => 'not_found'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['errors' => [['id' => 'not_found']]], 404);
            }

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => []],
                'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertSame('7.00', $unrelated->fresh()->consumed_units);
        $companyCall = ProviderCall::query()
            ->where('provider', 'hunter')
            ->where('operation', 'company_enrichment')
            ->where('idempotency_key', $key)
            ->sole();
        $this->assertSame('failed', $companyCall->status);
        $this->assertSame(404, $companyCall->http_status);
        $this->assertSame(0, $companyCall->result_count);
        $this->assertSame('0.00', $companyCall->consumed_units);
        $this->assertNotNull($companyCall->finished_at);
    }

    public function test_successful_empty_enrichment_and_email_finder_consume_zero_units(): void
    {
        $item = $this->item([
            'provided_domain' => 'acme.fr',
            'source_metadata' => ['full_name' => 'Ada Lovelace'],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/domain-search')) {
                return Http::response([
                    'data' => ['domain' => 'acme.fr', 'emails' => []],
                    'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        foreach (['company_enrichment', 'domain_search', 'email_finder'] as $operation) {
            $call = ProviderCall::query()->where('operation', $operation)->sole();
            $this->assertSame('succeeded', $call->status);
            $this->assertSame(0, $call->result_count);
            $this->assertSame('0.00', $call->consumed_units);
        }
    }

    public function test_local_provider_item_keeps_all_ledger_units_at_zero(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
        ]);
        $item = $this->item([
            'company_name' => 'GEODIS',
            'normalized_name' => 'geodis',
            'provided_domain' => 'geodis.com',
        ]);

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertGreaterThan(0, ProspectBatchContact::query()->where('prospect_batch_item_id', $item->id)->count());
        $calls = ProviderCall::query()->orderBy('id')->get();
        $this->assertSame(['company_enrichment', 'domain_search'], $calls->pluck('operation')->all());
        foreach ($calls as $call) {
            $this->assertSame('succeeded', $call->status);
            $this->assertSame('0.00', $call->reserved_units);
            $this->assertSame('0.00', $call->consumed_units);
        }
    }

    public function test_lean_preset_caps_domain_search_at_ten_results(): void
    {
        $batch = ProspectBatch::factory()->create(['quality_preset' => 'lean']);
        $item = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'provided_domain' => 'acme.fr',
        ]);
        $requests = [];

        Http::fake(function (Request $request) use (&$requests) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            $requests[] = $request->data();

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => $this->hunterEmails(0, 10)],
                'meta' => ['results' => 50, 'limit' => 10, 'offset' => 0],
            ], 200);
        });

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame('ready', $item->fresh()->status);
        $this->assertCount(1, $requests);
        $this->assertSame(10, (int) $requests[0]['limit']);
        $this->assertDatabaseCount('prospect_batch_contacts', 10);
        $this->assertDatabaseCount('contacts', 10);
        $domainCall = ProviderCall::query()->where('operation', 'domain_search')->sole();
        $this->assertSame('1.00', $domainCall->reserved_units);
        $this->assertSame('1.00', $domainCall->consumed_units);
    }

    public function test_estimate_uses_preset_or_bounded_override_domain_search_maximum(): void
    {
        $service = app(ProspectBatchService::class);
        $cases = [
            ['preset' => 'lean', 'settings' => [], 'calls' => 1, 'hunter' => 1.2],
            ['preset' => 'balanced', 'settings' => [], 'calls' => 1, 'hunter' => 10.2],
            ['preset' => 'deep', 'settings' => [], 'calls' => 2, 'hunter' => 20.2],
            ['preset' => 'balanced', 'settings' => ['domain_search_max_results' => 125], 'calls' => 2, 'hunter' => 13.2],
            ['preset' => 'balanced', 'settings' => ['domain_search_max_results' => 999], 'calls' => 5, 'hunter' => 50.2],
        ];

        foreach ($cases as $index => $case) {
            $batch = ProspectBatch::factory()->create([
                'quality_preset' => $case['preset'],
                'quality_settings' => $case['settings'],
            ]);
            ProspectBatchItem::factory()->create([
                'prospect_batch_id' => $batch->id,
                'row_number' => 1,
                'provided_domain' => "acme{$index}.fr",
            ]);

            $estimate = $service->estimate($batch);

            $this->assertSame($case['calls'], $estimate['calls']['hunter_domain_search_max']);
            $this->assertSame($case['hunter'], $estimate['reserved_units']['hunter']);
        }
    }

    public function test_email_finder_runs_only_for_a_concrete_named_person(): void
    {
        $batch = ProspectBatch::factory()->create();
        $unnamed = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
            'provided_domain' => 'unnamed.fr',
            'source_metadata' => ['full_name' => '   '],
        ]);
        $named = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 2,
            'provided_domain' => 'named.fr',
            'source_metadata' => ['full_name' => '  Ada   Lovelace  '],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($request->url(), '/domain-search')) {
                $domain = (string) ($request->data()['domain'] ?? '');

                return Http::response([
                    'data' => ['domain' => $domain, 'emails' => []],
                    'meta' => ['results' => 0, 'limit' => 100, 'offset' => 0],
                ], 200);
            }
            if (str_contains($request->url(), '/email-finder')) {
                return Http::response(['data' => [
                    'email' => 'ada@named.fr',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'position' => 'Directrice logistique',
                    'verification' => ['status' => 'accept_all', 'date' => '2026-08-02'],
                ]], 200);
            }

            return Http::response([], 500);
        });

        $processor = app(ProspectItemProcessor::class);
        $processor->process($unnamed);
        $processor->process($named);

        $this->assertSame(1, ProviderCall::query()->where('operation', 'email_finder')->count());
        $namedContact = Contact::query()->where('email', 'ada@named.fr')->sole();
        $this->assertSame('accept_all', $namedContact->email_verification_status);
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_item_id' => $named->id,
            'contact_id' => $namedContact->id,
            'provider_source' => 'hunter_email_finder',
        ]);
        $this->assertDatabaseMissing('prospect_batch_contacts', [
            'prospect_batch_item_id' => $unnamed->id,
        ]);
    }

    public function test_terminal_item_replay_performs_no_provider_io(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $sent = 0;
        Http::fake(function (Request $request) use (&$sent) {
            $sent++;
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([
                'data' => ['domain' => 'acme.fr', 'emails' => [[
                    'value' => 'replay@acme.fr',
                    'verification' => ['status' => 'valid', 'date' => '2026-08-13'],
                ]]],
                'meta' => ['results' => 1, 'limit' => 100, 'offset' => 0],
            ], 200);
        });
        $processor = app(ProspectItemProcessor::class);

        $processor->process($item);
        $this->assertSame(2, $sent);
        $this->assertSame(1, Contact::query()->where('email', 'replay@acme.fr')->count());
        $this->assertSame(1, ProspectBatchContact::query()->where('prospect_batch_item_id', $item->id)->count());
        $processor->process($item->fresh());

        $this->assertSame(2, $sent);
        $this->assertSame(1, Contact::query()->where('email', 'replay@acme.fr')->count());
        $this->assertSame(1, ProspectBatchContact::query()->where('prospect_batch_item_id', $item->id)->count());
        $this->assertSame('ready', $item->fresh()->status);
        $this->assertSame(2, ProviderCall::query()->count());
    }

    public function test_provider_settlement_contact_import_and_provenance_are_atomic_and_job_replay_is_safe(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $sent = 0;
        Http::fake(function (Request $request) use (&$sent) {
            $sent++;
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => $this->companyEnrichment([
                    'site' => ['emailAddresses' => ['atomic@acme.fr']],
                ])], 200);
            }

            return Http::response(['data' => ['domain' => 'acme.fr', 'emails' => []]], 200);
        });

        $event = 'eloquent.creating: '.ProspectBatchContact::class;
        Event::listen($event, static fn () => throw new RuntimeException('forced_provenance_failure'));
        Queue::fake();
        $job = new ProcessProspectBatchItemJob($item->id);

        try {
            $job->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));
            $this->fail('The forced provenance failure must abort provider settlement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced_provenance_failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $item->refresh();
        $this->assertNotNull($item->company_id);
        $this->assertDatabaseHas('companies', ['id' => $item->company_id, 'domain' => 'acme.fr']);
        $this->assertDatabaseMissing('contacts', ['email' => 'atomic@acme.fr']);
        $this->assertDatabaseCount('prospect_batch_contacts', 0);
        $this->assertSame('running', ProviderCall::query()->where('operation', 'company_enrichment')->sole()->status);
        $this->assertSame('review', $item->status);
        $this->assertSame('provider_outcome_uncertain', $item->domain_reason);
        $requestsAfterFailure = $sent;

        $job->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertSame($requestsAfterFailure, $sent);
        $this->assertSame('review', $item->fresh()->status);
    }

    public function test_pre_provider_company_link_failure_returns_item_to_queue_retry_without_provider_review(): void
    {
        $item = $this->item(['provided_domain' => 'acme.fr']);
        $event = 'eloquent.creating: '.Company::class;
        Event::listen($event, static fn () => throw new RuntimeException('forced_company_link_failure'));

        try {
            app(ProspectItemProcessor::class)->process($item);
            $this->fail('The local pre-provider failure must preserve its original exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced_company_link_failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $item->refresh();
        $this->assertSame('pending', $item->status);
        $this->assertSame('processor_unexpected_failure', $item->error_code);
        $this->assertNotSame('provider_outcome_uncertain', $item->domain_reason);
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public function test_stranded_running_provider_call_moves_item_to_review_without_second_transport(): void
    {
        $item = $this->item();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response(['data' => []], 200);
        });
        $key = hash('sha256', implode('|', [
            'prospect_item_v1',
            (string) $item->id,
            'domain_finder',
            hash('sha256', $item->normalized_name),
        ]));
        app(HunterClient::class)->domainFinder(new ProviderCallContext(
            $key,
            0,
            batchId: $item->prospect_batch_id,
            itemId: $item->id,
        ), $item->company_name);

        app(ProspectItemProcessor::class)->process($item);

        $this->assertSame(1, $sent);
        $this->assertSame('review', $item->fresh()->status);
        $this->assertSame('provider_outcome_uncertain', $item->fresh()->domain_reason);
        $this->assertSame('running', ProviderCall::query()->sole()->status);
    }

    public function test_transient_company_enrichment_failure_returns_item_to_pending_and_preserves_completed_resolution(): void
    {
        $item = $this->item([
            'provided_domain' => 'acme.fr',
            'source_metadata' => ['source' => 'import'],
        ]);
        Http::fake(['api.hunter.io/v2/companies/find*' => Http::response([], 503)]);

        try {
            app(ProspectItemProcessor::class)->process($item);
            $this->fail('Expected the transient provider failure to leave the processor.');
        } catch (ProviderRequestException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('provider_unavailable', $exception->safeCode);
            $this->assertSame(30, $exception->retryAfterSeconds);
        }

        $item->refresh();
        $this->assertSame('pending', $item->status);
        $this->assertSame('acme.fr', $item->selected_domain);
        $this->assertSame('provided_domain', $item->domain_reason);
        $this->assertTrue((bool) data_get($item->source_metadata, 'processing.resolution_done'));
        $this->assertSame('provider_unavailable', $item->error_code);
        $this->assertNull($item->processed_at);
    }

    private function item(array $overrides = []): ProspectBatchItem
    {
        $batch = ProspectBatch::factory()->create();

        return ProspectBatchItem::factory()->create(array_replace([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
            'company_name' => 'Acme Logistics',
            'normalized_name' => 'acme logistics',
            'country' => 'FR',
            'provided_domain' => null,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function companyEnrichment(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Acme Logistics SAS',
            'domain' => 'acme.fr',
            'category' => ['industry' => 'Logistics'],
            'metrics' => ['employeesRange' => '51-200'],
            'geo' => ['countryCode' => 'FR', 'city' => 'Paris'],
            'phone' => '+33102030405',
            'site' => ['url' => 'https://acme.fr', 'emailAddresses' => []],
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function hunterEmails(int $start, int $count): array
    {
        $rows = [];
        for ($index = $start; $index < $start + $count; $index++) {
            $rows[] = [
                'value' => "person{$index}@acme.fr",
                'type' => 'personal',
                'confidence' => 95,
                'first_name' => 'Person',
                'last_name' => (string) $index,
                'position' => 'Responsable logistique',
                'seniority' => 'senior',
                'department' => 'sales',
                'phone_number' => null,
                'verification' => ['status' => 'valid', 'date' => '2026-08-01'],
            ];
        }

        return $rows;
    }
}
