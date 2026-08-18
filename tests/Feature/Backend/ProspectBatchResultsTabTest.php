<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Résultats tab (_results-tab.blade.php) — the Contacts + Actions cells ported
 * from prospect_criteria's own results tab, plus the reason-label map fix in
 * config/global/data.php. Nothing else covered this: ProspectBatchEnrichmentActionsTest
 * only exercises the criteria-gated batch-wide JSON endpoints, and ProspectReviewTest
 * never asserts Motif text or column count on this pane.
 */
class ProspectBatchResultsTabTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProspectBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['backend.access', 'view prospect_batches', 'view companies', 'view contacts', 'enrich companies'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->givePermissionTo(['backend.access', 'view prospect_batches', 'view companies', 'view contacts', 'enrich companies']);
        $this->batch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'review',
        ]);
    }

    private function promotedItem(Company $company): ProspectBatchItem
    {
        return ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'promoted',
            'company_id' => $company->id,
            'domain_reason' => 'reviewer_selected',
            'selected_domain' => $company->domain,
        ]);
    }

    public function test_promoted_row_shows_the_enrich_action_and_never_the_manual_verification_fallback(): void
    {
        $company = Company::factory()->create([
            'domain' => 'alometal.ma',
            'qualification_status' => 'pending',
        ]);
        $this->promotedItem($company);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        $response->assertOk()
            ->assertSee('data-url="'.route('admin.companies.enrich', $company->id).'"', false)
            ->assertSee('href="'.route('admin.companies.view', $company->id).'"', false)
            ->assertDontSee('Vérification manuelle requise');
    }

    public function test_enrich_action_is_absent_without_the_enrich_companies_permission(): void
    {
        $this->user->revokePermissionTo('enrich companies');
        $company = Company::factory()->create([
            'domain' => 'alometal.ma',
            'qualification_status' => 'pending',
        ]);
        $this->promotedItem($company);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        // "Voir l'entreprise" stays (view companies is still granted) — only the
        // enrich action itself must disappear.
        $response->assertOk()
            ->assertSee('href="'.route('admin.companies.view', $company->id).'"', false)
            ->assertDontSee('data-url="'.route('admin.companies.enrich', $company->id).'"', false);
    }

    public function test_enrich_action_is_absent_for_a_social_domain_company(): void
    {
        $company = Company::factory()->create([
            'domain' => 'linkedin.com',
            'qualification_status' => 'pending',
        ]);
        $this->promotedItem($company);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        // hasSocialDomain() must gate the button even though the user holds
        // 'enrich companies' and the company has a domain — companies/partials/
        // _row-actions.blade.php omits this check; this pane must not repeat that gap.
        $response->assertOk()
            ->assertDontSee('data-url="'.route('admin.companies.enrich', $company->id).'"', false);
    }

    public function test_the_results_tab_eager_loads_companies_and_contacts_instead_of_n_plus_one(): void
    {
        foreach (range(1, 5) as $i) {
            $company = Company::factory()->create(['domain' => "domain{$i}.example.com"]);
            Contact::factory()->for($company)->create();
            $this->promotedItem($company);
        }

        DB::enableQueryLog();
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id))
            ->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $companyQueries = collect($queries)->filter(fn (array $q): bool => str_contains($q['query'], '`companies`'))->count();
        $contactQueries = collect($queries)->filter(fn (array $q): bool => str_contains($q['query'], '`contacts`'))->count();

        // 2, not 1: one `company.contacts` eager load (this change) covering all
        // 5 rows via a single "id in (...)" query, PLUS the pre-existing
        // enrichmentOutcomeBreakdown() aggregate (unrelated to this change, itself
        // already O(1) — a GROUP BY, not one query per row). Neither scales with
        // the item count; that's the actual N+1 guard.
        $this->assertSame(2, $companyQueries, 'companies queries must not scale with row count — got: '.$companyQueries);
        // 2, not 1: the eager load above, PLUS enrichmentOutcomeBreakdown()'s own
        // aggregate query, which now embeds a `contacts` NOT EXISTS subquery
        // (excludes null-status companies that already have contacts from the
        // null bucket, mirroring the row-level rule — see
        // ProspectBatchController::enrichmentOutcomeBreakdown()). Still one
        // grouped query, not one per row — the subquery is inlined, not a
        // separate round-trip.
        $this->assertSame(2, $contactQueries, 'contacts queries must not scale with row count — got: '.$contactQueries);
    }

    public function test_promoted_row_with_null_enrichment_status_and_contacts_hides_the_null_fallback_badge(): void
    {
        // enrichment_status stays NULL for companies whose contacts arrived via the
        // batch import pipeline (not a Hunter enrichment run) — with contacts already
        // present, the "no search performed" fallback badge would be self-contradictory.
        $company = Company::factory()->create([
            'domain' => 'importedwithcontacts.example.com',
            'qualification_status' => 'pending',
            'enrichment_status' => null,
        ]);
        Contact::factory()->for($company)->create();
        $this->promotedItem($company);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        $response->assertOk();
        $this->assertStringNotContainsString('Recherche de contacts non effectuée', $this->resultsRowsHtml($response->getContent()));
    }

    public function test_promoted_row_with_null_enrichment_status_and_no_contacts_shows_the_null_fallback_badge(): void
    {
        $company = Company::factory()->create([
            'domain' => 'importednocontacts.example.com',
            'qualification_status' => 'pending',
            'enrichment_status' => null,
        ]);
        $this->promotedItem($company);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        $response->assertOk();
        $this->assertStringContainsString('Recherche de contacts non effectuée', $this->resultsRowsHtml($response->getContent()));
    }

    /**
     * Slices the page down to the "Entreprises du lot" row table, excluding the
     * "Résultat de l'enrichissement" breakdown card above it — that card groups
     * companies.enrichment_status batch-wide regardless of contact count (a
     * separate, pre-existing, out-of-scope aggregate) and would otherwise make
     * a plain page-wide assertSee/assertDontSee for the null-status label
     * ambiguous between the two.
     */
    private function resultsRowsHtml(string $html): string
    {
        $rows = strstr($html, 'Entreprises du lot');
        $this->assertNotFalse($rows, 'Expected the "Entreprises du lot" heading to be present.');

        return $rows;
    }

    public function test_skipped_reviewer_rejected_row_renders_the_new_reason_label(): void
    {
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'skipped',
            'domain_reason' => 'not_a_match',
            'company_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $this->batch->id));

        $response->assertOk()
            ->assertSee('Exclu par le relecteur')
            ->assertDontSee('Vérification manuelle requise');
    }
}
