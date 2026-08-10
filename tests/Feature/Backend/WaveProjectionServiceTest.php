<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\WaveProjectionService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaveProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'local']);
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
