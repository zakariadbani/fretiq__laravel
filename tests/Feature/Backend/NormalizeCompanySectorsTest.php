<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectCriteria;
use App\Models\Sector;
use App\Models\Segment;
use App\Services\Campaign\SegmentService;
use App\Support\SectorClassifier;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NormalizeCompanySectorsTest — covers the App\Console\Commands\NormalizeCompanySectors
 * backfill's two-pass plan/apply orchestration (dry-run safety, reversibility-before-apply,
 * idempotency, withRejected() coverage, junk→null) across all three backfill targets
 * (companies.sector, segments.filter->sector, prospect_criteria.sectors) — the
 * classification logic itself is covered by SectorClassifierTest and the
 * Company::saving() choke point by CompanySectorNormalizationTest.
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

    /** Copied from SegmentContactsLiveFilterTest — known to pass ContactEligibilityService + VERIFIED_ONLY. */
    private function makeContact(Company $company, string $email, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'company_id'                 => $company->id,
            'email'                      => $email,
            'name'                       => 'Test Contact '.$email,
            'source'                     => 'manual',
            'email_kind'                 => 'role',
            'email_verification_status'  => 'valid',
        ], $extra));
    }

    /** Deletes any reversibility JSON written since $before — keeps storage/app clean between tests. */
    private function cleanupReversibilityFiles(array $before): void
    {
        foreach (array_diff(glob(storage_path('app/sector-normalization-*.json')), $before) as $file) {
            @unlink($file);
        }
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
        $this->assertArrayHasKey('companies', $reversibility);
        $this->assertArrayHasKey('segments', $reversibility);
        $this->assertArrayHasKey('prospect_criteria', $reversibility);
        $this->assertArrayHasKey('Fabricant de matériel médical', $reversibility['companies']);
        $this->assertArrayHasKey('Matériel médical', $reversibility['companies']['Fabricant de matériel médical']);
        $this->assertContains($company->id, $reversibility['companies']['Fabricant de matériel médical']['Matériel médical']);

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
            ->expectsOutputToContain('Changed : 0 segment(s)')
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

    // ── segments.filter->sector ─────────────────────────────────────────────

    public function test_segment_filter_raw_label_backfilled_and_resolves_same_audience_as_canonical(): void
    {
        $company = $this->makeCompany([
            'relationship' => 'client',
            'sector'       => 'Transport',
            'country'      => 'FR',
        ]);
        $contact = $this->makeContact($company, 'transport@test.test');

        $segmentA = Segment::create(['name' => 'Raw label', 'scope' => 'client', 'filter' => ['sector' => ['Transport']]]);
        $segmentB = Segment::create(['name' => 'Canonical label', 'scope' => 'client', 'filter' => ['sector' => ['Transport & Logistique']]]);

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        $this->cleanupReversibilityFiles($before);

        $this->assertSame(['sector' => ['Transport & Logistique']], $segmentA->fresh()->filter);

        $service = app(SegmentService::class);
        $idsA = $service->resolve($segmentA->fresh())->pluck('id')->sort()->values()->all();
        $idsB = $service->resolve($segmentB->fresh())->pluck('id')->sort()->values()->all();

        $this->assertNotEmpty($idsA);
        $this->assertSame($idsB, $idsA);
        $this->assertContains($contact->id, $idsA);
    }

    public function test_segment_filter_dedupes_labels_collapsing_to_same_canonical(): void
    {
        $segment = Segment::create(['name' => 'Dedup test', 'scope' => 'client', 'filter' => ['sector' => ['Transport', 'Logistique']]]);

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        $this->cleanupReversibilityFiles($before);

        $this->assertSame(['sector' => ['Transport & Logistique']], $segment->fresh()->filter);
    }

    public function test_segment_junk_sector_label_is_dropped_and_empty_filter_collapses_to_null(): void
    {
        $onlySector = Segment::create(['name' => 'Junk only', 'scope' => 'client', 'filter' => ['sector' => ['siege social']]]);
        $sectorPlusCountry = Segment::create(['name' => 'Junk plus country', 'scope' => 'client', 'filter' => ['sector' => ['siege social'], 'country' => ['FR']]]);

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        $this->cleanupReversibilityFiles($before);

        $this->assertNull($onlySector->fresh()->filter);
        $this->assertSame(['country' => ['FR']], $sectorPlusCountry->fresh()->filter);
    }

    // ── prospect_criteria.sectors ───────────────────────────────────────────

    public function test_prospect_criteria_sectors_backfilled_and_deduped(): void
    {
        $criteria = ProspectCriteria::create([
            'name'                    => 'Sector backfill test',
            'sectors'                 => ['Transport', 'Transport & Logistique'],
            'hunter_discover_filters' => ['sector' => ['Transport']],
        ]);

        $before = glob(storage_path('app/sector-normalization-*.json'));

        $this->artisan('companies:normalize-sectors')->assertExitCode(0);

        $this->cleanupReversibilityFiles($before);

        $fresh = $criteria->fresh();
        $this->assertSame(['Transport & Logistique'], $fresh->sectors);
        // Side effect: a real sectors change trips discoverTargetingChanged(),
        // which invalidates the Hunter discovery cursor (see applyProspectCriteria()).
        $this->assertNull($fresh->hunter_discover_filters);
    }

    // ── dry-run parity for the two new targets ──────────────────────────────

    public function test_dry_run_leaves_segments_and_prospect_criteria_untouched(): void
    {
        $segment = Segment::create(['name' => 'Dry run segment', 'scope' => 'client', 'filter' => ['sector' => ['Transport']]]);
        $criteria = ProspectCriteria::create(['name' => 'Dry run criteria', 'sectors' => ['Transport']]);

        $this->artisan('companies:normalize-sectors', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(['sector' => ['Transport']], $segment->fresh()->filter);
        $this->assertSame(['Transport'], $criteria->fresh()->sectors);
    }
}
