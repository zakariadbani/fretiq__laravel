<?php

namespace Tests\Feature\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;
use App\Services\Discovery\ContactUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContactUpsertVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    public function test_upsert_persists_status_checked_at_and_source(): void
    {
        $company = Company::factory()->create(['domain' => 'verified.test']);

        app(ContactUpsertService::class)->upsertFromHunter($company, 'verified.test', [[
            'value' => 'sales@verified.test',
            'verification' => [
                'status' => 'valid',
                'result' => 'undeliverable',
                'date' => '2026-08-01T12:34:56Z',
            ],
        ]]);

        $contact = Contact::where('email', 'sales@verified.test')->firstOrFail();
        $this->assertSame('valid', $contact->email_verification_status);
        $this->assertSame('hunter', $contact->email_verification_source);
        $this->assertSame('2026-08-01T12:34:56+00:00', $contact->email_verification_checked_at->toIso8601String());
    }

    public function test_blank_status_uses_legacy_result(): void
    {
        $company = Company::factory()->create(['domain' => 'legacy.test']);

        app(ContactUpsertService::class)->upsertFromHunter($company, 'legacy.test', [[
            'value' => 'sales@legacy.test',
            'verification' => ['status' => '', 'result' => 'risky'],
        ]]);

        $contact = Contact::where('email', 'sales@legacy.test')->firstOrFail();
        $this->assertSame('accept_all', $contact->email_verification_status);
        $this->assertSame('hunter', $contact->email_verification_source);
        $this->assertTrue($contact->email_verification_checked_at->equalTo($contact->source_captured_at));
    }

    public function test_missing_verification_does_not_null_wipe_existing_evidence(): void
    {
        $company = Company::factory()->create(['domain' => 'preserved.test']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'sales@preserved.test',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => '2026-08-01T12:34:56Z',
        ]);

        app(ContactUpsertService::class)->upsertFromHunter(
            $company,
            'preserved.test',
            [['value' => $contact->email, 'position' => 'Direction']],
        );

        $fresh = $contact->fresh();
        $this->assertSame('valid', $fresh->email_verification_status);
        $this->assertSame('hunter', $fresh->email_verification_source);
        $this->assertSame('2026-08-01T12:34:56+00:00', $fresh->email_verification_checked_at->toIso8601String());
    }

    public function test_unsupported_status_does_not_null_wipe_existing_evidence_or_use_legacy_result(): void
    {
        $company = Company::factory()->create(['domain' => 'unsupported.test']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'sales@unsupported.test',
            'email_verification_status' => 'unknown',
            'email_verification_source' => 'manual',
            'email_verification_checked_at' => '2026-07-31T01:02:03Z',
        ]);

        app(ContactUpsertService::class)->upsertFromHunter($company, 'unsupported.test', [[
            'value' => $contact->email,
            'verification' => [
                'status' => 'unsupported',
                'result' => 'deliverable',
                'date' => '2026-08-02T01:02:03Z',
            ],
        ]]);

        $fresh = $contact->fresh();
        $this->assertSame('unknown', $fresh->email_verification_status);
        $this->assertSame('manual', $fresh->email_verification_source);
        $this->assertSame('2026-07-31T01:02:03+00:00', $fresh->email_verification_checked_at->toIso8601String());
    }
}
