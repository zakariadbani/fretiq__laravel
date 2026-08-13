<?php

namespace Tests\Feature\Console;

use App\Jobs\FinalizeProspectBatchJob;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecoverExistingProspectingDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    public function test_command_requires_exactly_one_mode(): void
    {
        $this->artisan('prospecting:recover-existing-data')
            ->expectsOutputToContain('Choisissez exactement un mode')
            ->assertExitCode(Command::INVALID);

        $this->artisan('prospecting:recover-existing-data', [
            '--dry-run' => true,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Choisissez exactement un mode')
            ->assertExitCode(Command::INVALID);
    }

    public function test_dry_run_has_no_writes_or_jobs_and_prints_non_sensitive_summary(): void
    {
        Company::factory()->create([
            'name' => 'Dry Run Company',
            'domain' => 'dry-run-company.fr',
            'enrichment_data' => [
                'company' => ['site' => ['emailAddresses' => ['sales@dry-run-company.fr']]],
            ],
        ]);
        DiscoveryRun::query()->create([
            'prospect_criteria_id' => null,
            'type' => 'discovery',
            'company_id' => null,
            'status' => 'failed',
            'consumed' => 0,
            'candidates_snapshot' => [[
                'domain' => 'dry-run-snapshot.fr',
                'title' => 'Dry Run Snapshot',
            ]],
        ]);
        Bus::fake();
        $before = $this->databaseCounts();

        $this->artisan('prospecting:recover-existing-data', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Emails Company Enrichment à importer automatiquement')
            ->expectsOutputToContain('Domaines de snapshots à revoir')
            ->expectsOutputToContain('Appels fournisseur: 0')
            ->assertSuccessful();

        $this->assertSame($before, $this->databaseCounts());
        Bus::assertNothingDispatched();
        $output = Artisan::output();
        $this->assertStringNotContainsString('sales@dry-run-company.fr', $output);
        $this->assertStringNotContainsString('Dry Run Company', $output);
        $this->assertStringNotContainsString('dry-run-snapshot.fr', $output);
    }

    public function test_apply_finalizes_and_reuses_the_recovery_batch_without_provider_calls(): void
    {
        Company::factory()->create([
            'name' => 'Apply Company',
            'domain' => 'apply-company.fr',
            'enrichment_data' => [
                'company' => ['site' => ['emailAddresses' => ['sales@apply-company.fr']]],
            ],
        ]);
        Bus::fake([FinalizeProspectBatchJob::class]);

        $this->artisan('prospecting:recover-existing-data', ['--apply' => true])
            ->expectsOutputToContain('Appels fournisseur: 0')
            ->expectsOutputToContain('Lot recovery #')
            ->assertSuccessful();

        $batch = ProspectBatch::query()->where('source_type', 'recovery')->sole();
        Bus::assertDispatchedSync(
            FinalizeProspectBatchJob::class,
            fn (FinalizeProspectBatchJob $job): bool => $job->batchId === $batch->id,
        );

        $this->artisan('prospecting:recover-existing-data', ['--apply' => true])
            ->expectsOutputToContain('réutilisé')
            ->assertSuccessful();

        $this->assertSame(1, ProspectBatch::query()->where('source_type', 'recovery')->count());
        $this->assertSame(0, ProviderCall::query()->count());
    }

    /** @return array<string, int> */
    private function databaseCounts(): array
    {
        return [
            'companies' => Company::withRejected()->count(),
            'discovery_runs' => DiscoveryRun::query()->count(),
            'prospect_batches' => ProspectBatch::query()->count(),
            'prospect_batch_items' => ProspectBatchItem::query()->count(),
            'prospect_batch_contacts' => ProspectBatchContact::query()->count(),
            'provider_calls' => ProviderCall::query()->count(),
        ];
    }
}
