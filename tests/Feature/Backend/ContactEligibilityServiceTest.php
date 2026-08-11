<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Suppression;
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
    public function test_verification_policy(
        ?string $status,
        ?string $source,
        bool $sendTime,
        bool $feedback,
        ?string $expectedReason,
    ): void {
        $contact = $this->contact([
            'email_verification_status' => $status,
            'email_verification_source' => $source,
            'email_verification_checked_at' => now(),
        ]);

        $this->assertSame(
            $expectedReason,
            app(ContactEligibilityService::class)->qualityReason(
                $contact->load('company'),
                $sendTime,
                $feedback,
            ),
        );
    }

    public static function verificationPolicy(): array
    {
        return [
            'valid' => ['valid', 'hunter', true, false, null],
            'accept_all segment risk' => ['accept_all', 'hunter', false, false, null],
            'accept_all unsafe send' => ['accept_all', 'hunter', true, false, 'accept_all_feedback_required'],
            'accept_all safe send' => ['accept_all', 'hunter', true, true, null],
            'unknown' => ['unknown', 'hunter', true, true, 'verification_required'],
            'pending' => ['pending', 'hunter', true, true, 'verification_required'],
            'manual approval' => ['unknown', 'manual', true, true, null],
            'missing' => [null, null, true, true, 'verification_required'],
            'invalid' => ['invalid', 'hunter', true, true, 'invalid_email'],
            'disposable' => ['disposable', 'hunter', true, true, 'disposable_email'],
            'webmail prospect' => ['webmail', 'hunter', true, true, 'personal_email'],
        ];
    }

    public function test_stale_valid_verification_requires_reverification(): void
    {
        config()->set('prospecting.email_verification_ttl_days', 90);
        $contact = $this->contact([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now()->subDays(91),
        ])->load('company');

        $this->assertSame(
            'verification_required',
            app(ContactEligibilityService::class)->qualityReason($contact, true, true),
        );
    }

    public function test_send_check_tests_suppression_once(): void
    {
        config()->set('prospecting.cold_send_enabled', true);
        $contact = $this->contact([
            'email' => '  QUALITY@example.test  ',
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
            ->sendIneligibilityReasonForSingle($contact, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('suppressed', $reason);
        $this->assertCount(1, $queries);
    }

    public function test_personal_prospect_is_blocked(): void
    {
        $contact = $this->contact([
            'email_kind' => 'personal',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ])->load('company');

        $this->assertSame(
            'personal_email',
            app(ContactEligibilityService::class)->qualityReason($contact),
        );
    }

    public function test_personal_client_is_not_blocked_by_email_kind(): void
    {
        $contact = $this->contact([
            'email_kind' => 'personal',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ], 'client')->load('company');

        $this->assertNull(app(ContactEligibilityService::class)->qualityReason($contact));
    }

    public function test_cold_send_flag_does_not_empty_segment_preview_but_blocks_send(): void
    {
        config()->set('prospecting.cold_send_enabled', false);
        $contact = $this->contact([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ])->load('company');
        $service = app(ContactEligibilityService::class);

        $this->assertNull($service->qualityReason($contact));
        $this->assertSame(
            'cold_send_disabled',
            $service->sendIneligibilityReason($contact, false, false),
        );
    }

    public function test_segment_quality_filter_adds_no_query_per_contact_for_one_thousand_contacts(): void
    {
        $company = new Company(['relationship' => 'prospect']);
        $contacts = collect(range(1, 1000))->map(function (int $index) use ($company): Contact {
            $contact = new Contact([
                'email' => "quality-{$index}@example.test",
                'email_kind' => 'role',
            ]);
            $contact->setAttribute('email_verification_status', 'valid');
            $contact->setAttribute('email_verification_source', 'hunter');
            $contact->setAttribute('email_verification_checked_at', now());
            $contact->setRelation('company', $company);

            return $contact;
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service = app(ContactEligibilityService::class);
        $reasons = $contacts->map(fn (Contact $contact): ?string => $service->qualityReason($contact));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($reasons->every(fn (?string $reason): bool => $reason === null));
        $this->assertCount(0, $queries);
    }

    public function test_missing_company_context_fails_closed_without_lazy_loading(): void
    {
        config()->set('prospecting.cold_send_enabled', false);
        $contact = new Contact([
            'email' => 'role@example.test',
            'email_kind' => 'role',
        ]);
        $contact->setAttribute('email_verification_status', 'valid');
        $contact->setAttribute('email_verification_source', 'hunter');
        $contact->setAttribute('email_verification_checked_at', now());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reason = app(ContactEligibilityService::class)
            ->sendIneligibilityReason($contact, true, false);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('cold_send_disabled', $reason);
        $this->assertCount(0, $queries);
    }

    public function test_badges_are_safe_configured_values_and_manual_approval_is_distinct(): void
    {
        $service = app(ContactEligibilityService::class);
        $valid = $this->contactModel('valid', 'hunter');
        $acceptAll = $this->contactModel('accept_all', 'hunter');
        $missing = $this->contactModel(null, null);
        $manual = $this->contactModel('unknown', 'manual');

        $this->assertSame(['label' => 'Valide', 'color' => 'success', 'risk' => false], $service->badge($valid));
        $this->assertSame(['label' => 'Accept-all', 'color' => 'warning', 'risk' => true], $service->badge($acceptAll));
        $this->assertSame(['label' => 'Non vérifié', 'color' => 'secondary', 'risk' => true], $service->badge($missing));
        $this->assertSame(['label' => 'Approuvé manuellement', 'color' => 'info', 'risk' => false], $service->badge($manual));
    }

    public function test_schema_and_model_metadata_persist_email_safety_evidence(): void
    {
        $this->assertTrue(Schema::hasColumns('contacts', [
            'email_verification_checked_at',
            'email_verification_source',
        ]));
        $this->assertTrue(Schema::hasColumn('campaign_recipients', 'bounce_type'));
        $this->assertTrue(Schema::hasColumns('sequence_step_sends', [
            'bounce_type',
            'bounce_reason',
            'bounced_at',
        ]));

        $contact = $this->contact([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now()->startOfSecond(),
        ]);

        $this->assertSame('hunter', $contact->fresh()->email_verification_source);
        $this->assertTrue($contact->fresh()->email_verification_checked_at->equalTo(now()->startOfSecond()));
    }

    private function contact(array $overrides = [], string $relationship = 'prospect'): Contact
    {
        $company = Company::factory()->create(['relationship' => $relationship]);

        return Contact::factory()->create(array_merge([
            'company_id' => $company->id,
            'email_kind' => 'role',
        ], $overrides));
    }

    private function contactModel(?string $status, ?string $source): Contact
    {
        $contact = new Contact;
        $contact->setAttribute('email_verification_status', $status);
        $contact->setAttribute('email_verification_source', $source);

        return $contact;
    }
}
