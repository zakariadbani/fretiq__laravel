<?php

namespace Tests\Feature\Backend;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Campaign\CampaignsClient;
use App\Services\Campaign\LocalCampaignsDriver;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CampaignTestSendTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->app->bind(CampaignsClient::class, fn () => new LocalCampaignsDriver);
        $this->superadmin = User::factory()->create([
            'email' => 'admin-test@example.test',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    public function test_commercial_cannot_send_a_campaign_test(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->commercial)
            ->post(route('admin.campaigns.testSend', $campaign->id))
            ->assertForbidden();
    }

    public function test_test_send_is_blocked_when_the_resolved_driver_is_not_local(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign();
        $this->app->bind(CampaignsClient::class, fn () => new class implements CampaignsClient
        {
            public function send(CampaignRecipient $recipient, Campaign $campaign, CampaignRun $run, string $trackingToken, string $unsubscribeUrl): string
            {
                throw new \LogicException('The test-send action must never call a non-local driver.');
            }

            public function driverName(): string
            {
                return 'zoho';
            }

            public function supportsBounceFeedback(Campaign $campaign): bool
            {
                return false;
            }
        });

        $this->actingAs($this->superadmin)
            ->post(route('admin.campaigns.testSend', $campaign->id))
            ->assertRedirect(route('admin.campaigns.view', $campaign->id))
            ->assertSessionHas('error', 'L\'envoi test est disponible uniquement avec le pilote local.');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_recipients', 0);
    }

    public function test_test_send_reports_a_missing_sequence_template_without_creating_rows(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign([
            'template_id' => null,
            'schedule_type' => 'sequence',
        ]);

        $this->actingAs($this->superadmin)
            ->post(route('admin.campaigns.testSend', $campaign->id))
            ->assertRedirect(route('admin.campaigns.view', $campaign->id))
            ->assertSessionHas('error', html_entity_decode('Ajoutez une premi&egrave;re &eacute;tape avec un mod&egrave;le avant l\'envoi test.'));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_recipients', 0);
    }

    public function test_local_test_send_delivers_one_real_mailable_without_campaign_rows(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign();

        $this->actingAs($this->superadmin)
            ->post(route('admin.campaigns.testSend', $campaign->id))
            ->assertRedirect(route('admin.campaigns.view', $campaign->id))
            ->assertSessionHas('success', html_entity_decode('Email test envoy&eacute; &agrave; admin-test@example.test.'));

        Mail::assertSent(CampaignMailable::class, fn (CampaignMailable $mail) => $mail->hasTo('admin-test@example.test'));
        Mail::assertSent(CampaignMailable::class, 1);
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_recipients', 0);
    }

    private function makeCampaign(array $overrides = []): Campaign
    {
        $segment = Segment::create(['name' => 'Audience test', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Test local',
            'subject' => 'Bonjour {{contact.name}}',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name' => 'Campagne test local',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'scheduled_at' => now()->addHour(),
            'timezone' => 'Europe/Paris',
        ], $overrides));
    }
}
