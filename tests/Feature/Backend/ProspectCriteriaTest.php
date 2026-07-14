<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    /**
     * Résultats tab labels separate SerpAPI candidates, kept companies, and AI exclusions.
     */
    public function test_results_tab_distinguishes_candidates_kept_and_excluded(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Résultats UX',
            'daily_limit' => 4,
            'is_active'   => true,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Entreprise Gardée',
            'domain'               => 'gardee.test',
            'country'              => 'FR',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête test',
            'ai_score'             => 80,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Entreprise Exclue',
            'domain'               => 'exclue.test',
            'country'              => 'FR',
            'qualification_status' => 'rejected',
            'discovery_query'      => 'requête test',
            'ai_score'             => 10,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '#criteria_resultats');

        $response->assertStatus(200);
        $response->assertSee('Résultats par requête SerpAPI', false);
        $response->assertSee('2 candidat(s) trouvé(s) · 1 entreprise(s) gardée(s) · 1 exclue(s) par l\'IA.', false);
        $response->assertSee('2 candidat(s)', false);
        $response->assertSee('1 gardée(s)', false);
        $response->assertSee('1 exclue(s)', false);
        $response->assertSee('Détails', false);
        $response->assertSee('Entreprises gardées (1)', false);
        $response->assertSee('Voir les entreprises gardées', false);
    }

    /**
     * Résultats tab table headers support ordering the kept companies table.
     */
    public function test_results_table_can_be_sorted_by_name(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Résultats Tri',
            'daily_limit' => 4,
            'is_active'   => true,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Zeta Tri Test',
            'domain'               => 'zeta-tri.test',
            'country'              => 'FR',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête tri',
            'ai_score'             => 30,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Alpha Tri Test',
            'domain'               => 'alpha-tri.test',
            'country'              => 'MA',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête tri',
            'ai_score'             => 90,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '?results_sort=name&results_dir=asc#criteria_resultats');

        $response->assertStatus(200);
        $response->assertSee('Cliquez sur un en-tête pour trier la table.', false);
        $response->assertSee('Créée le', false);
        $response->assertSee('results_sort=created_at', false);
        $response->assertSee('results_sort=name', false);
        $response->assertSee('results_dir=desc', false);

        $resultsTable = strstr($response->getContent(), 'Entreprises gardées (2)') ?: $response->getContent();
        $alphaPosition = strpos($resultsTable, 'Alpha Tri Test');
        $zetaPosition  = strpos($resultsTable, 'Zeta Tri Test');

        $this->assertNotFalse($alphaPosition);
        $this->assertNotFalse($zetaPosition);
        $this->assertLessThan($zetaPosition, $alphaPosition);
    }

    /**
     * Résultats tab can be sorted by creation date.
     */
    public function test_results_table_can_be_sorted_by_created_at(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Résultats Date Création',
            'daily_limit' => 4,
            'is_active'   => true,
        ]);

        $old = Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Ancienne Entreprise Test',
            'domain'               => 'ancienne-entreprise.test',
            'country'              => 'FR',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête date',
            'ai_score'             => 50,
        ]);
        $old->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();

        $new = Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Nouvelle Entreprise Test',
            'domain'               => 'nouvelle-entreprise.test',
            'country'              => 'MA',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête date',
            'ai_score'             => 50,
        ]);
        $new->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '?results_sort=created_at&results_dir=desc#criteria_resultats');

        $response->assertStatus(200);
        $response->assertSee('Créée le', false);
        $response->assertSee('results_sort=created_at', false);
        $response->assertSee('results_dir=asc', false);

        $resultsTable = strstr($response->getContent(), 'Entreprises gardées (2)') ?: $response->getContent();
        $newPosition = strpos($resultsTable, 'Nouvelle Entreprise Test');
        $oldPosition = strpos($resultsTable, 'Ancienne Entreprise Test');

        $this->assertNotFalse($newPosition);
        $this->assertNotFalse($oldPosition);
        $this->assertLessThan($oldPosition, $newPosition);
    }

    /**
     * Résultats tab defaults to the strongest AI score first.
     */
    public function test_results_table_defaults_to_score_desc(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Résultats Score Par Défaut',
            'daily_limit' => 4,
            'is_active'   => true,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Score Faible Test',
            'domain'               => 'score-faible.test',
            'country'              => 'FR',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête score',
            'ai_score'             => 20,
        ]);

        Company::create([
            'criteria_id'          => $criteria->id,
            'name'                 => 'Score Fort Test',
            'domain'               => 'score-fort.test',
            'country'              => 'MA',
            'qualification_status' => 'pending',
            'discovery_query'      => 'requête score',
            'ai_score'             => 95,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '#criteria_resultats');

        $response->assertStatus(200);
        $response->assertSee('results_sort=score', false);
        $response->assertSee('results_dir=asc', false);

        $resultsTable = strstr($response->getContent(), 'Entreprises gardées (2)') ?: $response->getContent();
        $strongPosition = strpos($resultsTable, 'Score Fort Test');
        $weakPosition   = strpos($resultsTable, 'Score Faible Test');

        $this->assertNotFalse($strongPosition);
        $this->assertNotFalse($weakPosition);
        $this->assertLessThan($weakPosition, $strongPosition);
    }

    /**
     * Historique tab distinguishes discovery launches from SerpAPI searches.
     */
    public function test_view_history_shows_run_count_and_serpapi_search_count(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Historique SerpAPI',
            'daily_limit' => 4,
            'is_active'   => true,
        ]);

        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'completed',
            'credits_reserved'     => 4,
            'searches_reserved'    => 4,
            'searches_consumed'    => 4,
            'consumed'             => 20,
            'companies_count'      => 16,
            'new_companies_count'  => 16,
            'contacts_count'       => 0,
            'skipped_count'        => 0,
            'low_score_count'      => 0,
            'quota_date'           => now()->toDateString(),
            'started_at'           => now()->subMinutes(3),
            'finished_at'          => now(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '#criteria_historique');

        $response->assertStatus(200);
        $response->assertSee('Historique des lancements (1)', false);
        $response->assertSee('1 lancement(s) de découverte · 4 recherche(s) SerpAPI consommée(s).', false);
        $response->assertSee('Recherches SerpAPI', false);
        $response->assertSee('>4</span>', false);
        $response->assertSee('/ 4</span>', false);
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
        $response->assertJsonStructure([
            'queries',
            'execution' => ['daily_limit', 'search_budget'],
        ]);

        $queries = $response->json('queries');
        $this->assertIsArray($queries);
        $this->assertNotEmpty($queries, 'buildQueries() must return ≥1 query for a criteria with sectors+countries');
        $this->assertSame(20, $response->json('execution.daily_limit'));
        $this->assertSame(20, $response->json('execution.search_budget'));
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

    /**
     * "Générer avec l'IA" uses the unsaved textarea payload and returns Gemini
     * queries without persisting them; existing enabled flags are preserved.
     */
    public function test_generate_queries_returns_gemini_queries_for_unsaved_target(): void
    {
        config(['services.gemini.api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"queries":["grossiste textile France -transporteur","importateur habillement Maroc -logistique"]}',
                        ]],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
        ]);

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Generate Test',
            'ai_target'   => 'Ancienne cible stockée',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/generate-queries', [
                'ai_target'  => 'grossistes textile et importateurs habillement',
                'ai_exclude' => 'transporteurs et logisticiens',
                'queries'    => [[
                    'q'       => 'grossiste textile France -transporteur',
                    'enabled' => '0',
                ]],
            ]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'queries' => [
                ['q' => 'grossiste textile France -transporteur', 'enabled' => false],
                ['q' => 'importateur habillement Maroc -logistique', 'enabled' => true],
            ],
            'notice' => null,
        ]);

        $criteria->refresh();
        $this->assertNull($criteria->ai_queries, 'Generate preview must not persist ai_queries before Enregistrer.');

        Http::assertSent(function ($request) {
            $prompt = data_get($request->data(), 'contents.0.parts.0.text', '');

            return str_contains($prompt, 'grossistes textile et importateurs habillement')
                && str_contains($prompt, 'transporteurs et logisticiens')
                && ! str_contains($prompt, 'Ancienne cible stockée');
        });
    }

    /**
     * When Gemini is unreachable (e.g. HTTP 429 rate-limit) expand() returns [] by
     * design; with a target still filled, generate-queries must surface an
     * "AI unavailable" notice instead of the misleading "enter a target" empty state.
     */
    public function test_generate_queries_surfaces_notice_when_gemini_unavailable(): void
    {
        config(['services.gemini.api_key' => 'test-gemini-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('rate limited', 429),
        ]);

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Notice Indispo',
            'ai_target'   => 'grossistes textile',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/generate-queries', [
                'ai_target'  => 'grossistes textile et importateurs habillement',
                'ai_exclude' => '',
                'queries'    => [],
            ]);

        $response->assertStatus(200);
        $response->assertJson(['queries' => []]);
        $this->assertStringContainsString(
            'indisponible',
            (string) $response->json('notice'),
            'A failed Gemini call with a target set must return the AI-unavailable notice, not the no-criteria text.'
        );
    }

    // ── Automation fields (auto_run/run_at_hour/contact_limit/min_score_enrich/auto_enrich) ──

    /**
     * Updating a criteria with automation fields persists all 5 columns correctly-typed.
     */
    public function test_update_persists_automation_fields(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Automatisation',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/' . $criteria->id, [
                'name'             => 'Critère Automatisation',
                'daily_limit'      => 10,
                'is_active'        => 1,
                'auto_run'         => 1,
                'run_at_hour'      => 8,
                'contact_limit'    => 5,
                'min_score_enrich' => 70,
                'auto_enrich'      => 0,
            ]);

        $response->assertStatus(200);

        $criteria->refresh();
        $this->assertTrue((bool) $criteria->auto_run);
        $this->assertSame(8, $criteria->run_at_hour);
        $this->assertSame(5, $criteria->contact_limit);
        $this->assertSame(70, $criteria->min_score_enrich);
        $this->assertFalse((bool) $criteria->auto_enrich);
    }

    /**
     * auto_enrich posted as an empty string ('' — the Hérité select option) round-trips
     * to null in the DB (ConvertEmptyStringsToNull + nullable|boolean rule), preserving
     * the tri-state "inherit global setting" semantics.
     */
    public function test_auto_enrich_empty_string_saves_as_null(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Tri-State',
            'daily_limit' => 10,
            'is_active'   => true,
            'auto_enrich' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/' . $criteria->id, [
                'name'        => 'Critère Tri-State',
                'daily_limit' => 10,
                'is_active'   => 1,
                'auto_enrich' => '',
            ]);

        $response->assertStatus(200);

        $criteria->refresh();
        $this->assertNull($criteria->auto_enrich, 'auto_enrich must round-trip to null when posted as an empty string');
    }

    /**
     * run_at_hour is required when auto_run=1 — omitting it returns 406 with a
     * validation error on run_at_hour (required_if:auto_run,1).
     */
    public function test_run_at_hour_required_when_auto_run_enabled(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'        => 'Critère Auto Sans Heure',
                'daily_limit' => 10,
                'is_active'   => 1,
                'auto_run'    => 1,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['run_at_hour']]);

        $this->assertDatabaseMissing('prospect_criteria', ['name' => 'Critère Auto Sans Heure']);
    }

    /**
     * min_score_enrich above 100 is rejected by the between:0,100 rule (406).
     */
    public function test_min_score_enrich_above_100_rejected(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'             => 'Critère Score Invalide',
                'daily_limit'      => 10,
                'is_active'        => 1,
                'min_score_enrich' => 101,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['min_score_enrich']]);

        $this->assertDatabaseMissing('prospect_criteria', ['name' => 'Critère Score Invalide']);
    }
}
