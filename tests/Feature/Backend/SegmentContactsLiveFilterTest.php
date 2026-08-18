<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentContactsLiveFilterTest — covers the live-filter path added to GET /admin/segments/{id}/contacts.
 *
 * Tests:
 *  - Live path: ?scope=client&filter[sector][]=X returns audience matching that scope+filter
 *    (differs from the segment's saved filter audience)
 *  - Pins from the saved segment apply on the live path (includeIds / excludeIds are always saved)
 *  - Absent/empty scope → falls back to the saved audience
 *  - ?filter[lifecycle_state]= (empty string) normalises to same audience as omitting it
 *  - Out-of-enum filter[country][]/filter[lifecycle_state] → 422 (identical to preview endpoint)
 *  - Missing scope with non-empty filter → falls back to saved audience (scope absent)
 *  - Response returns HTML fragment (text/html) on 200
 */
class SegmentContactsLiveFilterTest extends TestCase
{
    use RefreshDatabase;

    private User    $superadmin;
    private User    $noPermUser;
    private Segment $segment;

    /** Client company matching the segment's saved filter (sector=Transport) */
    private Company $clientCompanyTransport;
    /** Client company NOT matching the segment's saved filter (sector=IT) */
    private Company $clientCompanyIT;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Superadmin has 'view segments' (and everything else).
        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // A user with backend.access but NO segment permissions at all.
        $this->noPermUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->noPermUser->givePermissionTo('backend.access');

        // Companies
        $this->clientCompanyTransport = Company::create([
            'name'                 => 'Transport Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Transport',
            'country'              => 'FR',
        ]);

        $this->clientCompanyIT = Company::create([
            'name'                 => 'IT Corp',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'IT',
            'country'              => 'FR',
        ]);

        // Saved segment: scope=client, filter[sector]=Transport
        $this->segment = Segment::create([
            'name'   => 'Transport Clients',
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport']],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeContact(Company $company, string $email, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'company_id'                 => $company->id,
            'email'                      => $email,
            'name'                       => 'Test Contact ' . $email,
            'source'                     => 'manual',
            'email_kind'                 => 'role',
            'email_verification_status'  => 'valid',
        ], $extra));
    }

    private function contactsUrl(int $segmentId = 0): string
    {
        $id = $segmentId ?: $this->segment->id;
        return "/admin/segments/{$id}/contacts";
    }

    private function getContacts(array $params = []): \Illuminate\Testing\TestResponse
    {
        $url = $this->contactsUrl();
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $this->actingAs($this->superadmin)
            ->get($url, ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    // ── Live path: filter differs from saved segment ───────────────────────────

    /**
     * GET /admin/segments/{id}/contacts?scope=client&filter[sector][]=IT
     * should resolve the IT audience (sector=IT), not the saved sector=Transport audience.
     *
     * Transport contact appears in saved audience but NOT in live IT audience.
     * IT contact appears in live IT audience but NOT in saved Transport audience.
     */
    public function test_live_filter_returns_different_audience_from_saved(): void
    {

        $transportContact = $this->makeContact($this->clientCompanyTransport, 'transport@test.test');
        $itContact        = $this->makeContact($this->clientCompanyIT, 'it@test.test');

        // Saved audience: sector=Transport → only transportContact
        $savedResponse = $this->getContacts();
        $savedResponse->assertStatus(200);
        $savedHtml = $savedResponse->content();
        $this->assertStringContainsString('transport@test.test', $savedHtml, 'Saved audience must include Transport contact');
        $this->assertStringNotContainsString('it@test.test', $savedHtml, 'Saved audience must NOT include IT contact');

        // Live audience: scope=client, filter[sector][]=IT → only itContact
        $liveResponse = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['IT']],
        ]);
        $liveResponse->assertStatus(200);
        $liveHtml = $liveResponse->content();
        $this->assertStringContainsString('it@test.test', $liveHtml, 'Live IT filter must include IT contact');
        $this->assertStringNotContainsString('transport@test.test', $liveHtml, 'Live IT filter must NOT include Transport contact');
    }

    /**
     * The data-contacts-count attribute on the fragment root reflects the live audience count.
     */
    public function test_live_filter_fragment_carries_count_attribute(): void
    {

        // Two IT contacts, zero Transport contacts
        $this->makeContact($this->clientCompanyIT, 'it1@test.test');
        $this->makeContact($this->clientCompanyIT, 'it2@test.test');

        $response = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['IT']],
        ]);

        $response->assertStatus(200);
        $html = $response->content();

        // The root div must carry data-contacts-count="2"
        $this->assertStringContainsString('data-contacts-count="2"', $html,
            'Fragment root must carry data-contacts-count matching the live audience count');
    }

    // ── Pins from saved segment apply on the live path ─────────────────────────

    /**
     * An include-pin from the saved segment appears in the live-filter audience
     * even if it doesn't match the live filter (just as in the saved path).
     */
    public function test_saved_include_pin_applies_on_live_path(): void
    {

        // IT contact pinned-IN on the saved segment
        $itContact = $this->makeContact($this->clientCompanyIT, 'pinned-it@test.test');

        $this->segment->pinnedContacts()->syncWithoutDetaching([
            $itContact->id => ['mode' => 'include'],
        ]);

        // Live filter: sector=Transport — IT contact doesn't match, but it's pinned-in
        $response = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport']],
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('pinned-it@test.test', $response->content(),
            'Saved include-pin must appear in live audience even if it does not match live filter');
    }

    /**
     * An exclude-pin from the saved segment is subtracted from the live-filter audience.
     *
     * The excluded contact does NOT appear in the main audience table.
     * It DOES appear in the "X exclus" chip (excluded-contacts section) — this is by design:
     * the excluded chip always shows $segment->excludedContacts() regardless of live filter.
     * We verify via data-contacts-count that the audience count excludes it.
     */
    public function test_saved_exclude_pin_applies_on_live_path(): void
    {

        // Two IT contacts; one is excluded on the saved segment
        $itKeep    = $this->makeContact($this->clientCompanyIT, 'it-keep@test.test');
        $itExclude = $this->makeContact($this->clientCompanyIT, 'it-excl@test.test');

        $this->segment->pinnedContacts()->syncWithoutDetaching([
            $itExclude->id => ['mode' => 'exclude'],
        ]);

        // Live filter: sector=IT — both contacts match the filter, but itExclude is excluded
        $response = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['IT']],
        ]);

        $response->assertStatus(200);
        $html = $response->content();

        // it-keep must appear in the main table
        $this->assertStringContainsString('it-keep@test.test', $html, 'Non-excluded IT contact must appear');

        // Audience count must be 1 (only it-keep — it-excl is excluded)
        $this->assertStringContainsString('data-contacts-count="1"', $html,
            'data-contacts-count must be 1 (exclude pin removes contact from live audience)');

        // it-excl appears in the excluded chip section (correct — excluded contacts are always
        // shown in the chip regardless of live filter), but it must NOT appear in the main table.
        // We verify this via the count: if it appeared in the audience, count would be 2.
    }

    // ── Absent/empty scope → saved audience ───────────────────────────────────

    /**
     * GET without scope parameter → falls back to the saved audience.
     */
    public function test_absent_scope_falls_back_to_saved_audience(): void
    {

        $transportContact = $this->makeContact($this->clientCompanyTransport, 'saved@test.test');
        $itContact        = $this->makeContact($this->clientCompanyIT, 'extra-it@test.test');

        $response = $this->getContacts(); // no scope

        $response->assertStatus(200);
        $html = $response->content();
        // Saved segment has filter[sector]=Transport → only Transport contact
        $this->assertStringContainsString('saved@test.test', $html, 'Saved Transport contact must appear on saved path');
        $this->assertStringNotContainsString('extra-it@test.test', $html, 'IT contact must NOT appear on saved path');
    }

    /**
     * GET with scope="" (empty string) → falls back to the saved audience.
     * filled('') === false in Laravel, so this is treated as absent.
     */
    public function test_empty_scope_string_falls_back_to_saved_audience(): void
    {

        $transportContact = $this->makeContact($this->clientCompanyTransport, 'saved2@test.test');

        // Pass scope= (empty) explicitly — filled('') = false → saved path
        $url = $this->contactsUrl() . '?scope=';
        $response = $this->actingAs($this->superadmin)
            ->get($url, ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest']);

        $response->assertStatus(200);
        $this->assertStringContainsString('saved2@test.test', $response->content(),
            'Empty scope must fall back to saved audience');
    }

    // ── Normalization parity ───────────────────────────────────────────────────

    /**
     * ?filter[lifecycle_state]= (empty string) yields same audience as omitting it entirely.
     * normalizeFilter() strips empty lifecycle_state → both calls resolve identically.
     *
     * Renamed from the dead filter.status key (2026-08-16): the form and both
     * server-side validators only ever accepted filter.lifecycle_state.
     */
    public function test_empty_status_filter_is_normalized_same_as_omitted(): void
    {

        // Two IT contacts
        $this->makeContact($this->clientCompanyIT, 'status-new@test.test');
        $this->makeContact($this->clientCompanyIT, 'status-qualified@test.test');

        // With explicit empty lifecycle_state
        $withEmpty = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['IT'], 'lifecycle_state' => ''],
        ]);
        $withEmpty->assertStatus(200);

        // Without status key
        $withoutStatus = $this->getContacts([
            'scope'  => 'client',
            'filter' => ['sector' => ['IT']],
        ]);
        $withoutStatus->assertStatus(200);

        // Both must include both contacts (no status restriction)
        foreach (['status-new@test.test', 'status-qualified@test.test'] as $email) {
            $this->assertStringContainsString($email, $withEmpty->content(),
                "Empty status: {$email} must appear");
            $this->assertStringContainsString($email, $withoutStatus->content(),
                "Omitted status: {$email} must appear");
        }
    }

    // ── Validation parity with preview endpoint ────────────────────────────────

    /**
     * An out-of-enum filter[country][] → 422 (identical rule as preview).
     */
    public function test_invalid_country_in_live_filter_returns_422(): void
    {
        $invalidCountry = 'ZZ';
        $countryCodes   = array_keys(config('global.data.company_countries', []));
        if (in_array($invalidCountry, $countryCodes, true)) {
            $invalidCountry = 'XX';
        }

        $url = $this->contactsUrl() . '?' . http_build_query([
            'scope'  => 'client',
            'filter' => ['country' => [$invalidCountry]],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->getJson($url); // getJson so validation errors are returned as JSON

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.country.0']);
    }

    /**
     * An out-of-enum filter[lifecycle_state] → 422 (identical rule as preview).
     *
     * Renamed from the dead filter.status key (2026-08-16): the form and both
     * server-side validators only ever accepted filter.lifecycle_state.
     */
    public function test_invalid_status_in_live_filter_returns_422(): void
    {
        $url = $this->contactsUrl() . '?' . http_build_query([
            'scope'  => 'client',
            'filter' => ['lifecycle_state' => 'not_a_real_status'],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->getJson($url);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.lifecycle_state']);
    }

    /**
     * An out-of-enum scope → 422.
     */
    public function test_invalid_scope_in_live_filter_returns_422(): void
    {
        $url = $this->contactsUrl() . '?' . http_build_query([
            'scope' => 'not_a_real_scope',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->getJson($url);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope']);
    }

    // ── Auth gating ────────────────────────────────────────────────────────────

    /**
     * Guest → redirect (302/401).
     */
    public function test_guest_is_redirected(): void
    {
        $response = $this->get($this->contactsUrl() . '?scope=client');
        $this->assertContains($response->status(), [302, 401]);
    }

    /**
     * User without view segments → 403.
     */
    public function test_user_without_view_segments_gets_403(): void
    {
        $response = $this->actingAs($this->noPermUser)
            ->get($this->contactsUrl() . '?scope=client',
                  ['Accept' => 'text/html', 'X-Requested-With' => 'XMLHttpRequest']);
        $response->assertStatus(403);
    }

    // ── Saved path: data-contacts-count matches saved audience ─────────────────

    /**
     * On the saved path (no scope), data-contacts-count reflects the saved audience count.
     */
    public function test_saved_path_fragment_carries_correct_count(): void
    {

        // Two Transport contacts → saved audience count = 2
        $this->makeContact($this->clientCompanyTransport, 'c1@test.test');
        $this->makeContact($this->clientCompanyTransport, 'c2@test.test');

        $response = $this->getContacts(); // no scope → saved path
        $response->assertStatus(200);

        $this->assertStringContainsString('data-contacts-count="2"', $response->content(),
            'Saved path must emit data-contacts-count="2" on fragment root');
    }
}
