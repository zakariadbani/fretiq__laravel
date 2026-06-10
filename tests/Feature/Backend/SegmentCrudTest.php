<?php

namespace Tests\Feature\Backend;

use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentCrudTest — HTTP CRUD surface for the /admin/segments resource.
 *
 * Tests the new structured-field beforeSave() pattern introduced in the
 * rebuilt segments module (filter[sector][], filter[country][], filter[status]).
 *
 * Key contract:
 *   - Crudable::store() returns HTTP 200 JSON {message, model, redirect} on success.
 *   - Crudable::store() returns HTTP 406 JSON {message, errors} on validation failure.
 *   - SegmentController::beforeSave() normalises structured filter fields to a
 *     clean array (or null if all fields are empty) before the model validator runs.
 */
class SegmentCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Store ──────────────────────────────────────────────────────────────────

    /**
     * POST /admin/segments with structured filter fields → segment persisted
     * with filter stored as the correct JSON structure.
     */
    public function test_store_with_structured_filter_persists_correctly(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'             => 'Transport France Nouveaux',
                'scope'            => 'client',
                'filter'           => [
                    'sector'  => ['Transport'],
                    'country' => ['FR'],
                    'status'  => 'new',
                ],
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'Transport France Nouveaux')->first();
        $this->assertNotNull($segment, 'Segment must be persisted in the database');

        $filter = $segment->filter;
        $this->assertIsArray($filter, 'filter must be cast to array');
        $this->assertSame(['Transport'], $filter['sector']);
        $this->assertSame(['FR'], $filter['country']);
        $this->assertSame('new', $filter['status']);
    }

    /**
     * POST with multi-value sector and country arrays.
     */
    public function test_store_with_multi_value_filter(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'  => 'Multi Filter Segment',
                'scope' => 'mixed',
                'filter' => [
                    'sector'  => ['Transport', 'Logistique'],
                    'country' => ['FR', 'BE'],
                ],
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'Multi Filter Segment')->first();
        $this->assertNotNull($segment);

        $filter = $segment->filter;
        $this->assertSame(['Transport', 'Logistique'], $filter['sector']);
        $this->assertSame(['FR', 'BE'], $filter['country']);
        $this->assertArrayNotHasKey('status', $filter, 'status key must be absent when not provided');
    }

    /**
     * POST with all filter fields empty → filter stored as null.
     */
    public function test_store_with_empty_filter_stores_null(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'  => 'No Filter Segment',
                'scope' => 'client',
                'filter' => [
                    'sector'  => [],
                    'country' => [],
                    'status'  => '',
                ],
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('segments', ['name' => 'No Filter Segment']);

        $segment = Segment::where('name', 'No Filter Segment')->first();
        $this->assertNull($segment->filter, 'filter must be null when all filter fields are empty');
    }

    /**
     * POST without any filter key → filter stored as null.
     */
    public function test_store_without_filter_key_stores_null(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'  => 'Bare Segment',
                'scope' => 'prospect',
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'Bare Segment')->first();
        $this->assertNull($segment->filter);
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    /**
     * PUT /admin/segments/{id} changes the filter correctly.
     */
    public function test_update_changes_filter(): void
    {
        $segment = Segment::create([
            'name'   => 'Original Segment',
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport']],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put("/admin/segments/{$segment->id}", [
                'name'  => 'Updated Segment',
                'scope' => 'mixed',
                'filter' => [
                    'sector'  => ['Logistique'],
                    'country' => ['DE'],
                    'status'  => 'qualified',
                ],
            ]);

        $response->assertStatus(200);

        $segment->refresh();
        $this->assertSame('Updated Segment', $segment->name);
        $this->assertSame('mixed', $segment->scope);
        $this->assertSame(['Logistique'], $segment->filter['sector']);
        $this->assertSame(['DE'], $segment->filter['country']);
        $this->assertSame('qualified', $segment->filter['status']);
    }

    /**
     * PUT with empty filter clears it to null.
     */
    public function test_update_to_empty_filter_sets_null(): void
    {
        $segment = Segment::create([
            'name'   => 'Segment With Filter',
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport']],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put("/admin/segments/{$segment->id}", [
                'name'   => 'Segment With Filter',
                'scope'  => 'client',
                'filter' => ['sector' => [], 'country' => [], 'status' => ''],
            ]);

        $response->assertStatus(200);

        $segment->refresh();
        $this->assertNull($segment->filter, 'filter must be cleared to null when all fields empty');
    }

    // ── Validation failures ────────────────────────────────────────────────────

    /**
     * Missing name → 406 with errors.
     * Crudable::store() returns 406 (not 422) when the model validator fails.
     */
    public function test_store_requires_name(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'scope' => 'client',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors']);
        $response->assertJsonValidationErrors(['name'], 'errors');
    }

    /**
     * Missing scope → 406 with errors.
     */
    public function test_store_requires_scope(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name' => 'Missing Scope',
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['scope'], 'errors');
    }

    /**
     * Invalid scope value → 406.
     */
    public function test_store_rejects_invalid_scope(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'  => 'Bad Scope',
                'scope' => 'not_a_scope',
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['scope'], 'errors');
    }

    /**
     * filter.country with invalid ISO code → 406.
     *
     * The model-level validator uses the same country_countries config keys
     * as the preview endpoint validator.
     */
    public function test_store_rejects_invalid_country_in_filter(): void
    {
        // Choose a code that is NOT in the country list
        $countryCodes   = array_keys(config('global.data.company_countries', []));
        $invalidCountry = 'ZZ';
        if (in_array($invalidCountry, $countryCodes, true)) {
            $invalidCountry = 'XX';
        }

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Bad Country',
                'scope'  => 'client',
                'filter' => ['country' => [$invalidCountry]],
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['filter.country.0'], 'errors');
    }

    /**
     * filter.sector with > 20 items → 406 (max:20 rule on the array).
     */
    public function test_store_rejects_sector_array_exceeding_20_items(): void
    {
        $sectors = array_map(fn ($i) => "Secteur{$i}", range(1, 25));

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Too Many Sectors',
                'scope'  => 'client',
                'filter' => ['sector' => $sectors],
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['filter.sector'], 'errors');
    }

    /**
     * filter.status with invalid value → 406.
     */
    public function test_store_rejects_invalid_status_in_filter(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Bad Status',
                'scope'  => 'client',
                'filter' => ['status' => 'not_a_valid_status_value'],
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['filter.status'], 'errors');
    }

    // ── Permission gating ──────────────────────────────────────────────────────

    /**
     * Guest cannot access segment CRUD.
     */
    public function test_guest_cannot_store_segment(): void
    {
        $response = $this->postJson('/admin/segments', [
            'name'  => 'Guest Attempt',
            'scope' => 'client',
        ]);

        $this->assertContains($response->status(), [302, 401]);
    }

    /**
     * Commercial role has 'create segments' permission — store should succeed.
     */
    public function test_commercial_can_store_segment(): void
    {
        $response = $this->actingAs($this->commercial)
            ->post('/admin/segments', [
                'name'  => 'Commercial Segment',
                'scope' => 'client',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('segments', ['name' => 'Commercial Segment']);
    }
}
