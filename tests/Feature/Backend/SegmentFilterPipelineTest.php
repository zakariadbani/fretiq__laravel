<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Services\Campaign\SegmentService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SegmentFilterPipelineTest — unit-ish feature tests exercising each stage of
 * SegmentService's 6-stage compliance pipeline directly (via app()).
 *
 * Tests:
 *  - Scope: client / prospect / mixed
 *  - Filter: scalar vs array equivalence; whereIn multi-value; unknown keys ignored
 *  - D11a: empty / whitespace emails excluded
 *  - D11b: case-insensitive email deduplication
 *  - Suppression stage (stage 3)
 *  - Prospect and personal-email eligibility
 *  - resolve() vs resolveWithStats() consistency
 *  - Send-path parity: resolved contacts pass CampaignService invariants
 */
class SegmentFilterPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** @var \App\Services\Campaign\SegmentService */
    private SegmentService $service;

    /** Company A — client, sector=Transport, country=FR */
    private Company $companyA;
    /** Company B — prospect, sector=Logistics, country=BE */
    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->service = app(SegmentService::class);

        $this->companyA = Company::create([
            'name'                 => 'Acme Transport',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Transport',
            'country'              => 'FR',
        ]);

        $this->companyB = Company::create([
            'name'                 => 'Prospect Logistics',
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'sector'               => 'Logistics',
            'country'              => 'BE',
        ]);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    private function makeContact(Company $co, string $email, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Test Contact',
            'source'      => 'manual',
            'email_kind'  => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ], $extra));
    }

    private function makeCriteria(string $name): ProspectCriteria
    {
        return ProspectCriteria::create([
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    // ── Stage 1: Scope filter ──────────────────────────────────────────────────

    public function test_scope_client_excludes_prospects(): void
    {
        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('client', []);

        // Only client contact is matched; prospect never enters scope
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['final']);
    }

    public function test_scope_prospect_includes_eligible_contact(): void
    {
        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('prospect', []);

        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['final']);
    }

    public function test_scope_mixed_includes_clients_and_prospects(): void
    {
        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('mixed', []);

        $this->assertSame(2, $stats['matched']);
        $this->assertSame(2, $stats['final']);
    }

    // ── Stage 2: JSON filter ───────────────────────────────────────────────────

    public function test_filter_scalar_and_array_sector_equivalence(): void
    {

        $this->makeContact($this->companyA, 'a@acme.test');

        $statsScalar = $this->service->resolveWithStats('client', ['sector' => 'Transport']);
        $statsArray  = $this->service->resolveWithStats('client', ['sector' => ['Transport']]);

        $this->assertSame(
            $statsScalar['final'],
            $statsArray['final'],
            'Scalar and single-item array sector filter must yield same result'
        );
    }

    public function test_filter_wherein_multi_sector_matches_both_companies(): void
    {
        // Gate open so prospect can be matched

        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test', ['email_kind' => 'role']);

        $stats = $this->service->resolveWithStats('mixed', ['sector' => ['Transport', 'Logistics']]);

        $this->assertSame(2, $stats['matched'], 'Both companies match the multi-sector whereIn filter');
        $this->assertSame(2, $stats['final']);
    }

    // ── Stage 2: criteria_id filter + sector OR criteria_id union ─────────────

    /**
     * criteria_id alone matches only companies tagged with that criteria.
     */
    public function test_filter_criteria_id_only_matches_tagged_company(): void
    {

        $criteria = $this->makeCriteria('Critère A');
        $this->companyA->update(['criteria_id' => $criteria->id]);

        $this->makeContact($this->companyA, 'a@acme.test');

        $stats = $this->service->resolveWithStats('client', ['criteria_id' => [$criteria->id]]);

        $this->assertSame(1, $stats['matched'], 'Company tagged with the criteria must match');
        $this->assertSame(1, $stats['final']);
    }

    /**
     * A criteria_id that no company carries yields zero matches.
     */
    public function test_filter_criteria_id_without_match_yields_zero(): void
    {

        $criteria = $this->makeCriteria('Critère orphelin');

        $this->makeContact($this->companyA, 'a@acme.test');

        $stats = $this->service->resolveWithStats('client', ['criteria_id' => [$criteria->id]]);

        $this->assertSame(0, $stats['matched'], 'No company carries this criteria_id');
        $this->assertSame(0, $stats['final']);
    }

    /**
     * When BOTH sector and criteria_id are present they are ORed: a company that
     * matches only the sector AND a company that matches only the criteria_id
     * must both resolve.
     */
    public function test_filter_sector_or_criteria_id_returns_the_union(): void
    {
        // Gate open so the prospect company can be matched too.

        $criteria = $this->makeCriteria('Critère union');

        // companyA matches by sector only (Transport, no criteria_id).
        // companyB matches by criteria_id only (sector=Logistics is NOT in the filter).
        $this->companyB->update(['criteria_id' => $criteria->id]);

        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('mixed', [
            'sector'      => ['Transport'],
            'criteria_id' => [$criteria->id],
        ]);

        $this->assertSame(2, $stats['matched'], 'sector OR criteria_id must return the union of both');
        $this->assertSame(2, $stats['final']);
    }

    /**
     * country stays ANDed with the sector/criteria_id OR-group: a company matching
     * the criteria_id but sitting in the wrong country must be excluded.
     */
    public function test_filter_country_still_anded_with_criteria_id(): void
    {

        $criteria = $this->makeCriteria('Critère pays');

        // Both companies carry the criteria, but only companyA is in FR.
        $this->companyA->update(['criteria_id' => $criteria->id]);   // FR
        $this->companyB->update(['criteria_id' => $criteria->id]);   // BE

        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('mixed', [
            'criteria_id' => [$criteria->id],
            'country'     => ['FR'],
        ]);

        $this->assertSame(1, $stats['matched'], 'BE company must be excluded by the ANDed country filter');
        $this->assertSame(1, $stats['final']);
    }

    /**
     * country is ANDed with the whole OR-group, not just one branch: a company
     * matching the sector branch but in the wrong country is still excluded.
     */
    public function test_filter_country_anded_with_the_whole_or_group(): void
    {

        $criteria = $this->makeCriteria('Critère et pays');

        $this->companyB->update(['criteria_id' => $criteria->id]);   // BE, criteria branch

        $this->makeContact($this->companyA, 'a@acme.test');          // FR, sector branch
        $this->makeContact($this->companyB, 'b@prospect.test');

        $stats = $this->service->resolveWithStats('mixed', [
            'sector'      => ['Transport'],
            'criteria_id' => [$criteria->id],
            'country'     => ['FR'],
        ]);

        $this->assertSame(1, $stats['matched'], 'Only the FR company survives the ANDed country filter');
        $this->assertSame(1, $stats['final']);
    }

    /**
     * A criteria_id array that cleans down to empty is treated as absent.
     */
    public function test_filter_empty_criteria_id_array_treated_as_absent(): void
    {

        $this->makeContact($this->companyA, 'a@acme.test');

        $statsNoFilter = $this->service->resolveWithStats('client', []);
        $statsEmpty    = $this->service->resolveWithStats('client', ['criteria_id' => [null, '']]);

        $this->assertSame($statsNoFilter['final'], $statsEmpty['final']);
    }

    // ── Stage 2: position filter (D3.new) ──────────────────────────────────────

    public function test_filter_position_case_insensitive_like_match(): void
    {
        $this->makeContact($this->companyA, 'buyer@acme.test', ['position' => 'Purchasing Manager']);
        $this->makeContact($this->companyA, 'other@acme.test', ['position' => 'Sales Director']);

        $stats = $this->service->resolveWithStats('client', ['position' => ['manager']]);

        $this->assertSame(1, $stats['matched'], 'Only the case-insensitive LIKE match on position must be counted');
        $this->assertSame(1, $stats['final']);
    }

    public function test_filter_position_no_match_returns_zero(): void
    {
        $this->makeContact($this->companyA, 'buyer@acme.test', ['position' => 'Purchasing Manager']);

        $stats = $this->service->resolveWithStats('client', ['position' => ['nonexistent-title']]);

        $this->assertSame(0, $stats['matched']);
        $this->assertSame(0, $stats['final']);
    }

    // ── Stage 2: exclude_contacted filter ──────────────────────────────────────

    private function campaignRun(): CampaignRun
    {
        $segment = Segment::create(['name' => 'Pipeline Run', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Pipeline', 'subject' => 'Hello', 'html_content' => '<p>Hello</p>']);
        $sender = SenderIdentity::create(['name' => 'Pipeline', 'email' => 'sender@example.test']);
        $campaign = Campaign::create([
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'name' => 'Pipeline',
            'email_verification_policy' => Campaign::VERIFICATION_VERIFIED_ONLY,
        ]);

        return CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => uniqid('pipeline-', true),
            'run_at' => now(),
        ]);
    }

    public function test_filter_exclude_contacted_excludes_contacted_and_replied(): void
    {
        $this->makeContact($this->companyA, 'normal@acme.test');
        $contacted = $this->makeContact($this->companyA, 'contacted@acme.test');
        $replied   = $this->makeContact($this->companyA, 'replied@acme.test');

        $run = $this->campaignRun();
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contacted->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);
        InboxEmail::create([
            'message_id'  => 'reply-' . uniqid() . '@example.test',
            'from_email'  => $replied->email,
            'contact_id'  => $replied->id,
            'received_at' => now(),
        ]);

        $stats = $this->service->resolveWithStats('client', ['exclude_contacted' => true]);

        $this->assertSame(1, $stats['matched'], 'Only the never-contacted contact must remain — both contacted and replied are excluded');
        $this->assertSame(1, $stats['final']);
    }

    // ── Stage 2: exclude_generic_mailbox filter ────────────────────────────────

    public function test_filter_exclude_generic_mailbox_excludes_role_local_parts(): void
    {
        $this->makeContact($this->companyA, 'info@acme.test');
        $this->makeContact($this->companyA, 'jane.doe@acme.test');

        $stats = $this->service->resolveWithStats('client', ['exclude_generic_mailbox' => true]);

        $this->assertSame(1, $stats['matched'], 'Generic role-mailbox local-part must be excluded, named contact kept');
        $this->assertSame(1, $stats['final']);
    }

    public function test_unknown_filter_keys_ignored(): void
    {

        $this->makeContact($this->companyA, 'a@acme.test');

        $statsWithUnknown = $this->service->resolveWithStats('client', ['foo' => 'bar', 'baz' => [1, 2]]);
        $statsWithout     = $this->service->resolveWithStats('client', []);

        $this->assertSame(
            $statsWithout['final'],
            $statsWithUnknown['final'],
            'Unknown filter keys must be silently ignored'
        );
    }

    public function test_empty_filter_array_same_as_no_filter(): void
    {

        $this->makeContact($this->companyA, 'a@acme.test');

        $statsNoFilter   = $this->service->resolveWithStats('client', []);
        $statsEmptyArray = $this->service->resolveWithStats('client', ['sector' => [], 'country' => []]);

        $this->assertSame($statsNoFilter['final'], $statsEmptyArray['final']);
    }

    // ── D11a: empty / whitespace email exclusion ───────────────────────────────

    public function test_d11a_empty_email_excluded(): void
    {

        // Create a contact with empty email directly (bypassing unique constraint: use raw DB)
        // We use DB::table to avoid the unique email validation on the model.
        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            'company_id'   => $this->companyA->id,
            'email'        => '',
            'name'         => 'Empty Email',
            'source'       => 'manual',
            'email_kind'   => 'role',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $stats = $this->service->resolveWithStats('client', []);

        // Contact with empty email must not be counted
        $this->assertSame(0, $stats['matched'], 'Contact with empty email must be excluded (D11a)');
    }

    public function test_d11a_whitespace_only_email_excluded(): void
    {

        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            'company_id'   => $this->companyA->id,
            'email'        => '   ',
            'name'         => 'Whitespace Email',
            'source'       => 'manual',
            'email_kind'   => 'role',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertSame(0, $stats['matched'], 'Contact with whitespace-only email must be excluded (D11a)');
    }

    // ── D11b: case-insensitive / trim-aware deduplication ─────────────────────
    //
    // The contacts.email column uses ascii_general_ci collation on MySQL, which
    // IS case-insensitive at the DB level. That prevents inserting both
    // 'Alice@Corp.com' AND 'alice@corp.com' via normal paths (UNIQUE violation).
    //
    // The D11b dedup is a PHP-level safety-net (mb_strtolower + trim) for data
    // that arrived via bulk import or a prior schema. We test the same code path
    // using emails that differ only by surrounding whitespace: ' alice@corp.com '
    // and 'alice@corp.com' are distinct byte sequences (unique constraint passes),
    // but collapse to the same key after trim — exercising the dedup lambda.
    //
    // Both the SQL-level COUNT(DISTINCT LOWER(TRIM(...))) and the PHP-level
    // unique() lambda are exercised by this fixture.

    public function test_d11b_trim_aware_dedup(): void
    {

        // ' alice@corp.com ' (with spaces) and 'alice@corp.com' are different
        // bytes → unique constraint passes. After TRIM they are identical.
        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            [
                'company_id'  => $this->companyA->id,
                'email'       => 'alice@corp.com',
                'name'        => 'Alice Trimmed',
                'source'      => 'manual',
                'email_kind'  => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'company_id'  => $this->companyA->id,
                'email'       => ' alice@corp.com ',
                'name'        => 'Alice Padded',
                'source'      => 'manual',
                'email_kind'  => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $stats = $this->service->resolveWithStats('client', []);

        // Both rows pass the whereNotNull + whereRaw("TRIM(email) != ''") guard.
        // Stage 6 dedup collapses them to 1 (LOWER(TRIM(email)) is identical).
        $this->assertSame(2, $stats['matched'], 'Both rows pass stages 1–5');
        $this->assertSame(1, $stats['duplicates_excluded'], 'One duplicate excluded after trim-dedup (D11b)');
        $this->assertSame(1, $stats['final'], 'Final must be 1 after trim dedup');
    }

    public function test_d11b_resolve_returns_1_contact_for_trim_duplicate(): void
    {

        \Illuminate\Support\Facades\DB::table('contacts')->insert([
            [
                'company_id'  => $this->companyA->id,
                'email'       => 'bob@corp.com',
                'name'        => 'Bob Clean',
                'source'      => 'manual',
                'email_kind'  => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'company_id'  => $this->companyA->id,
                'email'       => ' bob@corp.com ',
                'name'        => 'Bob Padded',
                'source'      => 'manual',
                'email_kind'  => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $segment  = Segment::create(['name' => 'Trim Dedup', 'scope' => 'client']);
        $resolved = $this->service->resolve($segment);

        $this->assertCount(1, $resolved, 'resolve() must return exactly 1 contact for trim-duplicate emails (D11b)');
    }

    // ── Stage 3: Suppression ───────────────────────────────────────────────────

    public function test_suppressed_contact_excluded_and_counted(): void
    {

        $ct = $this->makeContact($this->companyA, 'suppress@acme.test');

        Suppression::create([
            'email'  => 'suppress@acme.test',
            'reason' => 'hard_bounce',
            'source' => 'campaign',
        ]);

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertSame(1, $stats['suppressed'], 'Suppressed contact must be counted');
        $this->assertSame(0, $stats['final']);
    }

    // ── Prospect contact eligibility ───────────────────────────────────────────

    public function test_personal_email_prospect_is_excluded_by_quality_policy(): void
    {
        $this->makeContact($this->companyB, 'personal@prospect.test', ['email_kind' => 'personal']);

        $stats = $this->service->resolveWithStats('prospect', []);

        $this->assertSame(1, $stats['verification_excluded']);
        $this->assertSame(0, $stats['final']);
    }

    public function test_personal_email_client_remains_eligible(): void
    {
        $this->makeContact($this->companyA, 'personal@client.test', ['email_kind' => 'personal']);

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertSame(1, $stats['final']);
    }

    public function test_verification_status_is_applied_after_suppression_and_before_manual_excludes(): void
    {
        $keep = $this->makeContact($this->companyA, 'keep-policy@acme.test');
        $invalid = $this->makeContact($this->companyA, 'invalid-policy@acme.test', [
            'email_verification_status' => 'invalid',
        ]);
        $suppressed = $this->makeContact($this->companyA, 'suppressed-policy@acme.test');
        $excluded = $this->makeContact($this->companyA, 'excluded-policy@acme.test');

        Suppression::create([
            'email' => $suppressed->email,
            'reason' => 'manual',
            'source' => 'manual',
        ]);

        $stats = $this->service->resolveWithStats(
            'client',
            [],
            false,
            [],
            [$excluded->id],
        );

        $this->assertSame(4, $stats['matched']);
        $this->assertSame(1, $stats['suppressed']);
        $this->assertSame(1, $stats['verification_excluded']);
        $this->assertSame(1, $stats['manually_excluded']);
        $this->assertSame(1, $stats['final']);
        $this->assertSame($keep->id, $this->service->resolveAudience('client', [], [], [$excluded->id])->sole()->id);
        $this->assertSame(
            $stats['final'],
            $stats['matched']
                - $stats['suppressed']
                - $stats['duplicates_excluded']
                - $stats['verification_excluded']
                - $stats['manually_excluded'],
        );
        $this->assertNotSame($invalid->id, $keep->id);
    }

    public function test_cold_send_flag_does_not_empty_segment_preview(): void
    {
        config(['prospecting.cold_send_enabled' => false]);
        $this->makeContact($this->companyB, 'verified-prospect@example.test');

        $stats = $this->service->resolveWithStats('prospect', []);

        $this->assertSame(1, $stats['final']);
        $this->assertSame(0, $stats['verification_excluded']);
    }

    public function test_quality_filter_query_count_is_constant_for_one_thousand_contacts(): void
    {
        $now = now();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = [
                'company_id' => $this->companyA->id,
                'email' => "quality-{$i}@example.test",
                'name' => "Quality {$i}",
                'source' => 'manual',
                'email_kind' => 'role',
                'email_verification_status' => $i === 999 ? 'invalid' : 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Contact::query()->insert($rows);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertSame(999, $stats['final']);
        $this->assertSame(1, $stats['verification_excluded']);
        $this->assertLessThanOrEqual(5, count($queries));
    }

    // ── Pin funnel keys on no-pin path ────────────────────────────────────────

    /**
     * When no pins are set, manually_included and manually_excluded must be
     * present in resolveWithStats() output and equal to 0.
     */
    public function test_no_pin_path_manually_keys_present_and_zero(): void
    {

        $this->makeContact($this->companyA, 'nopin@acme.test');

        $stats = $this->service->resolveWithStats('client', []);

        $this->assertArrayHasKey('manually_included', $stats,
            'manually_included key must exist in resolveWithStats() output');
        $this->assertArrayHasKey('manually_excluded', $stats,
            'manually_excluded key must exist in resolveWithStats() output');
        $this->assertSame(0, $stats['manually_included'],
            'manually_included must be 0 on the no-pin path');
        $this->assertSame(0, $stats['manually_excluded'],
            'manually_excluded must be 0 on the no-pin path');
    }

    /**
     * Funnel identity extended with manually_excluded term:
     *   matched − suppressed − duplicates_excluded − manually_excluded === final
     *
     * Verifies the identity when manually_excluded > 0.
     */
    public function test_funnel_identity_with_manually_excluded_term(): void
    {

        $keep    = $this->makeContact($this->companyA, 'keep@acme.test');
        $exclude = $this->makeContact($this->companyA, 'excl@acme.test');

        $segment = Segment::create(['name' => 'Identity Test', 'scope' => 'client']);
        $segment->pinnedContacts()->syncWithoutDetaching([
            $exclude->id => ['mode' => 'exclude'],
        ]);

        $stats = $this->service->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
        );

        $this->assertSame(1, $stats['manually_excluded'],
            'manually_excluded must count the pinned-exclude');

        $computed = $stats['matched']
            - $stats['suppressed']
            - $stats['duplicates_excluded']
            - $stats['manually_excluded'];

        $this->assertSame($stats['final'], $computed,
            'Extended funnel identity must hold: matched − ... − manually_excluded === final');
    }

    // ── resolve() vs resolveWithStats() consistency ────────────────────────────

    public function test_resolve_count_equals_resolvewithstats_final(): void
    {

        $this->makeContact($this->companyA, 'a@acme.test');
        $this->makeContact($this->companyA, 'b@acme.test');
        $this->makeContact($this->companyB, 'c@prospect.test');

        $segment = Segment::create(['name' => 'Mixed', 'scope' => 'mixed']);

        $resolvedCount = $this->service->resolve($segment)->count();
        $statsCount    = $this->service->resolveWithStats('mixed', [])['final'];

        $this->assertSame(
            $statsCount,
            $resolvedCount,
            'resolve()->count() must equal resolveWithStats()[final]'
        );
    }

    public function test_resolve_count_matches_stats_with_suppression(): void
    {

        $c1 = $this->makeContact($this->companyA, 'x@acme.test');
        $this->makeContact($this->companyA, 'y@acme.test');

        Suppression::create(['email' => 'x@acme.test', 'reason' => 'manual', 'source' => 'manual']);

        $segment = Segment::create(['name' => 'Client Sup', 'scope' => 'client']);

        $resolvedCount = $this->service->resolve($segment)->count();
        $statsCount    = $this->service->resolveWithStats('client', [])['final'];

        $this->assertSame($statsCount, $resolvedCount);
    }

    public function test_resolve_count_matches_stats_with_filter(): void
    {

        $this->makeContact($this->companyA, 'fr@acme.test');  // sector=Transport, country=FR
        $this->makeContact($this->companyB, 'be@prospect.test'); // sector=Logistics, country=BE — client only scope won't match

        $segment = Segment::create([
            'name'   => 'Client FR Transport',
            'scope'  => 'client',
            'filter' => ['sector' => ['Transport'], 'country' => ['FR']],
        ]);

        $resolvedCount = $this->service->resolve($segment)->count();
        $statsCount    = $this->service->resolveWithStats('client', ['sector' => ['Transport'], 'country' => ['FR']])['final'];

        $this->assertSame($statsCount, $resolvedCount);
        $this->assertSame(1, $resolvedCount);
    }

    // ── Send-path parity ───────────────────────────────────────────────────────
    //
    // CampaignService re-checks suppression per-recipient at send time (step 4a).
    // We assert here the invariants that make that check always a no-op when
    // called on the output of resolve():
    //   (a) No resolved contact is on the suppression list.
    //
    // CampaignService does not expose a public unit seam for a dry-run compliance
    // check; we assert the invariants directly against the resolve() output.

    public function test_send_parity_no_resolved_contact_is_suppressed(): void
    {

        $c1 = $this->makeContact($this->companyA, 'safe@acme.test');
        $c2 = $this->makeContact($this->companyA, 'blocked@acme.test');

        Suppression::create(['email' => 'blocked@acme.test', 'reason' => 'manual', 'source' => 'manual']);

        $segment  = Segment::create(['name' => 'Parity Check', 'scope' => 'client']);
        $resolved = $this->service->resolve($segment);

        $suppressedEmails = Suppression::pluck('email')->map(fn ($e) => strtolower(trim($e)))->all();

        foreach ($resolved as $contact) {
            $normalised = strtolower(trim($contact->email));
            $this->assertNotContains(
                $normalised,
                $suppressedEmails,
                "Resolved contact {$contact->email} must not be on the suppression list"
            );
        }
    }

    public function test_send_parity_keeps_only_quality_eligible_prospect_contacts(): void
    {
        $this->makeContact($this->companyA, 'client@acme.test');
        $this->makeContact($this->companyB, 'role@prospect.test', ['email_kind' => 'role']);
        $this->makeContact($this->companyB, 'personal@prospect.test', ['email_kind' => 'personal']);

        $segment  = Segment::create(['name' => 'Parity Mixed', 'scope' => 'mixed']);
        $resolved = $this->service->resolve($segment);

        $this->assertCount(2, $resolved);
        $this->assertFalse($resolved->contains(fn (Contact $contact): bool => $contact->email_kind === 'personal'));
    }
}
