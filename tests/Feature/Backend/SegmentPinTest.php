<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
use App\Services\Campaign\SegmentService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentPinTest — service-level tests for hybrid smart-list merge semantics.
 *
 * Verifies the merge contract:
 *   Final = pipeline( (filter_matches ∪ manual_includes) − manual_excludes )
 *
 * Tests:
 *  - Include of a filter-miss contact appears in resolve()
 *  - B3: whitespace-padded suppression match (LOWER/TRIM normalization)
 *  - Include of a cold-excluded prospect does NOT appear (compliance applies)
 *  - Exclude removes a filter-matched contact
 *  - Exclude wins when contact also matches the filter
 *  - N2: resolveWithStats final === resolve count when exclude overlaps a dup
 *  - N3: soft-deleted included contact never appears
 *  - Funnel identity: matched − suppressed − cold − personal − dups − manually_excluded === final
 *  - B2 parity: Segment::contactsCount() === resolve()->count() when pins exist
 *  - manually_included / manually_excluded keys present on no-pin path (= 0)
 */
class SegmentPinTest extends TestCase
{
    use RefreshDatabase;

    private SegmentService $service;

    /** Client company — passes cold gate regardless of setting */
    private Company $clientCompany;
    /** Prospect company — cold gate blocks it when disabled */
    private Company $prospectCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->service = app(SegmentService::class);

        $this->clientCompany = Company::create([
            'name'                 => 'Client Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Transport',
            'country'              => 'FR',
        ]);

