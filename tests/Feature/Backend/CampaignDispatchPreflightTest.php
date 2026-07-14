<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SegmentService;
use App\Services\Zoho\CampaignsReadinessService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CampaignDispatchPreflightTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config([
            'services.zoho.driver' => 'local',
            'prospecting.cold_send_enabled' => false,
        ]);

        Mail::fake();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    public function test_preview_returns_exact_eligible_count_after_compliance_exclusions(): void
    {
        config(['prospecting.cold_send_enabled' => true]);

        $this->makeClientContact('eligible-one@example.test');
        $this->makeProspectContact('eligible-two@example.test');
        $suppressed = $this->makeClientContact('suppressed@example.test');
        $this->makeProspectContact('personal@example.test', ['email_kind' => 'personal']);
        $excluded = $this->makeClientContact('excluded@example.test');
        Suppression::create(['email' => $suppressed->email, 'contact_id' => $suppressed->id]);

        $segment = Segment::create(['name' => 'Clients', 'scope' => 'mixed']);
        $segment->pinnedContacts()->syncWithoutDetaching([
            $excluded->id => ['mode' => 'exclude'],
        ]);

        $campaign = $this->makeCampaign($segment);

        $this->actingAs($this->superadmin)
            ->getJson("/admin/campaigns/{$campaign->id}/dispatch-preview")
            ->assertOk()
            ->assertJson(['ok' => true, 'count' => 2]);
    }

    public function test_schedule_happy_path_creates_run_and_confirms_verified_count(): void
    {
        Bus::fake();

        $campaign = $this->makeCampaign($this->makeMixedAudienceWithSuppressedAndNonCompliantContacts('schedule'));

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule")
            ->assertOk()
            ->assertJsonPath('message', 'success')
            ->assertJsonPath('text', 'Campagne planifiée avec succès pour 2 destinataire(s) éligible(s) vérifié(s).');

        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status' => 'scheduled',
        ]);
        Bus::assertNotDispatched(SendCampaignJob::class);
    }

    public function test_send_now_happy_path_creates_run_queues_job_and_confirms_verified_count(): void
    {
        Bus::fake();

        $campaign = $this->makeCampaign($this->makeMixedAudienceWithSuppressedAndNonCompliantContacts('send'));

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertOk()
            ->assertJsonPath('message', 'success')
            ->assertJsonPath('text', "Envoi lancé pour 2 destinataire(s) éligible(s) vérifié(s) — la campagne est en file d'attente.");

        $run = CampaignRun::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame('scheduled', $run->status);

        Bus::assertDispatched(SendCampaignJob::class, fn (SendCampaignJob $job) => $job->runId === $run->id);
    }

    public function test_zero_recipients_blocks_send_without_queueing(): void
    {
        Bus::fake();

        $campaign = $this->makeCampaign(Segment::create(['name' => 'Empty', 'scope' => 'client']));

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Aucun destinataire éligible après exclusions, suppressions et règles de conformité.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_missing_template_blocks_schedule_before_mutation(): void
    {
        $this->makeClientContact('ready@example.test');
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']), ['template_id' => null]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Sélectionnez un modèle email avant de lancer la campagne.');

        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_missing_segment_blocks_send_without_run_or_queue(): void
    {
        Bus::fake();

        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']));
        $this->breakCampaignRelation($campaign, ['segment_id' => 999999]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Sélectionnez un segment avant de lancer la campagne.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_missing_sender_blocks_send_without_run_or_queue(): void
    {
        Bus::fake();

        $this->makeClientContact('ready-sender@example.test');
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']));
        $this->breakCampaignRelation($campaign, ['sender_identity_id' => 999999]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Sélectionnez un expéditeur avant de lancer la campagne.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_audience_resolution_error_blocks_preview_without_run_or_queue(): void
    {
        Bus::fake();

        $this->app->forgetInstance(CampaignService::class);
        $this->mock(SegmentService::class, function ($mock) {
            $mock->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('resolver failed'));
        });

        $segment = Segment::create(['name' => 'Broken resolver ' . uniqid(), 'scope' => 'client']);
        $campaign = $this->makeCampaign($segment);

        $this->actingAs($this->superadmin)
            ->getJson("/admin/campaigns/{$campaign->id}/dispatch-preview")
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'count' => 0])
            ->assertJsonPath('message', 'Impossible de vérifier l’audience finale : corrigez le segment avant de lancer la campagne.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_missing_zoho_list_key_blocks_send_without_run_or_queue(): void
    {
        Bus::fake();

        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'refresh-token',
            'services.zoho.campaigns.client_id' => 'client-id',
            'services.zoho.campaigns.client_secret' => 'client-secret',
            'services.zoho.campaigns.list_key' => '',
            'prospecting.spf_dkim_dmarc_configured' => true,
            'prospecting.bounce_handling_configured' => true,
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.example.test',
        ]);

        $this->makeClientContact('zoho-list@example.test');
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']), [
            'driver' => 'zoho',
            'zoho_list_key' => '',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Préparation Zoho incomplète : ajoutez et vérifiez la liste Zoho dédiée avant de lancer l’envoi.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_non_oauth_incomplete_readiness_blocks_schedule_without_run_or_queue(): void
    {
        Bus::fake();

        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'refresh-token',
            'services.zoho.campaigns.client_id' => 'client-id',
            'services.zoho.campaigns.client_secret' => 'client-secret',
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'prospecting.spf_dkim_dmarc_configured' => false,
            'prospecting.bounce_handling_configured' => true,
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.example.test',
        ]);

        $this->makeClientContact('zoho-readiness@example.test');
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']), [
            'driver' => 'zoho',
            'zoho_list_key' => 'verified-list-key',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'SPF / DKIM / DMARC configurés : Vérification manuelle — configurer PROSPECTING_SPF_DKIM_DMARC_CONFIGURED=true une fois validé');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_zoho_oauth_failure_blocks_before_queueing(): void
    {
        Bus::fake();
        Http::fake(['*oauth/v2/token*' => Http::response(['error' => 'invalid_grant'], 400)]);

        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'bad-refresh-token',
            'services.zoho.campaigns.client_id' => 'client-id',
            'services.zoho.campaigns.client_secret' => 'client-secret',
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'prospecting.spf_dkim_dmarc_configured' => true,
            'prospecting.bounce_handling_configured' => true,
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.example.test',
        ]);

        $this->makeClientContact('zoho@example.test');
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']), [
            'driver' => 'zoho',
            'zoho_list_key' => 'verified-list-key',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'OAuth Zoho Campaigns expiré ou invalide : reconnectez Zoho Campaigns avant de lancer l’envoi.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_unknown_zoho_readiness_result_blocks_send_without_run_or_queue(): void
    {
        Bus::fake();
        $this->fakeZohoReadinessFailure('Vérification Zoho indisponible : réessayez après avoir contrôlé la page Zoho.');

        $this->makeClientContact('zoho-unknown@example.test');
        $campaign = $this->makeZohoReadyCampaign('zoho-unknown');

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Vérification Zoho indisponible : réessayez après avoir contrôlé la page Zoho.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_stale_zoho_readiness_result_blocks_schedule_without_run_or_queue(): void
    {
        Bus::fake();
        $this->fakeZohoReadinessFailure('Vérification Zoho obsolète : relancez la vérification de la liste avant envoi.');

        $this->makeClientContact('zoho-stale@example.test');
        $campaign = $this->makeZohoReadyCampaign('zoho-stale');

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule")
            ->assertStatus(422)
            ->assertJson(['message' => 'error'])
            ->assertJsonPath('text', 'Vérification Zoho obsolète : relancez la vérification de la liste avant envoi.');

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    public function test_due_dispatch_marks_blocked_run_failed_without_queueing(): void
    {
        Bus::fake();

        $campaign = $this->makeCampaign(Segment::create(['name' => 'Empty', 'scope' => 'client']), [
            'is_active' => true,
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'due-empty',
            'run_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->assertSame(0, app(CampaignService::class)->dispatchDue());

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_send_run_fails_closed_when_preflight_turns_stale_after_queueing(): void
    {
        $campaign = $this->makeCampaign(Segment::create(['name' => 'Empty', 'scope' => 'client']), ['is_active' => true]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'stale-empty',
            'run_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        app(CampaignService::class)->sendRun($run);

        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_dispatch_preview_uses_send_campaigns_permission_gate(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo('backend.access');
        $user->givePermissionTo('view campaigns');

        $campaign = $this->makeCampaign(Segment::create(['name' => 'Clients', 'scope' => 'client']));

        $this->actingAs($user)
            ->getJson("/admin/campaigns/{$campaign->id}/dispatch-preview")
            ->assertForbidden();
    }

    private function breakCampaignRelation(Campaign $campaign, array $attributes): void
    {
        Schema::disableForeignKeyConstraints();
        $campaign->forceFill($attributes)->save();
        Schema::enableForeignKeyConstraints();
    }

    private function makeCampaign(Segment $segment, array $overrides = []): Campaign
    {
        $template = CampaignTemplate::create([
            'name' => 'Template ' . uniqid(),
            'subject' => 'Sujet test',
            'html_content' => '<p>Bonjour</p>',
        ]);

        $sender = SenderIdentity::create([
            'name' => 'TCL France ' . uniqid(),
            'email' => 'sender_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name' => 'Campagne ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'scheduled_at' => now(),
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ], $overrides));
    }

    private function makeClientContact(string $email): Contact
    {
        $company = Company::create([
            'name' => 'Client ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'Contact Test',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
    }

    private function makeProspectContact(string $email, array $overrides = []): Contact
    {
        $company = Company::create([
            'name' => 'Prospect ' . uniqid(),
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create(array_merge([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'Contact Test',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
        ], $overrides));
    }

    private function makeMixedAudienceWithSuppressedAndNonCompliantContacts(string $prefix): Segment
    {
        config(['prospecting.cold_send_enabled' => true]);

        $this->makeClientContact("{$prefix}-eligible-client@example.test");
        $this->makeProspectContact("{$prefix}-eligible-prospect@example.test");
        $suppressed = $this->makeClientContact("{$prefix}-suppressed@example.test");
        $this->makeProspectContact("{$prefix}-personal@example.test", ['email_kind' => 'personal']);

        Suppression::create(['email' => $suppressed->email, 'contact_id' => $suppressed->id]);

        return Segment::create(['name' => 'Mixed ' . $prefix, 'scope' => 'mixed']);
    }

    private function makeZohoReadyCampaign(string $name): Campaign
    {
        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'refresh-token',
            'services.zoho.campaigns.client_id' => 'client-id',
            'services.zoho.campaigns.client_secret' => 'client-secret',
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'prospecting.spf_dkim_dmarc_configured' => true,
            'prospecting.bounce_handling_configured' => true,
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.example.test',
        ]);

        return $this->makeCampaign(Segment::create(['name' => $name, 'scope' => 'client']), [
            'driver' => 'zoho',
            'zoho_list_key' => 'verified-list-key',
        ]);
    }

    private function fakeZohoReadinessFailure(string $message): void
    {
        $this->app->forgetInstance(CampaignService::class);
        $this->app->instance(CampaignsReadinessService::class, new class($message) extends CampaignsReadinessService {
            public function __construct(private readonly string $message) {}

            public function dispatchCheck(): array
            {
                return [
                    'ready' => false,
                    'messages' => [$this->message],
                    'access_token_checked' => false,
                ];
            }
        });
    }
}
