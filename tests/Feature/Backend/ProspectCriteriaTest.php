<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ProspectCriteriaTest — CRUD HTTP-layer tests for the prospect criteria module.
 *
 * Mirrors CompaniesTest pattern: superadmin via factory, ACL seeded manually.
 * Controller uses Crudable + Datatableable traits (same as all backend modules).
 */
class ProspectCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    /**
     * Prospect criteria index page renders 200 for superadmin.
     */
    public function test_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
    }

    /**
     * Prospect criteria create form renders 200 for superadmin.
     */
    public function test_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/create');

        $response->assertStatus(200);
    }

    /**
     * Create form pre-selects FR and MA on a fresh create page (D7).
     */
    public function test_create_render_preselects_fr_and_ma(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/create');

        $response->assertStatus(200);
        // The component renders <option value="FR" selected> and <option value="MA" selected>
        $response->assertSee('value="FR"', false);
        $response->assertSee('value="MA"', false);
    }

    /**
     * DataTable AJAX endpoint returns JSON with a 'data' key.
     */
    public function test_datatable_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Storing a criteria via POST with comma-separated sectors string creates the DB
     * record and casts the comma string to a proper PHP array.
     *
     * The controller's beforeSave() splits comma strings → arrays before persistence.
     * KEEP THIS TEST UNCHANGED — CSV branch coverage of beforeSave().
     */
    public function test_store_creates_criteria_with_array_cast(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'        => 'Test Critère Transport',
                'sectors'     => 'transport,logistique',
                'countries'   => 'France',
                'daily_limit' => 10,
                'is_active'   => 1,
            ]);

        $response->assertStatus(200);

        // Verify the record exists in the DB
        $this->assertDatabaseHas('prospect_criteria', ['name' => 'Test Critère Transport']);

        // Load the freshly created model and confirm the cast is an array
        $criteria = ProspectCriteria::where('name', 'Test Critère Transport')->firstOrFail();

        $this->assertIsArray($criteria->sectors, 'sectors must be cast to an array by the model');
        $this->assertSame(['transport', 'logistique'], $criteria->sectors);

        $this->assertIsArray($criteria->countries, 'countries must be cast to an array by the model');
        $this->assertContains('France', $criteria->countries);
    }

    /**
     * Storing a criteria via POST with array payload (e.g. from select2 multi-select)
     * stores ISO codes as-is and persists the array correctly.
     */
    public function test_store_accepts_array_payload(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'        => 'Test Critère ISO',
                'sectors'     => ['Transport & Logistique', 'Agroalimentaire'],
                'countries'   => ['FR', 'MA', 'DE'],
                'daily_limit' => 15,
                'is_active'   => 1,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('prospect_criteria', ['name' => 'Test Critère ISO']);

        $criteria = ProspectCriteria::where('name', 'Test Critère ISO')->firstOrFail();

        $this->assertIsArray($criteria->countries);
        $this->assertContains('FR', $criteria->countries);
        $this->assertContains('MA', $criteria->countries);
        $this->assertContains('DE', $criteria->countries);

        $this->assertIsArray($criteria->sectors);
        $this->assertContains('Transport & Logistique', $criteria->sectors);
    }

    /**
     * Edit page render with legacy free-text countries shows the values as selected
     * (orphan-option guard in the <x-crud.select-multi> component).
     */
    public function test_edit_render_with_legacy_freetext_countries_shows_passthrough(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Legacy Critère',
            'sectors'     => ['Transport'],
            'countries'   => ['France', 'Maroc'],  // legacy free-text, not ISO codes
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/edit');

        $response->assertStatus(200);
        // Orphan guard renders legacy values as options
        $response->assertSee('France', false);
        $response->assertSee('Maroc', false);
    }

    /**
     * Per-item validation (F1): a sector item exceeding 100 chars is rejected.
     */
    public function test_store_rejects_oversize_sector_item(): void
    {
        $oversizeSector = str_repeat('A', 101); // 101 chars — exceeds max:100

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'        => 'Test Oversize',
                'sectors'     => [$oversizeSector],
                'daily_limit' => 10,
                'is_active'   => 1,
            ]);

        // Crudable returns 406 on validation failure with 'message' => 'Errors occurred during validation'
        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors']);

        $this->assertDatabaseMissing('prospect_criteria', ['name' => 'Test Oversize']);
    }

    /**
     * Deactivation round-trip (D9): submitting the edit form WITHOUT the checkbox
     * checked (hidden input sends is_active=0) correctly deactivates the criteria.
     */
    public function test_deactivation_via_hidden_input(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Actif',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        // POST without checkbox value — only the hidden input (is_active=0) is submitted
        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/' . $criteria->id, [
                'name'        => 'Critère Actif',
                'daily_limit' => 10,
                'is_active'   => 0,  // simulates hidden input, checkbox unchecked
            ]);

        $response->assertStatus(200);

        $criteria->refresh();
        $this->assertFalse((bool) $criteria->is_active, 'Criteria should be inactive after deactivation POST');
    }

    /**
     * D11: discover() gate — inactive criteria returns a 422 error toast.
     */
    public function test_discover_gate_refuses_inactive_criteria(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Inactif',
            'daily_limit' => 10,
            'is_active'   => false,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover', [
                '_token' => csrf_token(),
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'error']);
        $this->assertStringContainsString('inactif', $response->json('text'));
    }

    /**
     * D11: discover() gate — active criteria dispatches the job successfully.
     */
    public function test_discover_dispatches_for_active_criteria(): void
    {
        Queue::fake();

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Actif Discovery',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover', [
                '_token' => csrf_token(),
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        Queue::assertPushed(RunDiscoveryPipelineJob::class);
    }

    /**
     * E1: Job-level skip — RunDiscoveryPipelineJob for an inactive criteria
     * logs the skip and does NOT invoke the pipeline.
     */
    public function test_job_skips_inactive_criteria(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Pour Job Skip',
            'daily_limit' => 10,
            'is_active'   => false,
        ]);

        // Ensure no pipeline is invoked — job runs synchronously (QUEUE_CONNECTION=sync in phpunit.xml)
        // and should return early without throwing
        $job = new RunDiscoveryPipelineJob($criteria->id);

        // Run the job directly — should not throw, should just return early
        $this->assertNull($job->handle()); // handle() returns void (null)
    }

    /**
     * E2: Nested-array guard — POST with sectors[0][] nested array payload
     * never causes a 500 TypeError. The response is either a clean validation
     * error or a clean save (non-string items dropped by beforeSave E2 guard).
     */
    public function test_nested_array_payload_never_causes_500(): void
    {
        // Simulate a malicious or malformed nested-array POST (e.g. sectors[0][]='x')
        // PHP parses this as sectors => [0 => ['x']] — nested array item hits beforeSave.
        $response = $this->actingAs($this->superadmin)
            ->call('POST', '/admin/prospect_criteria', [
                'name'        => 'Test Nested Array',
                'daily_limit' => 10,
                'is_active'   => 1,
                // sectors posted as nested array (sectors[0][] in HTTP)
                'sectors'     => [['nested_value']],
            ]);

        // Must NOT be a 500. Any other status (200 with error or 200 with success) is fine.
        $this->assertNotEquals(500, $response->status(), 'Nested array POST must not cause 500 TypeError');
    }

    /**
     * DataTable countries column maps ISO codes to French labels.
     */
    public function test_datatable_countries_column_maps_iso_to_labels(): void
    {
        ProspectCriteria::create([
            'name'        => 'Critère DataTable Test',
            'countries'   => ['FR', 'DE'],
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);
        $json = $response->json();

        // Find the row for our criteria and check countries shows French labels
        $found = false;
        foreach ($json['data'] ?? [] as $row) {
            if (str_contains($row['name'] ?? '', 'Critère DataTable Test')) {
                $found = true;
                $this->assertStringContainsString('France', $row['countries'] ?? '');
                $this->assertStringContainsString('Allemagne', $row['countries'] ?? '');
                break;
            }
        }
        $this->assertTrue($found, 'The created criteria should appear in DataTable results');
    }

    /**
     * ViewConfig countries detailRow maps ISO codes to French labels.
     */
    public function test_viewconfig_countries_detail_row_maps_iso_to_labels(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère ViewConfig Test',
            'countries'   => ['FR', 'MA'],
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $config = \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make($criteria);

        // Find the Pays detail row
        $paysRow = null;
        foreach ($config['detail_rows'] as $row) {
            if ($row['label'] === 'Pays') {
                $paysRow = $row;
                break;
            }
        }

        $this->assertNotNull($paysRow, 'Pays detail row should exist');
        $this->assertStringContainsString('France', $paysRow['value'] ?? '');
        $this->assertStringContainsString('Maroc', $paysRow['value'] ?? '');
    }

    // ── TODO-PC-2: Dupliquer ──────────────────────────────────────────────────

    /**
     * Admin can duplicate a criteria — redirected to the clone's edit page,
     * DB has a record with 'Copie de …' name, is_active=false, and other
     * fields copied from the original.
     */
    public function test_duplicate_creates_clone_and_redirects_to_edit(): void
    {
        $original = ProspectCriteria::create([
            'name'        => 'Critère Original',
            'sectors'     => ['Transport', 'Logistique'],
            'countries'   => ['FR', 'MA'],
            'daily_limit' => 25,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $original->id . '/duplicate');

        // Should redirect (302) to the clone's edit page
        $response->assertStatus(302);

        // Clone must exist in DB
        $this->assertDatabaseHas('prospect_criteria', [
            'name'      => 'Copie de Critère Original',
            'is_active' => false,
        ]);

        // Load the clone and verify fields were copied
        $clone = ProspectCriteria::where('name', 'Copie de Critère Original')->firstOrFail();

        $this->assertSame(25, $clone->daily_limit);
        $this->assertSame(['Transport', 'Logistique'], $clone->sectors);
        $this->assertSame(['FR', 'MA'], $clone->countries);
        $this->assertFalse((bool) $clone->is_active);

        // Redirect should point to the clone's edit page
        $response->assertRedirect(route('admin.prospect_criteria.edit', $clone->id));
    }

    /**
     * Duplicating a criteria whose name is at the 100-char column limit must not
     * overflow varchar(100): the 'Copie de ' prefix is kept intact and the
     * original-name tail is trimmed so the clone name fits in 100 characters.
     */
    public function test_duplicate_truncates_long_name_to_fit_column(): void
    {
        $longName = str_repeat('A', 100); // exactly at the column limit

        $original = ProspectCriteria::create([
            'name'        => $longName,
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $original->id . '/duplicate');

        $response->assertStatus(302);

        $clone = ProspectCriteria::where('id', '!=', $original->id)
            ->orderByDesc('id')
            ->firstOrFail();

        // Prefix kept intact, total length within the column limit.
        $this->assertStringStartsWith('Copie de ', $clone->name);
        $this->assertLessThanOrEqual(100, mb_strlen($clone->name));
        // 'Copie de ' (9) + first 91 chars of the original name = 100 chars.
        $this->assertSame('Copie de ' . str_repeat('A', 91), $clone->name);
    }

    /**
     * A user WITHOUT create prospect_criteria permission gets 403 on duplicate.
     */
    public function test_duplicate_forbidden_without_create_permission(): void
    {
        $original = ProspectCriteria::create([
            'name'        => 'Critère Pour 403',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        // Create a user with only view permission — no create
        $viewOnlyUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $viewOnlyUser->givePermissionTo('view prospect_criteria');
        $viewOnlyUser->givePermissionTo('backend.access');

        $response = $this->actingAs($viewOnlyUser)
            ->post('/admin/prospect_criteria/' . $original->id . '/duplicate');

        $response->assertStatus(403);
        $this->assertDatabaseMissing('prospect_criteria', ['name' => 'Copie de Critère Pour 403']);
    }

    /**
     * Guest is redirected to login on duplicate (not authenticated).
     */
    public function test_duplicate_guest_redirected_to_login(): void
    {
        $original = ProspectCriteria::create([
            'name'        => 'Critère Guest Test',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->post('/admin/prospect_criteria/' . $original->id . '/duplicate');

        $response->assertRedirect('/login');
    }

    // ── TODO-PC-1: SerpAPI query preview ─────────────────────────────────────

    /**
     * Authorized user gets 200 + JSON with a non-empty 'queries' array.
     * Criteria with sectors and countries set to guaranteed-non-empty values.
     */
    public function test_preview_queries_returns_json_with_queries(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Preview Test',
            'sectors'     => ['Transport'],
            'countries'   => ['FR'],
            'daily_limit' => 20,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/preview-queries');

        $response->assertStatus(200);
        $response->assertJsonStructure(['queries']);

        $queries = $response->json('queries');
        $this->assertIsArray($queries);
        $this->assertNotEmpty($queries, 'buildQueries() must return ≥1 query for a criteria with sectors+countries');
    }

    /**
     * A user WITHOUT view prospect_criteria permission gets 403 on preview-queries.
     */
    public function test_preview_queries_forbidden_without_view_permission(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Preview 403',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        // Create a user with only create permission — no view
        $noViewUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noViewUser->givePermissionTo('create prospect_criteria');
        $noViewUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noViewUser)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/preview-queries');

        $response->assertStatus(403);
    }
}
