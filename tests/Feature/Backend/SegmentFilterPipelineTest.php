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

    /**
     * Read-time canonicalization regression (structure/specs/sector-taxonomy.md):
     * the segment picker allows free-text/historical labels, and a label merge
     * (e.g. "Matériel industriel" → "Machines & Équipements industriels") must
     * apply retroactively to segments saved with the old label — otherwise the
     * exact-match whereIn in SegmentService::applyJsonFilter silently drops
     * matches for any segment saved before the merge.
     */
    public function test_stale_sector_filter_label_is_canonicalized_at_read_time(): void
    {
        $company = Company::create([
            'name' => 'Prospect Industrial SARL',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            // Already canonical — SectorClassifier::canonical() maps this to itself.
            'sector' => 'Machines & Équipements industriels',
        ]);

        $this->assertSame('Machines & Équipements industriels', $company->fresh()->sector);

        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'contact@prospect-industrial.test',
            'name' => 'Jean Industriel',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
            // resolve() defaults to Campaign::VERIFICATION_VERIFIED_ONLY — an
            // unverified contact is filtered out by ContactEligibilityService
            // regardless of sector match, so mark it verified explicitly.
            'email_verification_status' => 'valid',
        ]);

        $staleSegment = Segment::create([
            'name' => 'Matériel industriel (stale label)',
            'scope' => 'prospect',
            // Removed-from-taxonomy label, merged into the canonical one above.
            'filter' => ['sector' => ['Matériel industriel']],
        ]);

        $canonicalSegment = Segment::create([
            'name' => 'Machines & Équipements industriels',
            'scope' => 'prospect',
            'filter' => ['sector' => ['Machines & Équipements industriels']],
        ]);

        $service = app(SegmentService::class);

        $staleEmails = $service->resolve($staleSegment)->pluck('email')->all();
        $canonicalEmails = $service->resolve($canonicalSegment)->pluck('email')->all();

        $this->assertContains($contact->email, $staleEmails);
        $this->assertSame($canonicalEmails, $staleEmails);
    }
}
