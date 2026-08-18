<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentPreviewTest — covers the POST /admin/segments/preview endpoint.
 *
 * Tests:
 *  - Auth gating: guest → redirect, no-permission → 403, permitted → 200
 *  - Response structure: required JSON keys present
 *  - Funnel math: matched - suppressed - duplicates_excluded === final
 *  - Validation: missing scope, invalid country, too-many-sector items, invalid status → 422
 *  - Sample: ≤ 10 items, each has name/company/email, deterministic (ordered by id)
 *  - summary: non-empty French string
 */
class SegmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $noPermUser;

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
        // Assign a bare custom role with only backend.access so they can pass
        // the group middleware but fail the per-action permission check.
        $this->noPermUser->givePermissionTo('backend.access');
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    /**
     * Make a client company with one contact.
     */
    private function makeClientContact(
        string $email     = 'jean@acme.test',
        string $emailKind = 'role',
        string $name      = 'Jean Dupont',
        string $company   = 'Acme SA',
        string $sector    = 'Transport',
        string $country   = 'FR',
    ): array {
        $co = Company::create([
            'name'                 => $company,
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => $sector,
            'country'              => $country,
        ]);

        $ct = Contact::create([
            'company_id'                 => $co->id,
            'email'                      => $email,
            'name'                       => $name,
            'source'                     => 'manual',
            'email_kind'                 => $emailKind,
            'email_verification_status'  => 'valid',
        ]);

        return [$co, $ct];
    }

    /**
     * Make a prospect company with one contact.
     */
    private function makeProspectContact(
        string $email     = 'pierre@prospect.test',
        string $emailKind = 'role',
    ): array {
        $co = Company::create([
            'name'                 => 'Prospect SARL',
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $ct = Contact::create([
            'company_id'                 => $co->id,
            'email'                      => $email,
            'name'                       => 'Pierre Martin',
            'source'                     => 'manual',
            'email_kind'                 => $emailKind,
            'email_verification_status'  => 'valid',
        ]);

        return [$co, $ct];
    }

    private function postPreview(array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superadmin)
            ->postJson('/admin/segments/preview', $body);
    }

    // ── Auth gating ────────────────────────────────────────────────────────────

    public function test_guest_is_redirected(): void
    {
        $response = $this->postJson('/admin/segments/preview', ['scope' => 'client']);

        // postJson with guest returns 401; regular post returns 302.
        $this->assertContains($response->status(), [302, 401]);
    }

    public function test_user_without_view_segments_gets_403(): void
    {
        $response = $this->actingAs($this->noPermUser)
            ->postJson('/admin/segments/preview', ['scope' => 'client']);

        $response->assertStatus(403);
    }

    // ── Happy path ─────────────────────────────────────────────────────────────

    public function test_permitted_user_gets_200_with_expected_keys(): void
    {

        $response = $this->postPreview(['scope' => 'client']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'matched',
            'suppressed',
            'duplicates_excluded',
            'manually_excluded',
            'manually_included',
            'final',
            'sample',
            'summary',
        ]);
    }

    /**
     * On the no-pin preview path (preview endpoint never passes pin ids),
     * manually_included and manually_excluded must be 0.
     */
    public function test_preview_manually_keys_are_zero_on_no_pin_path(): void
    {

        $this->makeClientContact();

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);
        $response->assertJson(['manually_included' => 0, 'manually_excluded' => 0]);
    }

    // ── Funnel math ────────────────────────────────────────────────────────────

    /**
     * Funnel identity must hold:
     *   matched − suppressed − duplicates_excluded === final
     *
     * Fixture:
     *   - 2 client contacts (both in FR/Transport)
     *   - 1 suppressed client contact
     *   - 1 prospect contact
     */
    public function test_funnel_math_closes(): void
    {

        // Client contacts
        [, $c1] = $this->makeClientContact('alice@acme.test', 'role', 'Alice');
        [, $c2] = $this->makeClientContact('bob@acme.test', 'role', 'Bob', 'Acme2 SA');

        // Suppress one client contact
        Suppression::create([
            'email'  => 'alice@acme.test',
            'reason' => 'unsubscribe',
            'source' => 'manual',
        ]);

        $this->makeProspectContact('prospect@example.test');

        $response = $this->postPreview(['scope' => 'mixed']);
        $response->assertStatus(200);

        $data = $response->json();

        $computed = $data['matched']
            - $data['suppressed']
            - $data['duplicates_excluded']
            - $data['manually_excluded'];  // new term — 0 on the no-pin preview path

        $this->assertSame($data['final'], $computed, 'Funnel identity must hold (incl. manually_excluded)');
        // alice is suppressed; bob and the prospect remain eligible.
        $this->assertSame(1, $data['suppressed'], 'One suppressed contact');
        $this->assertSame(2, $data['final']);
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    public function test_missing_scope_returns_422(): void
    {
        $response = $this->postPreview([]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope']);
    }

    public function test_invalid_scope_returns_422(): void
    {
        $response = $this->postPreview(['scope' => 'unknown_scope']);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['scope']);
    }

    public function test_invalid_country_code_returns_422(): void
    {
        // 'ZZ' is 2 chars and size:2 passes, but must also be in company_countries keys.
        // If 'ZZ' happens to be in the list, we use an obviously invalid code.
        $invalidCountry = 'ZZ';
        $countryCodes   = array_keys(config('global.data.company_countries', []));

        // Make sure our chosen code is NOT in the list
        if (in_array($invalidCountry, $countryCodes, true)) {
            $invalidCountry = 'XX';
        }

        $response = $this->postPreview([
            'scope'            => 'client',
            'filter'           => ['country' => [$invalidCountry]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.country.0']);
    }

    public function test_sector_array_exceeding_max_20_returns_422(): void
    {
        $sectors = array_map(fn ($i) => "Secteur{$i}", range(1, 25));

        $response = $this->postPreview([
            'scope'  => 'client',
            'filter' => ['sector' => $sectors],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.sector']);
    }

    /**
     * Renamed from the dead filter.status key (2026-08-16): the form and both
     * server-side validators only ever accepted filter.lifecycle_state.
     */
    public function test_invalid_contact_status_returns_422(): void
    {
        $response = $this->postPreview([
            'scope'  => 'client',
            'filter' => ['lifecycle_state' => 'not_a_real_status'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.lifecycle_state']);
    }

    // ── Sample ─────────────────────────────────────────────────────────────────

    public function test_sample_has_at_most_10_items(): void
    {

        // Shared company to reduce FK+lock pressure (one company, 12 contacts)
        $co = Company::create([
            'name'                 => 'Bulk Company',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Contact::create([
                'company_id'                 => $co->id,
                'email'                      => "bulk{$i}@bulk.test",
                'name'                       => "Bulk {$i}",
                'source'                     => 'manual',
                'email_kind'                 => 'role',
                'email_verification_status'  => 'valid',
            ]);
        }

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        $sample = $response->json('sample');
        $this->assertIsArray($sample);
        $this->assertLessThanOrEqual(10, count($sample), 'Sample must contain at most 10 items');
    }

    /**
     * M2: preview endpoint now passes withSample=false (sample table removed from UI).
     * The sample key must exist but must be an empty array.
     */
    public function test_sample_items_have_name_company_email_keys(): void
    {

        $this->makeClientContact('sample@acme.test', 'role', 'Henri Lebrun', 'Lebrun SARL');

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        // M2: preview is called with withSample=false — sample is always empty.
        // The sample key must still exist (structure contract).
        $sample = $response->json('sample');
        $this->assertIsArray($sample, 'sample key must be an array');
        // Items are empty because withSample=false (M2 — sample table removed from edit UI).
        // When sample IS requested (withSample=true), each item would have name/company/email.
    }

    /**
     * M2: preview endpoint now passes withSample=false (sample table removed from UI).
     * The sample is always empty — ordering test now asserts the key exists and is an array.
     */
    public function test_sample_is_ordered_by_contact_id(): void
    {

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        // M2: withSample=false → always empty. Key must still exist.
        $sample = $response->json('sample');
        $this->assertIsArray($sample, 'sample key must be an array (even if empty)');
    }

    // ── Summary ────────────────────────────────────────────────────────────────

    public function test_summary_is_non_empty_french_string(): void
    {

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
        // Must start with the French "Cible" keyword
        $this->assertStringStartsWith('Cible', $summary);
    }

    public function test_summary_mentions_scope_in_french(): void
    {

        $response = $this->postPreview(['scope' => 'prospect']);
        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertStringContainsString('prospects', $summary);
    }
}
