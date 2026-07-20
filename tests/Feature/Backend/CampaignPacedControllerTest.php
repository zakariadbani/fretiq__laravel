<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Crud\ViewConfigs\CampaignViewConfig;
use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignTemplate;
use App\Models\CampaignRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignPacedControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('superadmin');
        config(['services.zoho.driver' => 'local', 'prospecting.cold_send_enabled' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_form_renders_dedicated_paced_fields(): void
    {
        $this->actingAs($this->admin)->get('/admin/campaigns/create')
            ->assertOk()
            ->assertSee('Envoi progressif')
            ->assertSee('name="paced_first_send_at"', false)
            ->assertSee('name="daily_company_limit"', false)
            ->assertSee('Le nombre d’e-mails peut dépasser le nombre de sociétés.', false);
    }

    public function test_paced_update_maps_first_send_and_clears_incompatible_fields(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'recurring', 'recurrence' => ['frequency' => 'daily'], 'scheduled_at' => now()]);

        $this->actingAs($this->admin)->putJson("/admin/campaigns/{$campaign->id}", $this->payload($campaign, [
            'schedule_type' => 'paced',
            'paced_first_send_at' => '2026-07-21 10:30',
            'daily_company_limit' => '',
            'is_active' => '1',
        ]))->assertOk();

        $campaign->refresh();
        $this->assertSame(20, $campaign->daily_company_limit);
        $this->assertSame('2026-07-21 08:30', $campaign->next_run_at->format('Y-m-d H:i'));
        $this->assertNull($campaign->scheduled_at);
        $this->assertNull($campaign->recurrence);
    }

    public function test_paced_update_rejects_explicit_zero_company_limit(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 20, 'next_run_at' => now()->addDay()]);

        $this->actingAs($this->admin)->putJson("/admin/campaigns/{$campaign->id}", $this->payload($campaign, [
            'schedule_type' => 'paced',
            'paced_first_send_at' => '2026-07-21 10:30',
            'daily_company_limit' => '0',
            'is_active' => '1',
        ]))->assertStatus(406)->assertJsonValidationErrors(['daily_company_limit']);

        $this->assertSame(20, $campaign->fresh()->daily_company_limit);
    }

    public function test_segment_count_remains_backward_compatible_and_includes_company_count(): void
    {
        $campaign = $this->campaign();
        $this->contacts(companyCount: 2, contactsPerCompany: 2);

        $this->actingAs($this->admin)->getJson("/admin/campaigns/segment-count/{$campaign->segment_id}")
            ->assertOk()
            ->assertJson(['count' => 4, 'contact_count' => 4, 'company_count' => 2]);
    }

    public function test_empty_paced_campaign_can_be_activated_for_dynamic_intake(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 20, 'next_run_at' => now()->addDay(), 'is_active' => false]);

        $this->actingAs($this->admin)->getJson("/admin/campaigns/{$campaign->id}/dispatch-preview?action=schedule")
            ->assertOk()
            ->assertJson(['ok' => true, 'contact_count' => 0, 'company_count' => 0]);

        $this->actingAs($this->admin)->getJson("/admin/campaigns/{$campaign->id}/dispatch-preview")
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/schedule")
            ->assertOk()
            ->assertJsonPath('message', 'success');

        $this->assertTrue($campaign->fresh()->is_active);
        $this->assertSame(0, $campaign->runs()->count());
    }

    public function test_send_now_creates_today_batch_dispatches_job_and_advances_cursor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', 'UTC'));
        Bus::fake();
        $campaign = $this->campaign([
            'schedule_type' => 'paced',
            'daily_company_limit' => 1,
            'next_run_at' => Carbon::parse('2026-07-20 10:30', 'Europe/Paris')->utc(),
            'is_active' => false,
        ]);
        $this->contacts(companyCount: 2, contactsPerCompany: 2);

        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertOk()
            ->assertJsonPath('message', 'success');

        $run = $campaign->runs()->firstOrFail();
        $this->assertSame('paced-20260720', $run->occurrence_key);
        $this->assertSame(1, $run->companyDispatches()->count());
        $this->assertSame(2, $run->recipients()->count());
        $this->assertSame('2026-07-21 10:30', $campaign->fresh()->next_run_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertTrue($campaign->fresh()->is_active);
        Bus::assertDispatched(SendCampaignJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_empty_send_now_is_rejected_without_dispatching_job(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', 'UTC'));
        Bus::fake();
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 20, 'next_run_at' => now(), 'is_active' => true]);

        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'error']);

        Bus::assertNotDispatched(SendCampaignJob::class);
        $this->assertSame(0, $campaign->runs()->count());
    }

    public function test_send_now_rejects_second_same_day_batch(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 08:00:00', 'UTC'));
        Bus::fake();
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 1, 'next_run_at' => now(), 'is_active' => true]);
        $this->contacts(companyCount: 2, contactsPerCompany: 1);

        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/send")->assertOk();
        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)->assertJsonFragment(['message' => 'error']);
        $this->assertSame(1, $campaign->runs()->count());
    }

    public function test_send_now_rejects_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 08:00:00', 'UTC'));
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 20, 'next_run_at' => now(), 'is_active' => true]);
        $this->contacts(1, 1);

        $this->actingAs($this->admin)->postJson("/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(422)->assertJsonFragment(['message' => 'error']);
    }

    public function test_paced_toggle_reactivation_requires_cursor_and_valid_limit(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => null, 'next_run_at' => null, 'is_active' => false]);

        $this->actingAs($this->admin)->putJson("/admin/campaigns/executeSwitch/{$campaign->id}", [
            'field' => 'is_active', 'state' => 1,
        ])->assertStatus(422)->assertJsonFragment(['success' => false]);
    }

    public function test_edit_only_user_cannot_activate_paced_campaign_but_can_pause_it(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['backend.access', 'view campaigns', 'edit campaigns']);
        $campaign = $this->campaign([
            'schedule_type' => 'paced', 'daily_company_limit' => 20,
            'next_run_at' => now()->addDay(), 'is_active' => false,
        ]);

        $this->actingAs($user)->putJson("/admin/campaigns/executeSwitch/{$campaign->id}", [
            'field' => 'is_active', 'state' => 1,
        ])->assertForbidden()->assertJsonFragment([
            'success' => false,
            'msg' => 'L’autorisation d’envoi de campagnes est obligatoire pour activer une campagne progressive.',
        ]);
        $this->assertFalse($campaign->fresh()->is_active);

        $campaign->update(['is_active' => true]);
        $activeConfig = CampaignViewConfig::make($campaign->fresh());
        $this->assertSame('edit campaigns', $activeConfig['toggle']['permission']);
        $this->actingAs($user)->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('toggle_is_active_' . $campaign->id, false);

        $this->actingAs($user)->putJson("/admin/campaigns/executeSwitch/{$campaign->id}", [
            'field' => 'is_active', 'state' => 0,
        ])->assertOk();
        $this->assertFalse($campaign->fresh()->is_active);

        $config = CampaignViewConfig::make($campaign->fresh());
        $this->assertSame('send campaigns', $config['toggle']['permission']);
        $this->actingAs($user)->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertDontSee('toggle_is_active_' . $campaign->id, false);
    }

    public function test_detail_shows_paced_company_progress_and_backlog(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 1, 'next_run_at' => now()->addDay()]);
        $companies = $this->contacts(2, 1);
        CampaignCompanyDispatch::create([
            'campaign_id' => $campaign->id,
            'company_id' => $companies[0]->id,
            'status' => 'processed',
            'attempts' => 1,
            'processed_at' => now(),
        ]);
        CampaignCompanyDispatch::create([
            'campaign_id' => $campaign->id,
            'company_id' => $companies[1]->id,
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'timeout',
        ]);
        $third = $this->contacts(1, 2)[0];
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'paced-20260720',
            'run_at' => Carbon::parse('2026-07-20 08:00:00', 'UTC'),
            'status' => 'sent',
        ]);
        CampaignCompanyDispatch::create([
            'campaign_id' => $campaign->id,
            'company_id' => $third->id,
            'current_run_id' => $run->id,
            'status' => 'processed',
            'attempts' => 1,
            'processed_at' => now(),
        ]);
        $this->contacts(1, 2);

        $this->actingAs($this->admin)->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('Progression des sociétés')
            ->assertSee('Sociétés traitées')
            ->assertSee('File actuelle')
            ->assertSee('Sociétés en échec')
            ->assertSee('data-paced-processed="2"', false)
            ->assertSee('data-paced-backlog-companies="1"', false)
            ->assertSee('data-paced-failed="1"', false)
            ->assertSee('data-paced-last-batch-companies="1"', false)
            ->assertSee('2 contact(s)')
            ->assertSee('20/07/2026')
            ->assertSee('Prochain lot :');
    }

    public function test_paced_view_renders_specific_schedule_and_send_confirmation_copy(): void
    {
        $campaign = $this->campaign(['schedule_type' => 'paced', 'daily_company_limit' => 20, 'next_run_at' => now()->addDay()]);

        $this->actingAs($this->admin)->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('active les lots continus des jours ouvrés', false)
            ->assertSee('uniquement le lot du jour', false)
            ->assertSee('preview.company_count', false)
            ->assertSee('preview.contact_count', false)
            ->assertSee('action=schedule', false)
            ->assertSee('campaignActionErrorMessage', false)
            ->assertSee('data.text', false)
            ->assertSee('data.messages', false)
            ->assertSee('error.message', false)
            ->assertSee('.catch(function (error) { showBlocked(campaignActionErrorMessage(error)); })', false);
    }

    private function campaign(array $overrides = []): Campaign
    {
        $segment = Segment::create(['name' => 'Clients ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Tpl ' . uniqid(), 'subject' => 'Objet', 'html_content' => '<p>Bonjour</p>']);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => uniqid('sender_') . '@tcl.test']);

        return Campaign::create(array_merge([
            'name' => 'Campagne ' . uniqid(), 'segment_id' => $segment->id,
            'template_id' => $template->id, 'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot', 'timezone' => 'Europe/Paris', 'is_active' => true,
        ], $overrides));
    }

    private function payload(Campaign $campaign, array $overrides): array
    {
        return array_merge([
            'name' => $campaign->name, 'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id, 'sender_identity_id' => $campaign->sender_identity_id,
            'timezone' => 'Europe/Paris', 'is_active' => '1',
        ], $overrides);
    }

    /** @return array<int, Company> */
    private function contacts(int $companyCount, int $contactsPerCompany): array
    {
        $companies = [];
        for ($i = 0; $i < $companyCount; $i++) {
            $company = Company::create([
                'name' => 'Client ' . uniqid(), 'relationship' => 'client', 'source' => 'manual',
                'qualification_status' => 'pending', 'ai_score' => 90 - $i,
            ]);
            $companies[] = $company;
            for ($j = 0; $j < $contactsPerCompany; $j++) {
                Contact::create([
                    'company_id' => $company->id, 'email' => uniqid('contact_') . '@client.test',
                    'name' => 'Contact', 'status' => 'new', 'source' => 'manual',
                    'legal_basis' => 'relationship', 'email_kind' => 'role',
                ]);
            }
        }
        return $companies;
    }
}
