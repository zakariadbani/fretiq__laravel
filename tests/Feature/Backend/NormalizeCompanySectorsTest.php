<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Sector;
use App\Support\SectorClassifier;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NormalizeCompanySectorsTest — covers the App\Console\Commands\NormalizeCompanySectors
 * backfill's two-pass plan/apply orchestration (dry-run safety, reversibility-before-apply,
 * idempotency, withRejected() coverage, junk→null) — the classification logic itself is
 * covered by SectorClassifierTest and the Company::saving() choke point by
 * CompanySectorNormalizationTest.
 */
class NormalizeCompanySectorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        SectorClassifier::resetCache();

        Sector::create(['label' => 'Matériel médical', 'is_active' => true, 'use_in_discovery' => true, 'sort_order' => 1]);
        Sector::create(['label' => 'Transport & Logistique', 'is_active' => true, 'use_in_discovery' => true, 'sort_order' => 2]);
    }

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Acme '.uniqid(),
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ], $overrides));
    }

    public function test_dry_run_performs_no_writes_but_reports_transitions(): void
    {
        $company = $this->makeCompany();
        // Bypass the saving() hook's normalization so the row stays raw for the plan to find.
        $company->forceFill(['sector' => 'Fabricant de matériel médical'])->saveQuietly();

        $this->artisan('companies:normalize-sectors', ['--dry-run' => true])
            ->expectsOutputToContain('Changed : 1 company(ies)')
            ->assertExitCode(0);

        $this->assertSame('Fabricant de matériel médical', $company->fresh()->sector);
    }

    public function test_real_run_writes_reversibility_json_before_mutating_and_normalizes(): void
    {
        $company = $this->makeCompany();
        $company->forceFill(['sector' => 'Fabricant de matériel médical'])->saveQuietly();

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')
            ->assertExitCode(0);

        $after = glob(storage_path('app/sector-normalization-*.json'));
        $newFiles = array_diff($after, $before);
        $this->assertNotEmpty($newFiles, 'Expected a new reversibility JSON file to be written.');

        $path = array_values($newFiles)[0];
        $reversibility = json_decode(file_get_contents($path), true);
        $this->assertArrayHasKey('Fabricant de matériel médical', $reversibility);
        $this->assertArrayHasKey('Matériel médical', $reversibility['Fabricant de matériel médical']);
        $this->assertContains($company->id, $reversibility['Fabricant de matériel médical']['Matériel médical']);

        $this->assertSame('Matériel médical', $company->fresh()->sector);

        @unlink($path);
    }

    public function test_idempotent_second_run_reports_zero_changes(): void
    {
        $company = $this->makeCompany();
        $company->forceFill(['sector' => 'Fabricant de matériel médical'])->saveQuietly();

        $before = glob(storage_path('app/sector-normalization-*.json'));
        $this->artisan('companies:normalize-sectors')->assertExitCode(0);
        $after = glob(storage_path('app/sector-normalization-*.json'));
        foreach (array_diff($after, $before) as $file) {
            @unlink($file);
        }

        $this->assertSame('Matériel médical', $company->fresh()->sector);

        $secondRunFilesBefore = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')
            ->expectsOutputToContain('Changed : 0 company(ies)')
            ->assertExitCode(0);

        // Nothing left to write for a second, no-op run — no new reversibility file.
        $secondRunFilesAfter = glob(storage_path('app/sector-normalization-*.json'));
        $this->assertSame($secondRunFilesBefore, $secondRunFilesAfter);
    }

    public function test_rejected_company_is_normalized_via_with_rejected(): void
    {
        $company = $this->makeCompany(['qualification_status' => 'rejected']);
        $company->forceFill(['sector' => 'Fabricant de matériel médical'])->saveQuietly();

        // Sanity: the notRejected global scope hides it from a default query.
        $this->assertNull(Company::find($company->id));
        $this->assertNotNull(Company::withRejected()->find($company->id));

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        foreach (array_diff(glob(storage_path('app/sector-normalization-*.json')), $before) as $file) {
            @unlink($file);
        }

        $this->assertSame('Matériel médical', Company::withRejected()->find($company->id)->sector);
    }

    public function test_junk_raw_sector_ends_as_null(): void
    {
        $company = $this->makeCompany();
        $company->forceFill(['sector' => 'Siège social'])->saveQuietly();

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        foreach (array_diff(glob(storage_path('app/sector-normalization-*.json')), $before) as $file) {
            @unlink($file);
        }

        $this->assertNull($company->fresh()->sector);
    }
}
