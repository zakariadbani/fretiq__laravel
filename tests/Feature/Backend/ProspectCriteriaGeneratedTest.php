<?php

namespace Tests\Feature\Backend;

use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// >>> custom-test-author:prospect_criteria-code

/**
 * ProspectCriteriaGeneratedTest — gap-fill test slice for the prospect_criteria module.
 *
 * Existing coverage in ProspectCriteriaTest.php (DO NOT duplicate):
 *   index 200 for superadmin, create 200, create preselects FR/MA, datatable JSON,
 *   store with CSV sectors (happy-path), store with array payload (happy-path),
 *   edit render with legacy countries, store validation: oversize sector item (406),
 *   deactivation via hidden input (update happy-path), discover inactive → 422,
 *   discover active → 200 + Queue::assertPushed, job skips inactive criteria,
 *   nested array never 500, datatable ISO→labels, viewconfig ISO→labels,
 *   duplicate (happy-path, long-name truncate, 403 without permission, guest redirect),
 *   previewQueries (returns queries + 403 without view permission).
 *
 * This file covers the uncovered surface:
 *   - index 403 for user without view permission
 *   - index guest redirect (302)
 *   - view (detail) 200 for existing criteria
 *   - store validation failure: missing required `name` → 406
 *   - update happy-path → 200 + DB mutated
 *   - delete happy-path → 200 {success:true} + DB row gone
 *   - delete 403 for user without delete permission (commercial has no delete)
 *   - executeSwitch sets is_active active (state='1')
 *   - executeSwitch sets is_active inactive (state='0')
 *   - executeSwitch rejects unlisted field (403)
 *   - discover 403 for user without `run discovery` permission
 *   - discover in-flight guard → 409 (mocked DiscoveryQuotaService)
 *   - discover quota exhausted → 422 (mocked DiscoveryQuotaService)
 *   - discoveryStatus: seeds a DiscoveryRun row and asserts JSON shape
 *   - discoveryStatus: criteria with no run returns null-safe JSON
 */
class ProspectCriteriaGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

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

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Insert a ProspectCriteria row directly without going through the controller.
     */
    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Critère Test ' . uniqid(),
            'sectors'     => ['Transport'],
            'countries'   => ['FR'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to prospect_criteria index must redirect (302).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/prospect_criteria');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view prospect_criteria` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view prospect_criteria`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/prospect_criteria/{id} returns 200 for superadmin viewing an existing criteria.
     */
    public function test_view_renders_for_existing_criteria(): void
    {
        $criteria = $this->makeCriteria();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id);

        $response->assertStatus(200);
        // criteria name is rendered in the <title> section and the breadcrumb span.
        $response->assertSee($criteria->name);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * The Crudable trait calls $model->validator() and returns JSON 406 on failure.
     * ProspectCriteria::rules(): 'name' => 'required|string|max:100'
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/prospect_criteria', [
                // name intentionally omitted
                'sectors'     => ['Transport'],
                'countries'   => ['FR'],
                'daily_limit' => 10,
                'is_active'   => 1,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/prospect_criteria/{id} with valid data mutates the row.
     *
     * Crudable returns JSON 200 with {message:'success', redirect:...}.
     */
    public function test_update_mutates_criteria(): void
    {
        $criteria = $this->makeCriteria(['name' => 'Nom Original']);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/' . $criteria->id, [
                'name'        => 'Nom Modifié',
                'sectors'     => ['Logistique'],
                'countries'   => ['FR'],
                'daily_limit' => 20,
                'is_active'   => 1,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('prospect_criteria', [
            'id'   => $criteria->id,
            'name' => 'Nom Modifié',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/prospect_criteria/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_criteria(): void
    {
        $criteria = $this->makeCriteria();
        $id       = $criteria->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/prospect_criteria/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('prospect_criteria', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete prospect_criteria` permission — must receive 403.
     *
     * commercial has view/create/edit prospect_criteria but NOT delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $criteria = $this->makeCriteria();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/prospect_criteria/' . $criteria->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('prospect_criteria', ['id' => $criteria->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/prospect_criteria/executeSwitch/{id} with field=is_active, state='1' sets active.
     *
     * State sent as a STRING to match real browser AJAX (form values post as strings).
     * ProspectCriteriaController::$toggleableFields = ['is_active']
     */
    public function test_execute_switch_sets_active(): void
    {
        $criteria = $this->makeCriteria(['is_active' => 0]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/executeSwitch/' . $criteria->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('prospect_criteria', ['id' => $criteria->id, 'is_active' => 1]);
    }

    /**
     * PUT /admin/prospect_criteria/executeSwitch/{id} with field=is_active, state='0' sets inactive.
     *
     * Isolated per-direction with its own fresh criteria.
     */
    public function test_execute_switch_sets_inactive(): void
    {
        $criteria = $this->makeCriteria(['is_active' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/executeSwitch/' . $criteria->id, [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('prospect_criteria', ['id' => $criteria->id, 'is_active' => 0]);
    }

    /**
     * executeSwitch must return 403 when an unlisted field is requested.
     *
     * Datatableable returns JSON 403 for fields not in $toggleableFields.
     * ProspectCriteriaController::$toggleableFields = ['is_active'] only.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $criteria = $this->makeCriteria();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/prospect_criteria/executeSwitch/' . $criteria->id, [
                'field' => 'name',   // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }

    // ── discover() ───────────────────────────────────────────────────────────

    /**
     * A user WITHOUT `run discovery` permission must receive 403 on discover.
     *
     * The `run discovery` middleware is on discover(); a user with only
     * `view prospect_criteria` + `backend.access` must be denied.
     * Note: commercial DOES have run discovery, so we use a custom user here.
     */
    public function test_discover_403_for_user_without_run_discovery_permission(): void
    {
        $criteria = $this->makeCriteria(['is_active' => true]);

        $noDiscoveryUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noDiscoveryUser->givePermissionTo('view prospect_criteria');
        $noDiscoveryUser->givePermissionTo('backend.access');
        // deliberately NOT granting 'run discovery'

        $response = $this->actingAs($noDiscoveryUser)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover');

        $response->assertStatus(403);
    }

    /**
     * discover() returns 409 when another run is already in-flight.
     *
     * Mock DiscoveryQuotaService to throw DiscoveryRunInFlightException so no
     * real quota tables or SerpAPI calls are involved.
     * Queue::fake() ensures the job is never dispatched.
     */
    public function test_discover_returns_409_when_run_in_flight(): void
    {
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true]);

        // Create a pre-existing pending run to pass as the existingRun.
        $existingRun = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'pending',
            'credits_reserved'     => 10,
            'consumed'             => 0,
            'quota_date'           => now()->toDateString(),
        ]);

        // Bind a mock DiscoveryQuotaService that throws DiscoveryRunInFlightException.
        $this->app->bind(DiscoveryQuotaService::class, function () use ($existingRun) {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            $mock->method('reserveRun')
                 ->willThrowException(new DiscoveryRunInFlightException($existingRun));
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover');

        $response->assertStatus(409);
        $response->assertJson([
            'message' => 'error',
            'run_id'  => $existingRun->id,
            'status'  => 'pending',
        ]);

        // The job must NOT have been dispatched — no duplicate runs.
        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
    }

    /**
     * discover() returns 422 when the daily quota is exhausted.
     *
     * Mock DiscoveryQuotaService to throw QuotaExhaustedException.
     */
    public function test_discover_returns_422_when_quota_exhausted(): void
    {
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true]);

        $this->app->bind(DiscoveryQuotaService::class, function () {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            $mock->method('reserveRun')
                 ->willThrowException(new QuotaExhaustedException());
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover');

        $response->assertStatus(422);
        $response->assertJson(['message' => 'error']);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
    }

    // ── discoveryStatus() ────────────────────────────────────────────────────

    /**
     * GET /admin/prospect_criteria/{id}/discovery-status with a seeded DiscoveryRun
     * returns JSON whose shape matches the controller's response contract:
     *   status, companies_count, contacts_count, skipped_count, low_score_count,
     *   finished_at, companies_total, stale, error.
     *
     * The endpoint is gated by `view prospect_criteria` (via middleware).
     */
    public function test_discovery_status_returns_run_json_for_existing_run(): void
    {
        $criteria = $this->makeCriteria(['is_active' => true]);

        // Seed a completed discovery run for this criteria.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'completed',
            'companies_count'      => 5,
            'contacts_count'       => 12,
            'skipped_count'        => 2,
            'low_score_count'      => 1,
            'credits_reserved'     => 10,
            'consumed'             => 5,
            'quota_date'           => now()->toDateString(),
            'started_at'           => now()->subMinutes(3),
            'finished_at'          => now()->subMinute(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/discovery-status');

        $response->assertStatus(200);

        // Verify the response shape matches the controller's exact JSON structure.
        $response->assertJsonStructure([
            'status',
            'companies_count',
            'contacts_count',
            'skipped_count',
            'low_score_count',
            'finished_at',
            'companies_total',
            'stale',
            'error',
        ]);

        // Verify the values reflect the seeded run.
        $response->assertJson([
            'status'          => 'completed',
            'companies_count' => 5,
            'contacts_count'  => 12,
            'skipped_count'   => 2,
            'low_score_count' => 1,
            'stale'           => false,
            'error'           => null,
        ]);

        // Cache-Control: no-store must be present (prevents stale AJAX polling).
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
    }

    /**
     * GET /admin/prospect_criteria/{id}/discovery-status for a criteria with NO run
     * returns null-safe JSON (all counts zero, status/finished_at/error null).
     */
    public function test_discovery_status_returns_nulls_when_no_run_exists(): void
    {
        $criteria = $this->makeCriteria(['is_active' => true]);
        // No DiscoveryRun rows — latestDiscoveryRun will be null.

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/discovery-status');

        $response->assertStatus(200);
        $response->assertJson([
            'status'          => null,
            'companies_count' => 0,
            'contacts_count'  => 0,
            'skipped_count'   => 0,
            'low_score_count' => 0,
            'finished_at'     => null,
            'companies_total' => 0,
            'stale'           => false,
            'error'           => null,
        ]);
    }

    /**
     * discoveryStatus is gated by `view prospect_criteria` — user without that
     * permission must receive 403.
     */
    public function test_discovery_status_403_without_view_permission(): void
    {
        $criteria = $this->makeCriteria();

        $noViewUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noViewUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noViewUser)
            ->get('/admin/prospect_criteria/' . $criteria->id . '/discovery-status');

        $response->assertStatus(403);
    }

    // ── New quota surface ─────────────────────────────────────────────────────

    /**
     * discover() returns 200 with "(enrichissement limité à N)" when the contact-
     * enrichment quota is capped lower than the company batch.
     *
     * The controller appends the note at line:
     *   if (! $quotaService->contactIsUnlimited() && $run->contact_credits_reserved < $run->credits_reserved)
     *
     * We mock reserveRun() to return a real DiscoveryRun row so that
     * RunDiscoveryPipelineJob::dispatch() has a valid run id. We also mock
     * contactIsUnlimited() → false so the note branch is taken.
     * Queue::fake() ensures no actual job dispatch occurs.
     *
     * Scenario: daily_limit=20, credits_reserved=20 (full batch), contact_credits_reserved=5
     * → success text must contain "(enrichissement limité à 5)".
     */
    public function test_discover_success_appends_contact_quota_cap_note(): void
    {
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'daily_limit' => 20]);

        // Create the DiscoveryRun row inline so the mocked reserveRun() returns a
        // persisted model with a real id (required by RunDiscoveryPipelineJob::dispatch).
        $run = DiscoveryRun::create([
            'prospect_criteria_id'     => $criteria->id,
            'type'                     => 'discovery',
            'status'                   => 'pending',
            'credits_reserved'         => 20,
            'consumed'                 => 0,
            'contact_credits_reserved' => 5,
            'contact_consumed'         => 0,
            'quota_date'               => now()->toDateString(),
        ]);

        $this->app->bind(DiscoveryQuotaService::class, function () use ($run) {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            // reserveRun() returns the pre-created run (no real quota logic executed).
            $mock->method('reserveRun')->willReturn($run);
            // isUnlimited() → false: keeps the partial-batch check alive but
            // credits_reserved(20) == wantedBatch(20) so isPartial stays false.
            $mock->method('isUnlimited')->willReturn(false);
            // contactIsUnlimited() → false: triggers the enrichment-limit note.
            $mock->method('contactIsUnlimited')->willReturn(false);
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria/' . $criteria->id . '/discover');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $text = $response->json('text');
        $this->assertStringContainsString('enrichissement limité à 5', $text,
            'Success text should contain the contact cap note when contact quota is capped.');

        Queue::assertPushed(RunDiscoveryPipelineJob::class);
    }

    /**
     * GET /admin/prospect_criteria passes quota view vars to the index blade.
     *
     * The controller resolves five vars from resolveQuotaVars():
     *   quotaRemaining, quotaPackage, contactRemaining, monthlyRemaining, monthlyContactRemaining.
     *
     * We mock the quota service to return fixed values and assert each key is
     * present in the view data.
     */
    public function test_index_passes_quota_view_vars(): void
    {
        $this->app->bind(DiscoveryQuotaService::class, function () {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            $mock->method('remainingTodayForDisplay')->willReturn(42);
            $mock->method('activePackage')->willReturn(null);
            $mock->method('contactRemainingTodayForDisplay')->willReturn(10);
            $mock->method('monthlyRemainingForDisplay')->willReturn(200);
            $mock->method('monthlyContactRemainingForDisplay')->willReturn(50);
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertViewHas('quotaRemaining', 42);
        $response->assertViewHas('quotaPackage', null);
        $response->assertViewHas('contactRemaining', 10);
        $response->assertViewHas('monthlyRemaining', 200);
        $response->assertViewHas('monthlyContactRemaining', 50);
    }

    /**
     * GET /admin/prospect_criteria/{id} passes quota view vars to the detail blade.
     *
     * The overridden view() method calls resolveQuotaVars() and passes the same
     * five keys to the view as the index page.
     */
    public function test_view_passes_quota_view_vars(): void
    {
        $criteria = $this->makeCriteria(['is_active' => true]);

        $this->app->bind(DiscoveryQuotaService::class, function () {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            $mock->method('remainingTodayForDisplay')->willReturn(15);
            $mock->method('activePackage')->willReturn(null);
            $mock->method('contactRemainingTodayForDisplay')->willReturn(3);
            $mock->method('monthlyRemainingForDisplay')->willReturn(90);
            $mock->method('monthlyContactRemainingForDisplay')->willReturn(20);
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id);

        $response->assertStatus(200);
        $response->assertViewHas('quotaRemaining', 15);
        $response->assertViewHas('quotaPackage', null);
        $response->assertViewHas('contactRemaining', 3);
        $response->assertViewHas('monthlyRemaining', 90);
        $response->assertViewHas('monthlyContactRemaining', 20);
    }

    /**
     * DataTable discover button is disabled when the MONTHLY company quota is at 0.
     *
     * ProspectCriteriaDataTable::__construct() computes quotaExhausted as:
     *   ($remaining === 0) || ($monthlyRemaining === 0)
     * The action column then adds the `disabled` attribute when quotaExhausted === true.
     *
     * We bind DiscoveryQuotaService before the request so the container injects
     * the mock into the DataTable constructor. Then we hit the datatable JSON route
     * and assert the rendered action HTML carries `disabled`.
     */
    public function test_datatable_discover_button_disabled_when_monthly_quota_exhausted(): void
    {
        $this->makeCriteria(['is_active' => true]);

        $this->app->bind(DiscoveryQuotaService::class, function () {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            // Daily has room, monthly is exhausted.
            $mock->method('remainingTodayForDisplay')->willReturn(10);
            $mock->method('monthlyRemainingForDisplay')->willReturn(0);
            // Remaining display methods needed by index/view if also called.
            $mock->method('activePackage')->willReturn(null);
            $mock->method('contactRemainingTodayForDisplay')->willReturn(5);
            $mock->method('monthlyContactRemainingForDisplay')->willReturn(0);
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertNotEmpty($rows, 'DataTable must return at least one row.');

        // The action column HTML is rendered per-row. Assert the discover button
        // carries the `disabled` attribute in every returned row.
        foreach ($rows as $row) {
            $this->assertStringContainsString(
                'disabled',
                $row['action'],
                'Discover button must be disabled when monthly quota is exhausted.'
            );
        }
    }

    /**
     * DataTable discover button is disabled when the DAILY company quota is at 0
     * (regression guard — the original daily-only exhaustion path must still disable).
     *
     * Mirrors test_datatable_discover_button_disabled_when_monthly_quota_exhausted
     * but with daily=0 and monthly having credits remaining.
     */
    public function test_datatable_discover_button_disabled_when_daily_quota_exhausted(): void
    {
        $this->makeCriteria(['is_active' => true]);

        $this->app->bind(DiscoveryQuotaService::class, function () {
            $mock = $this->createMock(DiscoveryQuotaService::class);
            // Daily is exhausted, monthly has room.
            $mock->method('remainingTodayForDisplay')->willReturn(0);
            $mock->method('monthlyRemainingForDisplay')->willReturn(100);
            $mock->method('activePackage')->willReturn(null);
            $mock->method('contactRemainingTodayForDisplay')->willReturn(0);
            $mock->method('monthlyContactRemainingForDisplay')->willReturn(50);
            return $mock;
        });

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertNotEmpty($rows, 'DataTable must return at least one row.');

        foreach ($rows as $row) {
            $this->assertStringContainsString(
                'disabled',
                $row['action'],
                'Discover button must be disabled when daily quota is exhausted.'
            );
        }
    }
}

// <<<
