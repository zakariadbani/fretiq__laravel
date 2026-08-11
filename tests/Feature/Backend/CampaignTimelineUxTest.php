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
use App\Models\SmtpSendReservation;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignTimelineUxTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'local']);

        $this->operator = $this->makeUser([
            'view campaigns',
            'edit campaigns',
            'send campaigns',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_campaign_shared_header_keeps_operational_controls_on_view_and_edit_without_nested_forms(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));

        $campaign = $this->makePacedSmtpCampaign();
        $this->makeSafePendingRun($campaign);

        $view = $this->actingAs($this->operator)->get(route('admin.campaigns.view', $campaign));
        $edit = $this->actingAs($this->operator)->get(route('admin.campaigns.edit', $campaign));

        $view->assertOk();
        $edit->assertOk();

        foreach ([$view->getContent(), $edit->getContent()] as $body) {
            $this->assertStringContainsString('Outils d’envoi', $body);
            $this->assertStringContainsString('Mettre en pause', $body);
            $this->assertStringContainsString('Gérer le lot programmé', $body);
            $this->assertStringContainsString(route('admin.campaigns.view', $campaign) . '#campaign_historique', $body);
            $this->assertStringNotContainsString('id="btn-send-now"', $body);
            $this->assertSame(1, substr_count($body, 'data-campaign-action-handlers'), 'The campaign action handler must be included once per page.');
            $this->assertStringContainsString('data-guard-unsaved="true"', $body);
            $this->assertStringContainsString('a[data-guard-unsaved="true"]', $body);
        }

        $this->assertStringContainsString('Modifier', $view->getContent());
        $this->assertStringNotContainsString('data-view-edit-link', $edit->getContent());
        $this->assertSame(1, substr_count($edit->getContent(), '<form'), 'The edit page must keep exactly its outer #form_crud form.');
    }

    public function test_history_rows_present_only_the_actions_that_are_safe_for_their_timeline_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));

        $campaign = $this->makePacedSmtpCampaign();
        $pending = $this->makeSafePendingRun($campaign);
        $released = $this->makeSafePendingRun($campaign, 'released');
        $releasedWithEvidence = $this->makeSafePendingRun($campaign, 'released');
        SmtpSendReservation::query()
            ->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
            ->whereIn('source_id', $releasedWithEvidence->recipients()->pluck('id'))
            ->update([
                'accepted_at' => now(),
                'provider_message_id' => 'ux-provider-evidence',
            ]);
        $sent = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'timeline-sent-' . uniqid(),
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'driver_ref' => 'smtp',
            'finished_at' => now()->subDay(),
        ]);
        $canceled = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'timeline-canceled-' . uniqid(),
            'run_at' => now()->subHours(2),
            'status' => 'canceled',
            'driver_ref' => 'smtp',
            'finished_at' => now()->subHour(),
            'failure_reason' => 'Annulé par un opérateur.',
        ]);
        $providerOwned = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'timeline-provider-owned-' . uniqid(),
            'run_at' => now()->addHours(3),
            'status' => 'scheduled',
            'zoho_list_key' => 'provider-list-already-created',
        ]);
        $driverOwned = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'timeline-driver-owned-' . uniqid(),
            'run_at' => now()->addHours(4),
            'status' => 'scheduled',
            'driver_ref' => 'local-provider-attempted',
        ]);

        $body = $this->actingAs($this->operator)
            ->get(route('admin.campaigns.view', $campaign))
            ->assertOk()
            ->getContent();

        $pendingRow = $this->runRow($body, $pending);
        $this->assertStringContainsString('Programmé', $pendingRow);
        $this->assertStringContainsString('Prochain créneau sûr', $pendingRow);
        $this->assertStringContainsString('Démarrer maintenant', $pendingRow);
        $this->assertStringContainsString('Annuler ce lot', $pendingRow);
        $this->assertStringContainsString(route('admin.campaigns.runs.startNow', [$campaign, $pending]), $pendingRow);
        $this->assertStringContainsString(route('admin.campaigns.runs.cancel', [$campaign, $pending]), $pendingRow);
        $this->assertStringContainsString('Voir destinataires', $pendingRow);

        $releasedRow = $this->runRow($body, $released);
        $this->assertStringContainsString('Programmé', $releasedRow);
        $this->assertStringContainsString('Démarrer maintenant', $releasedRow);
        $this->assertStringContainsString('Annuler ce lot', $releasedRow);

        $releasedEvidenceRow = $this->runRow($body, $releasedWithEvidence);
        $this->assertStringContainsString('déjà remis au fournisseur', $releasedEvidenceRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $releasedEvidenceRow);
        $this->assertStringNotContainsString('Annuler ce lot', $releasedEvidenceRow);

        $sentRow = $this->runRow($body, $sent);
        $this->assertStringContainsString('Renvoyer ce lot', $sentRow);
        $this->assertStringContainsString(route('admin.campaigns.runs.resend', [$campaign, $sent]), $sentRow);
        $this->assertStringContainsString('Voir destinataires', $sentRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $sentRow);
        $this->assertStringNotContainsString('Annuler ce lot', $sentRow);

        $canceledRow = $this->runRow($body, $canceled);
        $this->assertStringContainsString('Annulé', $canceledRow);
        $this->assertStringContainsString('Annulé par un opérateur.', $canceledRow);
        $this->assertStringContainsString('Voir destinataires', $canceledRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $canceledRow);
        $this->assertStringNotContainsString('Annuler ce lot', $canceledRow);
        $this->assertStringNotContainsString('Renvoyer ce lot', $canceledRow);

        $providerOwnedRow = $this->runRow($body, $providerOwned);
        $this->assertStringContainsString('déjà remis au fournisseur', $providerOwnedRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $providerOwnedRow);
        $this->assertStringNotContainsString('Annuler ce lot', $providerOwnedRow);

        $driverOwnedRow = $this->runRow($body, $driverOwned);
        $this->assertStringContainsString('déjà remis au fournisseur', $driverOwnedRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $driverOwnedRow);
        $this->assertStringNotContainsString('Annuler ce lot', $driverOwnedRow);

        $viewOnly = $this->makeUser(['view campaigns']);
        $viewOnlyRow = $this->runRow(
            $this->actingAs($viewOnly)->get(route('admin.campaigns.view', $campaign))->assertOk()->getContent(),
            $pending,
        );
        $this->assertStringContainsString('Voir destinataires', $viewOnlyRow);
        $this->assertStringNotContainsString('>Gérer<', $viewOnlyRow);
        $this->assertStringNotContainsString('timeline-action', $viewOnlyRow);
    }

    public function test_pause_resume_controls_follow_campaign_state_and_permissions_on_both_shared_headers(): void
    {
        $paused = $this->makePacedSmtpCampaign(['is_active' => false]);

        foreach ([
            route('admin.campaigns.view', $paused),
            route('admin.campaigns.edit', $paused),
        ] as $url) {
            $body = $this->actingAs($this->operator)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Reprendre la campagne', $body);
            $this->assertStringNotContainsString('data-state="0"', $body);
            $this->assertStringNotContainsString('id="btn-schedule"', $body);
            $this->assertStringNotContainsString('id="btn-send-now"', $body);
        }

        $editOnly = $this->makeUser(['view campaigns', 'edit campaigns']);
        $active = $this->makePacedSmtpCampaign();
        $this->makeSafePendingRun($active);

        $activeBody = $this->actingAs($editOnly)
            ->get(route('admin.campaigns.view', $active))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-state="0"', $activeBody);
        $this->assertStringContainsString('Gérer le lot programmé', $activeBody);
        $this->assertStringContainsString(route('admin.campaigns.view', $active) . '#campaign_historique', $activeBody);
        $this->assertStringNotContainsString('Démarrer maintenant', $activeBody);
        $this->assertStringNotContainsString('data-action="resend"', $activeBody);

        $pausedBody = $this->actingAs($editOnly)
            ->get(route('admin.campaigns.view', $paused))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-state="1"', $pausedBody);
    }

    public function test_dynamic_lifecycle_permissions_allow_send_only_resume_and_edit_only_pause(): void
    {
        $paused = $this->makePacedSmtpCampaign(['is_active' => false]);
        $sendOnly = $this->makeUser(['view campaigns', 'send campaigns']);
        $editOnly = $this->makeUser(['view campaigns', 'edit campaigns']);

        $this->actingAs($sendOnly)
            ->putJson(route('admin.campaigns.executeSwitch', $paused), ['field' => 'is_active', 'state' => 1])
            ->assertOk();
        $this->assertTrue($paused->fresh()->is_active);

        $this->actingAs($editOnly)
            ->putJson(route('admin.campaigns.executeSwitch', $paused), ['field' => 'is_active', 'state' => 1])
            ->assertForbidden();

        $this->actingAs($editOnly)
            ->putJson(route('admin.campaigns.executeSwitch', $paused), ['field' => 'is_active', 'state' => 0])
            ->assertOk();
        $this->assertFalse($paused->fresh()->is_active);

        $this->actingAs($sendOnly)
            ->putJson(route('admin.campaigns.executeSwitch', $paused), ['field' => 'is_active', 'state' => 2])
            ->assertUnprocessable();
        $this->assertFalse($paused->fresh()->is_active);
    }

    public function test_history_cancel_affordance_supports_untouched_non_smtp_runs_but_never_sequence_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC'));

        $zoho = $this->makePacedSmtpCampaign(['delivery_channel' => 'zoho']);
        $zohoRun = CampaignRun::create([
            'campaign_id' => $zoho->id,
            'occurrence_key' => 'timeline-local-cancel',
            'run_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        $zohoRow = $this->runRow(
            $this->actingAs($this->operator)->get(route('admin.campaigns.view', $zoho))->assertOk()->getContent(),
            $zohoRun,
        );
        $this->assertStringContainsString('Annuler ce lot', $zohoRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $zohoRow);

        $sequence = $this->makePacedSmtpCampaign(['schedule_type' => 'sequence']);
        $sequenceRun = $this->makeSafePendingRun($sequence);
        $sequenceRow = $this->runRow(
            $this->actingAs($this->operator)->get(route('admin.campaigns.view', $sequence))->assertOk()->getContent(),
            $sequenceRun,
        );
        $this->assertStringNotContainsString('Annuler ce lot', $sequenceRow);
        $this->assertStringNotContainsString('Démarrer maintenant', $sequenceRow);
    }

    public function test_active_campaign_send_controls_expose_preview_and_confirmation_contracts(): void
    {
        $campaign = $this->makePacedSmtpCampaign();
        $body = $this->actingAs($this->operator)
            ->get(route('admin.campaigns.view', $campaign))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="btn-schedule"', $body);
        $this->assertStringContainsString('id="btn-send-now"', $body);
        $this->assertStringContainsString('data-preview-url=', $body);
        $this->assertStringContainsString('Mettre la campagne en pause ?', $body);
        $this->assertStringContainsString('Reprendre la campagne ?', $body);
        $this->assertStringContainsString('Aucune campagne Zoho ne sera créée ni envoyée.', $body);
        $this->assertStringContainsString('data-campaign-stats-sync', $body);
    }

    private function makeUser(array $permissions): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo(array_merge(['backend.access'], $permissions));

        return $user;
    }

    private function makePacedSmtpCampaign(array $overrides = []): Campaign
    {
        $segment = Segment::create([
            'name' => 'UX segment ' . uniqid(),
            'scope' => 'client',
        ]);
        $template = CampaignTemplate::create([
            'name' => 'UX template ' . uniqid(),
            'subject' => 'Objet UX',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => 'UX sender',
            'email' => uniqid('ux_sender_') . '@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name' => 'Campagne UX ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'paced',
            'daily_company_limit' => 1,
            'next_run_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'send_window' => [
                'days' => [1, 2, 3, 4, 5],
                'start' => '09:00',
                'end' => '18:00',
            ],
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'is_active' => true,
        ], $overrides));
    }

    private function makeSafePendingRun(Campaign $campaign, string $reservationStatus = 'reserved'): CampaignRun
    {
        $wasReleased = $reservationStatus === 'released';
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'timeline-pending-' . uniqid(),
            'run_at' => now()->addDay(),
            'status' => $wasReleased ? 'scheduled' : 'sending',
            'driver_ref' => 'smtp',
            'started_at' => $wasReleased ? null : now(),
        ]);
        $company = Company::create([
            'name' => 'UX client ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => uniqid('ux_contact_') . '@client.test',
            'name' => 'Contact UX',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);
        SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => $recipient->id,
            'reserved_for' => now()->addDay(),
            'status' => $reservationStatus,
        ]);

        return $run;
    }

    private function runRow(string $body, CampaignRun $run): string
    {
        $matched = preg_match(
            '/<tr\\b[^>]*\\bdata-run-id="' . preg_quote((string) $run->id, '/') . '"[^>]*>.*?<\\/tr>/s',
            $body,
            $matches,
        );

        $this->assertSame(1, $matched, "Missing history row for run {$run->id}.");

        return $matches[0];
    }
}
