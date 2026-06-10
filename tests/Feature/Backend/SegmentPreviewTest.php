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
 *  - Funnel math: matched - suppressed - cold_excluded - personal_excluded - duplicates_excluded === final
 *  - Validation: missing scope, invalid country, too-many-sector items, invalid status → 422
 *  - Sample: ≤ 10 items, each has name/company/email, deterministic (ordered by id)
 *  - summary: non-empty French string
 *  - cold_gate_closed: reflects config('prospecting.cold_send_enabled')
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
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => $name,
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => $emailKind,
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
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Pierre Martin',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'legitimate_interest',
            'email_kind'  => $emailKind,
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
        config(['prospecting.cold_send_enabled' => false]);

        $response = $this->postPreview(['scope' => 'client']);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'matched',
            'suppressed',
            'cold_excluded',
            'personal_excluded',
            'duplicates_excluded',
            'final',
            'sample',
            'summary',
            'cold_gate_closed',
        ]);
    }

    public function test_cold_gate_closed_true_when_disabled(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $response = $this->postPreview(['scope' => 'client']);

        $response->assertStatus(200);
        $response->assertJson(['cold_gate_closed' => true]);
    }

    public function test_cold_gate_closed_false_when_enabled(): void
    {
        config(['prospecting.cold_send_enabled' => true]);

        $response = $this->postPreview(['scope' => 'client']);

        $response->assertStatus(200);
        $response->assertJson(['cold_gate_closed' => false]);
    }

    // ── Funnel math ────────────────────────────────────────────────────────────

    /**
     * Funnel identity must hold:
     *   matched − suppressed − cold_excluded − personal_excluded − duplicates_excluded === final
     *
     * Fixture:
     *   - 2 client contacts (both in FR/Transport)
     *   - 1 suppressed client contact
     *   - 1 prospect contact (cold gate OFF → cold_excluded = 1)
     *
     * Cold gate is off so prospects land in cold_excluded.
     */
    public function test_funnel_math_closes(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // Client contacts
        [, $c1] = $this->makeClientContact('alice@acme.test', 'role', 'Alice');
        [, $c2] = $this->makeClientContact('bob@acme.test', 'role', 'Bob', 'Acme2 SA');

        // Suppress one client contact
        Suppression::create([
            'email'  => 'alice@acme.test',
            'reason' => 'unsubscribe',
            'source' => 'manual',
        ]);

        // Prospect contact (cold gate closed → cold_excluded)
        $this->makeProspectContact('prospect@cold.test');

        $response = $this->postPreview(['scope' => 'mixed']);
        $response->assertStatus(200);

        $data = $response->json();

        $computed = $data['matched']
            - $data['suppressed']
            - $data['cold_excluded']
            - $data['personal_excluded']
            - $data['duplicates_excluded'];

        $this->assertSame($data['final'], $computed, 'Funnel identity must hold');
        // alice is suppressed, prospect is cold-excluded, bob is final
        $this->assertSame(1, $data['suppressed'], 'One suppressed contact');
        $this->assertSame(1, $data['cold_excluded'], 'One cold-excluded contact');
        $this->assertSame(1, $data['final'], 'Only bob survives');
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

    public function test_invalid_contact_status_returns_422(): void
    {
        $response = $this->postPreview([
            'scope'  => 'client',
            'filter' => ['status' => 'not_a_real_status'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.status']);
    }

    // ── Sample ─────────────────────────────────────────────────────────────────

    public function test_sample_has_at_most_10_items(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // Shared company to reduce FK+lock pressure (one company, 12 contacts)
        $co = Company::create([
            'name'                 => 'Bulk Company',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        for ($i = 1; $i <= 12; $i++) {
            Contact::create([
                'company_id'  => $co->id,
                'email'       => "bulk{$i}@bulk.test",
                'name'        => "Bulk {$i}",
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
            ]);
        }

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        $sample = $response->json('sample');
        $this->assertIsArray($sample);
        $this->assertLessThanOrEqual(10, count($sample), 'Sample must contain at most 10 items');
    }

    public function test_sample_items_have_name_company_email_keys(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        $this->makeClientContact('sample@acme.test', 'role', 'Henri Lebrun', 'Lebrun SARL');

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        $sample = $response->json('sample');
        $this->assertNotEmpty($sample, 'Sample must not be empty when contacts exist');

        foreach ($sample as $item) {
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('company', $item);
            $this->assertArrayHasKey('email', $item);
        }
    }

    public function test_sample_is_ordered_by_contact_id(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

        // One company, 4 contacts — keeps inserts low, still verifies order
        $co = Company::create([
            'name'                 => 'Order Company',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $expectedEmails = [];
        for ($i = 1; $i <= 4; $i++) {
            Contact::create([
                'company_id'  => $co->id,
                'email'       => "order{$i}@order.test",
                'name'        => "Order {$i}",
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
            ]);
            $expectedEmails[] = "order{$i}@order.test";
        }

        $response = $this->postPreview(['scope' => 'client']);
        $response->assertStatus(200);

        $sample = $response->json('sample');
        $actualEmails = array_column($sample, 'email');

        // Must be in insertion order (lowest contact id first)
        $this->assertSame($expectedEmails, $actualEmails, 'Sample must be ordered by contact id');
    }

    // ── Summary ────────────────────────────────────────────────────────────────

    public function test_summary_is_non_empty_french_string(): void
    {
        config(['prospecting.cold_send_enabled' => false]);

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
        config(['prospecting.cold_send_enabled' => false]);

        $response = $this->postPreview(['scope' => 'prospect']);
        $response->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertStringContainsString('prospects', $summary);
    }
}
