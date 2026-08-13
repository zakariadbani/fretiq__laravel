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
use App\Models\Suppression;
use App\Services\Campaign\CampaignFeedbackService;
use App\Services\Campaign\ContactEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('verificationPolicy')]
    public function test_campaign_verification_policy_matrix(
        ?string $status,
        string $policy,
        ?string $expectedReason,
    ): void {
        $contact = $this->contactModel($status);

        $this->assertSame(
            $expectedReason,
            app(ContactEligibilityService::class)->qualityReason($contact, $policy),
        );
    }

    public static function verificationPolicy(): array
    {
        return [
            'strict valid' => ['valid', Campaign::VERIFICATION_VERIFIED_ONLY, null],
            'strict missing' => [null, Campaign::VERIFICATION_VERIFIED_ONLY, 'verification_required'],
            'strict unknown' => ['unknown', Campaign::VERIFICATION_VERIFIED_ONLY, 'verification_required'],
            'strict accept all' => ['accept_all', Campaign::VERIFICATION_VERIFIED_ONLY, 'verification_required'],
            'strict webmail' => ['webmail', Campaign::VERIFICATION_VERIFIED_ONLY, 'verification_required'],
            'all missing' => [null, Campaign::VERIFICATION_ALL_SENDABLE, null],
            'all unknown' => ['unknown', Campaign::VERIFICATION_ALL_SENDABLE, null],
            'all accept all' => ['accept_all', Campaign::VERIFICATION_ALL_SENDABLE, null],
            'all webmail' => ['webmail', Campaign::VERIFICATION_ALL_SENDABLE, null],
            'pending always waits' => ['pending', Campaign::VERIFICATION_ALL_SENDABLE, 'verification_pending'],
            'invalid always blocked' => ['invalid', Campaign::VERIFICATION_ALL_SENDABLE, 'invalid_email'],
            'disposable always blocked' => ['disposable', Campaign::VERIFICATION_ALL_SENDABLE, 'disposable_email'],
        ];
    }

    public function test_valid_verification_never_expires(): void
    {
        $contact = $this->contactModel('valid');
        $contact->setAttribute('email_verification_checked_at', now()->subYears(5));

        $this->assertNull(app(ContactEligibilityService::class)->qualityReason($contact));
    }

    public function test_all_sendable_allows_personal_address(): void
    {
        $contact = $this->contactModel(null);
        $contact->email_kind = 'personal';

        $this->assertNull(app(ContactEligibilityService::class)->qualityReason(
            $contact,
            Campaign::VERIFICATION_ALL_SENDABLE,
        ));
    }

    public function test_all_sendable_keeps_pending_in_the_audience_but_blocks_transport(): void
    {
        $contact = $this->contactModel('pending');
        $service = app(ContactEligibilityService::class);

        $this->assertNull($service->audienceQualityReason(
            $contact,
            Campaign::VERIFICATION_ALL_SENDABLE,
        ));
        $this->assertSame('verification_pending', $service->qualityReason(
            $contact,
            Campaign::VERIFICATION_ALL_SENDABLE,
        ));
        $this->assertSame('verification_pending', $service->audienceQualityReason(
            $contact,
            Campaign::VERIFICATION_VERIFIED_ONLY,
        ));
    }

    public function test_send_check_tests_suppression_once_and_keeps_cold_gate(): void
    {
        config()->set('prospecting.cold_send_enabled', true);
        $company = Company::factory()->create(['relationship' => 'prospect']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'quality@example.test',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ])->load('company');
        Suppression::create([
            'contact_id' => $contact->id,
            'email' => 'quality@example.test',
            'reason' => 'manual',
            'source' => 'manual',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reason = app(ContactEligibilityService::class)
            ->sendIneligibilityReasonForSingle($contact, Campaign::VERIFICATION_VERIFIED_ONLY);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('suppressed', $reason);
        $this->assertCount(1, $queries);

        config()->set('prospecting.cold_send_enabled', false);
        $this->assertSame('cold_send_disabled', app(ContactEligibilityService::class)
            ->sendIneligibilityReason($contact, Campaign::VERIFICATION_VERIFIED_ONLY, false));
    }

    public function test_bounce_evidence_blocks_both_policies_before_transport(): void
    {
        config()->set('prospecting.cold_send_enabled', true);
        $contact = Contact::factory()->create([
            'company_id' => Company::factory()->create(['relationship' => 'client'])->id,
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ])->load('company');
        $run = $this->campaignRun();
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
        ]);
        app(CampaignFeedbackService::class)
            ->apply($recipient, 'soft_bounce', now(), 'Temporary failure', 'dsn');
        $contact->refresh()->load('company');

        $service = app(ContactEligibilityService::class);
        $this->assertSame('bounced', $service->sendIneligibilityReasonForSingle(
            $contact,
            Campaign::VERIFICATION_VERIFIED_ONLY,
        ));
        $this->assertSame('bounced', $service->sendIneligibilityReasonForSingle(
            $contact,
            Campaign::VERIFICATION_ALL_SENDABLE,
        ));

        $contact->update(['email' => 'replacement-after-bounce@example.test']);
        $this->assertNull($service->sendIneligibilityReasonForSingle(
            $contact->fresh()->load('company'),
            Campaign::VERIFICATION_ALL_SENDABLE,
        ));
    }

    public function test_schema_keeps_evidence_and_removes_legacy_contact_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('contacts', [
            'email_verification_status',
            'email_verification_checked_at',
            'email_verification_source',
        ]));
        $this->assertFalse(Schema::hasColumn('contacts', 'status'));
        $this->assertFalse(Schema::hasColumn('contacts', 'legal_basis'));
        $this->assertFalse(Schema::hasColumn('contacts', 'consent_at'));
        $this->assertTrue(Schema::hasColumn('campaigns', 'email_verification_policy'));
        $this->assertFalse(Schema::hasColumn('sequences', 'stop_on_reply'));
    }

    public function test_policy_check_adds_no_query_per_contact(): void
    {
        $company = new Company(['relationship' => 'prospect']);
        $contacts = collect(range(1, 1000))->map(function (int $index) use ($company): Contact {
            $contact = $this->contactModel($index % 2 === 0 ? 'valid' : null);
            $contact->setRelation('company', $company);

            return $contact;
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        $contacts->each(fn (Contact $contact) => app(ContactEligibilityService::class)
            ->qualityReason($contact, Campaign::VERIFICATION_ALL_SENDABLE));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }

    private function contactModel(?string $status): Contact
    {
        $contact = new Contact([
            'email' => 'contact-'.uniqid().'@example.test',
            'email_kind' => 'role',
        ]);
        $contact->setAttribute('email_verification_status', $status);
        $contact->setAttribute('email_verification_source', $status === null ? null : 'hunter');
        $contact->setAttribute('email_verification_checked_at', $status === null || $status === 'pending' ? null : now());

        return $contact;
    }

    private function campaignRun(): CampaignRun
    {
        $campaign = Campaign::create([
            'name' => 'Eligibility bounce',
            'segment_id' => Segment::create(['name' => 'Eligibility', 'scope' => 'client'])->id,
            'template_id' => CampaignTemplate::create([
                'name' => 'Eligibility',
                'subject' => 'Hello',
                'html_content' => '<p>Hello</p>',
            ])->id,
            'sender_identity_id' => SenderIdentity::create([
                'name' => 'Eligibility',
                'email' => 'eligibility-sender@example.test',
            ])->id,
        ]);

        return CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => uniqid('eligibility-', true),
            'run_at' => now(),
        ]);
    }
}
