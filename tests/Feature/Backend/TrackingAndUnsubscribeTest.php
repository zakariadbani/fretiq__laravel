<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Mail\CampaignMailable;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailTrackingEvent;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Tests for the public tracking pixel, click-through redirect, and
 * unsubscribe endpoints.
 *
 * Routes under test (public — no auth required):
 *   GET  /track/open/{token}   → TrackingController::open
 *   GET  /track/click/{token}  → TrackingController::click  (signed)
 *   GET  /u/{contact}          → UnsubscribeController::show  (signed)
 *   POST /u/{contact}          → UnsubscribeController::show  (signed, RFC 8058)
 */
class TrackingAndUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Mail::fake();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

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

    /**
     * Build a minimal CampaignRecipient attached to a real CampaignRun.
     */
    private function makeCampaignRecipient(Contact $contact): CampaignRecipient
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'T',
            'subject'      => 'S',
            'html_content' => '<p>Hi</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL',
            'email' => 'noreply@tcl.test',
        ]);
        $campaign = Campaign::create([
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
            'occurrence_key' => 'oneshot-test',
            'run_at'         => now(),
            'status'         => 'sent',
        ]);

        return CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);
    }

    /**
     * Generate a 64-char hex token suitable for EmailTrackingEvent.
     */
    private function makeToken(): string
    {
        return str_pad(bin2hex(random_bytes(16)), 64, '0');
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * GET /track/open/{token} must:
     *   - return HTTP 200
     *   - respond with Content-Type: image/gif
     *   - increment email_tracking_events.human_open_count
     *   - set campaign_recipients.opened_at on the first human open
     */
    public function test_tracking_pixel_records_open(): void
    {
        $contact   = $this->makeClientContact();
        $recipient = $this->makeCampaignRecipient($contact);
        $token     = $this->makeToken();

        $event = EmailTrackingEvent::create([
            'trackable_type'     => CampaignRecipient::class,
            'trackable_id'       => $recipient->id,
            'token'              => $token,
            'event'              => 'sent',
            'human_open_count'   => 0,
            'machine_open_count' => 0,
        ]);

        // Use a non-bot User-Agent so it is classified as human
        $response = $this->get(
            '/track/open/' . $token,
            ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
        );

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/gif');

        $event->refresh();
        $this->assertGreaterThanOrEqual(1, $event->human_open_count, 'human_open_count must be incremented');

        // EmailTrackingService sets recipient.opened_at on first unique human open
        $recipient->refresh();
        $this->assertNotNull($recipient->opened_at, 'recipient.opened_at must be set after first human open');
    }

    /**
     * An unknown / invalid token must still return 200 with a GIF —
     * the controller swallows all errors to never leak information to spam filters.
     */
    public function test_tracking_pixel_with_invalid_token_returns_gif(): void
    {
        $invalidToken = str_repeat('f', 64); // 64 hex chars — will not match any event

        $response = $this->get('/track/open/' . $invalidToken);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/gif');
    }

    /**
     * GET /track/click/{token}?url=... must:
     *   - 302-redirect to the destination
     *   - set campaign_recipients.clicked_at (first click) and status='clicked'
     *   - also imply opened_at (a click proves the message was opened)
     */
    public function test_tracking_click_records_and_redirects(): void
    {
        $contact   = $this->makeClientContact('click@acme.test');
        $recipient = $this->makeCampaignRecipient($contact);
        $token     = $this->makeToken();

        EmailTrackingEvent::create([
            'trackable_type'     => CampaignRecipient::class,
            'trackable_id'       => $recipient->id,
            'token'              => $token,
            'event'              => 'sent',
            'human_open_count'   => 0,
            'machine_open_count' => 0,
        ]);

        $destination = 'https://example.test/offer?a=1&b=2';
        // Relative signature — must match the production 'signed:relative'
        // route middleware / RendersTrackedHtml generation.
        $signedUrl   = URL::signedRoute('track.click', ['token' => $token, 'url' => $destination], null, false);

        $response = $this->get(
            $signedUrl,
            ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
        );

        $response->assertRedirect($destination);

        $recipient->refresh();
        $this->assertSame('clicked', $recipient->status);
        $this->assertNotNull($recipient->clicked_at, 'clicked_at must be set after a human click');
        $this->assertNotNull($recipient->opened_at, 'a click must also imply an open');
    }

    /**
     * A request whose 'url' query param was swapped after signing must be
     * rejected by the 'signed:relative' middleware — the click route must
     * never become an open redirect. Rejection redirects to the app root
     * (never to the attacker-controlled 'url' param, never a bare 403 for a
     * human recipient — see Handler::register()). The recipient must be
     * left untouched.
     */
    public function test_tracking_click_rejects_tampered_url_param(): void
    {
        $contact   = $this->makeClientContact('click-tamper@acme.test');
        $recipient = $this->makeCampaignRecipient($contact);
        $token     = $this->makeToken();

        EmailTrackingEvent::create([
            'trackable_type'     => CampaignRecipient::class,
            'trackable_id'       => $recipient->id,
            'token'              => $token,
            'event'              => 'sent',
            'human_open_count'   => 0,
            'machine_open_count' => 0,
        ]);

        $signedUrl = URL::signedRoute('track.click', ['token' => $token, 'url' => 'https://example.test/legit'], null, false);

        // Swap the destination but keep the original signature — simulates an
        // attacker rewriting a legitimate signed link without the app key.
        $parts = parse_url($signedUrl);
        parse_str($parts['query'], $query);
        $query['url'] = 'https://evil.test/phish';
        $tamperedUrl  = $parts['path'] . '?' . http_build_query($query);

        $response = $this->get($tamperedUrl);

        $response->assertRedirect(url('/'));

        $recipient->refresh();
        $this->assertNull($recipient->clicked_at, 'A tampered click must not be recorded');
    }

    /**
     * GET /u/{contact} with a valid signed URL must show confirmation only.
     */
    public function test_unsubscribe_get_shows_confirmation_without_suppression(): void
    {
        $contact = $this->makeClientContact('unsub@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $response = $this->get($signedUrl);

        $response->assertStatus(200)
            ->assertSeeText('Confirmer la désinscription');

        $this->assertDatabaseMissing('suppressions', [
            'email' => 'unsub@acme.test',
        ]);
    }

    /**
     * POST /u/{contact} with a valid signed URL confirms the human form.
     */
    public function test_unsubscribe_confirmation_post_creates_suppression(): void
    {
        $contact = $this->makeClientContact('unsub-post@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $response = $this->post($signedUrl);

        $response->assertStatus(200);

        $this->assertDatabaseHas('suppressions', [
            'email'  => 'unsub-post@acme.test',
            'reason' => 'unsubscribe',
        ]);
    }

    public function test_unsubscribe_one_click_post_creates_suppression(): void
    {
        $contact = $this->makeClientContact('one-click@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe.one-click', ['contact' => $contact->id]);

        $response = $this->post($signedUrl, ['List-Unsubscribe' => 'One-Click']);

        $response->assertStatus(200);

        $this->assertDatabaseHas('suppressions', [
            'email'  => 'one-click@acme.test',
            'reason' => 'unsubscribe',
        ]);
    }

    public function test_unsubscribe_one_click_post_requires_rfc8058_body(): void
    {
        $contact = $this->makeClientContact('bad-one-click@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe.one-click', ['contact' => $contact->id]);

        $response = $this->post($signedUrl);

        $response->assertStatus(400)
            ->assertSeeText('Demande indisponible');

        $this->assertDatabaseMissing('suppressions', [
            'email' => 'bad-one-click@acme.test',
        ]);
    }

    /**
     * GET /u/{contact} WITHOUT a valid signature must be rejected with 403.
     * The 'signed' middleware enforces this before the controller runs.
     */
    public function test_unsubscribe_rejects_tampered_url(): void
    {
        $contact = $this->makeClientContact('tamper@acme.test');

        // Craft a URL without a signature (or with an invalid one)
        $unsignedUrl = '/u/' . $contact->id;

        $response = $this->get($unsignedUrl);

        $response->assertStatus(403)
            ->assertSeeText('Demande indisponible')
            ->assertDontSee('tamper@acme.test');
    }

    /**
     * Unsubscribing twice (idempotent) must not create a duplicate suppression
     * and must return 200 both times.
     */
    public function test_unsubscribe_is_idempotent(): void
    {
        $contact   = $this->makeClientContact('idempotent@acme.test');
        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $this->post($signedUrl)->assertStatus(200);
        $this->post($signedUrl)->assertStatus(200);

        $count = \DB::table('suppressions')->where('email', 'idempotent@acme.test')->count();
        $this->assertSame(1, $count, 'Suppression must not be created twice (firstOrCreate)');
    }

    public function test_visible_unsubscribe_link_and_list_unsubscribe_header_use_separate_urls(): void
    {
        $contact = $this->makeClientContact('header@acme.test');
        $recipient = $this->makeCampaignRecipient($contact);
        $recipient->load('run.campaign.template');
        $campaign = $recipient->run->campaign;
        $template = $campaign->template;

        $confirmationUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);
        $oneClickUrl = URL::signedRoute('unsubscribe.one-click', ['contact' => $contact->id]);

        $mail = new CampaignMailable(
            campaign: $campaign,
            template: $template,
            contact: $contact,
            subjectLine: 'Subject',
            trackingToken: $this->makeToken(),
            unsubscribeUrl: $confirmationUrl,
            resolvedHtml: '<a href="{{unsubscribe_url}}">Se désabonner</a>',
        );

        $this->assertStringContainsString($confirmationUrl, $mail->render());
        $this->assertStringNotContainsString($oneClickUrl, $mail->render());
        $this->assertSame('<' . $oneClickUrl . '>', $mail->headers()->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail->headers()->text['List-Unsubscribe-Post']);
    }
}
