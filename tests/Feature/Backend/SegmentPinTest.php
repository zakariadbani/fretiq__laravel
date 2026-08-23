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
 *  - Include of a prospect appears when it is eligible
 *  - Exclude removes a filter-matched contact
 *  - Exclude wins when contact also matches the filter
 *  - N2: resolveWithStats final === resolve count when exclude overlaps a dup
 *  - N3: soft-deleted included contact never appears
 *  - Funnel identity: matched − suppressed − dups − manually_excluded === final
 *  - B2 parity: Segment::contactsCount() === resolve()->count() when pins exist
 *  - manually_included / manually_excluded keys present on no-pin path (= 0)
 */
class SegmentPinTest extends TestCase
{
    use RefreshDatabase;

    private SegmentService $service;

    /** Client company fixture. */
    private Company $clientCompany;
    /** Prospect company fixture. */
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
            'email_verification_status' => 'valid',
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
        $segment = $this->makeSegment('client', ['sector' => ['Transport & Logistique']]);

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

        $paddedContact = $this->makeContact($this->clientCompany, '  Blocked@X.com  ', ['name' => 'Padded Blocked']);

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

        $paddedContact = $this->makeContact($this->clientCompany, '  Blocked@X.com  ', ['name' => 'Padded Blocked 2']);

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
     * A pinned prospect remains eligible after the scope/filter union.
     */
    public function test_include_of_prospect_appears(): void
    {
        $prospect = $this->makeContact($this->prospectCompany, 'contact@prospect.test');

        $segment = $this->makeSegment('client');

        $segment->pinnedContacts()->syncWithoutDetaching([
            $prospect->id => ['mode' => 'include'],
        ]);

        $resolved = $this->service->resolve($segment);
        $ids      = $resolved->pluck('id')->all();

        $this->assertContains($prospect->id, $ids);
    }

    /**
     * A pinned-EXCLUDE of a filter-matched contact removes it from resolve().
     */
    public function test_exclude_removes_filter_matched_contact(): void
    {

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

        $clean  = $this->makeContact($this->clientCompany, 'dup@corp.test',  ['name' => 'Dup Clean']);
        $padded = $this->makeContact($this->clientCompany, ' dup@corp.test ', ['name' => 'Dup Padded']);

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
     * matched − suppressed − duplicates_excluded − manually_excluded === final
     */
    public function test_funnel_identity_holds_with_pins(): void
    {

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

        $segment = $this->makeSegment('client', ['sector' => ['Transport & Logistique']]);

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
            - $stats['duplicates_excluded']
            - $stats['manually_excluded'];

        $this->assertSame(
            $stats['final'],
            $computed,
            'Funnel identity must hold with pins'
        );
    }

    /**
     * No-pin path: manually_included and manually_excluded keys exist and are 0.
     */
    public function test_no_pin_path_manually_keys_exist_and_are_zero(): void
    {

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

        $segment = $this->makeSegment('client', ['sector' => ['Transport & Logistique']]);

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
