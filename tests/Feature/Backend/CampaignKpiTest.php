<?php

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
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignKpiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    private function makeCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'KPI segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'KPI template',
            'subject' => 'KPI subject',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'KPI sender', 'email' => 'kpi@tcl.test']);

        return Campaign::create([
            'name' => 'KPI campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'scheduled_at' => now(),
            'timezone' => 'Europe/Paris',
        ]);
    }

    private function makeContact(string $email): Contact
    {
        $company = Company::create([
            'name' => $email,
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => $email,
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
    }

    public function test_view_uses_only_sent_runs_for_kpis_and_history(): void
    {
        $campaign = $this->makeCampaign();
        $sent = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sent-kpi',
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'stats_sent' => 10,
            'stats_delivered' => 6,
            'stats_opened' => 3,
            'stats_clicked' => 2,
        ]);
        $prepared = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'prepared-kpi',
            'run_at' => now(),
            'status' => 'prepared',
            'stats_sent' => 99999,
            'stats_delivered' => 99999,
            'stats_opened' => 99999,
            'stats_clicked' => 99999,
        ]);

        $sentEmail = 'kpi-sent@tcl.test';
        $preparedEmail = 'kpi-prepared@tcl.test';

        foreach ([[$sent, $sentEmail], [$prepared, $preparedEmail]] as [$run, $email]) {
            CampaignRecipient::create([
                'campaign_run_id' => $run->id,
                'contact_id' => $this->makeContact($email)->id,
                'status' => 'sent',
                'provider_message_id' => "kpi-{$run->id}",
            ]);
        }

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertOk();

        $this->assertSame($sent->id, $response->viewData('latestRun')->id);
        $this->assertSame([$sent->id], $response->viewData('runs')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('recipientsTotal'));

        $recipientEmails = $response->viewData('recipients')
            ->getCollection()
            ->pluck('contact.email')
            ->all();
        $this->assertSame([$sentEmail], $recipientEmails);
        $this->assertNotContains($preparedEmail, $recipientEmails);

        $stats = $response->viewData('stats');
        $this->assertSame(10, $stats['total_sent']);
        $this->assertSame(6, $stats['total_delivered']);
        $this->assertSame(3, $stats['total_opened']);
        $this->assertSame(2, $stats['total_clicked']);
        $this->assertSame(50.0, $stats['open_rate']);
        $this->assertSame(33.33, $stats['click_rate']);

        $tabs = collect($response->viewData('viewConfig')['tabs']);
        $this->assertSame(1, $tabs->firstWhere('key', 'historique')['count']);
        $this->assertSame(1, $tabs->firstWhere('key', 'destinataires')['count']);
        $response->assertSee('50.0%');
        $response->assertSee('33.3%');
    }

    public function test_zero_delivery_rates_render_as_em_dash(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'zero-kpi',
            'run_at' => now(),
            'status' => 'sent',
            'stats_sent' => 0,
            'stats_delivered' => 0,
            'stats_opened' => 0,
            'stats_clicked' => 0,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertNull($stats['open_rate']);
        $this->assertNull($stats['click_rate']);

        $tiles = collect($response->viewData('viewConfig')['tiles']);
        $this->assertSame('—', $tiles->firstWhere('caption', "Taux d'ouverture")['value']);
        $response->assertSee('—');
    }

    public function test_completed_all_delivered_run_keeps_summary_and_history_rates_consistent_with_scope_labels(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'completed-all-delivered-kpi',
            'run_at' => now(),
            'status' => 'sent',
            'stats_sent' => 4,
            'stats_delivered' => 4,
            'stats_opened' => 3,
            'stats_clicked' => 2,
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}");

        $response->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(4, $stats['total_sent']);
        $this->assertSame(4, $stats['total_delivered']);
        $this->assertSame(3, $stats['total_opened']);
        $this->assertSame(2, $stats['total_clicked']);
        $this->assertSame(75.0, $stats['open_rate']);
        $this->assertSame(50.0, $stats['click_rate']);

        $runKpis = $response->viewData('runs')->first()->kpis();
        $this->assertSame(75.0, $runKpis['open_rate']);
        $this->assertSame(50.0, $runKpis['click_rate']);

        $response->assertSee('Vue cumulee - duree de vie de la campagne, executions envoyees uniquement.');
        $response->assertSee('Derniere execution envoyee - statistiques de ce run uniquement.');
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '75.0%'));
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '50.0%'));
    }
}
