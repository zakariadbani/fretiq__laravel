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
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Services\Campaign\CampaignDeliveryResolver;
use App\Services\Campaign\CampaignsClient;
use App\Services\Campaign\LocalCampaignsDriver;
use App\Services\Campaign\MailjetCampaignsDriver;
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
            'services.zoho.campaigns.topic_id' => 'fake-topic-id',
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

            '*json/listsubscribe*' => Http::response([
                'status' => 'success',
                'code'   => '0',
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
            'company_id' => $co->id,
            'email' => $email,
            'name' => 'Jean Dupont',
            'source' => 'manual',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ]);
    }

    private function makeCampaignWithRun(
        Contact $contact,
        string $senderEmail = 'noreply@tcl.test',
        string $campaignName = 'Campagne Zoho Test',
    ): CampaignRun
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Template Zoho',
            'subject'      => 'Sujet test',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => $senderEmail,
        ]);
        $campaign = Campaign::create([
            'name'               => $campaignName,
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
                : app(LocalCampaignsDriver::class),
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
                : app(LocalCampaignsDriver::class),
        );

        $driver = app(CampaignsClient::class);

        $this->assertInstanceOf(ZohoCampaignsDriver::class, $driver);
    }

    /**
     * CampaignDeliveryResolver returns MailjetCampaignsDriver for an explicit
     * delivery_channel='mailjet' campaign.
     */
    public function test_resolver_returns_mailjet_driver_for_mailjet_channel(): void
    {
        $contact = $this->makeClientContact('mailjet-resolver@acme.test');
        $run = $this->makeCampaignWithRun($contact);
        $run->campaign()->update(['delivery_channel' => 'mailjet']);

        $driver = app(CampaignDeliveryResolver::class)->resolve($run->campaign()->first());

        $this->assertInstanceOf(MailjetCampaignsDriver::class, $driver);
    }

    /**
     * A campaign with no explicit delivery_channel (legacy null) keeps
     * following the global driver config, unaffected by the mailjet branch.
     */
    public function test_resolver_null_channel_still_follows_global_driver_config(): void
    {
        config(['services.zoho.driver' => 'local']);

        $contact = $this->makeClientContact('null-channel-resolver@acme.test');
        $run = $this->makeCampaignWithRun($contact);
        $this->assertNull($run->campaign()->first()->delivery_channel);

        $driver = app(CampaignDeliveryResolver::class)->resolve($run->campaign()->first());

        $this->assertInstanceOf(LocalCampaignsDriver::class, $driver);
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
     *   - calls listsubscribe, createCampaign, sendcampaign
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
                : app(LocalCampaignsDriver::class),
        );

        $contact = $this->makeClientContact('zoho-run@acme.test');
        $run     = $this->makeCampaignWithRun($contact);
        $run->campaign()->update(['subject' => 'Bonjour {{company.name}}']);

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
        Http::assertSent(fn ($req) => str_contains($req->url(), '/json/listsubscribe'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'createCampaign')
            && $req['campaignname'] === "Fretiq Campagne Zoho Test - C{$run->campaign_id} - R{$run->id} - " . now()->format('Ymd')
            && $req['subject'] === 'Bonjour $[UD:COMPANY_NAME||]$'
            && ! str_contains($req['subject'], '$[COMPANYNAME|'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendcampaign'));
    }

    /**
     * Regression: CampaignService::sendViaZoho() used to write the single
     * shared Zoho campaign_key into provider_message_id for every recipient
     * row — a globally-unique column — which collides as soon as a Zoho
     * one-shot run has 2+ recipients. Every prior test here only ever sent
     * to collect([$contact]).
     */
    public function test_zoho_one_shot_run_with_multiple_recipients_marks_all_sent_without_collision(): void
    {
        $this->setZohoDriver();
        $this->fakeZohoHttp('CK-RUN-MULTI');

        $this->app->bind(
            CampaignsClient::class,
            fn () => config('services.zoho.driver', 'local') === 'zoho'
                ? new ZohoCampaignsDriver(app(\App\Services\Zoho\ZohoCampaignsClient::class))
                : app(LocalCampaignsDriver::class),
        );

        $contactA = $this->makeClientContact('zoho-multi-a@acme.test');
        $this->makeClientContact('zoho-multi-b@acme.test');
        $run = $this->makeCampaignWithRun($contactA);

        /** @var CampaignService $service */
        $service = app(CampaignService::class);
        $service->sendRun($run);

        $run->refresh();
        $this->assertSame('sent', $run->status);
        $this->assertSame(2, $run->stats_sent);
        $this->assertSame(2, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count());
        Mail::assertNothingSent();
    }

    public function test_sequence_wave_campaign_name_includes_compact_wave_and_step_token(): void
    {
        config([
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'app.url' => 'https://fretiq.example.test',
        ]);

        $contact = $this->makeClientContact('wave-name@acme.test');
        $sequence = Sequence::create(['name' => 'Wave naming', 'is_active' => true]);
        $createdNames = [];

        $zohoClient = \Mockery::mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $zohoClient->shouldNotReceive('addListSubscribers');
        $zohoClient->shouldReceive('createCampaign')
            ->times(3)
            ->withArgs(function ($name) use (&$createdNames): bool {
                $createdNames[] = $name;

                return true;
            })
            ->andReturn(
                ['campaignKey' => 'CK-WAVE-2-STEP-3'],
                ['campaignKey' => 'CK-WAVE-1-STEP-1'],
                ['campaignKey' => 'CK-LEGACY'],
            );
        $zohoClient->shouldReceive('sendCampaign')->times(3)->andReturn([]);
        $driver = new ZohoCampaignsDriver($zohoClient);

        foreach ([
            [2, 3, 'sequence-wave-000002-step-003'],
            [1, 1, 'sequence-wave-000001'],
            [3, 2, 'sequence-wave-legacy-000003'],
        ] as [$wave, $stepNo, $occurrenceKey]) {
            $run = $this->makeCampaignWithRun($contact);
            $step = SequenceStep::create([
                'sequence_id' => $sequence->id,
                'step_no' => $stepNo,
                'delay_days' => 0,
                'template_id' => $run->campaign->template_id,
            ]);
            $run->update([
                'sequence_step_id' => $step->id,
                'occurrence_key' => $occurrenceKey,
                'zoho_list_key' => 'verified-list-key',
            ]);

            $driver->dispatchRun($run->fresh(), collect([$contact]));

            $suffix = " - C{$run->campaign_id} - R{$run->id} - " . now()->format('Ymd');
            $expectedToken = str_contains($occurrenceKey, 'legacy') ? '' : " - WV{$wave}-ST{$stepNo}";
            $this->assertSame("Fretiq Campagne Zoho Test{$expectedToken}{$suffix}", array_pop($createdNames));
        }
    }

    public function test_dispatch_run_caps_campaign_name_while_preserving_traceable_suffix(): void
    {
        config([
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'app.url' => 'https://fretiq.example.test',
        ]);

        $contact = $this->makeClientContact('long-name@acme.test');
        $run = $this->makeCampaignWithRun(
            $contact,
            campaignName: str_repeat('Long   Name ', 20),
        );
        $sequence = Sequence::create(['name' => 'Long wave naming', 'is_active' => true]);
        $step = SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 3,
            'delay_days' => 0,
            'template_id' => $run->campaign->template_id,
        ]);
        $run->update([
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000002',
            'zoho_list_key' => 'verified-list-key',
        ]);
        $suffix = " - WV2-ST3 - C{$run->campaign_id} - R{$run->id} - " . now()->format('Ymd');
        $createdName = null;

        $zohoClient = \Mockery::mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $zohoClient->shouldNotReceive('addListSubscribers');
        $zohoClient->shouldReceive('createCampaign')
            ->once()
            ->withArgs(function ($name) use (&$createdName): bool {
                $createdName = $name;

                return true;
            })
            ->andReturn(['campaignKey' => 'CK-LONG-NAME']);
        $zohoClient->shouldReceive('sendCampaign')->once()->andReturn([]);

        (new ZohoCampaignsDriver($zohoClient))->dispatchRun($run, collect([$contact]));

        $this->assertLessThanOrEqual(191, mb_strlen($createdName));
        $this->assertStringEndsWith($suffix, $createdName);
        $this->assertDoesNotMatchRegularExpression('/\s{2,}/', $createdName);
    }

    /**
     * Sender precedence: dispatchRun() must resolve `from` using the campaign's
     * own senderIdentity email FIRST — services.zoho.default_from_email is only
     * a fallback for campaigns with no sender identity at all (see the from-email
     * resolution comment in ZohoCampaignsDriver::dispatchRun()).
     */
    public function test_dispatch_run_prefers_sender_identity_over_config_default_from_email(): void
    {
        config([
            'services.zoho.campaigns.list_key' => 'verified-list-key',
            'services.zoho.default_from_email' => 'default@example.com',
            'app.url' => 'https://fretiq.example.test',
        ]);

        $contact = $this->makeClientContact('identity-precedence@acme.test');
        $run     = $this->makeCampaignWithRun($contact, 'identity@example.com');

        $zohoClientMock = \Mockery::mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $zohoClientMock->shouldReceive('addListSubscribers')->once()->andReturn([]);
        $zohoClientMock->shouldReceive('createCampaign')
            ->once()
            ->withArgs(fn ($name, $subject, $fromEmail, $fromName, $listKey, $contentUrl) =>
                $fromEmail === 'identity@example.com' && $fromName === 'TCL France')
            ->andReturn(['campaignKey' => 'CK-IDENTITY']);
        $zohoClientMock->shouldReceive('sendCampaign')->once()->andReturn([]);

        $driver  = new ZohoCampaignsDriver($zohoClientMock);
        $summary = $driver->dispatchRun($run, collect([$contact]));

        $this->assertSame('CK-IDENTITY', $summary['campaign_key']);
    }

    /**
     * Fail-closed preflight (plan item 2): with append_unsubscribe_fallback
     * disabled and a builder-authored template that carries no unsubscribe
     * link of its own, dispatchRun() must refuse the ENTIRE send — before any
     * Zoho HTTP call — rather than silently mailing a list with zero opt-out.
     */
    public function test_dispatch_run_refuses_when_fallback_disabled_and_template_has_no_unsubscribe_link(): void
    {
        config([
            'services.zoho.campaigns.list_key'          => 'verified-list-key',
            'services.zoho.append_unsubscribe_fallback'  => false,
            'app.url' => 'https://fretiq.example.test',
        ]);

        $contact = $this->makeClientContact('no-unsub@acme.test');
        // makeCampaignWithRun() seeds html_content as '<p>Bonjour</p>' — no
        // unsubscribe link, exactly the builder-authored shape this guards.
        $run = $this->makeCampaignWithRun($contact);

        $zohoClientMock = \Mockery::mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $zohoClientMock->shouldNotReceive('addListSubscribers');
        $zohoClientMock->shouldNotReceive('createCampaign');
        $zohoClientMock->shouldNotReceive('sendCampaign');

        $driver = new ZohoCampaignsDriver($zohoClientMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/lien de désabonnement/');

        $driver->dispatchRun($run, collect([$contact]));
    }
}
