<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Models\SmtpSendReservation;
use App\Models\User;
use App\Services\Campaign\CampaignDeliveryFence;
use App\Services\Discovery\EmailVerificationSettings;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignDeliveryChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->assignRole('superadmin');
    }

    public function test_legacy_null_delivery_channel_keeps_global_and_legacy_driver_semantics(): void
    {
        $campaign = $this->campaign(['delivery_channel' => null, 'driver' => 'local']);

        config(['services.zoho.driver' => 'zoho']);
        $this->assertSame('zoho', $campaign->effectiveDeliveryChannel());

        config(['services.zoho.driver' => 'local']);
        $this->assertSame('smtp', $campaign->fresh()->effectiveDeliveryChannel());

        $campaign->update(['driver' => 'zoho']);
        $this->assertSame('zoho', $campaign->fresh()->effectiveDeliveryChannel());
    }

    public function test_new_campaign_defaults_to_explicit_zoho_delivery(): void
    {
        $data = $this->fixtureData();

        $this->actingAs($this->user)->postJson(route('admin.campaigns.store'), [
            'name' => 'New explicit campaign',
            'segment_id' => $data['segment']->id,
            'template_id' => $data['template']->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ])->assertOk();

        $this->assertSame('zoho', Campaign::where('name', 'New explicit campaign')->firstOrFail()->delivery_channel);
    }

    public function test_new_campaign_inherits_the_global_email_verification_policy_once(): void
    {
        Setting::set(EmailVerificationSettings::DEFAULT_POLICY_KEY, Campaign::VERIFICATION_ALL_SENDABLE);
        $data = $this->fixtureData();

        $this->actingAs($this->user)->postJson(route('admin.campaigns.store'), [
            'name' => 'Campaign policy default',
            'segment_id' => $data['segment']->id,
            'template_id' => $data['template']->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ])->assertOk();

        $campaign = Campaign::where('name', 'Campaign policy default')->firstOrFail();
        $this->assertSame(Campaign::VERIFICATION_ALL_SENDABLE, $campaign->email_verification_policy);

        Setting::set(EmailVerificationSettings::DEFAULT_POLICY_KEY, Campaign::VERIFICATION_VERIFIED_ONLY);
        $this->assertSame(Campaign::VERIFICATION_ALL_SENDABLE, $campaign->fresh()->email_verification_policy);
    }

    public function test_email_verification_policy_locks_after_first_delivery(): void
    {
        $campaign = $this->campaign([
            'email_verification_policy' => Campaign::VERIFICATION_VERIFIED_ONLY,
            'delivery_started_at' => now(),
        ]);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'email_verification_policy' => Campaign::VERIFICATION_ALL_SENDABLE,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email_verification_policy');

        $this->assertSame(Campaign::VERIFICATION_VERIFIED_ONLY, $campaign->fresh()->email_verification_policy);
    }

    public function test_campaign_form_exposes_both_email_verification_policies(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.campaigns.create'))
            ->assertOk()
            ->assertSee('verified_only', false)
            ->assertSee('all_sendable', false);
    }

    public function test_segment_preview_applies_the_selected_policy_without_external_verification(): void
    {
        Http::preventStrayRequests();
        $segment = Segment::create(['name' => 'Policy preview', 'scope' => 'client']);
        $company = Company::factory()->create(['relationship' => 'client']);
        Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'verified-preview@example.test',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ]);
        Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'unverified-preview@example.test',
        ]);
        Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'pending-preview@example.test',
            'email_verification_status' => 'pending',
            'email_verification_source' => 'hunter',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('admin.campaigns.segmentCount', [
                'id' => $segment->id,
                'email_verification_policy' => Campaign::VERIFICATION_VERIFIED_ONLY,
            ]))
            ->assertOk()
            ->assertJsonPath('count', 1);
        $this->actingAs($this->user)
            ->getJson(route('admin.campaigns.segmentCount', [
                'id' => $segment->id,
                'email_verification_policy' => Campaign::VERIFICATION_ALL_SENDABLE,
            ]))
            ->assertOk()
            ->assertJsonPath('count', 3);

        Http::assertNothingSent();
    }

    public function test_explicit_smtp_wins_over_the_global_zoho_driver(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        $campaign = $this->campaign(['delivery_channel' => 'smtp']);

        $this->assertSame('smtp', $campaign->effectiveDeliveryChannel());
        $this->assertFalse($campaign->usesZohoDriver());
    }

    public function test_mailjet_channel_is_accepted_for_a_non_sequence_campaign(): void
    {
        $data = $this->fixtureData();
        $campaign = $this->campaign(['delivery_channel' => 'zoho'], $data);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'mailjet',
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertOk();

        $this->assertSame('mailjet', $campaign->fresh()->delivery_channel);
    }

    public function test_mailjet_channel_is_rejected_for_a_sequence_campaign(): void
    {
        [$campaign, $sequence] = $this->pacedSequenceCampaign('smtp', now());

        $response = $this->actingAs($this->user)
            ->putJson(route('admin.campaigns.update', $campaign), $this->sequenceUpdatePayload($campaign, $sequence, 'mailjet'));

        // Model-level rule failures (Campaign::rules()) return 406 in this
        // controller — distinct from the 422 ValidationException path used
        // by the deliverySettingsLocked() guard elsewhere in this file.
        $response->assertStatus(406)->assertJsonValidationErrors('delivery_channel');
        $this->assertSame('smtp', $campaign->fresh()->delivery_channel);
    }

    public function test_smtp_campaign_defaults_to_twenty_emails_per_day(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp', 'smtp_daily_email_limit' => null]);

        $this->assertSame(20, $campaign->smtpDailyEmailLimit());
    }

    public function test_channel_and_sender_can_change_before_real_delivery(): void
    {
        $data = $this->fixtureData();
        $campaign = $this->campaign(['delivery_channel' => 'smtp'], $data);
        $otherSender = SenderIdentity::create(['name' => 'Other sender', 'email' => 'other@example.test']);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $otherSender->id,
            'delivery_channel' => 'zoho',
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertOk();

        $campaign->refresh();
        $this->assertSame('zoho', $campaign->delivery_channel);
        $this->assertSame($otherSender->id, $campaign->sender_identity_id);
    }

    public function test_channel_and_sender_lock_after_real_delivery_but_daily_target_stays_editable(): void
    {
        $data = $this->fixtureData();
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'delivery_started_at' => now(),
        ], $data);
        $otherSender = SenderIdentity::create(['name' => 'Other locked sender', 'email' => 'locked@example.test']);

        $response = $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $otherSender->id,
            'delivery_channel' => 'zoho',
            'smtp_daily_email_limit' => 5,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);

        $response->assertStatus(422);
        $campaign->refresh();
        $this->assertSame('smtp', $campaign->delivery_channel);
        $this->assertSame($data['sender']->id, $campaign->sender_identity_id);

        $this->assertSame(20, $campaign->smtp_daily_email_limit);
    }

    public function test_failed_preflight_without_delivery_does_not_lock_channel_or_sender(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp', 'delivery_started_at' => null]);

        $this->assertFalse($campaign->deliverySettingsLocked());
    }

    public function test_durable_smtp_acceptance_locks_delivery_settings_before_local_reconciliation(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp', 'delivery_started_at' => null]);
        SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 424242,
            'reserved_for' => now(),
            'status' => 'accepted',
            'accepted_at' => now(),
            'provider_message_id' => 'accepted-before-reconciliation',
        ]);

        $this->assertTrue($campaign->fresh()->deliverySettingsLocked());
    }

    public function test_uncertain_smtp_delivery_rejects_a_channel_change_until_manual_reconciliation(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp', 'delivery_started_at' => null]);
        SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 424243,
            'reserved_for' => now(),
            'status' => 'uncertain',
            'attempted_at' => now(),
        ]);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'zoho',
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertStatus(422);

        $this->assertSame('smtp', $campaign->fresh()->delivery_channel);
    }

    /** @dataProvider ambiguousZohoDriverRefs */
    public function test_failed_zoho_delivery_evidence_locks_settings_and_blocks_deletion(string $driverRef): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'zoho', 'delivery_started_at' => null]);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'ambiguous-' . $driverRef,
            'run_at' => now(),
            'status' => 'failed',
            'driver_ref' => $driverRef,
            'finished_at' => now(),
        ]);

        $this->assertTrue($campaign->fresh()->deliverySettingsLocked());

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertStatus(422);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.campaigns.delete', $campaign))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_legacy_campaign_with_a_sent_run_keeps_delivery_settings_locked(): void
    {
        $campaign = $this->campaign(['delivery_channel' => null, 'delivery_started_at' => null]);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'legacy-sent',
            'run_at' => now(),
            'status' => 'sent',
            'driver_ref' => 'local',
            'finished_at' => now(),
        ]);

        $this->assertTrue($campaign->fresh()->deliverySettingsLocked());

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertStatus(422);

        $this->assertNull($campaign->fresh()->delivery_channel);
    }

    public function test_campaign_form_shows_delivery_choice_and_full_smtp_guidance(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.campaigns.create'));

        $response->assertOk()
            ->assertSee('delivery_channel', false)
            ->assertSee('15 emails/jour', false)
            ->assertSee('2 emails/minute', false)
            ->assertSee('SPF', false)
            ->assertSee('DKIM', false)
            ->assertSee('DMARC', false)
            ->assertSee('APP_URL', false);
    }

    public function test_smtp_sequence_edit_keeps_its_inactive_selected_sequence_and_immediate_enrollment(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        $data = $this->fixtureData();
        $sequence = Sequence::create(['name' => 'Archived pilot sequence', 'is_active' => false]);
        $campaign = Campaign::create([
            'name' => 'SMTP pilot campaign',
            'segment_id' => $data['segment']->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'immediate',
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 10,
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee($sequence->name, false)
            ->assertSee('id="sequence_enrollment_immediate_hint"', false);

        $body = $this->actingAs($this->user)->get(route('admin.campaigns.edit', $campaign))->getContent();
        $document = new \DOMDocument();
        @$document->loadHTML($body);
        $sequenceOption = (new \DOMXPath($document))
            ->query('//select[@id="sequence_select"]/option[@value="'.$sequence->id.'"]')
            ?->item(0);
        self::assertNotNull($sequenceOption);
        self::assertTrue($sequenceOption->hasAttribute('selected'));
        self::assertMatchesRegularExpression('/<option value="immediate"[^>]*selected[^>]*>/', $body);
        self::assertMatchesRegularExpression('/<input[^>]*name="smtp_daily_email_limit"[^>]*value="10"/', $body);

        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'sequence_id' => $sequence->id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 10,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'immediate',
            'timezone' => 'Europe/Paris',
            'is_active' => false,
            'sequence_auto_enroll_enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'sequence_id' => $sequence->id,
            'sequence_enrollment_mode' => 'immediate',
            'smtp_daily_email_limit' => 10,
            'is_active' => false,
            'sequence_auto_enroll_enabled' => false,
        ]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_zoho_create_and_legacy_zoho_edit_keep_immediate_enrollment_unavailable(): void
    {
        config(['services.zoho.driver' => 'zoho']);

        $create = $this->actingAs($this->user)->get(route('admin.campaigns.create'));
        $create->assertOk()
            ->assertSee('data-effective-delivery-channel="zoho"', false);
        self::assertMatchesRegularExpression('/<option value="immediate"[^>]*disabled[^>]*>/', $create->getContent());

        $data = $this->fixtureData();
        $sequence = Sequence::create(['name' => 'Legacy Zoho sequence', 'is_active' => true]);
        $campaign = Campaign::create([
            'name' => 'Legacy Zoho campaign',
            'segment_id' => $data['segment']->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'delivery_channel' => null,
            'driver' => 'local',
            'daily_company_limit' => 1,
            'next_run_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ]);

        $edit = $this->actingAs($this->user)->get(route('admin.campaigns.edit', $campaign));
        $edit->assertOk()
            ->assertSee('data-effective-delivery-channel="zoho"', false);
        self::assertMatchesRegularExpression('/<option value="immediate"[^>]*disabled[^>]*>/', $edit->getContent());
    }

    public function test_manual_segment_preview_identifies_an_empty_selected_list(): void
    {
        $segment = Segment::create([
            'name' => 'Empty manual pilot list',
            'scope' => 'client',
            'is_manual' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson(route('admin.campaigns.segmentCount', $segment))
            ->assertOk()
            ->assertJsonPath('is_manual', true)
            ->assertJsonPath('selected_contact_count', 0);

        $dynamic = Segment::create(['name' => 'Empty dynamic pilot list', 'scope' => 'client']);
        $this->actingAs($this->user)
            ->getJson(route('admin.campaigns.segmentCount', $dynamic))
            ->assertOk()
            ->assertJsonPath('is_manual', false)
            ->assertJsonPath('matched_count', 0);
    }

    public function test_locked_campaign_rejects_channel_or_sender_changes_server_side(): void
    {
        $this->test_channel_and_sender_lock_after_real_delivery_but_daily_target_stays_editable();
    }

    public function test_locked_smtp_campaign_accepts_a_daily_target_change(): void
    {
        $data = $this->fixtureData();
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'delivery_started_at' => now(),
        ], $data);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 0,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ])->assertOk();

        $this->assertSame(0, $campaign->fresh()->smtp_daily_email_limit);
    }

    public function test_setting_the_smtp_daily_target_to_zero_releases_future_reserved_work(): void
    {
        $data = $this->fixtureData();
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'delivery_started_at' => now(),
            'is_active' => true,
        ], $data);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 717171,
            'reserved_for' => now()->addDay(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 0,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ])->assertOk();

        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_switching_a_prepared_zoho_sequence_to_smtp_rearms_enrollments_and_cancels_wave_work(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        // Post-fix, a wave-managed enrollment's next_send_at mirrors the
        // child wave's run_at instead of staying null (see SequenceWaveService),
        // so this must be a real future timestamp — not null — to actually
        // exercise the run/recipient-linkage selector rather than a stale
        // whereNull() check that no longer matches this shape.
        [$campaign, $sequence, $step, $enrollment] = $this->pacedSequenceCampaign('zoho', now()->addHour());
        $wave = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now()->addHour(),
            'status' => 'prepared',
            'driver_ref' => 'zoho-wave-pending',
        ]);
        // The queued CampaignRecipient row is what SequenceWaveService always
        // creates alongside a prepared wave — it is the run/recipient
        // linkage the unstall selector uses to find enrollments actually
        // stranded by the wave being canceled below.
        CampaignRecipient::create([
            'campaign_run_id' => $wave->id,
            'contact_id' => $enrollment->contact_id,
            'status' => 'queued',
        ]);

        $this->actingAs($this->user)
            ->putJson(route('admin.campaigns.update', $campaign), $this->sequenceUpdatePayload($campaign, $sequence, 'smtp'))
            ->assertOk();

        $this->assertSame('smtp', $campaign->fresh()->delivery_channel);
        $this->assertNotNull($enrollment->fresh()->next_send_at);
        $this->assertTrue($enrollment->fresh()->next_send_at->lte(now()));
        $this->assertSame('canceled', $wave->fresh()->status);
    }

    public function test_switching_a_prepared_zoho_sequence_to_smtp_does_not_touch_an_enrollment_with_no_recipient_on_the_canceled_wave(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        $untouchedNextSendAt = now()->addDays(2);
        [$campaign, $sequence, $step, $enrollment] = $this->pacedSequenceCampaign('zoho', $untouchedNextSendAt);
        // A prepared wave exists for this campaign, but this enrollment's
        // contact was never queued on it (e.g. it belongs to a different,
        // still-live wave not being canceled) — the run/recipient linkage
        // must not match it, so its own schedule is left alone rather than
        // blanket-rearming every active enrollment on the campaign.
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000002',
            'run_at' => now()->addHour(),
            'status' => 'prepared',
            'driver_ref' => 'zoho-wave-pending',
        ]);

        $this->actingAs($this->user)
            ->putJson(route('admin.campaigns.update', $campaign), $this->sequenceUpdatePayload($campaign, $sequence, 'smtp'))
            ->assertOk();

        $this->assertSame('smtp', $campaign->fresh()->delivery_channel);
        // gt(), not equalTo() — the datetime column truncates the fixture's
        // microseconds on round-trip, so an exact-equality check would be a
        // false negative. Staying in the far future (not rearmed to "now")
        // is the actual behavior under test.
        $this->assertTrue($enrollment->fresh()->next_send_at->gt(now()->addDay()));
    }

    public function test_switching_an_unsent_smtp_sequence_to_live_zoho_releases_direct_smtp_work(): void
    {
        config(['services.zoho.driver' => 'zoho']);
        [$campaign, $sequence, $step, $enrollment] = $this->pacedSequenceCampaign('smtp', now());
        $stepSend = SequenceStepSend::create([
            'enrollment_id' => $enrollment->id,
            'step_no' => 1,
            'status' => 'queued',
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
            'source_id' => $stepSend->id,
            'reserved_for' => now()->addMinute(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->user)
            ->putJson(route('admin.campaigns.update', $campaign), $this->sequenceUpdatePayload($campaign, $sequence, 'zoho'))
            ->assertOk();

        $this->assertSame('zoho', $campaign->fresh()->delivery_channel);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertNotNull($enrollment->fresh()->next_send_at);
    }

    public function test_switching_an_unsent_regular_smtp_campaign_reschedules_its_existing_run(): void
    {
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'is_active' => true,
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'one-shot-transition',
            'run_at' => now(),
            'status' => 'sending',
            'started_at' => now(),
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 515151,
            'reserved_for' => now()->addHour(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'delivery_channel' => 'zoho',
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ])->assertOk();

        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
    }

    public function test_changing_only_the_smtp_sender_releases_old_quota_and_reschedules_the_run(): void
    {
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'is_active' => true,
        ]);
        $newSender = SenderIdentity::create([
            'name' => 'Replacement sender',
            'email' => uniqid('replacement_') . '@example.test',
            'is_active' => true,
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sender-transition',
            'run_at' => now(),
            'status' => 'sending',
            'started_at' => now(),
        ]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 616161,
            'reserved_for' => now()->addHour(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.campaigns.update', $campaign), [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'template_id' => $campaign->template_id,
            'sender_identity_id' => $newSender->id,
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ])->assertOk();

        $this->assertSame($newSender->id, $campaign->fresh()->sender_identity_id);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
    }

    public function test_model_deletion_preserves_sent_smtp_quota_history_without_campaign_reference(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp']);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 818181,
            'reserved_for' => now(),
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $campaign->delete();

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('smtp_send_reservations', [
            'id' => $reservation->id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => null,
            'status' => 'sent',
        ]);
    }

    /** @dataProvider campaignDeletionBlockingStates */
    public function test_delete_endpoint_rejects_started_in_flight_or_uncertain_delivery(
        bool $deliveryStarted,
        ?string $reservationStatus,
    ): void {
        $campaign = $this->campaign([
            'delivery_channel' => 'smtp',
            'delivery_started_at' => $deliveryStarted ? now() : null,
        ]);

        if ($reservationStatus !== null) {
            SmtpSendReservation::create([
                'sender_identity_id' => $campaign->sender_identity_id,
                'campaign_id' => $campaign->id,
                'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
                'source_id' => 828282,
                'reserved_for' => now(),
                'status' => $reservationStatus,
                'attempted_at' => now(),
                'lease_expires_at' => $reservationStatus === 'sending' ? now()->addMinutes(5) : null,
            ]);
        }

        $this->actingAs($this->user)
            ->deleteJson(route('admin.campaigns.delete', $campaign))
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
        if ($reservationStatus !== null) {
            $this->assertDatabaseHas('smtp_send_reservations', [
                'campaign_id' => $campaign->id,
                'status' => $reservationStatus,
            ]);
        }
    }

    public function test_delete_endpoint_releases_queued_smtp_work_for_an_unstarted_campaign(): void
    {
        $campaign = $this->campaign(['delivery_channel' => 'smtp', 'delivery_started_at' => null]);
        $reservation = SmtpSendReservation::create([
            'sender_identity_id' => $campaign->sender_identity_id,
            'campaign_id' => $campaign->id,
            'source_type' => SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT,
            'source_id' => 838383,
            'reserved_for' => now(),
            'status' => 'reserved',
        ]);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.campaigns.delete', $campaign))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('smtp_send_reservations', [
            'id' => $reservation->id,
            'campaign_id' => null,
            'status' => 'released',
        ]);
    }

    public static function campaignDeletionBlockingStates(): array
    {
        return [
            'started marker' => [true, null],
            'live SMTP lease' => [false, 'sending'],
            'uncertain SMTP transport' => [false, 'uncertain'],
        ];
    }

    public static function ambiguousZohoDriverRefs(): array
    {
        return [
            'attempted marker' => ['zoho-send-attempted'],
            'uncertain marker' => ['zoho-send-uncertain'],
        ];
    }

    public function test_live_zoho_transport_claim_revalidates_the_channel_under_the_delivery_fence(): void
    {
        $this->assertTrue(class_exists(CampaignDeliveryFence::class));
        config(['services.zoho.driver' => 'zoho']);
        $campaign = $this->campaign(['delivery_channel' => 'zoho', 'is_active' => true]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'zoho-fence',
            'run_at' => now(),
            'status' => 'scheduled',
        ]);
        $fence = app(CampaignDeliveryFence::class);

        $claimed = $fence->claimZohoTransport($run, ['scheduled']);

        $this->assertNotNull($claimed);
        $this->assertSame('sending', $run->fresh()->status);

        CampaignRun::whereKey($run->id)->update(['status' => 'scheduled']);
        $campaign->update(['delivery_channel' => 'smtp']);

        $this->assertNull($fence->claimZohoTransport($run->fresh(), ['scheduled']));
        $this->assertSame('scheduled', $run->fresh()->status);
    }

    private function campaign(array $attributes = [], ?array $data = null): Campaign
    {
        $data ??= $this->fixtureData();

        return Campaign::create(array_merge([
            'name' => 'Campaign ' . uniqid(),
            'segment_id' => $data['segment']->id,
            'template_id' => $data['template']->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'is_active' => false,
        ], $attributes));
    }

    private function fixtureData(): array
    {
        return [
            'segment' => Segment::create(['name' => 'Segment ' . uniqid(), 'scope' => 'client']),
            'template' => CampaignTemplate::create([
                'name' => 'Template ' . uniqid(),
                'subject' => 'Subject',
                'html_content' => '<p>Hello</p>',
            ]),
            'sender' => SenderIdentity::create([
                'name' => 'Sender ' . uniqid(),
                'email' => uniqid('sender') . '@example.test',
                'is_active' => true,
            ]),
        ];
    }

    /** @return array{Campaign, Sequence, SequenceStep, SequenceEnrollment} */
    private function pacedSequenceCampaign(string $channel, $nextSendAt): array
    {
        $data = $this->fixtureData();
        $sequence = Sequence::create(['name' => 'Sequence ' . uniqid(), 'is_active' => true]);
        $step = SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $data['template']->id,
            'subject' => 'Step one',
        ]);
        $campaign = Campaign::create([
            'name' => 'Sequence campaign ' . uniqid(),
            'segment_id' => $data['segment']->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $data['sender']->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'delivery_channel' => $channel,
            'smtp_daily_email_limit' => $channel === 'smtp' ? 20 : null,
            'daily_company_limit' => 1,
            'next_run_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ]);
        $company = Company::create([
            'name' => 'Company ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => uniqid('contact_') . '@example.test',
            'name' => 'Contact',
            'source' => 'manual',
            'email_kind' => 'role',
        ]);
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => $nextSendAt,
        ]);

        return [$campaign, $sequence, $step, $enrollment];
    }

    private function sequenceUpdatePayload(Campaign $campaign, Sequence $sequence, string $channel): array
    {
        return [
            'name' => $campaign->name,
            'segment_id' => $campaign->segment_id,
            'sender_identity_id' => $campaign->sender_identity_id,
            'sequence_id' => $sequence->id,
            'delivery_channel' => $channel,
            'smtp_daily_email_limit' => $channel === 'smtp' ? 20 : null,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'sequence_first_batch_at' => now()->addDay()->setTimezone('Europe/Paris')->format('Y-m-d\TH:i'),
            'sequence_daily_company_limit' => 1,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ];
    }
}
