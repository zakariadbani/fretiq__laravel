<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Services\Campaign\CampaignRunResendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignRunResendServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * F2 regression: EmailTrackingService advances a recipient's status to
     * 'clicked' on click (see markCampaignRecipientClicked()), which used to
     * silently drop that recipient from the `where('status', 'sent')` resend
     * cohort even though it was genuinely sent. sent_at is never cleared by a
     * later click/open, so filtering on it instead keeps clickers resendable
     * while still excluding a recipient that never actually sent.
     */
    public function test_a_clicked_recipient_stays_in_the_resend_cohort(): void
    {
        config(['services.zoho.driver' => 'local']);

        $segment = Segment::create(['name' => 'Resend segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Resend template',
            'subject' => 'Bonjour',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'resend@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Resend campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => true,
            'driver' => 'local',
        ]);

        $company = Company::factory()->client()->create();
        $clickedContact = Contact::factory()->create([
            'company_id' => $company->id,
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => now(),
            'email_verification_source' => 'manual',
        ]);
        $queuedContact = Contact::factory()->create([
            'company_id' => $company->id,
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => now(),
            'email_verification_source' => 'manual',
        ]);

        $sourceRun = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'manual-test-' . uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);

        // Sent then clicked — status advanced away from 'sent', sent_at kept.
        CampaignRecipient::create([
            'campaign_run_id' => $sourceRun->id,
            'contact_id' => $clickedContact->id,
            'status' => 'clicked',
            'sent_at' => now()->subDay(),
            'clicked_at' => now(),
        ]);
        // Never actually sent — must stay excluded from the resend cohort.
        CampaignRecipient::create([
            'campaign_run_id' => $sourceRun->id,
            'contact_id' => $queuedContact->id,
            'status' => 'queued',
        ]);

        $resendRun = app(CampaignRunResendService::class)->create($campaign, $sourceRun, now());

        $resentContactIds = $resendRun->recipients()->pluck('contact_id')->all();
        $this->assertContains($clickedContact->id, $resentContactIds);
        $this->assertNotContains($queuedContact->id, $resentContactIds);
    }
}
