<?php

namespace Tests\Feature\Backend;

use App\Mail\CampaignMailable;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\Campaign\CampaignsClient;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config(['mail.smtp_mode' => 'mailpit']);
        $this->superadmin = User::factory()->create([
            'name' => 'Admin Preview',
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

    #[DataProvider('invalidRecipients')]
    public function test_test_send_validates_the_temporary_recipient(array $payload): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign(['delivery_channel' => 'zoho']);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.testSend', $campaign), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_email');

        Mail::assertNothingSent();
        $this->assertNoOperationalRows();
    }

    public static function invalidRecipients(): array
    {
        return [
            'missing' => [[]],
            'invalid' => [['recipient_email' => 'not-an-email']],
        ];
    }

    public function test_commercial_cannot_send_a_campaign_test(): void
    {
        $campaign = $this->makeCampaign(['delivery_channel' => 'zoho']);

        $this->actingAs($this->commercial)
            ->post(route('admin.campaigns.testSend', $campaign->id), ['recipient_email' => 'commercial@example.test'])
            ->assertForbidden();
    }

    public function test_test_send_uses_smtp_preview_even_when_global_driver_is_not_local(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign(['delivery_channel' => 'zoho']);
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
            ->postJson(route('admin.campaigns.testSend', $campaign->id), ['recipient_email' => 'override@example.test'])
            ->assertOk()
            ->assertJsonPath('recipient', 'override@example.test')
            ->assertJsonPath('sender.name', 'TCL France')
            ->assertJsonPath('sender.email', 'noreply@tcl.test')
            ->assertJsonPath('mode', 'mailpit')
            ->assertJsonPath('transport', 'Mailpit');

        Mail::assertSent(CampaignMailable::class, function (CampaignMailable $mail): bool {
            $html = $mail->render();
            $headers = $mail->headers()->text;

            return $mail->hasTo('override@example.test')
                && $mail->hasFrom('noreply@tcl.test', 'TCL France')
                && $mail->hasReplyTo('reply@tcl.test', 'TCL France')
                && str_contains($html, 'Bonjour Admin Preview')
                && ! str_contains($html, 'Se désabonner')
                && ! str_contains($html, 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels.')
                && ! str_contains($html, 'You are receiving this email because you are part of our professional contact list.')
                && ! str_contains($html, '/u/0')
                && ! str_contains($html, '<a ')
                && str_contains($html, '/track/open/')
                && ! empty($headers['List-Unsubscribe'])
                && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click';
        });
        $this->assertNoOperationalRows();
    }

    public function test_test_send_reports_a_missing_sequence_template_without_creating_rows(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign([
            'template_id' => null,
            'schedule_type' => 'sequence',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.testSend', $campaign->id), ['recipient_email' => 'admin-test@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('message', html_entity_decode('Ajoutez une premi&egrave;re &eacute;tape avec un mod&egrave;le avant l\'envoi test.'));

        Mail::assertNothingSent();
        $this->assertNoOperationalRows();
    }

    public function test_test_send_delivers_one_real_campaign_mailable_to_the_custom_recipient(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.testSend', $campaign->id), ['recipient_email' => 'custom-preview@example.test'])
            ->assertOk()
            ->assertJsonPath('recipient', 'custom-preview@example.test');

        Mail::assertSent(CampaignMailable::class, fn (CampaignMailable $mail) => $mail->hasTo('custom-preview@example.test'));
        Mail::assertSent(CampaignMailable::class, 1);
        $this->assertNoOperationalRows();
    }

    public function test_sequence_preview_uses_the_first_step_and_creates_no_operational_rows(): void
    {
        Mail::fake();
        $sequence = Sequence::create([
            'name' => 'Séquence preview',
            'is_active' => true,
            'stop_on_reply' => true,
        ]);
        $template = CampaignTemplate::create([
            'name' => 'Première étape',
            'subject' => 'Étape {{contact.name}}',
            'html_content' => '<p>Étape pour {{contact.name}} — {{contact.email}}</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $template->id,
            'subject' => 'Premier contact avec {{contact.name}}',
        ]);
        $campaign = $this->makeCampaign([
            'template_id' => null,
            'schedule_type' => 'sequence',
            'sequence_id' => $sequence->id,
            'delivery_channel' => 'zoho',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.testSend', $campaign), ['recipient_email' => 'sequence-preview@example.test'])
            ->assertOk()
            ->assertJsonPath('recipient', 'sequence-preview@example.test')
            ->assertJsonPath('transport', 'Mailpit');

        Mail::assertSent(SequenceStepMailable::class, function (SequenceStepMailable $mail): bool {
            $html = $mail->render();
            $headers = $mail->headers()->text;

            return $mail->hasTo('sequence-preview@example.test')
                && $mail->hasFrom('noreply@tcl.test', 'TCL France')
                && $mail->hasReplyTo('reply@tcl.test', 'TCL France')
                && $mail->hasSubject('Premier contact avec Admin Preview')
                && str_contains($html, 'Étape pour Admin Preview — sequence-preview@example.test')
                && ! str_contains($html, 'Se désabonner')
                && ! str_contains($html, 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels.')
                && ! str_contains($html, 'You are receiving this email because you are part of our professional contact list.')
                && ! str_contains($html, '/u/0')
                && ! str_contains($html, '<a ')
                && str_contains($html, '/track/open/')
                && ! empty($headers['List-Unsubscribe'])
                && ($headers['List-Unsubscribe-Post'] ?? null) === 'List-Unsubscribe=One-Click';
        });
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertNoOperationalRows();
    }

    public function test_sender_identity_mode_blocks_an_incomplete_campaign_identity(): void
    {
        Mail::fake();
        config(['mail.smtp_mode' => 'sender_identity']);
        $campaign = $this->makeCampaign();

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.testSend', $campaign), ['recipient_email' => 'blocked@example.test'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Configuration SMTP incomplète pour cette identité.');

        Mail::assertNothingSent();
        $this->assertNoOperationalRows();
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
            'reply_to' => 'reply@tcl.test',
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

    private function assertNoOperationalRows(): void
    {
        foreach ([
            'campaign_runs',
            'campaign_recipients',
            'email_tracking_events',
            'sequence_enrollments',
            'sequence_step_sends',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
