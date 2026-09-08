<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SmtpSendReservation;
use App\Models\User;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\WaveProjectionService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WaveProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app->make('db')->connection()->getDatabaseName() !== 'fretiq_test') {
            throw new \RuntimeException('These tests require the disposable fretiq_test database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'local']);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    public function test_projects_the_capped_next_wave_and_excludes_every_existing_enrollment_status(): void
    {
        $segment = Segment::create(['name' => 'Projection', 'scope' => 'client']);
        $sequence = Sequence::create(['name' => 'Projection', 'is_active' => true, 'stop_on_reply' => false]);
        $template = CampaignTemplate::create([
            'name' => 'Projection',
            'subject' => 'Projection',
            'html_content' => '<p>Projection</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $template->id,
        ]);
        $campaign = $this->campaign($segment, $sequence, [
            'daily_company_limit' => 20,
            'next_run_at' => Carbon::parse('2026-08-03 08:00:00', 'UTC'),
            'timezone' => 'Europe/Paris',
        ]);

        $contacts = collect(range(1, 31))->map(function (int $score): Contact {
            $company = Company::create([
                'name' => "Company {$score}",
                'relationship' => 'client',
                'source' => 'manual',
                'qualification_status' => 'pending',
                'ai_score' => $score,
            ]);

            return Contact::create([
                'company_id' => $company->id,
                'email' => "projection-{$score}@example.test",
                'name' => "Contact {$score}",
                'status' => 'new',
                'source' => 'manual',
                'legal_basis' => 'relationship',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
            ]);
        });
        Contact::create([
            'company_id' => $contacts[29]->company_id,
            'email' => 'projection-second@example.test',
            'name' => 'Second contact',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
        ]);

        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contacts->last()->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'stopped',
        ]);

        $projection = app(WaveProjectionService::class)->projectNext($campaign);

        $this->assertSame(30, $projection['eligible_remaining_companies']);
        $this->assertSame(20, $projection['next_wave_companies']);
        $this->assertSame(21, $projection['next_wave_contacts']);
        $this->assertSame(2, $projection['projected_remaining_waves']);
        $this->assertSame(20, $projection['daily_limit']);
        $this->assertSame('03/08/2026 10:00', $projection['next_run_at']);

        $actual = app(PacedSequenceEnrollmentService::class)->activate(
            $campaign,
            Carbon::parse('2026-08-03 10:00:00', 'UTC'),
        );

        $this->assertSame($projection['next_wave_companies'], $actual['companies']);
        $this->assertSame($projection['next_wave_contacts'], $actual['enrolled']);
    }

    public function test_next_wave_preview_exposes_an_unsaved_daily_limit_override(): void
    {
        $segment = Segment::create(['name' => 'Preview', 'scope' => 'client']);
        $sequence = Sequence::create(['name' => 'Preview', 'is_active' => true, 'stop_on_reply' => false]);
        $campaign = $this->campaign($segment, $sequence, ['daily_company_limit' => 20]);

        $contacts = collect();
        foreach (range(1, 3) as $index) {
            $company = Company::create([
                'name' => "Preview {$index}",
                'relationship' => 'client',
                'source' => 'manual',
                'qualification_status' => 'pending',
                'ai_score' => $index,
            ]);
            $contacts->push(Contact::create([
                'company_id' => $company->id,
                'email' => "preview-{$index}@example.test",
                'name' => "Preview {$index}",
                'status' => 'new',
                'source' => 'manual',
                'legal_basis' => 'relationship',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
            ]));
        }

        $overrideSegment = Segment::create([
            'name' => 'Preview override',
            'scope' => 'client',
            'is_manual' => true,
        ]);
        $overrideSegment->includedContacts()->attach($contacts->first()->id, ['mode' => 'include']);

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->getJson("/admin/campaigns/{$campaign->id}/next-wave-preview?daily_company_limit=2")
            ->assertOk()
            ->assertJson([
                'is_sequence_paced' => true,
                'eligible_remaining_companies' => 3,
                'next_wave_companies' => 2,
                'next_wave_contacts' => 2,
                'projected_remaining_waves' => 2,
                'daily_limit' => 2,
            ]);

        $this->actingAs($admin)
            ->getJson("/admin/campaigns/{$campaign->id}/next-wave-preview?segment_id={$overrideSegment->id}&daily_company_limit=2")
            ->assertOk()
            ->assertJson([
                'is_sequence_paced' => true,
                'eligible_remaining_companies' => 1,
                'next_wave_companies' => 1,
                'next_wave_contacts' => 1,
                'projected_remaining_waves' => 1,
                'daily_limit' => 2,
            ]);
    }

    public function test_next_wave_preview_uses_the_continuous_selector_for_multi_contact_and_excluded_companies(): void
    {
        $segment = Segment::create([
            'name' => 'Continuous preview',
            'scope' => 'prospect',
            'filter' => ['prospecting_rules' => ['enabled' => true]],
        ]);
        $sequence = Sequence::create(['name' => 'Continuous preview', 'is_active' => true, 'stop_on_reply' => false]);
        $campaign = $this->campaign($segment, $sequence, [
            'delivery_channel' => 'smtp',
            'daily_company_limit' => 10,
            'email_verification_policy' => Campaign::VERIFICATION_VERIFIED_ONLY,
        ]);

        $staleCompany = Company::create(['name' => 'Stale first', 'relationship' => 'prospect', 'is_active' => true, 'ai_score' => 100]);
        Contact::create([
            'company_id' => $staleCompany->id, 'email' => 'stale-first@example.test', 'name' => 'Stale first',
            'source' => 'manual', 'email_kind' => 'role', 'email_verification_status' => 'valid',
            'email_verification_checked_at' => now()->subDays(31),
        ]);
        $firstEligibleCompany = null;
        foreach (range(1, 10) as $index) {
            $company = Company::create([
                'name' => "Continuous {$index}", 'relationship' => 'prospect', 'is_active' => true, 'ai_score' => 100 - $index,
                'country' => $index === 1 ? 'CH' : 'FR',
            ]);
            $firstEligibleCompany ??= $company;
            foreach (range(1, 2) as $contactIndex) {
                Contact::create([
                    'company_id' => $company->id, 'email' => "continuous-{$index}-{$contactIndex}@example.test", 'name' => "Continuous {$index}",
                    'source' => 'manual', 'email_kind' => 'role', 'email_verification_status' => 'valid',
                    'email_verification_checked_at' => now(),
                ]);
            }
        }

        $campaignSnapshot = $campaign->only(['segment_id', 'daily_company_limit', 'email_verification_policy']);
        $segmentFilter = $segment->filter;

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->getJson("/admin/campaigns/{$campaign->id}/next-wave-preview")
            ->assertOk()
            ->assertJson([
                'is_sequence_paced' => true,
                'continuous_prospecting' => true,
                'next_wave_companies' => 10,
                'next_wave_contacts' => 10,
                'daily_limit' => 10,
                'eligible_remaining_companies' => null,
                'projected_remaining_waves' => null,
                'scanned_companies' => 11,
                'scan_capped' => false,
                'excluded_reasons' => ['verification_stale' => 1],
                'research_pool_count' => 21,
            ]);

        $override = Segment::create([
            'name' => 'Continuous unsaved override',
            'scope' => 'prospect',
            'filter' => ['country' => ['CH'], 'prospecting_rules' => ['enabled' => true]],
        ]);
        $emptyOverride = Segment::create([
            'name' => 'Continuous unsaved empty override',
            'scope' => 'prospect',
            'filter' => ['country' => ['NZ'], 'prospecting_rules' => ['enabled' => true]],
        ]);

        $this->actingAs($admin)
            ->getJson("/admin/campaigns/{$campaign->id}/next-wave-preview?segment_id={$override->id}&daily_company_limit=2&email_verification_policy=all_sendable")
            ->assertOk()
            ->assertJson([
                'continuous_prospecting' => true,
                'next_wave_companies' => 1,
                'next_wave_contacts' => 1,
                'daily_limit' => 2,
                'scanned_companies' => 1,
                'research_pool_count' => 2,
            ]);

        $this->actingAs($admin)
            ->getJson("/admin/campaigns/{$campaign->id}/next-wave-preview?segment_id={$emptyOverride->id}&daily_company_limit=2")
            ->assertOk()
            ->assertJson([
                'continuous_prospecting' => true,
                'next_wave_companies' => 0,
                'next_wave_contacts' => 0,
                'daily_limit' => 2,
                'scanned_companies' => 0,
                'research_pool_count' => 0,
            ]);

        $this->assertSame($campaignSnapshot, $campaign->fresh()->only(['segment_id', 'daily_company_limit', 'email_verification_policy']));
        $this->assertSame($segmentFilter, $segment->fresh()->filter);
        $this->assertSame($override->filter, $override->fresh()->filter);
        $this->assertSame($emptyOverride->filter, $emptyOverride->fresh()->filter);
        $this->assertSame(0, SequenceEnrollment::count());
        $this->assertSame(0, CampaignRecipient::count());
        $this->assertSame(0, SmtpSendReservation::count());
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    private function campaign(Segment $segment, Sequence $sequence, array $overrides = []): Campaign
    {
        $sender = SenderIdentity::create([
            'name' => 'Projection sender',
            'email' => uniqid('projection-sender-') . '@example.test',
            'is_active' => true,
        ]);

        return Campaign::create(array_merge([
            'name' => 'Projection campaign',
            'segment_id' => $segment->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'daily_company_limit' => 20,
            'next_run_at' => now()->addDay(),
            'timezone' => 'UTC',
            'driver' => 'local',
        ], $overrides));
    }
}