        $this->prospectCompany = Company::create([
            'name'                 => 'Prospect SARL',
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Logistics',
            'country'              => 'BE',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeContact(Company $co, string $email, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Test Contact',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => $co->relationship === 'client' ? 'relationship' : 'legitimate_interest',
            'email_kind'  => 'role',
        ], $extra));
    }

    private function makeSegment(string $scope = 'client', array $filter = [], string $name = 'Test Segment'): Segment
    {
        return Segment::create([
            'name'   => $name,
            'scope'  => $scope,
            'filter' => empty($filter) ? null : $filter,
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A contact that does NOT match the filter (wrong sector) but is pinned-IN
     * must appear in resolve().
     */
    public function test_include_of_filter_miss_appears_in_resolve(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // contact at client company, sector = Transport — matches filter
        $matched = $this->makeContact($this->clientCompany, 'matched@client.test');

        // contact at another client company with sector = IT — does NOT match sector filter
        $otherCompany = Company::create([
            'name'                 => 'IT Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'IT',
            'country'              => 'FR',
        ]);
        $filtermiss = $this->makeContact($otherCompany, 'filtermiss@it.test');

        // Segment filters to sector=Transport, so filtermiss is NOT in the filter result
        $segment = $this->makeSegment('client', ['sector' => ['Transport']]);

        // Pin filtermiss in as an include
        $segment->pinnedContacts()->syncWithoutDetaching([
            $filtermiss->id => ['mode' => 'include'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertContains($matched->id, $ids, 'Filter-matched contact must appear');
        $this->assertContains($filtermiss->id, $ids, 'Pinned-IN filter-miss contact must appear in resolve()');
    }

    /**
     * B3: A pinned-include contact whose email matches a suppression row with
     * whitespace padding must NOT appear in resolve().
     *
     * Contact email: '  Blocked@X.com  ' (padded)
     * Suppression email: 'blocked@x.com' (clean)
     * The LOWER(TRIM()) normalization must match both sides.
     */
    public function test_b3_whitespace_padded_suppressed_include_does_not_appear(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // Insert contact with padded email directly (bypass unique validation)
        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            'company_id'  => $this->clientCompany->id,
            'email'       => '  Blocked@X.com  ',
            'name'        => 'Padded Blocked',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $paddedContact = Contact::where('email', '  Blocked@X.com  ')->firstOrFail();

        // Suppression row has clean email (no padding)
        Suppression::create([
            'email'  => 'blocked@x.com',
            'reason' => 'hard_bounce',
            'source' => 'campaign',
        ]);

        $segment = $this->makeSegment('client');

        // Pin the padded-email contact as include (bypasses filter, but not suppression)
        $segment->pinnedContacts()->syncWithoutDetaching([
            $paddedContact->id => ['mode' => 'include'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertNotContains(
            $paddedContact->id,
            $ids,
            'B3: Whitespace-padded suppressed include must NOT appear in resolve() (LOWER/TRIM normalization)'
        );
    }

    /**
     * B3 continuation: resolveWithStats manually_included reflects the drop of the
     * suppressed padded-email include.
     */
    public function test_b3_manually_included_reflects_suppressed_drop(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            'company_id'  => $this->clientCompany->id,
            'email'       => '  Blocked@X.com  ',
            'name'        => 'Padded Blocked 2',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $paddedContact = Contact::where('email', '  Blocked@X.com  ')->firstOrFail();

        Suppression::create([
            'email'  => 'blocked@x.com',
            'reason' => 'hard_bounce',
            'source' => 'campaign',
        ]);

        $segment = $this->makeSegment('client');
        $segment->pinnedContacts()->syncWithoutDetaching([
            $paddedContact->id => ['mode' => 'include'],
        ]);

        $stats = $this->service->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
        );

        // The suppressed include did not survive → manually_included must be 0
        // (survivor count, not attempt count)
        $this->assertSame(0, $stats['manually_included'],
            'B3: suppressed pinned-include must not be counted in manually_included (annotation is survivors only)');
        // Suppressed count must be >= 1
        $this->assertGreaterThanOrEqual(1, $stats['suppressed'],
            'B3: padded-email suppression must be counted in suppressed stage');
    }

    /**
     * A pinned-include of a cold-excluded prospect (cold gate OFF) must NOT
     * appear in resolve() — compliance pipeline still applies to includes.
     */
    public function test_include_of_cold_excluded_prospect_does_not_appear(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $prospect = $this->makeContact($this->prospectCompany, 'cold@prospect.test');

        // Segment is client-scoped; prospect company won't match scope.
        // We use mixed scope to let it through stage 1, then cold gate kills it.
        $segment = $this->makeSegment('mixed');

        // Pin prospect as include (bypasses scope, but cold gate still applies)
        $segment->pinnedContacts()->syncWithoutDetaching([
            $prospect->id => ['mode' => 'include'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertNotContains(
            $prospect->id,
            $ids,
            'Cold-excluded prospect include must NOT appear in resolve() (compliance applies to includes)'
        );
    }

    /**
     * A pinned-EXCLUDE of a filter-matched contact removes it from resolve().
     */
    public function test_exclude_removes_filter_matched_contact(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $keep    = $this->makeContact($this->clientCompany, 'keep@client.test');
        $exclude = $this->makeContact($this->clientCompany, 'exclude@client.test');

        $segment = $this->makeSegment('client');

        $segment->pinnedContacts()->syncWithoutDetaching([
            $exclude->id => ['mode' => 'exclude'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertContains($keep->id, $ids, 'Non-excluded contact must remain');
        $this->assertNotContains($exclude->id, $ids, 'Pinned-exclude must remove the contact from resolve()');
    }

    /**
     * Exclude wins when the contact also matches the filter (exclude always wins).
     */
    public function test_exclude_wins_over_filter_match(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $contact = $this->makeContact($this->clientCompany, 'both@client.test');

        $segment = $this->makeSegment('client');

        // Same contact is both in the filter result AND pinned exclude
        $segment->pinnedContacts()->syncWithoutDetaching([
            $contact->id => ['mode' => 'exclude'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertNotContains(
            $contact->id,
            $ids,
            'Exclude must win even when contact matches filter'
        );
    }

    /**
     * N2: when an exclude overlaps a duplicate email,
     * resolveWithStats final === resolve()->count().
     */
    public function test_n2_resolve_count_equals_stats_final_with_exclude_dup_overlap(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // Insert two contacts with emails that trim to the same value
        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            [
                'company_id'  => $this->clientCompany->id,
                'email'       => 'dup@corp.test',
                'name'        => 'Dup Clean',
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'company_id'  => $this->clientCompany->id,
                'email'       => ' dup@corp.test ',
                'name'        => 'Dup Padded',
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $clean  = Contact::where('email', 'dup@corp.test')->firstOrFail();
        $padded = Contact::where('email', ' dup@corp.test ')->firstOrFail();

        $segment = $this->makeSegment('client');

        // Exclude the clean copy — padded is the duplicate
        $segment->pinnedContacts()->syncWithoutDetaching([
            $clean->id => ['mode' => 'exclude'],
        ]);

        $resolveCount = $this->service->resolve($segment)->count();

        $stats = $this->service->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
        );

        $this->assertSame(
            $resolveCount,
            $stats['final'],
            'N2: resolveWithStats final must equal resolve()->count() when exclude overlaps a duplicate email'
        );
    }

    /**
     * N3: A soft-deleted included contact never appears in resolve().
     */
    public function test_n3_soft_deleted_included_contact_never_appears(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $alive   = $this->makeContact($this->clientCompany, 'alive@client.test');
        $deleted = $this->makeContact($this->clientCompany, 'deleted@client.test');

        $segment = $this->makeSegment('client');

        // Pin both as includes
        $segment->pinnedContacts()->syncWithoutDetaching([
            $alive->id   => ['mode' => 'include'],
            $deleted->id => ['mode' => 'include'],
        ]);

        // Soft-delete one
        $deleted->delete();

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertContains($alive->id, $ids, 'Live included contact must appear');
        $this->assertNotContains($deleted->id, $ids, 'N3: Soft-deleted included contact must NOT appear');
    }

    /**
     * Funnel identity:
     * matched − suppressed − cold_excluded − personal_excluded − duplicates_excluded − manually_excluded === final
     */
    public function test_funnel_identity_holds_with_pins(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // 2 client contacts that match the filter
        $keep    = $this->makeContact($this->clientCompany, 'keep@client.test');
        $exclude = $this->makeContact($this->clientCompany, 'excl@client.test');

        // 1 filter-miss client pinned-IN from a different sector
        $otherCo = Company::create([
            'name'                 => 'Other Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Finance',
            'country'              => 'FR',
        ]);
        $include = $this->makeContact($otherCo, 'include@other.test');

        $segment = $this->makeSegment('client', ['sector' => ['Transport']]);

        $segment->pinnedContacts()->syncWithoutDetaching([
            $include->id => ['mode' => 'include'],
            $exclude->id => ['mode' => 'exclude'],
        ]);

        $stats = $this->service->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
        );

        $computed = $stats['matched']
            - $stats['suppressed']
            - $stats['cold_excluded']
            - $stats['personal_excluded']
            - $stats['duplicates_excluded']
            - $stats['manually_excluded'];

        $this->assertSame(
            $stats['final'],
            $computed,
            'Funnel identity must hold with pins: matched − suppressed − cold − personal − dups − manually_excluded === final'
        );
    }

    /**
     * No-pin path: manually_included and manually_excluded keys exist and are 0.
     */
    public function test_no_pin_path_manually_keys_exist_and_are_zero(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $this->makeContact($this->clientCompany, 'a@client.test');

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertArrayHasKey('manually_included', $stats);
        $this->assertArrayHasKey('manually_excluded', $stats);
        $this->assertSame(0, $stats['manually_included']);
        $this->assertSame(0, $stats['manually_excluded']);
    }

    // ── B2 Parity ──────────────────────────────────────────────────────────────

    /**
     * B2: Segment::contactsCount() === resolve()->count() when pins are present.
     */
    public function test_b2_contacts_count_matches_resolve_count_with_pins(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $filterMatch = $this->makeContact($this->clientCompany, 'match@client.test');

        // Filter-miss client pinned-IN
        $otherCo = Company::create([
            'name'                 => 'Pin Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'IT',
            'country'              => 'FR',
        ]);
        $pinned = $this->makeContact($otherCo, 'pinned@it.test');

        // A filter-matching contact pinned-OUT
        $excluded = $this->makeContact($this->clientCompany, 'excluded@client.test');

        $segment = $this->makeSegment('client', ['sector' => ['Transport']]);

        $segment->pinnedContacts()->syncWithoutDetaching([
            $pinned->id   => ['mode' => 'include'],
            $excluded->id => ['mode' => 'exclude'],
        ]);

        $resolveCount   = $this->service->resolve($segment)->count();
        $contactsCount  = $segment->contactsCount();

        $this->assertSame(
            $resolveCount,
            $contactsCount,
            'B2: Segment::contactsCount() must equal resolve()->count() when pins exist'
        );
    }
}
