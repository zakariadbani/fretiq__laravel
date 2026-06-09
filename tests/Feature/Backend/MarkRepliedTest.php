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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * HTTP-level tests for POST /admin/campaigns/{id}/recipients/{rid}/replied.
 *
 * Verifies:
 *  - Demande is created with correct contact_id + campaign_id.
 *  - Recipient status is set to 'replied'.
 *  - Commercial (who has 'create demandes') is not blocked (not 403).
 */
class MarkRepliedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Mail::fake();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeContact(string $email = 'j@acme.test'): Contact
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
            'name'        => 'J',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeCampaign(): Campaign
    {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl MarkReplied',
            'subject'      => 'Sujet',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne MarkReplied',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    private function makeCampaignRun(Campaign $campaign): CampaignRun
    {
        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'replied-test-' . now()->format('YmdHisv'),
            'run_at'         => now(),
            'status'         => 'sent',
            'conversion_count' => 0,
        ]);
    }

    private function makeRecipient(CampaignRun $run, Contact $contact): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_run_id'     => $run->id,
            'contact_id'          => $contact->id,
            'status'              => 'sent',
            'provider_message_id' => 'local-test-123',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Superadmin can mark a recipient as replied.
     * Expect: redirect (302 to campaign view) or 200; Demande created; recipient.status='replied'.
     */
    public function test_superadmin_can_mark_replied(): void
    {
        $contact   = $this->makeContact();
        $campaign  = $this->makeCampaign();
        $run       = $this->makeCampaignRun($campaign);
        $recipient = $this->makeRecipient($run, $contact);

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/replied");

        // Controller returns redirect to campaign view
        $response->assertRedirect();

        $this->assertDatabaseHas('demandes', [
            'contact_id'  => $contact->id,
            'campaign_id' => $campaign->id,
        ]);

        $recipient->refresh();
        $this->assertSame('replied', $recipient->status, 'Recipient status must be replied');
    }

    /**
     * Commercial (who has 'create demandes') must NOT get 403 on markReplied.
     */
    public function test_commercial_can_mark_replied(): void
    {
        $contact   = $this->makeContact('jc@acme.test');
        $campaign  = $this->makeCampaign();
        $run       = $this->makeCampaignRun($campaign);
        $recipient = $this->makeRecipient($run, $contact);

        $response = $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/replied");

        // Must not be 403
        $this->assertNotEquals(403, $response->status(), 'Commercial must not receive 403 on markReplied');

        $this->assertDatabaseHas('demandes', [
            'contact_id'  => $contact->id,
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * After markReplied, the CampaignRun.conversion_count is incremented.
     */
    public function test_mark_replied_increments_conversion_count(): void
    {
        $contact   = $this->makeContact('jrun@acme.test');
        $campaign  = $this->makeCampaign();
        $run       = $this->makeCampaignRun($campaign);
        $recipient = $this->makeRecipient($run, $contact);

        $initialCount = (int) $run->conversion_count;

        $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/recipients/{$recipient->id}/replied");

        $run->refresh();

        $this->assertSame(
            $initialCount + 1,
            (int) $run->conversion_count,
            'conversion_count must be incremented after markReplied',
        );
    }
}
