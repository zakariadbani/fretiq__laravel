<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Segment;
use App\Services\Campaign\SegmentService;
use App\Support\SectorClassifier;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentFilterPipelineTest — end-to-end regression for the sector-taxonomy
 * fix (structure/specs/sector-taxonomy.md): a company enriched with a raw
 * provider sector value must resolve into a segment filtered by the
 * canonical label. Before the Company::saving() choke point existed, this
 * failed — companies.sector held uncontrolled enrichment vocabulary and
 * SegmentService::applyJsonFilter's exact-string whereIn never matched.
 */
class SegmentFilterPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        SectorClassifier::resetCache();

        Sector::create(['label' => 'Matériel médical', 'is_active' => true, 'use_in_discovery' => true, 'sort_order' => 1]);
    }

    public function test_company_with_raw_provider_sector_resolves_into_canonical_sector_segment(): void
    {
        $company = Company::create([
            'name' => 'Prospect Medical SARL',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            // Raw enrichment vocabulary — must be normalized on save() before this test runs.
            'sector' => 'Magasin de matériel médical',
        ]);

        $this->assertSame('Matériel médical', $company->fresh()->sector);

        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'contact@prospect-medical.test',
            'name' => 'Jean Prospect',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
            // resolve() defaults to Campaign::VERIFICATION_VERIFIED_ONLY — an
            // unverified contact is filtered out by ContactEligibilityService
            // regardless of sector match, so mark it verified explicitly.
            'email_verification_status' => 'valid',
        ]);

        $segment = Segment::create([
            'name' => 'Matériel médical',
            'scope' => 'prospect',
            'filter' => ['sector' => ['Matériel médical']],
        ]);

        $emails = app(SegmentService::class)->resolve($segment)->pluck('email')->all();

        $this->assertContains($contact->email, $emails);
    }
}
