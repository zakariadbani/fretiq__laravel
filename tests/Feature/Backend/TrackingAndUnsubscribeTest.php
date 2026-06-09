<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
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
 * Tests for the public tracking pixel and unsubscribe endpoints.
 *
 * Routes under test (public — no auth required):
 *   GET  /track/open/{token}   → TrackingController::open
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
     * GET /u/{contact} with a valid signed URL must:
     *   - return HTTP 200
     *   - create a Suppression row with reason='unsubscribe'
     */
    public function test_unsubscribe_via_signed_url_creates_suppression(): void
    {
        $contact = $this->makeClientContact('unsub@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $response = $this->get($signedUrl);

        $response->assertStatus(200);

        $this->assertDatabaseHas('suppressions', [
            'email'  => 'unsub@acme.test',
            'reason' => 'unsubscribe',
        ]);
    }

    /**
     * POST /u/{contact} (RFC 8058 one-click) with a valid signed URL must also
     * create the suppression row.
     */
    public function test_unsubscribe_post_via_signed_url_creates_suppression(): void
    {
        $contact = $this->makeClientContact('unsub-post@acme.test');

        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $response = $this->post($signedUrl);

        // The UnsubscribeController returns a view (200) for both GET and POST.
        $response->assertStatus(200);

        $this->assertDatabaseHas('suppressions', [
            'email'  => 'unsub-post@acme.test',
            'reason' => 'unsubscribe',
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

        $response->assertStatus(403);
    }

    /**
     * Unsubscribing twice (idempotent) must not create a duplicate suppression
     * and must return 200 both times.
     */
    public function test_unsubscribe_is_idempotent(): void
    {
        $contact   = $this->makeClientContact('idempotent@acme.test');
        $signedUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $this->get($signedUrl)->assertStatus(200);
        $this->get($signedUrl)->assertStatus(200);

        $count = \DB::table('suppressions')->where('email', 'idempotent@acme.test')->count();
        $this->assertSame(1, $count, 'Suppression must not be created twice (firstOrCreate)');
    }
}
