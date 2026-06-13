<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for the Campaign view page — Destinataires tab.
 *
 * Covers:
 *  (a) view page shows recipients from ALL runs (rollup = 1 row per contact), not raw flat list;
 *  (b) ?recipients_page=2 returns the next slice (51 distinct contacts);
 *  (c) zero-runs campaign renders the view page with 200 + empty state;
 *  (d) markReplied redirect Location contains #campaign_destinataires;
 *  (e–n) new tests: rollup dedup, run scope, search, chips, action targets, idempotency.
 *
 * Test DB: fretiq_test (MySQL) — see phpunit.xml. RefreshDatabase wraps each test.
 * Do NOT run php artisan migrate manually; the test suite manages the schema.
 */
class CampaignViewRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Mail::fake();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeCampaign(string $suffix = ''): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg ' . $suffix, 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . $suffix,
            'subject'      => 'Sujet ' . $suffix,
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL ' . $suffix,
            'email' => 'noreply' . $suffix . '@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne ' . $suffix,
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    /**
     * Create a Contact, optionally linking it to a Company with the given name.
     *
     * @param  string      $email
     * @param  string|null $companyName  When provided, creates a Company and links it.
     */
    private function makeContact(string $email, ?string $companyName = null): Contact
    {
        $company = Company::create([
            'name'                 => $companyName ?? ('Co ' . $email),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $company->id,
            'email'       => $email,
            'name'        => 'Contact ' . $email,
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeRun(Campaign $campaign, string $keySuffix = ''): CampaignRun
    {
        return CampaignRun::create([
            'campaign_id'      => $campaign->id,
            'occurrence_key'   => 'test-' . $keySuffix . '-' . now()->format('YmdHisv'),
            'run_at'           => now(),
            'status'           => 'sent',
            'conversion_count' => 0,
        ]);
    }

    /**
     * Create a CampaignRecipient, merging optional $overrides into defaults.
     *
     * Pass 'sent_at' in $overrides for the Envoyés chip (counts sent_at NOT NULL).
     *
     * @param  CampaignRun       $run
     * @param  Contact           $contact
     * @param  array             $overrides  Merged into the create array.
     */
    private function makeRecipient(CampaignRun $run, Contact $contact, array $overrides = []): CampaignRecipient
    {
        return CampaignRecipient::create(array_merge([
            'campaign_run_id'     => $run->id,
            'contact_id'          => $contact->id,
            'status'              => 'sent',
            'provider_message_id' => 'local-' . $run->id . '-' . $contact->id,
        ], $overrides));
    }

    // ── Existing tests (a–d) — semantics adjusted to rollup ───────────────────

    /**
     * (a) Rollup: the view page shows DISTINCT contacts across all runs.
     *     Two runs, each with a DIFFERENT contact → 2 rollup rows.
     *     (Previously: "recipients from ALL runs, not just latest".)
     */
    public function test_view_page_recipients_span_all_runs(): void
    {
        $campaign = $this->makeCampaign('multi');

        $run1 = $this->makeRun($campaign, 'r1');
        $run2 = $this->makeRun($campaign, 'r2');

        $contact1 = $this->makeContact('a1@multi.test');
        $contact2 = $this->makeContact('a2@multi.test');

        $this->makeRecipient($run1, $contact1);
        $this->makeRecipient($run2, $contact2);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);

        // Both emails must appear in the rollup (one row each, 2 distinct contacts).
        $response->assertSee(e('a1@multi.test'));
        $response->assertSee(e('a2@multi.test'));
    }

    /**
     * (b) Pagination: ?recipients_page=2 with 51 distinct contacts.
     *     Each contact appears in exactly one run, so rollup = 51 distinct rows.
     *     Page 2 has 1 row (the oldest inserted = lowest id, end of DESC sort).
     */
    public function test_view_page_second_recipients_page(): void
    {
        $campaign = $this->makeCampaign('paged');
        $run      = $this->makeRun($campaign, 'paged');

        // Create 51 recipients (distinct contacts)
        $lastEmail = null;
        for ($i = 1; $i <= 51; $i++) {
            $email   = "page{$i}@paged.test";
            $contact = $this->makeContact($email);
            $this->makeRecipient($run, $contact);
            $lastEmail = $email;
        }

        // Page 2 must return 200
        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?recipients_page=2");

        $response->assertStatus(200);

        // page1@paged.test has the lowest id → sorted to page 2 (orderByDesc id).
        $response->assertSee(e('page1@paged.test'));
    }

    /**
     * (c) Zero-runs campaign: view page renders 200 with empty-state content.
     */
    public function test_view_page_zero_runs_renders_200(): void
    {
        $campaign = $this->makeCampaign('empty');

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);

        // Empty-state text from _historique-tab and _destinataires-tab
        $response->assertSee('Aucune exécution pour cette campagne');
        $response->assertSee('Aucune exécution — aucun destinataire');
    }

    /**
     * (d) markReplied redirect Location contains #campaign_destinataires.
     */
    public function test_mark_replied_redirect_contains_fragment(): void
    {
        $campaign  = $this->makeCampaign('frag');
        $run       = $this->makeRun($campaign, 'frag');
        $contact   = $this->makeContact('frag@test.test');
        $recipient = $this->makeRecipient($run, $contact);

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/replied");

        $response->assertRedirect();

        $location = $response->headers->get('Location', '');
        $this->assertStringContainsString(
            '#campaign_destinataires',
            $location,
            'Redirect after markReplied must contain #campaign_destinataires fragment'
        );
    }

    // ── New tests (e–n) ────────────────────────────────────────────────────────

    /**
     * (e) Rollup: same contact in 2 runs → header shows "Destinataires (1)",
     *     email appears exactly once in the table, Total chip data-count="1".
     */
    public function test_rollup_one_row_per_contact_across_runs(): void
    {
        $campaign = $this->makeCampaign('rollup1');
        $run1     = $this->makeRun($campaign, 'r1');
        $run2     = $this->makeRun($campaign, 'r2');
        // Use a company name that does NOT contain the email string, so substr_count is reliable.
        $contact  = $this->makeContact('unique-rollup@example.test', 'Rollup Corp');

        $this->makeRecipient($run1, $contact);
        $this->makeRecipient($run2, $contact);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);

        $body = $response->getContent();

        // Header must show 1, not 2.
        $this->assertStringContainsString('Destinataires (1)', $body);

        // Email must appear exactly once in the table rows (not the header or elsewhere).
        // We use a tightly scoped string that only appears inside a <td>.
        $this->assertSame(
            1,
            substr_count($body, e('unique-rollup@example.test')),
            'The email should appear exactly once in rollup (1 row per contact).'
        );

        // Total chip: data-chip="total" data-count="1"
        $this->assertStringContainsString('data-chip="total"', $body);
        $this->assertStringContainsString('data-count="1"', $body);
    }

    /**
     * (f) Run scope: ?run_id=run1 shows only that run's contacts, banner present.
     *     contactB (run2 only) must NOT appear.
     */
    public function test_run_scope_shows_only_that_runs_rows(): void
    {
        $campaign = $this->makeCampaign('scope1');
        $run1     = $this->makeRun($campaign, 'r1');
        $run2     = $this->makeRun($campaign, 'r2');
        $contactA = $this->makeContact('scopeA@scope.test');
        $contactB = $this->makeContact('scopeB@scope.test');

        $this->makeRecipient($run1, $contactA);
        $this->makeRecipient($run2, $contactB);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?run_id={$run1->id}");

        $response->assertStatus(200);

        $body = $response->getContent();

        $this->assertStringContainsString(e('scopeA@scope.test'), $body);
        $this->assertStringNotContainsString(e('scopeB@scope.test'), $body);

        // Run-scope banner must be visible.
        $this->assertStringContainsString('Exécution du', $body);
    }

    /**
     * (g) A run_id belonging to another campaign is silently ignored → rollup shown, no banner.
     */
    public function test_run_id_of_another_campaign_is_ignored(): void
    {
        $campaign  = $this->makeCampaign('other1');
        $campaign2 = $this->makeCampaign('other2');
        $run1      = $this->makeRun($campaign, 'c1r1');
        $foreignRun = $this->makeRun($campaign2, 'c2r1');
        $contact   = $this->makeContact('other@other.test');

        $this->makeRecipient($run1, $contact);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?run_id={$foreignRun->id}");

        $response->assertStatus(200);

        $body = $response->getContent();

        // Should show rollup of campaign's own recipients (not empty).
        $this->assertStringContainsString(e('other@other.test'), $body);

        // No run-scope banner.
        $this->assertStringNotContainsString('Exécution du', $body);
    }

    /**
     * (h) Search filters by email OR company name.
     */
    public function test_search_filters_by_email_or_company_name(): void
    {
        $campaign = $this->makeCampaign('search1');
        $run      = $this->makeRun($campaign, 'sr1');

        $contactAlpha = $this->makeContact('alpha-unique@search.test', 'Alpha Corp');
        $contactBeta  = $this->makeContact('beta-unique@search.test', 'Beta SARL');

        $this->makeRecipient($run, $contactAlpha);
        $this->makeRecipient($run, $contactBeta);

        // Search by email substring
        $responseEmail = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?q=alpha-unique");

        $responseEmail->assertStatus(200);
        $bodyEmail = $responseEmail->getContent();
        $this->assertStringContainsString(e('alpha-unique@search.test'), $bodyEmail);
        $this->assertStringNotContainsString(e('beta-unique@search.test'), $bodyEmail);

        // Search by company name
        $responseCompany = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?q=Beta+SARL");

        $responseCompany->assertStatus(200);
        $bodyCompany = $responseCompany->getContent();
        $this->assertStringContainsString(e('beta-unique@search.test'), $bodyCompany);
        $this->assertStringNotContainsString(e('alpha-unique@search.test'), $bodyCompany);
    }

    /**
     * (i) Chip filter narrows rows; chip counts still show full distribution.
     *
     *     2 contacts: one sent (sent_at set), one bounced.
     *     ?statut=bounced → 1 row shown; data-chip="sent" data-count="1" still present (full dist).
     */
    public function test_chip_filter_filters_rows_and_counts_show_distribution(): void
    {
        $campaign     = $this->makeCampaign('chips1');
        $run          = $this->makeRun($campaign, 'ch1');
        $contactSent  = $this->makeContact('chipsent@chips.test');
        $contactBounced = $this->makeContact('chipbounced@chips.test');

        $this->makeRecipient($run, $contactSent, ['status' => 'sent', 'sent_at' => now()]);
        $this->makeRecipient($run, $contactBounced, ['status' => 'bounced']);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}?statut=bounced");

        $response->assertStatus(200);
        $body = $response->getContent();

        // Only bounced row shown.
        $this->assertStringContainsString(e('chipbounced@chips.test'), $body);
        $this->assertStringNotContainsString(e('chipsent@chips.test'), $body);

        // But full distribution still in chips: sent chip count = 1.
        $this->assertStringContainsString('data-chip="sent"', $body);
        $this->assertMatchesRegularExpression('/data-chip="sent"[^>]*data-count="1"|data-count="1"[^>]*data-chip="sent"/', $body);
    }

    /**
     * (j) Rollup action targets the LATEST recipient row id (highest id = latest run).
     */
    public function test_rollup_replied_action_targets_latest_recipient_row(): void
    {
        $campaign = $this->makeCampaign('latest1');
        $run1     = $this->makeRun($campaign, 'lt1');
        $run2     = $this->makeRun($campaign, 'lt2');
        $contact  = $this->makeContact('latest@latest.test');

        $olderRecipient = $this->makeRecipient($run1, $contact);
        $latestRecipient = $this->makeRecipient($run2, $contact);

        // Ensure latest has a higher id.
        $this->assertGreaterThan($olderRecipient->id, $latestRecipient->id);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);
        $body = $response->getContent();

        // Action URL must contain the latestRecipient id.
        $this->assertStringContainsString("/recipients/{$latestRecipient->id}/replied", $body);
        // And must NOT target the older id.
        $this->assertStringNotContainsString("/recipients/{$olderRecipient->id}/replied", $body);
    }

    /**
     * (k) Contact replied in run1, re-sent in run2 (rollup latest status = "Envoyé"):
     *     - status badge shows latest status (Envoyé = run2 row status)
     *     - action shows "Déjà répondu" (any-run replied wins)
     */
    public function test_rollup_contact_with_any_replied_row_shows_deja_repondu(): void
    {
        $campaign = $this->makeCampaign('deja1');
        $run1     = $this->makeRun($campaign, 'dj1');
        $run2     = $this->makeRun($campaign, 'dj2');
        $contact  = $this->makeContact('deja@deja.test');

        // run1: replied
        $this->makeRecipient($run1, $contact, ['status' => 'replied', 'replied_at' => now()]);
        // run2: sent (latest row, higher id → rn=1 in rollup)
        $this->makeRecipient($run2, $contact, ['status' => 'sent']);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);
        $body = $response->getContent();

        // Latest status badge: "Envoyé" (from run2 row status = 'sent')
        $this->assertStringContainsString('Envoyé', $body);

        // Action must show "Déjà répondu" (any-run replied = true)
        $this->assertStringContainsString('Déjà répondu', $body);
    }

    /**
     * (l) Historique tab has "Voir destinataires" link for each run.
     */
    public function test_historique_tab_has_voir_destinataires_link(): void
    {
        $campaign = $this->makeCampaign('hist1');
        $run      = $this->makeRun($campaign, 'h1');

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringContainsString('Voir destinataires', $body);
        $this->assertStringContainsString("run_id={$run->id}", $body);
    }

    /**
     * (m) markReplied is idempotent: second POST does NOT create a second Demande;
     *     conversion_count incremented only once.
     */
    public function test_mark_replied_is_idempotent(): void
    {
        $campaign  = $this->makeCampaign('idem1');
        $run       = $this->makeRun($campaign, 'id1');
        $contact   = $this->makeContact('idem@idem.test');
        $recipient = $this->makeRecipient($run, $contact);

        $url = "/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/replied";

        // First POST — should create Demande and increment conversion_count.
        $this->actingAs($this->superadmin)->post($url)->assertRedirect();

        // Second POST — idempotent, must not create another Demande.
        $this->actingAs($this->superadmin)->post($url)->assertRedirect();

        // Exactly 1 Demande exists for this contact + campaign.
        $this->assertSame(
            1,
            Demande::where('contact_id', $contact->id)
                   ->where('campaign_id', $campaign->id)
                   ->count(),
            'Second markReplied must not create a duplicate Demande.'
        );

        // conversion_count incremented exactly once.
        $run->refresh();
        $this->assertSame(1, (int) $run->conversion_count, 'conversion_count must be 1 after one successful capture.');
    }

    /**
     * (n) markReplied blocked when contact already replied in another run of same campaign.
     *     No new Demande created; redirect with info flash.
     */
    public function test_mark_replied_blocked_when_contact_replied_in_other_run(): void
    {
        $campaign  = $this->makeCampaign('block1');
        $run1      = $this->makeRun($campaign, 'bl1');
        $run2      = $this->makeRun($campaign, 'bl2');
        $contact   = $this->makeContact('block@block.test');

        // Contact already replied in run1.
        $this->makeRecipient($run1, $contact, ['status' => 'replied', 'replied_at' => now()]);

        // New recipient in run2 (status sent).
        $recipientRun2 = $this->makeRecipient($run2, $contact, ['status' => 'sent']);

        $url = "/admin/campaigns/{$campaign->id}/recipients/{$recipientRun2->id}/replied";

        $response = $this->actingAs($this->superadmin)->post($url);
        $response->assertRedirect();

        // No new Demande should have been created.
        $this->assertSame(
            0,
            Demande::where('contact_id', $contact->id)
                   ->where('campaign_id', $campaign->id)
                   ->count(),
            'markReplied must not create a Demande when contact already replied in another run.'
        );
    }
}
