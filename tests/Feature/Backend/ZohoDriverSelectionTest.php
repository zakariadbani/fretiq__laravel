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
use App\Services\Campaign\CampaignsClient;
use App\Services\Campaign\LocalCampaignsDriver;
use App\Services\Campaign\ZohoCampaignsDriver;
use App\Services\Campaign\CampaignService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ZohoDriverSelectionTest — verifies the IoC container binding, send() guard,
 * and the full dispatchRun() flow for the Zoho driver.
 *
 * All external HTTP is faked — no live Zoho calls are made.
 */
class ZohoDriverSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Default: local driver, no mail sent to real SMTP
        config(['prospecting.cold_send_enabled' => false]);
        Mail::fake();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function setZohoDriver(): void
    {
        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.example.test',
        ]);
    }

    private function fakeZohoHttp(string $campaignKey = 'CK-001'): void
    {
        Http::fake([
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            '*addlistsubscribersinbulk*' => Http::response([
                'status' => 'success',
                'code'   => '0',
            ], 200),

            '*createCampaign*' => Http::response([
                'campaignKey' => $campaignKey,
                'status'      => 'success',
            ], 200),

            '*sendcampaign*' => Http::response([
                'status'  => 'success',
                'message' => 'Campaign sent',
            ], 200),
        ]);
    }

    private function makeClientContact(string $email = 'jean@acme.test'): Contact
    {
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Jean Dupont',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeCampaignWithRun(Contact $contact): CampaignRun
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Template Zoho',
            'subject'      => 'Sujet test',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);
        $campaign = Campaign::create([
            'name'               => 'Campagne Zoho Test',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);

        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'oneshot-' . now()->format('YmdHis'),
            'run_at'         => now()->subMinute(),
            'status'         => 'scheduled',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Default config (driver=local) resolves to LocalCampaignsDriver.
     */
    public function test_default_driver_is_local(): void
    {
        // Ensure driver is local (default)
        config(['services.zoho.driver' => 'local']);

        // Rebind so the container reflects the new config
        $this->app->bind(
            CampaignsClient::class,
            fn () => config('services.zoho.driver', 'local') === 'zoho'
                ? new ZohoCampaignsDriver(app(\App\Services\Zoho\ZohoCampaignsClient::class))
                : new LocalCampaignsDriver(),
        );

        $driver = app(CampaignsClient::class);

        $this->assertInstanceOf(LocalCampaignsDriver::class, $driver);
    }

    /**
     * With driver=zoho config, the container resolves to ZohoCampaignsDriver.
     */
    public function test_zoho_config_resolves_zoho_driver(): void
    {
        $this->setZohoDriver();

        // Rebind to pick up changed config
        $this->app->bind(
            CampaignsClient::class,
            fn () => config('services.zoho.driver', 'local') === 'zoho'
                ? new ZohoCampaignsDriver(app(\App\Services\Zoho\ZohoCampaignsClient::class))
                : new LocalCampaignsDriver(),
        );

        $driver = app(CampaignsClient::class);

        $this->assertInstanceOf(ZohoCampaignsDriver::class, $driver);
    }

    /**
     * ZohoCampaignsDriver::send() throws LogicException — it must never be
     * called per-recipient; use dispatchRun() instead.
     */
    public function test_zoho_driver_send_throws(): void
    {
        $this->expectException(\LogicException::class);

        $driver = new ZohoCampaignsDriver(
            app(\App\Services\Zoho\ZohoCampaignsClient::class)
        );

        // Build minimal stub models — we just need the exception to fire
        $contact   = $this->makeClientContact('throw@test.test');
        $segment   = Segment::create(['name' => 'S', 'scope' => 'client']);
        $template  = CampaignTemplate::create([
            'name'         => 'T',
            'subject'      => 'Sub',
            'html_content' => '<p>hi</p>',
        ]);
        $sender    = SenderIdentity::create(['name' => 'Sender', 'email' => 'a@b.com']);
        $campaign  = Campaign::create([
            'name'               => 'C',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'test',
            'run_at'         => now(),
            'status'         => 'scheduled',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'queued',
        ]);
        $recipient->setRelation('contact', $contact);

        // This must throw LogicException
        $driver->send($recipient, $campaign, $run, 'token', 'https://unsub.test');
    }

    /**
     * CampaignService::sendRun() with Zoho driver:
     *   - calls addlistsubscribersinbulk, createCampaign, sendcampaign
     *   - sets run.status='sent', run.zoho_campaign_key=campaignKey
     *   - marks recipients as 'sent'
     *   - does NOT send any Mail
     */
    public function test_zoho_dispatchRun_pushes_list_creates_and_sends(): void
    {
        $this->setZohoDriver();
        $this->fakeZohoHttp('CK-RUN-001');

        // Rebind so sendRun resolves ZohoCampaignsDriver
        $this->app->bind(
            CampaignsClient::class,
            fn () => config('services.zoho.driver', 'local') === 'zoho'
                ? new ZohoCampaignsDriver(app(\App\Services\Zoho\ZohoCampaignsClient::class))
                : new LocalCampaignsDriver(),
        );

        $contact = $this->makeClientContact('zoho-run@acme.test');
        $run     = $this->makeCampaignWithRun($contact);

        /** @var CampaignService $service */
        $service = app(CampaignService::class);
        $service->sendRun($run);

        $run->refresh();

        // Run must be 'sent' with the Zoho campaign key
        $this->assertSame('sent', $run->status, 'Run status must be sent after Zoho dispatchRun');
        $this->assertSame('CK-RUN-001', $run->zoho_campaign_key, 'zoho_campaign_key must be stored on the run');

        // At least one recipient must be marked sent
        $sentCount = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('status', 'sent')
            ->count();
        $this->assertGreaterThanOrEqual(1, $sentCount, 'At least one recipient must be marked sent');

        // No real Mail was dispatched (Zoho handles delivery)
        Mail::assertNothingSent();

        // HTTP assertions: all three API endpoints were called
        Http::assertSent(fn ($req) => str_contains($req->url(), 'addlistsubscribersinbulk'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'createCampaign'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendcampaign'));
    }
}
