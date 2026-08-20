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
 * CompanySectorNormalizationTest — verifies the Company::saving() choke
 * point (structure/business-rules/prospection-discovery.md "Sector ...
 * invariant") normalizes companies.sector regardless of which Eloquent
 * write path is used, and only when sector is actually dirty.
 */
class CompanySectorNormalizationTest extends TestCase
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

    public function test_create_normalizes_sector(): void
    {
        $company = Company::create([
            'name' => 'Acme A',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'sector' => 'Fabricant de matériel médical',
        ]);

        $this->assertSame('Matériel médical', $company->fresh()->sector);
    }

    public function test_forcefill_save_normalizes_sector(): void
    {
        $company = Company::create([
            'name' => 'Acme B',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        $company->forceFill(['sector' => 'Magasin de matériel médical'])->save();

        $this->assertSame('Matériel médical', $company->fresh()->sector);
    }

    public function test_property_assignment_save_normalizes_sector(): void
    {
        $company = Company::create([
            'name' => 'Acme C',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        $company->sector = 'Service de transport';
        $company->save();

        $this->assertSame('Transport & Logistique', $company->fresh()->sector);
    }

    public function test_saving_without_touching_sector_leaves_legacy_value_alone(): void
    {
        $company = Company::create([
            'name' => 'Acme D',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'sector' => 'Some Legacy Unmapped Value',
        ]);

        $this->assertSame('Some Legacy Unmapped Value', $company->fresh()->sector);

        // Touch an unrelated field and save — sector is NOT dirty, must stay untouched.
        $company->description = 'updated description';
        $company->save();

        $this->assertSame('Some Legacy Unmapped Value', $company->fresh()->sector);
    }
}
