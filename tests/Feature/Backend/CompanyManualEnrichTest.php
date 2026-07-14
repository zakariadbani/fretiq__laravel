<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CompanyManualEnrichTest — integration tests for POST /admin/companies/{id}/enrich.
 *
 * Uses MySQL (fretiq_test) per phpunit.xml — GET_LOCK is real.
 * Hunter fixture driver (local) — no real HTTP.
 *
 * Covers:
 *   - Permission gate (403, 200 for commercial)
 *   - Company without domain → 422, no run row
 *   - Limited package, quota pre-burned → 422, no run row
 *   - Success: 200, contacts created, run type='manual' consumed=1 company_id set
 *   - Unlimited package → success, no quota error
 *   - Same-company in-flight → 409, no second row
 *   - Failure path (force Hunter throw) → run row finalized 'failed', never left 'running', 500
 *   - Regression: running manual row for company does NOT block reserveRun() for criteria
 */
class CompanyManualEnrichTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;
    private User $userNoPermission;

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

        // A user with no role (has backend.access via direct assignment for convenience,
        // but NOT enrich companies).
        $this->userNoPermission = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give backend.access so they get past the outer middleware, but no enrich
        $this->userNoPermission->givePermissionTo('backend.access', 'view companies');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'             => 'Test Company ' . uniqid(),
            'domain'           => 'bolloretransport.com',
            'relationship'     => 'prospect',
            'source'           => 'discovered',
            'is_active'        => true,
            'qualification_status' => 'new',
        ], $overrides));
    }

    private function assignLimitedPackage(int $credits): Package
    {
        $package = Package::create([
            'name'          => "Pack {$credits}/j",
            'daily_credits' => $credits,
            'is_active'     => true,
            'sort_order'    => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    private function assignUnlimitedPackage(): Package
    {
        $package = Package::create([
            'name'          => 'Illimité',
            'daily_credits' => null,
            'is_active'     => true,
            'sort_order'    => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    /**
     * Burn N credits today by inserting a completed discovery run.
     */
    private function burnCredits(int $n, ProspectCriteria $criteria): void
    {
        $assignment = PackageAssignment::orderByDesc('id')->first();

        DiscoveryRun::create([
            'prospect_criteria_id'  => $criteria->id,
            'type'                  => 'discovery',
            'status'                => 'completed',
            'credits_reserved'      => $n,
            'consumed'              => $n,
            'quota_date'            => Carbon::today()->toDateString(),
            'package_assignment_id' => $assignment?->id,
        ]);
    }

    private function assignLimitedContactPackage(int $contactCredits, ?int $companyCredits = null): Package
    {
        $package = Package::create([
            'name'                  => "Pack C{$contactCredits}/j",
            'daily_credits'         => $companyCredits,
            'daily_contact_credits' => $contactCredits,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    /**
     * Burn N contact credits today by inserting a completed manual run.
     */
    private function burnContactCredits(int $n): void
    {
        $assignment = PackageAssignment::orderByDesc('id')->first();

        DiscoveryRun::create([
            'type'                     => 'manual',
            'status'                   => 'completed',
            'credits_reserved'         => 0,
            'consumed'                 => 0,
            'contact_credits_reserved' => $n,
            'contact_consumed'         => $n,
            'quota_date'               => Carbon::today()->toDateString(),
            'package_assignment_id'    => $assignment?->id,
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * A user without 'enrich companies' permission must receive 403.
     */
    public function test_403_for_user_without_enrich_permission(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->userNoPermission)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(403);
        $this->assertSame(0, DiscoveryRun::count(), 'No run row must be created on 403');
    }

    /**
     * Commercial role has 'enrich companies' — must receive 200 (not 403).
     */
    public function test_commercial_role_is_allowed(): void
    {
        $this->assignUnlimitedPackage();
        $company = $this->makeCompany();

        $response = $this->actingAs($this->commercial)
            ->postJson("/admin/companies/{$company->id}/enrich");

        // 200 means the permission gate passed; hunter fixture returns contacts.
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
    }

    /**
     * A company without a domain must return 422 with no DiscoveryRun row created.
     */
    public function test_422_when_company_has_no_domain(): void
    {
        $company = $this->makeCompany(['domain' => null]);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            "n'a pas de domaine",
            $response->json('text') ?? '',
            'Response must describe the missing domain'
        );
        $this->assertSame(0, DiscoveryRun::count(), 'No run row must be created when domain is missing');
    }

    public function test_422_for_social_network_domain_before_quota_reservation(): void
    {
        $this->assignUnlimitedPackage();

        $company = $this->makeCompany(['domain' => 'fr.linkedin.com']);
        $runsBefore = DiscoveryRun::count();

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(422);
        $this->assertStringContainsString("r\u{00E9}seau social", $response->json('text') ?? '');
        $this->assertSame($runsBefore, DiscoveryRun::count(), 'No run row for a social-network domain');
    }

    /**
     * Limited contact package, today's contact quota fully burned → 422, no new run row.
     * Manual enrichment checks the CONTACT meter (not company meter).
     */
    public function test_422_when_limited_quota_exhausted(): void
    {
        // Company meter unlimited (daily_credits=null), contact meter capped at 3.
        $this->assignLimitedContactPackage(3, null);

        // Burn all 3 contact credits today via a completed manual run.
        $this->burnContactCredits(3);

        $company = $this->makeCompany();

        $runsBefore = DiscoveryRun::count();

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Solde du jour épuisé',
            $response->json('text') ?? '',
            'Response must indicate quota exhaustion'
        );
        $this->assertSame(
            $runsBefore,
            DiscoveryRun::count(),
            'No new run row must be created when contact quota is exhausted'
        );
    }

    /**
     * Successful enrichment with local Hunter fixture:
     * - 200 response
     * - contacts created for the domain
     * - DiscoveryRun row: type='manual', consumed=0, credits_reserved=0,
     *   contact_consumed=1, contact_credits_reserved=1, company_id set, status='completed'
     * - contactUsedOn(today) increased by exactly 1
     */
    public function test_success_creates_contacts_and_run_row(): void
    {
        $this->assignLimitedContactPackage(10, null);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);

        $usedBefore = $quotaService->contactUsedOn(Carbon::today());

        $company = $this->makeCompany(['domain' => 'bolloretransport.com']);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        // At least 1 contact must have been created (fixture has 3 emails for bolloretransport.com).
        $contactCount = Contact::where('company_id', $company->id)->count();
        $this->assertGreaterThan(0, $contactCount, 'Contacts must be created for the domain');

        // Verify the run row.
        $run = DiscoveryRun::where('company_id', $company->id)->first();
        $this->assertNotNull($run, 'A DiscoveryRun row must be created');
        $this->assertSame('manual', $run->type, 'Run type must be manual');
        $this->assertSame($company->id, $run->company_id, 'Run must reference the company');
        $this->assertSame(0, (int) $run->consumed, 'consumed must be 0 (no company meter debit)');
        $this->assertSame(0, (int) $run->credits_reserved, 'credits_reserved must be 0 (no company meter debit)');
        $this->assertSame(1, (int) $run->contact_consumed, 'contact_consumed must be 1');
        $this->assertSame(1, (int) $run->contact_credits_reserved, 'contact_credits_reserved must be 1');
        $this->assertSame('completed', $run->status, 'Run must be completed');
        $this->assertNotNull($run->finished_at, 'finished_at must be set');

        // contactUsedOn(today) must have increased by exactly 1.
        $usedAfter = $quotaService->contactUsedOn(Carbon::today());
        $this->assertSame(
            $usedBefore + 1,
            $usedAfter,
            'contactUsedOn(today) must increase by exactly 1 after manual enrichment'
        );
    }

    /**
     * Unlimited package — enrichment succeeds without quota error.
     */
    public function test_unlimited_package_allows_enrichment(): void
    {
        $this->assignUnlimitedPackage();

        $company = $this->makeCompany(['domain' => 'geodis.com']);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $run = DiscoveryRun::where('company_id', $company->id)->first();
        $this->assertNotNull($run, 'A run row must be created even with unlimited package');
        $this->assertSame('manual', $run->type);
        $this->assertSame('completed', $run->status);
    }

    /**
     * Same-company in-flight: pre-create a running non-stale manual row for the company.
     * Second request must return 409 and no additional run row must be created.
     */
    public function test_409_when_same_company_enrichment_already_running(): void
    {
        $this->assignUnlimitedPackage();

        $company = $this->makeCompany();

        // Pre-create a running manual row for this company (non-stale: started just now).
        $existingRun = DB::table('discovery_runs')->insertGetId([
            'type'                 => 'manual',
            'company_id'           => $company->id,
            'status'               => 'running',
            'credits_reserved'     => 1,
            'consumed'             => 1,
            'quota_date'           => Carbon::today()->toDateString(),
            'started_at'           => now()->toDateTimeString(),
            'companies_count'      => 0,
            'contacts_count'       => 0,
            'skipped_count'        => 0,
            'low_score_count'      => 0,
            'created_at'           => now()->toDateTimeString(),
            'updated_at'           => now()->toDateTimeString(),
        ]);

        $runsBefore = DiscoveryRun::count();

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(409);
        $this->assertStringContainsString(
            'déjà en cours',
            $response->json('text') ?? '',
            'Response must mention in-flight enrichment'
        );
        $this->assertSame(
            $runsBefore,
            DiscoveryRun::count(),
            'No second run row must be created when enrichment is already in flight'
        );
    }

    /**
     * Failure path: force HunterEnrichmentService to throw so the catch(Throwable) path fires.
     * The run row must be finalized 'failed' — never left 'running'. Response must be 500.
     */
    public function test_failure_finalizes_run_as_failed_never_running(): void
    {
        $this->assignUnlimitedPackage();

        $company = $this->makeCompany(['domain' => 'bolloretransport.com']);

        // Swap the HunterEnrichmentService binding to one that throws.
        $this->app->bind(
            \App\Services\Discovery\HunterEnrichmentService::class,
            function () {
                return new class extends \App\Services\Discovery\HunterEnrichmentService {
                    public function domainSearch(string $domain, int $limit = 10): ?array
                    {
                        throw new \RuntimeException('Hunter simulated failure');
                    }
                };
            }
        );

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(500);
        $response->assertJson(['message' => 'error']);

        // The run row must be in 'failed' status — never 'running'.
        $run = DiscoveryRun::where('company_id', $company->id)->first();
        $this->assertNotNull($run, 'A run row must have been created before the failure');
        $this->assertSame('failed', $run->status, "Run must be 'failed', never left 'running'");
        $this->assertNotNull($run->finished_at, 'finished_at must be set on failed run');
        $this->assertNotEmpty($run->error, 'error column must be populated');
    }

    /**
     * Regression: a running manual run attached to a company (with criteria_id) does NOT
     * block DiscoveryQuotaService::reserveRun() for that criteria.
     *
     * reserveRun() scopes the in-flight check to type='discovery', so a manual run
     * must be invisible to the discovery reservation path.
     */
    public function test_regression_manual_run_does_not_block_criteria_discovery(): void
    {
        $this->assignUnlimitedPackage();

        $criteria = ProspectCriteria::create([
            'name'        => 'Régression Test ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 5,
            'is_active'   => true,
        ]);

        $company = $this->makeCompany(['domain' => 'geodis.com', 'criteria_id' => $criteria->id]);

        // Pre-create a running manual row for the company (with criteria_id set).
        DB::table('discovery_runs')->insert([
            'type'                 => 'manual',
            'company_id'           => $company->id,
            'prospect_criteria_id' => $criteria->id,
            'status'               => 'running',
            'credits_reserved'     => 1,
            'consumed'             => 1,
            'quota_date'           => Carbon::today()->toDateString(),
            'started_at'           => now()->toDateTimeString(),
            'companies_count'      => 0,
            'contacts_count'       => 0,
            'skipped_count'        => 0,
            'low_score_count'      => 0,
            'created_at'           => now()->toDateTimeString(),
            'updated_at'           => now()->toDateTimeString(),
        ]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);

        // reserveRun() must NOT throw DiscoveryRunInFlightException — the manual row
        // is scoped to type='manual' and must be invisible to the discovery in-flight check.
        $exception = null;
        $run       = null;

        try {
            $run = $quotaService->reserveRun($criteria);
        } catch (\App\Exceptions\DiscoveryRunInFlightException $e) {
            $exception = $e;
        }

        $this->assertNull(
            $exception,
            'reserveRun() must NOT throw DiscoveryRunInFlightException because of a manual run'
        );
        $this->assertNotNull($run, 'reserveRun() must return a new DiscoveryRun');
        $this->assertSame('discovery', $run->type ?? 'discovery', 'Reserved run type must be discovery');

        // Clean up the new run row to keep the DB tidy (not required, but avoids side-effects).
        if ($run) {
            $run->delete();
        }
    }

    /**
     * Company meter exhausted but contact meter unlimited → manual enrich succeeds.
     * reserveManualEnrichment() checks the CONTACT meter only — company exhaustion is irrelevant.
     */
    public function test_manual_enrich_allowed_when_company_exhausted_but_contact_unlimited(): void
    {
        // Company meter limited (2 credits), contact meter unlimited (null).
        $package = Package::create([
            'name'                  => 'Pack Company Limited',
            'daily_credits'         => 2,
            'daily_contact_credits' => null,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);
        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        // Create a criteria and burn 2 company credits today.
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Company Exhausted ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 2,
            'is_active'   => true,
        ]);
        $this->burnCredits(2, $criteria);

        // Make a company with a domain (enrich requires a domain).
        $company = $this->makeCompany(['domain' => 'geodis.com']);

        // POST enrich → must return 200 (company exhaustion must NOT block manual enrich).
        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/companies/{$company->id}/enrich");

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
    }
}
