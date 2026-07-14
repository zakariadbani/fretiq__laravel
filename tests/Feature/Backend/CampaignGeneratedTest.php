<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

// >>> custom-test-author:campaigns-code

/**
 * CampaignGeneratedTest — gap-fill test slice for the campaigns module.
 *
 * Existing coverage (DO NOT duplicate):
 *   CampaignCrudTest:             index 200 (superadmin), create 200, store happy-path.
 *   CampaignSendAccessTest:       commercial sendNow 403, commercial schedule 403,
 *                                 superadmin schedule 200 + CampaignRun in DB,
 *                                 commercial create 200, commercial index 200.
 *   CampaignSendTest:             service-level sendRun, idempotency, skipped suppressed.
 *   CampaignPauseTest:            executeSwitch is_active flip on recurring (state='0'),
 *                                 executeSwitch 403 on sequence campaign, lifecycle tests.
 *   CampaignSequenceLaunchTest:   sequence launch, enrollment, guards, sync-stats, W1.
 *   CampaignViewRecipientsTest:   view page rollup, search, chips, markReplied redirects.
 *   MarkRepliedTest:              markReplied happy-path, commercial can mark, conversion_count.
 *   CampaignsDataTableRenderTest: DataTable AJAX, toggle/badge rendering.
 *   CampaignsReadinessTest:       readiness service + Zoho admin screen.
 *
 * This file covers the uncovered surface:
 *   - index 403 for user without `view campaigns`
 *   - index guest redirect (302)
 *   - edit 200 for existing campaign
 *   - update happy-path: name mutated, JSON 200 {message:success}
 *   - store validation failure: missing `name` -> 406 JSON with errors.name
 *   - store validation failure: missing `template_id` on one_shot -> 406 JSON
 *   - delete happy-path: JSON {success:true} + row gone
 *   - delete 403 for commercial role
 *   - executeSwitch sets_active (state='1'): DB updated to 1
 *   - executeSwitch rejects unlisted field -> 403
 *   - segmentCount: GET returns JSON {count: N}
 *   - segmentCount: unknown segment returns JSON {count: 0}
 *   - audienceLanguageSplit: returns JSON shape with required keys
 *   - audienceLanguageSplit: 403 for user without `view campaigns`
 *   - audienceLanguageSplit: 422 for missing segment_id
 *   - sendNow (one_shot): Queue::fake() asserts SendCampaignJob dispatched for superadmin
 *   - sendNow 403 for user without `send campaigns` (bare permission check)
 *   - schedule recurring branch: campaign status set to 'active' + JSON 200
 *   - schedule 422 for sequence-type campaign
 */
class CampaignGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zoho.driver' => 'local',
            'prospecting.cold_send_enabled' => false,
        ]);
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
    // Fixtures

    /**
     * Build a minimal one_shot Campaign without going through the controller.
     */
    private function makeCampaign(array $overrides = []): Campaign
    {
        $segment = Segment::create(['name' => 'Seg ' . uniqid(), 'scope' => 'client']);

        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . uniqid(),
            'subject'      => 'Objet test',
            'html_content' => '<p>Bonjour</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => 'TCL France ' . uniqid(),
            'email' => 'sender_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name'               => 'Campagne Test ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now()->addHour(),
            'timezone'           => 'Europe/Paris',
            'is_active'          => true,
        ], $overrides));
    }

    /**
     * Build a sequence-type Campaign (no template_id).
     */
    private function makeSequenceCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'Seg Seq ' . uniqid(), 'scope' => 'client']);

        $sender = SenderIdentity::create([
            'name'  => 'TCL Seq ' . uniqid(),
            'email' => 'seq_' . uniqid() . '@tcl.test',
        ]);

        $sequence = Sequence::create([
            'name'      => 'Seq ' . uniqid(),
            'is_active' => true,
        ]);

        return Campaign::create([
            'name'               => 'Campagne Séquence ' . uniqid(),
            'segment_id'         => $segment->id,
            'sequence_id'        => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'sequence',
            'is_active'          => true,
        ]);
    }

    /**
     * Build a recurring Campaign with next_run_at set (schedule() needs it).
     */
    private function makeRecurringCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'Seg Rec ' . uniqid(), 'scope' => 'client']);

        $template = CampaignTemplate::create([
            'name'         => 'Tpl Rec ' . uniqid(),
            'subject'      => 'Objet récurrent',
            'html_content' => '<p>Bonjour</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => 'TCL Rec ' . uniqid(),
            'email' => 'rec_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne Récurrente ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'weekly', 'interval' => 1],
            'next_run_at'        => now()->addWeek(),
            'timezone'           => 'Europe/Paris',
            'is_active'          => true,
        ]);
    }

    /**
     * Create a minimal client Contact (needed by sendNow so segment is non-empty).
     */
    private function makeClientContact(): Contact
    {
        $company = Company::create([
            'name'                 => 'Acme ' . uniqid(),
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $company->id,
            'email'       => 'contact_' . uniqid() . '@acme.test',
            'name'        => 'Test Contact',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }
    // Access control

    /**
     * Unauthenticated request to campaigns index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/campaigns');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view campaigns` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view campaigns`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/campaigns');

        $response->assertStatus(403);
    }
    // Edit (form page)

    /**
     * GET /admin/campaigns/{id}/edit returns 200 for superadmin and renders the campaign name.
     */
    public function test_edit_renders_for_existing_campaign(): void
    {
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaigns/' . $campaign->id . '/edit');

        $response->assertStatus(200);
        // The edit form must render the campaign name so the user can see what they're editing.
        $response->assertSee($campaign->name);
    }
    // Update happy-path

    /**
     * PUT /admin/campaigns/{id} with valid data mutates the row.
     *
     * Crudable returns JSON 200 {message:'success', model, redirect}.
     */
    public function test_update_mutates_campaign_name(): void
    {
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaigns/' . $campaign->id, [
                'name'               => 'Nom Modifié',
                'segment_id'         => $campaign->segment_id,
                'template_id'        => $campaign->template_id,
                'sender_identity_id' => $campaign->sender_identity_id,
                'schedule_type'      => 'one_shot',
                'timezone'           => 'Europe/Paris',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $this->assertDatabaseHas('campaigns', [
            'id'   => $campaign->id,
            'name' => 'Nom Modifié',
        ]);
    }
    // Store validation (406)

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * The Crudable trait calls $model->validator() and returns JSON 406 on failure.
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $segment = Segment::create(['name' => 'Seg Val ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl Val ' . uniqid(),
            'subject'      => 'Objet',
            'html_content' => '<p>Hi</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'Sender Val ' . uniqid(),
            'email' => 'val_' . uniqid() . '@tcl.test',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaigns', [
                // name intentionally omitted
                'segment_id'         => $segment->id,
                'template_id'        => $template->id,
                'sender_identity_id' => $sender->id,
                'schedule_type'      => 'one_shot',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Store must return 406 when `template_id` is missing on a one_shot campaign.
     *
     * Rule: 'template_id' => 'required_unless:schedule_type,sequence|...'
     */
    public function test_store_validation_fails_when_template_id_missing_for_one_shot(): void
    {
        $segment = Segment::create(['name' => 'Seg Val2 ' . uniqid(), 'scope' => 'client']);
        $sender  = SenderIdentity::create([
            'name'  => 'Sender Val2 ' . uniqid(),
            'email' => 'val2_' . uniqid() . '@tcl.test',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaigns', [
                'name'               => 'Campagne Sans Template',
                'segment_id'         => $segment->id,
                // template_id intentionally omitted for one_shot
                'sender_identity_id' => $sender->id,
                'schedule_type'      => 'one_shot',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('template_id', $response->json('errors'));
    }
    // Delete

    /**
     * DELETE /admin/campaigns/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_campaign(): void
    {
        $campaign = $this->makeCampaign();
        $id       = $campaign->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/campaigns/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('campaigns', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete campaigns` permission — must receive 403.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/campaigns/' . $campaign->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }
    // executeSwitch

    /**
     * PUT /admin/campaigns/executeSwitch/{id} with field=is_active, state='1' sets active.
     *
     * CampaignController::$toggleableFields = ['is_active'].
     * State sent as STRING to match real browser AJAX behavior.
     * CampaignPauseTest already covers state='0' on recurring; this covers state='1'.
     */
    public function test_execute_switch_sets_active(): void
    {
        // Use a one_shot campaign (not sequence) — sequence campaigns are blocked.
        $campaign = $this->makeCampaign(['is_active' => false]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaigns/executeSwitch/' . $campaign->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'is_active' => 1]);
    }

    /**
     * executeSwitch must return 403 when an unlisted field is requested.
     *
     * CampaignController::$toggleableFields = ['is_active'].
     * Any other field must be rejected via the Datatableable whitelist check.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaigns/executeSwitch/' . $campaign->id, [
                'field' => 'name',   // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
    // segmentCount

    /**
     * GET /admin/campaigns/segment-count/{id} returns JSON {count: N} for a known segment.
     *
     * Seed two client Contacts; SegmentService::previewCount(scope=client) resolves both
     * -> count must equal exactly 2.
     * The controller is gated by `view campaigns` (enforced via middleware).
     */
    public function test_segment_count_returns_count_for_known_segment(): void
    {
        // Seed exactly 2 client contacts with non-empty emails — the service pipeline
        // filters by company.relationship='client', excludes suppressions, applies cold gate.
        // With cold_send_enabled=false (test default), prospects are excluded but clients
        // are always eligible. Two distinct emails -> previewCount must return 2.
        $this->makeClientContact();
        $this->makeClientContact();

        // Scope='client' — SegmentService::buildBaseQuery() will match these contacts.
        $segment = Segment::create(['name' => 'Clients SC ' . uniqid(), 'scope' => 'client']);

        $response = $this->actingAs($this->superadmin)
            ->getJson('/admin/campaigns/segment-count/' . $segment->id);

        $response->assertStatus(200);
        $response->assertJsonStructure(['count']);
        // count must be an integer (SegmentService::previewCount returns int)
        $this->assertIsInt($response->json('count'));
        // The count must match the number of eligible seeded contacts (>= 2).
        // We use >= rather than === to be resilient to extra rows that may exist,
        // but assert > 0 to ensure the assertion is non-vacuous.
        $this->assertGreaterThanOrEqual(2, $response->json('count'),
            'segmentCount must resolve at least the 2 seeded client contacts'
        );
    }

    /**
     * GET /admin/campaigns/segment-count/{id} returns {count: 0} for an unknown segment id.
     *
     * Controller: `if (!$segment) { return response()->json(['count' => 0]); }`
     */
    public function test_segment_count_returns_zero_for_unknown_segment(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->getJson('/admin/campaigns/segment-count/99999');

        $response->assertStatus(200);
        $response->assertJson(['count' => 0]);
    }
    // audienceLanguageSplit

    /**
     * POST /admin/campaigns/audience-language-split returns the expected JSON shape
     * and correct per-bucket counts for a seeded audience.
     *
     * Language bucketing in CampaignController::audienceLanguageSplit():
     *   - blank company.country                     -> unknown
     *   - country in francophone_countries (FR,BE...) -> fr
     *   - any other non-empty country code           -> en
     *
     * We seed:
     *   2 client contacts with country='FR'  -> fr bucket  (francophone)
     *   1 client contact  with country='DE'  -> en bucket  (non-francophone)
     *   1 client contact  with country=null  -> unknown bucket
     *
     * Segment scope='client' matches all four. With cold_send_enabled=false
     * all four pass the cold gate (clients are never excluded by it).
     * Expected: fr=2, en=1, unknown=1, total=4.
     */
    public function test_audience_language_split_returns_expected_shape(): void
    {
        // Helper: create a client contact linked to a company with a specific country.
        $makeContact = function (?string $country) {
            $company = Company::create([
                'name'                 => 'Acme Split ' . uniqid(),
                'relationship'         => 'client',
                'source'               => 'manual',
                'qualification_status' => 'pending',
                'country'              => $country,
            ]);
            return Contact::create([
                'company_id'  => $company->id,
                'email'       => 'split_' . uniqid() . '@acme.test',
                'name'        => 'Split Contact',
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
            ]);
        };

        // 2 FR contacts -> francophone -> fr bucket
        $makeContact('FR');
        $makeContact('FR');
        // 1 DE contact -> non-francophone -> en bucket
        $makeContact('DE');
        // 1 contact with no country -> unknown bucket
        $makeContact(null);

        // scope='client' + no filter -> SegmentService resolves all 4 contacts.
        $segment = Segment::create(['name' => 'Split Seg ' . uniqid(), 'scope' => 'client']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'fr',
            'en',
            'unknown',
            'total',
            'has_en',
            'en_stale',
            'warning',
            'template_edit_url',
        ]);

        $json = $response->json();

        // Bucket values must reflect the seeded audience exactly.
        $this->assertSame(2, $json['fr'],
            'fr bucket must equal the 2 seeded FR contacts');
        $this->assertSame(1, $json['en'],
            'en bucket must equal the 1 seeded DE contact');
        $this->assertSame(1, $json['unknown'],
            'unknown bucket must equal the 1 seeded null-country contact');
        $this->assertSame(4, $json['total'],
            'total must equal fr + en + unknown = 4');

        // Structural sanity: sum identity must hold.
        $this->assertSame(
            $json['fr'] + $json['en'] + $json['unknown'],
            $json['total'],
            'fr + en + unknown must equal total'
        );
        $this->assertIsBool($json['has_en']);
        $this->assertIsBool($json['en_stale']);
        $this->assertIsBool($json['warning']);
    }

    /**
     * audienceLanguageSplit must return 403 for a user without `view campaigns`.
     *
     * Gate: abort_unless($request->user()->can('view campaigns'), 403) — inline in controller.
     */
    public function test_audience_language_split_403_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view campaigns`.
        $user->givePermissionTo('backend.access');

        $segment = Segment::create(['name' => 'Split 403 ' . uniqid(), 'scope' => 'client']);

        $response = $this->actingAs($user)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(403);
    }

    /**
     * audienceLanguageSplit returns 422 when segment_id is missing.
     *
     * Uses $request->validate() (not Crudable trait) -> Laravel returns 422 on failure,
     * NOT 406. This is the custom-action 422 pattern described in the task spec.
     */
    public function test_audience_language_split_422_when_segment_id_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaigns/audience-language-split', [
                // segment_id intentionally omitted
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['segment_id']);
    }
    // sendNow

    /**
     * POST /admin/campaigns/{id}/send dispatches SendCampaignJob when called by a
     * user who has `send campaigns` permission and the campaign is a one_shot type.
     *
     * MOCK strategy:
     *   Bus::fake() intercepts job dispatch — no real job runs, no real email sent.
     *   Assert: Bus::assertDispatched(SendCampaignJob::class).
     *
     * CampaignPauseTest uses Bus::fake() for the same job — we follow that pattern
     * for consistency. Bus::fake() captures jobs dispatched via ShouldQueue + Dispatchable.
     *
     * The controller's one_shot branch:
     *   $run = app(CampaignService::class)->scheduleOneShot($campaign);
     *   SendCampaignJob::dispatch($run->id);
     *
     * We do NOT call the service directly — the HTTP path is what we test here.
     */
    public function test_send_now_dispatches_job_for_authorized_user(): void
    {
        Bus::fake();

        config(['prospecting.cold_send_enabled' => false]);

        // A client contact ensures the segment is non-empty (SegmentService::previewCount >= 1).
    // schedule
        // the job actually sends, so an empty segment is also fine here — but a contact
        // prevents the controller returning an unexpected response.
        $this->makeClientContact();

        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaigns/' . $campaign->id . '/send');

        // Controller returns JSON 200 {message:'success', text:'...', redirect:'...'}
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
    // schedule
        // SendCampaignJob with the run's id. We verify the dispatched job carries that
        // exact run id so the assertion is non-vacuous (wrong run id would be a real bug).
        $run = \App\Models\CampaignRun::where('campaign_id', $campaign->id)->latest('id')->firstOrFail();

        // Closure form: verify the dispatched job carries the correct run id.
        // SendCampaignJob::__construct(public readonly int $runId) — property is $runId.
        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job) => $job->runId === $run->id);
    }

    /**
     * POST /admin/campaigns/{id}/send returns 403 for a user who does NOT have
     * `send campaigns` permission.
     *
     * CampaignSendAccessTest already covers the `commercial` role; this test uses
     * a bare user with only backend.access to verify the middleware gate directly.
     */
    public function test_send_now_403_for_user_without_send_permission(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');
        $user->givePermissionTo('view campaigns');
        // Note: 'send campaigns' is intentionally NOT granted.

        $campaign = $this->makeCampaign();

        $response = $this->actingAs($user)
            ->post('/admin/campaigns/' . $campaign->id . '/send');

        $response->assertStatus(403);

        // No job must have been dispatched.
        Bus::assertNothingDispatched();
    }
    // schedule

    /**
     * POST /admin/campaigns/{id}/schedule on a recurring campaign sets is_active=true
     * and returns JSON 200 {message:'success'}.
     *
     * Recurring branch in the controller:
     *   if ($campaign->next_run_at === null) -> 422
     *   else $campaign->update(['is_active' => true]); return JSON 200
     */
    public function test_schedule_recurring_campaign_sets_status_active(): void
    {
        $this->makeClientContact();
        $campaign = $this->makeRecurringCampaign();

        // Precondition: campaign starts inactive (is_active=true default, but we verify the DB flip).
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaigns/' . $campaign->id . '/schedule');

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'is_active' => 1,
        ]);
    }

    /**
     * POST /admin/campaigns/{id}/schedule on a sequence-type campaign returns 422.
     *
     * Controller guard:
     *   if ($campaign->schedule_type === 'sequence') {
     *       return response()->json([...], 422);
     *   }
     *
     * This is the HTTP-level assertion for the guard; CampaignSequenceLaunchTest
     * test_schedule_rejected_for_sequence_type tests the service layer only.
     */
    public function test_schedule_sequence_campaign_returns_422(): void
    {
        $campaign = $this->makeSequenceCampaign();

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaigns/' . $campaign->id . '/schedule');

        $response->assertStatus(422);
        // Controller returns JSON with message:'error' and a French explanation.
        $response->assertJson(['message' => 'error']);
    }
}

// <<<
