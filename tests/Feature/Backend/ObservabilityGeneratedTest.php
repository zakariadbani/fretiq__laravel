<?php

// >>> custom-test-author:observability-code
/**
 * ObservabilityGeneratedTest — gap-fill coverage for the observability module.
 *
 * What is already covered elsewhere and therefore NOT repeated here:
 *   - ObservabilityAccessTest: superadmin→200, commercial→403, guest→302,
 *     counts() key structure, counts() zeros on empty DB.
 *   - ObservabilityControllerGatingTest: unauthenticated GET smoke.
 *
 * Gaps addressed in this file:
 *   1. admin role (distinct from superadmin) also holds 'manage roles' → 200.
 *   2. Controller passes 'counts', 'failedJobs', 'failedRuns' to view (view data contract).
 *   3. failedJobs() returns a Collection with the expected column set.
 *   4. failedJobs() truncates a multi-line exception to its first non-empty line.
 *   5. counts()['failed_jobs'] increments when a failed_jobs row is inserted.
 *   6. counts()['failed_runs'] / ['queued_recipients'] / ['scheduled_runs'] increment
 *      when matching CampaignRun / CampaignRecipient rows are seeded.
 *   7. failedRuns() returns CampaignRun rows with status='failed' and eager-loads campaign.
 *   8. Index renders 200 when failed_jobs and failed campaign_runs rows exist (integration).
 */

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Analytics\QueueObservabilityService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ObservabilityGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
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

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->admin->assignRole('admin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * Create a minimal Campaign with all required NOT NULL FKs satisfied.
     * Mirrors the makeCampaign() helper in CampaignGeneratedTest.
     */
    private function makeCampaign(string $namePrefix = 'Obs Campaign'): Campaign
    {
        $segment = Segment::create(['name' => $namePrefix . ' Seg ' . uniqid(), 'scope' => 'client']);

        $template = CampaignTemplate::create([
            'name'         => $namePrefix . ' Tpl ' . uniqid(),
            'subject'      => 'Objet test observability',
            'html_content' => '<p>Test</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => $namePrefix . ' Sender ' . uniqid(),
            'email' => 'obs_sender_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name'               => $namePrefix . ' ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'once',
            'is_active'          => false,
            'driver'             => 'local',
        ]);
    }

    /**
     * Create a minimal Contact with a parent Company.
     * Mirrors makeClientContact() in CampaignGeneratedTest.
     */
    private function makeContact(): Contact
    {
        $company = Company::create([
            'name'                 => 'Obs Company ' . uniqid(),
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $company->id,
            'name'        => 'Obs Contact ' . uniqid(),
            'email'       => 'obs_contact_' . uniqid() . '@test.fr',
            'source'      => 'manual',
            'status'      => 'new',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    // ── Gap 1: admin role ───────────────────────────────────────────────────────

    /**
     * The 'admin' role also holds 'manage roles', so the observability index
     * must return 200 for an admin user (not just superadmin).
     */
    public function test_admin_role_can_view_observability(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/observability')
            ->assertStatus(200);
    }

    // ── Gap 2: view data contract ───────────────────────────────────────────────

    /**
     * The controller must pass all three expected keys to the view:
     * 'counts', 'failedJobs', and 'failedRuns'.
     */
    public function test_index_view_receives_expected_data_keys(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/observability')
            ->assertStatus(200)
            ->assertViewHas('counts')
            ->assertViewHas('failedJobs')
            ->assertViewHas('failedRuns');
    }

    // ── Gap 3: failedJobs() Collection shape ────────────────────────────────────

    /**
     * failedJobs() must return a Collection. When no rows exist it should be
     * empty; the return type contract is still satisfied.
     */
    public function test_failed_jobs_returns_empty_collection_when_no_rows(): void
    {
        $service = app(QueueObservabilityService::class);

        $result = $service->failedJobs();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * When a failed_jobs row exists, failedJobs() must return a Collection
     * whose first item carries the columns: id, connection, queue, exception, failed_at.
     */
    public function test_failed_jobs_returns_expected_columns(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['job' => 'TestJob', 'data' => []]),
            'exception'  => 'RuntimeException: something went wrong',
            'failed_at'  => now(),
        ]);

        $service = app(QueueObservabilityService::class);
        $result  = $service->failedJobs();

        $this->assertCount(1, $result);

        $row = $result->first();
        foreach (['id', 'connection', 'queue', 'exception', 'failed_at'] as $column) {
            $this->assertObjectHasProperty($column, $row,
                "failedJobs() row must carry column '{$column}'");
        }
    }

    // ── Gap 4: first-line exception truncation ──────────────────────────────────

    /**
     * failedJobs() strips all lines after the first non-empty line of the
     * exception string so the monitoring dashboard stays readable.
     */
    public function test_failed_jobs_truncates_exception_to_first_line(): void
    {
        $fullTrace  = "RuntimeException: first line\n    #0 /app/foo.php(10)\n    #1 /app/bar.php(20)";
        $firstLine  = 'RuntimeException: first line';

        DB::table('failed_jobs')->insert([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['job' => 'TestJob', 'data' => []]),
            'exception'  => $fullTrace,
            'failed_at'  => now(),
        ]);

        $service = app(QueueObservabilityService::class);
        $row     = $service->failedJobs()->first();

        $this->assertSame($firstLine, $row->exception,
            'failedJobs() must return only the first line of the exception string');
    }

    // ── Gap 5: counts()['failed_jobs'] increments ───────────────────────────────

    /**
     * Inserting a row into failed_jobs must increment counts()['failed_jobs'].
     */
    public function test_counts_failed_jobs_increments_with_seeded_row(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['job' => 'TestJob', 'data' => []]),
            'exception'  => 'Error: test failure',
            'failed_at'  => now(),
        ]);

        $counts = app(QueueObservabilityService::class)->counts();

        $this->assertSame(1, $counts['failed_jobs'],
            'counts()[\'failed_jobs\'] must equal the number of rows in failed_jobs');
    }

    // ── Gap 6: counts() non-zero for campaign_runs / campaign_recipients ─────────

    /**
     * counts()['failed_runs'] must reflect CampaignRun rows with status='failed'.
     * counts()['scheduled_runs'] must reflect CampaignRun rows with status='scheduled'.
     *
     * Both are seeded directly via DB::table to avoid factory dependencies.
     */
    public function test_counts_failed_and_scheduled_runs_increment(): void
    {
        $campaign = $this->makeCampaign('Counts Test');

        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-failed-1',
            'run_at'         => now(),
            'status'         => 'failed',
        ]);

        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-scheduled-1',
            'run_at'         => now()->addHour(),
            'status'         => 'scheduled',
        ]);

        $counts = app(QueueObservabilityService::class)->counts();

        $this->assertSame(1, $counts['failed_runs'],
            'counts()[\'failed_runs\'] must count CampaignRun rows with status=failed');
        $this->assertSame(1, $counts['scheduled_runs'],
            'counts()[\'scheduled_runs\'] must count CampaignRun rows with status=scheduled');
    }

    /**
     * counts()['queued_recipients'] must reflect CampaignRecipient rows with status='queued'.
     */
    public function test_counts_queued_recipients_increments(): void
    {
        // Seed the minimal parent chain: campaign → run → recipient
        $campaign = $this->makeCampaign('Queued Rec');

        $run = CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-queued',
            'run_at'         => now(),
            'status'         => 'running',
        ]);

        $contact = $this->makeContact();

        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'queued',
        ]);

        $counts = app(QueueObservabilityService::class)->counts();

        $this->assertSame(1, $counts['queued_recipients'],
            'counts()[\'queued_recipients\'] must count CampaignRecipient rows with status=queued');
    }

    // ── Gap 7: failedRuns() shape + eager-load ──────────────────────────────────

    /**
     * failedRuns() must return CampaignRun models with status='failed' and
     * must eager-load the 'campaign' relationship so the view can render the
     * campaign name without N+1 queries.
     */
    public function test_failed_runs_returns_failed_campaign_runs_with_campaign(): void
    {
        $campaign = $this->makeCampaign('Broken Campaign');

        // One failed run + one non-failed run (must NOT appear in failedRuns())
        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-fail',
            'run_at'         => now(),
            'status'         => 'failed',
        ]);

        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-done',
            'run_at'         => now()->subMinute(),
            'status'         => 'done',
        ]);

        $service     = app(QueueObservabilityService::class);
        $failedRuns  = $service->failedRuns();

        $this->assertCount(1, $failedRuns,
            'failedRuns() must return only CampaignRun rows with status=failed');

        $run = $failedRuns->first();
        $this->assertSame('failed', $run->status);
        $this->assertTrue($run->relationLoaded('campaign'),
            'failedRuns() must eager-load the campaign relationship');
        $this->assertSame($campaign->name, $run->campaign->name);
    }

    // ── Gap 8: index renders with seeded failure data ───────────────────────────

    /**
     * The index page must return 200 even when failed_jobs rows and failed
     * CampaignRun rows exist in the database (no 500 on non-empty data set).
     */
    public function test_index_renders_with_failed_jobs_and_failed_runs(): void
    {
        // Seed a failed_jobs row
        DB::table('failed_jobs')->insert([
            'uuid'       => \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue'      => 'default',
            'payload'    => json_encode(['job' => 'SendCampaignJob']),
            'exception'  => "App\\Jobs\\SendCampaignJob: Connection timed out\n    #0 foo.php(1)",
            'failed_at'  => now(),
        ]);

        // Seed a failed campaign run
        $campaign = $this->makeCampaign('Failing Campaign');

        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'run-x',
            'run_at'         => now(),
            'status'         => 'failed',
        ]);

        $this->actingAs($this->superadmin)
            ->get('/admin/observability')
            ->assertStatus(200)
            ->assertViewHas('counts')
            ->assertViewHas('failedJobs')
            ->assertViewHas('failedRuns');
    }
}
// <<<
