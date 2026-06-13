<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CampaignsDataTableRenderTest — verifies the campaigns DataTable AJAX response
 * renders the is_active toggle and new columns introduced alongside the is_active
 * feature (segment_id, template_id, sender_identity_id, next_run_at, runs_count,
 * stats_sent_total).
 *
 * Request shape: GET /admin/campaigns with yajra DataTables wire params
 * (draw, start, length, columns[0][data/name], order[0][column/dir])
 * and X-Requested-With + Accept headers — identical to CompaniesTest.test_datatable_json.
 *
 * Two campaigns in DB:
 *   - recurring / active / is_active=true  → status.blade.php switch (class "status-toggle")
 *   - sequence-type                        → static badge "Via séquence"
 */
class CampaignsDataTableRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Campaign $recurring;
    private Campaign $sequenceCampaign;
    private Segment $segment;
    private CampaignTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // ── Shared fixtures ────────────────────────────────────────────────────
        $this->segment = Segment::create([
            'name'  => 'Segment DT Test',
            'scope' => 'client',
        ]);

        $this->template = CampaignTemplate::create([
            'name'         => 'Template DT Test',
            'subject'      => 'Sujet test',
            'html_content' => '<p>Hello</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => 'Expéditeur DT',
            'email' => 'dt@tcl.test',
        ]);

        // ── Recurring campaign (active, is_active=true) ───────────────────────
        $this->recurring = Campaign::create([
            'name'               => 'Campagne Récurrente DT',
            'segment_id'         => $this->segment->id,
            'template_id'        => $this->template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'weekly', 'interval' => 1],
            'next_run_at'        => now()->addWeek(),
            'timezone'           => 'Europe/Paris',
            'status'             => 'active',
            'is_active'          => true,
        ]);

        // Add one run so runs_count = 1
        CampaignRun::create([
            'campaign_id'    => $this->recurring->id,
            'occurrence_key' => 'dt_test_' . now()->format('Y-m-d'),
            'run_at'         => now()->subDay(),
            'status'         => 'done',
            'stats_sent'     => 5,
        ]);

        // ── Sequence campaign ──────────────────────────────────────────────────
        $seq = Sequence::create([
            'name'          => 'Seq DT',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);

        $this->sequenceCampaign = Campaign::create([
            'name'               => 'Campagne Séquence DT',
            'segment_id'         => $this->segment->id,
            'sender_identity_id' => $sender->id,
            'sequence_id'        => $seq->id,
            'schedule_type'      => 'sequence',
            'status'             => 'active',
            'is_active'          => true,
        ]);
    }

    /**
     * The DataTable AJAX endpoint returns JSON with 'data' containing both campaigns.
     *
     * Minimal yajra wire params — identical shape to CompaniesTest.test_datatable_json.
     */
    public function test_datatable_json_returns_data_array(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/campaigns'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * The recurring (non-sequence) campaign renders the is_active toggle switch.
     *
     * status.blade.php emits class="form-check-input status-toggle" for non-sequence rows.
     */
    public function test_datatable_contains_status_toggle_for_recurring_campaign(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/campaigns'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringContainsString('status-toggle', $body,
            'DataTable payload must contain "status-toggle" (the is_active checkbox CSS class)');
    }

    /**
     * The sequence campaign renders the static "Via séquence" badge instead of a toggle.
     */
    public function test_datatable_contains_via_sequence_badge_for_sequence_campaign(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/campaigns'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringContainsString('Via', $body,
            'DataTable payload must contain "Via" from the "Via séquence" sequence badge');
        $this->assertStringContainsString('badge-light-info', $body,
            'The sequence is_active cell must use badge-light-info class');
    }

    /**
     * Relation columns (segment name, template name) appear in the payload.
     *
     * segment_id and template_id columns are rendered via eager-loaded relations.
     */
    public function test_datatable_contains_segment_and_template_names(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/campaigns'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringContainsString(
            e($this->segment->name), $body,
            'DataTable payload must contain the segment name'
        );
        $this->assertStringContainsString(
            e($this->template->name), $body,
            'DataTable payload must contain the template name'
        );
    }

    /**
     * The executeSwitch route appears in the payload as a data-route attribute on the toggle.
     *
     * status.blade.php emits data-route="{{ route('admin.campaigns.executeSwitch', [...]) }}"
     */
    public function test_datatable_contains_execute_switch_route(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/campaigns'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);

        // The JSON payload uses forward-slash escaping (\/), so we search the decoded rows.
        // We find the recurring campaign's row by id, then check its is_active cell for the
        // data-route attribute containing the executeSwitch path.
        $rows = collect($response->json('data'));
        $recurringRow = $rows->firstWhere('id', $this->recurring->id);

        $this->assertNotNull($recurringRow, 'Recurring campaign must appear in DataTable data');

        $isActiveCell = $recurringRow['is_active'] ?? '';
        $routePath    = '/admin/campaigns/executeSwitch/' . $this->recurring->id;
        $this->assertStringContainsString(
            $routePath, $isActiveCell,
            'The is_active cell for a recurring campaign must embed the executeSwitch route path'
        );
    }
}
