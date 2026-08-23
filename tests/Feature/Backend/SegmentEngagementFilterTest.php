<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Services\Campaign\SegmentService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentEngagementFilterTest — filter.engagement cohort building
 * (structure/specs/... engagement filter): opened / clicked / sans_demande,
 * optionally scoped to a campaign_id list.
 *
 * All contacts are created with email_verification_status='valid' — resolve()
 * defaults to Campaign::VERIFICATION_VERIFIED_ONLY, and ContactEligibilityService
 * excludes any contact whose status isn't 'valid' regardless of engagement match
 * (see SegmentFilterPipelineTest for the same pattern).
 */
class SegmentEngagementFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeContact(string $email): Contact
    {
        $co = Company::create([
            'name'                 => 'Co ' . $email,
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Contact ' . $email,
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
            'email_verification_status' => 'valid',
        ]);
    }

    private function makeCampaignRun(): CampaignRun
    {
        $segment  = Segment::create(['name' => 'Engagement fixture ' . uniqid(), 'scope' => 'mixed']);
        $template = CampaignTemplate::create([
            'name'         => 'Engagement Template',
            'subject'      => 'Sujet',
            'html_content' => '<p>Test</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Engagement',
            'email' => 'engagement-' . uniqid() . '@tcl.test',
        ]);
        $campaign = Campaign::create([
            'name'               => 'Campaign ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now()->subHour(),
            'timezone'           => 'Europe/Paris',
        ]);

        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'oneshot-' . uniqid(),
            'run_at'         => now()->subHour(),
            'status'         => 'sent',
        ]);
    }

    private function makeRecipient(CampaignRun $run, Contact $contact, array $attrs = []): CampaignRecipient
    {
        return CampaignRecipient::create(array_merge([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMinutes(30),
        ], $attrs));
    }

    /** A sequence-schedule campaign — the shape an SMTP sequence-step send belongs to. */
    private function makeSequenceCampaign(): Campaign
    {
        $segment  = Segment::create(['name' => 'Engagement seq fixture ' . uniqid(), 'scope' => 'mixed']);
        $sequence = Sequence::create(['name' => 'Engagement sequence ' . uniqid(), 'is_active' => true]);
        $template = CampaignTemplate::create([
            'name'         => 'Engagement Seq Template ' . uniqid(),
            'subject'      => 'Sujet',
            'html_content' => '<p>Test</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $template->id,
            'subject'     => 'Etape 1',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Engagement Seq',
            'email' => 'engagement-seq-' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name'                     => 'Sequence campaign ' . uniqid(),
            'segment_id'               => $segment->id,
            'sequence_id'              => $sequence->id,
            'sender_identity_id'       => $sender->id,
            'schedule_type'            => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'daily_company_limit'      => 20,
            'next_run_at'              => now()->addDay(),
            'timezone'                 => 'Europe/Paris',
            'is_active'                => true,
        ]);
    }

    /**
     * A step-1 SequenceStepSend for $contact, enrolled under $campaign's own
     * sequence — no campaign_recipients row involved at all, so this only
     * matches the engagement filter through the sequence_step_sends union.
     */
    private function makeSequenceStepSend(Campaign $campaign, Contact $contact, array $attrs = []): SequenceStepSend
    {
        $enrollment = SequenceEnrollment::create([
            'sequence_id'  => $campaign->sequence_id,
            'contact_id'   => $contact->id,
            'campaign_id'  => $campaign->id,
            'current_step' => 1,
            'status'       => 'active',
        ]);

        return SequenceStepSend::create(array_merge([
            'enrollment_id' => $enrollment->id,
            'step_no'       => 1,
            'status'        => 'sent',
            'sent_at'       => now()->subMinutes(30),
        ], $attrs));
    }

    private function resolveEmails(array $engagement): array
    {
        $segment = Segment::create([
            'name'   => 'Engagement ' . uniqid(),
            'scope'  => 'mixed',
            'filter' => ['engagement' => $engagement],
        ]);

        return app(SegmentService::class)->resolve($segment)->pluck('email')->all();
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * {"opened":true,"clicked":false} — the "ouvert sans clic" cohort must
     * include an opener-who-didn't-click, and exclude both a clicker (who
     * also opened) and a contact who was never sent anything at all.
     */
    public function test_opened_no_click_cohort_excludes_clickers_and_never_sent(): void
    {
        $run = $this->makeCampaignRun();

        $opener = $this->makeContact('opener@acme.test');
        $this->makeRecipient($run, $opener, ['status' => 'opened', 'opened_at' => now()->subMinutes(10)]);

        $clicker = $this->makeContact('clicker@acme.test');
        $this->makeRecipient($run, $clicker, [
            'status'     => 'clicked',
            'opened_at'  => now()->subMinutes(10),
            'clicked_at' => now()->subMinutes(5),
        ]);

        $this->makeContact('neversent@acme.test'); // no campaign_recipients row at all

        $emails = $this->resolveEmails(['opened' => true, 'clicked' => false]);

        $this->assertContains('opener@acme.test', $emails);
        $this->assertNotContains('clicker@acme.test', $emails);
        $this->assertNotContains('neversent@acme.test', $emails);
    }

    /**
     * filter.engagement.campaign_id scopes the opened EXISTS check to the
     * given campaign(s) via campaign_runs.campaign_id.
     */
    public function test_engagement_campaign_id_scopes_the_opened_check(): void
    {
        $runA = $this->makeCampaignRun();
        $runB = $this->makeCampaignRun();

        $contact = $this->makeContact('scoped@acme.test');
        $this->makeRecipient($runA, $contact, ['status' => 'opened', 'opened_at' => now()->subMinutes(10)]);

        // Scoped to the campaign the contact actually opened under → included.
        $emailsScopedToA = $this->resolveEmails([
            'campaign_id' => [$runA->campaign_id],
            'opened'      => true,
        ]);
        $this->assertContains('scoped@acme.test', $emailsScopedToA);

        // Scoped to a different campaign (never opened there) → excluded, even
        // though the contact opened something under the unscoped campaign.
        $emailsScopedToB = $this->resolveEmails([
            'campaign_id' => [$runB->campaign_id],
            'opened'      => true,
        ]);
        $this->assertNotContains('scoped@acme.test', $emailsScopedToB);
    }

    /**
     * Zoho-synced clicks may persist status='clicked' with clicked_at still
     * NULL (a parallel work item is adding clicked_at persistence) — the
     * clicked predicate must be robust to that historical row shape.
     */
    public function test_clicked_matches_zoho_style_row_with_null_clicked_at(): void
    {
        $run = $this->makeCampaignRun();

        $zohoClicker = $this->makeContact('zoho-clicker@acme.test');
        $this->makeRecipient($run, $zohoClicker, ['status' => 'clicked', 'clicked_at' => null]);

        $emails = $this->resolveEmails(['clicked' => true]);

        $this->assertContains('zoho-clicker@acme.test', $emails);
    }

    /**
     * sans_demande:true excludes any contact with an existing demandes row,
     * regardless of campaign engagement.
     */
    public function test_sans_demande_excludes_contacts_with_a_demande(): void
    {
        $withDemande = $this->makeContact('has-demande@acme.test');
        Demande::create([
            'contact_id'  => $withDemande->id,
            'captured_at' => now(),
        ]);

        $withoutDemande = $this->makeContact('no-demande@acme.test');

        $emails = $this->resolveEmails(['sans_demande' => true]);

        $this->assertNotContains('has-demande@acme.test', $emails);
        $this->assertContains('no-demande@acme.test', $emails);
    }

    /**
     * opened:false excludes any contact with a matching opened row and
     * includes a sent-but-not-opened contact.
     */
    public function test_opened_false_excludes_openers(): void
    {
        $run = $this->makeCampaignRun();

        $opener = $this->makeContact('opener2@acme.test');
        $this->makeRecipient($run, $opener, ['status' => 'opened', 'opened_at' => now()->subMinutes(10)]);

        $nonOpener = $this->makeContact('nonopener@acme.test');
        $this->makeRecipient($run, $nonOpener, ['status' => 'sent']);

        $emails = $this->resolveEmails(['opened' => false]);

        $this->assertNotContains('opener2@acme.test', $emails);
        $this->assertContains('nonopener@acme.test', $emails);
    }

    /**
     * A contact with no campaign_recipients row at all, only a
     * sequence_step_sends row (SMTP sequence step), must still count as
     * "opened" — the primary sequence send path must not be invisible to
     * the engagement filter.
     */
    public function test_opened_cohort_includes_a_sequence_step_send_opener(): void
    {
        $campaign = $this->makeSequenceCampaign();

        $opener = $this->makeContact('seq-opener@acme.test');
        $this->makeSequenceStepSend($campaign, $opener, ['opened_at' => now()->subMinutes(10)]);

        $neverOpened = $this->makeContact('seq-never-opened@acme.test');
        $this->makeSequenceStepSend($campaign, $neverOpened);

        $emails = $this->resolveEmails(['opened' => true]);

        $this->assertContains('seq-opener@acme.test', $emails);
        $this->assertNotContains('seq-never-opened@acme.test', $emails);
    }

    /**
     * Same union, for the clicked predicate — sequence_step_sends.clicked_at
     * (persisted by EmailTrackingService::recordClick()).
     */
    public function test_clicked_cohort_includes_a_sequence_step_send_clicker(): void
    {
        $campaign = $this->makeSequenceCampaign();

        $clicker = $this->makeContact('seq-clicker@acme.test');
        $this->makeSequenceStepSend($campaign, $clicker, [
            'opened_at'  => now()->subMinutes(10),
            'clicked_at' => now()->subMinutes(5),
        ]);

        $nonClicker = $this->makeContact('seq-non-clicker@acme.test');
        $this->makeSequenceStepSend($campaign, $nonClicker, ['opened_at' => now()->subMinutes(10)]);

        $emails = $this->resolveEmails(['clicked' => true]);

        $this->assertContains('seq-clicker@acme.test', $emails);
        $this->assertNotContains('seq-non-clicker@acme.test', $emails);
    }

    /**
     * filter.engagement.campaign_id scopes the sequence-step-send opened
     * check via campaigns.sequence_id (sequence_step_sends → sequence_enrollments
     * → sequence_id → campaigns.sequence_id) — a sequence can be reused by
     * more than one campaign, so scoping is by the sequence tied to the
     * requested campaign, not the enrollment's own (nullable) campaign_id.
     */
    public function test_engagement_campaign_id_scopes_the_sequence_step_send_opened_check(): void
    {
        $campaignA = $this->makeSequenceCampaign();
        $campaignB = $this->makeSequenceCampaign();

        $contact = $this->makeContact('seq-scoped@acme.test');
        $this->makeSequenceStepSend($campaignA, $contact, ['opened_at' => now()->subMinutes(10)]);

        $emailsScopedToA = $this->resolveEmails([
            'campaign_id' => [$campaignA->id],
            'opened'      => true,
        ]);
        $this->assertContains('seq-scoped@acme.test', $emailsScopedToA);

        $emailsScopedToB = $this->resolveEmails([
            'campaign_id' => [$campaignB->id],
            'opened'      => true,
        ]);
        $this->assertNotContains('seq-scoped@acme.test', $emailsScopedToB);
    }
}
