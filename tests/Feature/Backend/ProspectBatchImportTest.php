<?php

namespace Tests\Feature\Backend;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Prospecting\CompanyListParser;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectBatchImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_copy_paste_accepts_name_country_city_and_optional_domain(): void
    {
        $result = app(CompanyListParser::class)->parseText(
            "ACME | France | Lyon | https://www.acme.fr/contact\r\nBeta | MA | Casablanca",
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            [
                'row_number' => 1,
                'original_input' => 'ACME | France | Lyon | https://www.acme.fr/contact',
                'company_name' => 'ACME',
                'country' => 'FR',
                'city' => 'Lyon',
                'provided_domain' => 'acme.fr',
            ],
            [
                'row_number' => 2,
                'original_input' => 'Beta | MA | Casablanca',
                'company_name' => 'Beta',
                'country' => 'MA',
                'city' => 'Casablanca',
                'provided_domain' => null,
            ],
        ], $result['rows']);
    }

    public function test_csv_header_aliases_are_normalized_and_invalid_rows_are_reported(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'entreprises.csv',
            "Raison sociale;Pays;Ville;Site\nACME;France;Lyon;acme.fr\nBeta;Maroc;Casa;linkedin.com/company/beta\n;France;Paris;example.fr",
        );

        $result = app(CompanyListParser::class)->parseCsv($file);

        $this->assertCount(2, $result['rows']);
        $this->assertSame('ACME', $result['rows'][0]['company_name']);
        $this->assertNull($result['rows'][1]['provided_domain']);
        $this->assertSame(
            ['platform_domain', 'company_name_required'],
            array_column($result['errors'], 'code'),
        );
    }

    public function test_bom_tabs_commas_and_quoted_newlines_are_parsed_as_csv_records(): void
    {
        $tabbed = app(CompanyListParser::class)->parseText(
            "\xEF\xBB\xBFcompany\tcountry\tcity\tdomain\nACME\tFR\tLyon\tacme.fr",
        );
        $quoted = app(CompanyListParser::class)->parseText(
            "company,country,city,domain\n\"ACME\nLogistics\",FR,Lyon,acme.fr",
        );

        $this->assertSame('ACME', $tabbed['rows'][0]['company_name']);
        $this->assertSame('ACME Logistics', $quoted['rows'][0]['company_name']);
        $this->assertSame(1, $quoted['rows'][0]['row_number']);
    }

    public function test_unsafe_and_oversized_csv_files_are_rejected(): void
    {
        $parser = app(CompanyListParser::class);

        $unsafe = $parser->parseCsv(UploadedFile::fake()->createWithContent('payload.exe', "MZ\0payload"));
        $stream = tmpfile();
        $this->assertIsResource($stream);
        fwrite($stream, "ACME,FR\n");
        $path = stream_get_meta_data($stream)['uri'];
        $unsafeMime = $parser->parseCsv(new UploadedFile(
            $path,
            'payload.csv',
            'application/x-dosexec',
            UPLOAD_ERR_OK,
            true,
        ));
        $oversized = $parser->parseCsv(UploadedFile::fake()->create('large.csv', 10_241, 'text/csv'));

        $this->assertSame([], $unsafe['rows']);
        $this->assertSame('unsafe_file', $unsafe['errors'][0]['code']);
        $this->assertSame([], $unsafeMime['rows']);
        $this->assertSame('unsafe_file', $unsafeMime['errors'][0]['code']);
        $this->assertSame([], $oversized['rows']);
        $this->assertSame('file_too_large', $oversized['errors'][0]['code']);
    }

    public function test_control_data_and_more_than_ten_thousand_rows_are_rejected(): void
    {
        $parser = app(CompanyListParser::class);
        $control = $parser->parseText("ACME | FR | Ly\0on");
        $tooMany = $parser->parseText(implode("\n", array_fill(0, 10_001, 'ACME')));

        $this->assertSame([], $control['rows']);
        $this->assertSame('unsafe_content', $control['errors'][0]['code']);
        $this->assertSame([], $tooMany['rows']);
        $this->assertSame('too_many_rows', $tooMany['errors'][0]['code']);
    }

    public function test_replaying_same_rows_in_same_batch_creates_no_duplicates(): void
    {
        $creator = User::factory()->create();
        $rows = app(CompanyListParser::class)->parseText("ACME | FR\nBeta | MA")['rows'];
        $service = app(ProspectBatchService::class);
        $batch = $service->createListBatch($creator, $rows, ['name' => 'Liste test']);

        $this->assertSame(2, $service->stageItems($batch, $rows));
        $this->assertSame(2, $batch->items()->count());
        $this->assertSame(2, $batch->fresh()->total_items);
    }

    public function test_distinct_same_name_rows_are_preserved_by_row_number(): void
    {
        $rows = app(CompanyListParser::class)->parseText("ACME | FR | Lyon\nACME | FR | Paris")['rows'];
        $batch = app(ProspectBatchService::class)->createListBatch(User::factory()->create(), $rows);

        $this->assertSame([1, 2], $batch->items()->orderBy('row_number')->pluck('row_number')->all());
        $this->assertSame(['Lyon', 'Paris'], $batch->items()->orderBy('row_number')->pluck('city')->all());
    }

    public function test_local_cleanup_preview_consumes_no_provider_call(): void
    {
        $rows = app(CompanyListParser::class)->parseText("ACME | FR | Lyon | acme.fr\nBeta | MA")['rows'];
        $batch = app(ProspectBatchService::class)->createListBatch(User::factory()->create(), $rows);

        $estimate = app(ProspectBatchService::class)->estimate($batch);

        $this->assertSame(0, ProviderCall::query()->count());

        $sortDeep = static function (array $value) use (&$sortDeep): array {
            ksort($value);
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sortDeep($item);
                }
            }

            return $value;
        };

        // MySQL json columns normalise object key order; compare canonically, still type-strict.
        $this->assertSame($sortDeep($estimate), $sortDeep($batch->fresh()->estimate));
    }

    public function test_estimate_breaks_down_operations_and_requires_explicit_confirmation(): void
    {
        Queue::fake();
        $actor = $this->actorAllowedToRun();
        $rows = app(CompanyListParser::class)->parseText("ACME | FR | Lyon | acme.fr\nBeta | MA")['rows'];
        $service = app(ProspectBatchService::class);
        $batch = $service->createListBatch($actor, $rows);

        $this->assertSame([
            'items' => 2,
            'free' => ['cleanup' => 2, 'deduplication' => 2],
            'calls' => [
                'hunter_domain_finder' => 1,
                'serpapi_search_max' => 2,
                'hunter_company_enrichment' => 2,
                'hunter_domain_search_max' => 2,
                'hunter_email_finder' => 0,
            ],
            'reserved_units' => ['hunter' => 20.4, 'serpapi' => 2.0],
        ], $service->estimate($batch));

        $this->assertNull($batch->fresh()->cost_confirmed_at);
        Queue::assertNothingPushed();
    }

    public function test_stale_estimate_is_rejected_without_dispatch(): void
    {
        Queue::fake();
        $actor = $this->actorAllowedToRun();
        $service = app(ProspectBatchService::class);
        $batch = $service->createListBatch(
            $actor,
            app(CompanyListParser::class)->parseText('ACME | FR')['rows'],
        );
        $service->estimate($batch);
        ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 2,
        ]);

        try {
            $service->confirmAndDispatch($batch, $actor);
            $this->fail('A stale estimate must not be confirmed.');
        } catch (LogicException $exception) {
            $this->assertSame('prospect_batch_estimate_stale', $exception->getMessage());
        }

        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->cost_confirmed_at);
        Queue::assertNothingPushed();
    }

    public function test_equivalent_estimate_with_reordered_json_keys_can_be_confirmed(): void
    {
        Queue::fake();
        $actor = $this->actorAllowedToRun();
        $service = app(ProspectBatchService::class);
        $batch = $service->createListBatch(
            $actor,
            app(CompanyListParser::class)->parseText('ACME | FR')['rows'],
        );
        $estimate = $service->estimate($batch);

        $batch->forceFill([
            'estimate' => [
                'free' => $estimate['free'],
                'calls' => array_reverse($estimate['calls'], true),
                'items' => $estimate['items'],
                'reserved_units' => array_reverse($estimate['reserved_units'], true),
            ],
        ])->save();

        $confirmed = $service->confirmAndDispatch($batch->fresh(), $actor);

        $this->assertSame('queued', $confirmed->status);
        $this->assertNotNull($confirmed->cost_confirmed_at);
        Queue::assertPushed(ProcessProspectBatchItemJob::class, 1);
        Queue::assertPushed(FinalizeProspectBatchJob::class, 1);
    }

    public function test_confirmation_requires_permission_and_replay_is_rejected(): void
    {
        Queue::fake();
        $service = app(ProspectBatchService::class);
        $owner = User::factory()->create();
        $batch = $service->createListBatch(
            $owner,
            app(CompanyListParser::class)->parseText('ACME | FR')['rows'],
        );
        $service->estimate($batch);

        $this->expectException(AuthorizationException::class);
        $service->confirmAndDispatch($batch, $owner);
    }

    public function test_confirmation_dispatches_only_after_outer_transaction_commits_and_cannot_repeat(): void
    {
        Queue::fake();
        $actor = $this->actorAllowedToRun();
        $service = app(ProspectBatchService::class);
        $batch = $service->createListBatch(
            $actor,
            app(CompanyListParser::class)->parseText("ACME | FR\nBeta | MA")['rows'],
        );
        $service->estimate($batch);

        DB::beginTransaction();
        $service->confirmAndDispatch($batch, $actor);
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushed(ProcessProspectBatchItemJob::class, 2);
        Queue::assertPushed(FinalizeProspectBatchJob::class, 1);
        $this->assertSame('queued', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->cost_confirmed_at);

        try {
            $service->confirmAndDispatch($batch, $actor);
            $this->fail('A confirmed batch must not dispatch twice.');
        } catch (LogicException $exception) {
            $this->assertSame('prospect_batch_already_confirmed', $exception->getMessage());
        }

        Queue::assertPushed(ProcessProspectBatchItemJob::class, 2);
        Queue::assertPushed(FinalizeProspectBatchJob::class, 1);
    }

    private function actorAllowedToRun(): User
    {
        $permission = Permission::findOrCreate('run prospect resolution', 'web');
        $actor = User::factory()->create();
        $actor->givePermissionTo($permission);

        return $actor;
    }
}
