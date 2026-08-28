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
use App\Services\Campaign\MailjetCampaignsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MailjetDriverTest — verifies MailjetCampaignsDriver::send() builds the
 * expected Send API v3.1 payload and handles success/failure responses.
 * All HTTP is faked — no live Mailjet calls are made.
 */
class MailjetDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailjet.key' => 'mj-key',
            'services.mailjet.secret' => 'mj-secret',
            'services.mailjet.api_url' => 'https://api.mailjet.com',
            'services.mailjet.sandbox' => false,
            'app.url' => 'https://fretiq.example.test',
        ]);
    }

    private function makeRecipient(string $email = 'jean@acme.test'): CampaignRecipient
    {
        $company = Company::create([
            'name' => 'Acme',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'Jean Dupont',
            'source' => 'manual',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ]);
        $segment = Segment::create(['name' => 'Mailjet segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Mailjet template',
            'subject' => 'Bonjour {{contact.name}}',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'TCL France', 'email' => 'noreply@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Campagne Mailjet Test',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'delivery_channel' => 'mailjet',
            'schedule_type' => 'one_shot',
            'scheduled_at' => now(),
            'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'mailjet-'.uniqid(),
            'run_at' => now(),
            'status' => 'scheduled',
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        $recipient->setRelation('contact', $contact);
        $recipient->setRelation('run', $run);

        return $recipient;
    }

    public function test_send_posts_expected_payload_and_returns_message_id(): void
    {
        Http::fake([
            '*api.mailjet.com/v3.1/send*' => Http::response([
                'Messages' => [[
                    'Status' => 'success',
                    'To' => [['Email' => 'jean@acme.test', 'MessageID' => 987654321]],
                ]],
            ], 200),
        ]);

        $recipient = $this->makeRecipient();
        $run = $recipient->run;
        $campaign = $run->campaign;

        $driver = app(MailjetCampaignsDriver::class);
        $messageId = $driver->send(
            $recipient,
            $campaign,
            $run,
            'tracking-token-123',
            'https://fretiq.example.test/unsubscribe/signed',
        );

        $this->assertSame('987654321', $messageId);

        Http::assertSent(function ($request) use ($run) {
            $authHeader = $request->header('Authorization')[0] ?? '';
            $this->assertStringStartsWith('Basic ', $authHeader);
            $this->assertSame('mj-key:mj-secret', base64_decode(substr($authHeader, 6)));

            $data = $request->data();
            $message = $data['Messages'][0];

            $this->assertSame('noreply@tcl.test', $message['From']['Email']);
            $this->assertSame('TCL France', $message['From']['Name']);
            $this->assertSame('jean@acme.test', $message['To'][0]['Email']);
            $this->assertSame('Bonjour Jean Dupont', $message['Subject']);
            $this->assertStringContainsString('/track/open/tracking-token-123', $message['HTMLPart']);
            $this->assertStringStartsWith('campaign-recipient-', $message['CustomID']);
            $this->assertSame('fretiq-run-' . $run->id, $message['CustomCampaign']);
            $this->assertFalse($message['DeduplicateCampaign']);
            $this->assertMatchesRegularExpression('/^<https?:\/\/.+one-click\?.*signature=.+>$/', $message['Headers']['List-Unsubscribe']);
            $this->assertSame('List-Unsubscribe=One-Click', $message['Headers']['List-Unsubscribe-Post']);
            $this->assertFalse($data['SandboxMode']);

            return true;
        });
    }

    public function test_send_propagates_sandbox_mode(): void
    {
        config(['services.mailjet.sandbox' => true]);

        Http::fake([
            '*api.mailjet.com/v3.1/send*' => Http::response([
                'Messages' => [[
                    'Status' => 'success',
                    'To' => [['Email' => 'jean@acme.test', 'MessageID' => 111]],
                ]],
            ], 200),
        ]);

        $recipient = $this->makeRecipient();
        $run = $recipient->run;

        app(MailjetCampaignsDriver::class)->send(
            $recipient,
            $run->campaign,
            $run,
            'token',
            'https://fretiq.example.test/unsubscribe/signed',
        );

        Http::assertSent(function ($request) {
            $this->assertTrue($request->data()['SandboxMode']);

            return true;
        });
    }

    public function test_send_throws_when_mailjet_reports_non_success_status(): void
    {
        Http::fake([
            '*api.mailjet.com/v3.1/send*' => Http::response([
                'Messages' => [[
                    'Status' => 'error',
                    'Errors' => [['ErrorMessage' => 'Invalid recipient']],
                ]],
            ], 200),
        ]);

        $recipient = $this->makeRecipient();
        $run = $recipient->run;

        $this->expectException(\RuntimeException::class);

        app(MailjetCampaignsDriver::class)->send(
            $recipient,
            $run->campaign,
            $run,
            'token',
            'https://fretiq.example.test/unsubscribe/signed',
        );
    }

    public function test_send_throws_on_non_2xx_response(): void
    {
        Http::fake([
            '*api.mailjet.com/v3.1/send*' => Http::response(['ErrorMessage' => 'Unauthorized'], 401),
        ]);

        $recipient = $this->makeRecipient();
        $run = $recipient->run;

        $this->expectException(\RuntimeException::class);

        app(MailjetCampaignsDriver::class)->send(
            $recipient,
            $run->campaign,
            $run,
            'token',
            'https://fretiq.example.test/unsubscribe/signed',
        );
    }
}
