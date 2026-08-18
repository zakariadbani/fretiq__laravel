<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectCriteria;
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
                    'sector'          => ['Transport'],
                    'country'         => ['FR'],
                    'lifecycle_state' => 'contacted',
                ],
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'Transport France Nouveaux')->first();
        $this->assertNotNull($segment, 'Segment must be persisted in the database');

        $filter = $segment->filter;
        $this->assertIsArray($filter, 'filter must be cast to array');
        $this->assertSame(['Transport'], $filter['sector']);
        $this->assertSame(['FR'], $filter['country']);
        $this->assertSame('contacted', $filter['lifecycle_state']);
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

    /**
     * POST with the new filter keys (position, exclude_contacted, exclude_generic_mailbox)
     * plus criteria_id — all must persist. criteria_id round-trip is the bug fix:
     * beforeSave() previously silently dropped it despite it being validated and previewed.
     */
    public function test_store_with_new_filter_keys_and_criteria_id_persists_correctly(): void
    {
        $criteria = ProspectCriteria::create(['name' => 'Critère Segment', 'is_active' => true]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Decision Makers',
                'scope'  => 'client',
                'filter' => [
                    'position'                => ['directeur', 'manager'],
                    'exclude_contacted'       => '1',
                    'exclude_generic_mailbox' => '1',
                    'criteria_id'             => [$criteria->id],
                ],
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'Decision Makers')->first();
        $this->assertNotNull($segment);

        $filter = $segment->filter;
        $this->assertSame(['directeur', 'manager'], $filter['position']);
        $this->assertTrue($filter['exclude_contacted']);
        $this->assertTrue($filter['exclude_generic_mailbox']);
        $this->assertSame([$criteria->id], $filter['criteria_id'], 'criteria_id must now survive beforeSave() (bug fix)');
    }

    /**
     * Unchecked / absent boolean filter keys must not be persisted as noise.
     */
    public function test_store_with_absent_booleans_omits_them_from_filter(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'No Booleans',
                'scope'  => 'client',
                'filter' => [
                    'position' => ['manager'],
                ],
            ]);

        $response->assertStatus(200);

        $segment = Segment::where('name', 'No Booleans')->first();
        $this->assertArrayNotHasKey('exclude_contacted', $segment->filter);
        $this->assertArrayNotHasKey('exclude_generic_mailbox', $segment->filter);
    }

    /**
     * PUT changes criteria_id — round-trip update coverage for the bug fix.
     */
    public function test_update_changes_criteria_id(): void
    {
        $criteriaA = ProspectCriteria::create(['name' => 'Critère A', 'is_active' => true]);
        $criteriaB = ProspectCriteria::create(['name' => 'Critère B', 'is_active' => true]);

        $segment = Segment::create([
            'name'   => 'Criteria Segment',
            'scope'  => 'client',
            'filter' => ['criteria_id' => [$criteriaA->id]],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put("/admin/segments/{$segment->id}", [
                'name'   => 'Criteria Segment',
                'scope'  => 'client',
                'filter' => ['criteria_id' => [$criteriaB->id]],
            ]);

        $response->assertStatus(200);

        $segment->refresh();
        $this->assertSame([$criteriaB->id], $segment->filter['criteria_id']);
    }

    /**
     * Full round-trip THROUGH THE FORM: create with criteria_id → reopen the
     * edit view → the select control must render the saved value as selected.
     * Guards against the "no UI control" gap — validation/persistence alone
     * are not enough if the form never surfaces the field back to the operator.
     */
    public function test_edit_form_reopens_with_criteria_id_selected(): void
    {
        $criteria = ProspectCriteria::create(['name' => 'Critère Form Reopen', 'is_active' => true]);

        $segment = Segment::create([
            'name'   => 'Reopen Segment',
            'scope'  => 'client',
            'filter' => ['criteria_id' => [$criteria->id]],
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/segments/{$segment->id}/edit");

        $response->assertStatus(200);

        $normalized = preg_replace('/\s+/', ' ', $response->getContent());
        $this->assertStringContainsString(
            'name="filter[criteria_id][]"',
            $normalized,
            'the criteria_id multi-select control must be present in the form'
        );
        $this->assertStringContainsString(
            'value="' . $criteria->id . '" selected',
            $normalized,
            'saved criteria_id must render back as a selected option on reopen'
        );
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
                    'sector'          => ['Logistique'],
                    'country'         => ['DE'],
                    'lifecycle_state' => 'verified',
                ],
            ]);

        $response->assertStatus(200);

        $segment->refresh();
        $this->assertSame('Updated Segment', $segment->name);
        $this->assertSame('mixed', $segment->scope);
        $this->assertSame(['Logistique'], $segment->filter['sector']);
        $this->assertSame(['DE'], $segment->filter['country']);
        $this->assertSame('verified', $segment->filter['lifecycle_state']);
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
     * filter.lifecycle_state with invalid value → 406.
     *
     * Renamed from the dead filter.status key (2026-08-16): the form and both
     * server-side validators only ever accepted filter.lifecycle_state.
     */
    public function test_store_rejects_invalid_status_in_filter(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Bad Status',
                'scope'  => 'client',
                'filter' => ['lifecycle_state' => 'not_a_valid_status_value'],
            ]);

        $response->assertStatus(406);
        $response->assertJsonValidationErrors(['filter.lifecycle_state'], 'errors');
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
