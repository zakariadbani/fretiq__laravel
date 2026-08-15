<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Services\Prospecting\ContactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactLifecycleStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_nine_states_and_priority_are_projected_in_one_query(): void
    {
        $contacts = collect([
            'unsubscribed' => $this->contact(),
            'blocked' => $this->contact(),
            'bounced' => $this->contact([
                'email_verification_status' => 'invalid',
                'email_verification_source' => 'bounce',
            ]),
            'invalid_email' => $this->contact(['email_verification_status' => 'invalid']),
            'replied' => $this->contact(),
            'contacted' => $this->contact(),
            'verified' => $this->contact(['email_verification_status' => 'valid']),
            'verification_pending' => $this->contact(['email_verification_status' => 'pending']),
            'needs_verification' => $this->contact(),
        ]);

        Suppression::create(['email' => $contacts['unsubscribed']->email, 'contact_id' => $contacts['unsubscribed']->id, 'reason' => 'unsubscribe', 'source' => 'manual']);
        Suppression::create(['email' => $contacts['blocked']->email, 'contact_id' => $contacts['blocked']->id, 'reason' => 'manual', 'source' => 'manual']);
        InboxEmail::create([
            'message_id' => 'reply-'.uniqid().'@example.test',
            'from_email' => $contacts['replied']->email,
            'contact_id' => $contacts['replied']->id,
            'received_at' => now(),
        ]);
        $run = $this->campaignRun();
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contacts['contacted']->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = app(ContactLifecycleService::class)
            ->select(Contact::query()->whereIn('id', $contacts->pluck('id')))
            ->get()
            ->keyBy('id');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($contacts as $state => $contact) {
            $this->assertSame($state, $rows[$contact->id]->lifecycle_state);
        }
        $this->assertCount(1, $queries);

        InboxEmail::create([
            'message_id' => 'priority-'.uniqid().'@example.test',
            'from_email' => $contacts['unsubscribed']->email,
            'contact_id' => $contacts['unsubscribed']->id,
            'received_at' => now(),
        ]);
        $this->assertSame('unsubscribed', app(ContactLifecycleService::class)->stateFor($contacts['unsubscribed']));
    }

    public function test_filter_and_sort_use_business_priority(): void
    {
        $needs = $this->contact();
        $verified = $this->contact(['email_verification_status' => 'valid']);
        $blocked = $this->contact();
        Suppression::create(['email' => $blocked->email, 'contact_id' => $blocked->id, 'reason' => 'complaint', 'source' => 'campaign']);

        $service = app(ContactLifecycleService::class);
        $filtered = $service->applyState(Contact::query(), 'verified')->pluck('id')->all();
        $ordered = $service->orderByState(
            $service->select(Contact::query()->whereIn('id', [$needs->id, $verified->id, $blocked->id])),
        )->pluck('lifecycle_state')->all();

        $this->assertSame([$verified->id], $filtered);
        $this->assertSame(['blocked', 'verified', 'needs_verification'], $ordered);
    }

    public function test_relation_query_projects_lifecycle_state(): void
    {
        $company = Company::factory()->create(['relationship' => 'client']);
        $contact = $this->contact(['company_id' => $company->id]);

        $rows = app(ContactLifecycleService::class)
            ->select($company->contacts())
            ->get()
            ->keyBy('id');

        $this->assertCount(1, $rows);
        $this->assertSame('needs_verification', $rows[$contact->id]->lifecycle_state);
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create(array_merge([
            'company_id' => Company::factory()->create(['relationship' => 'client'])->id,
            'email_verification_source' => isset($attributes['email_verification_status']) ? 'import' : null,
            'email_verification_checked_at' => isset($attributes['email_verification_status']) && $attributes['email_verification_status'] !== 'pending' ? now() : null,
        ], $attributes));
    }

    private function campaignRun(): CampaignRun
    {
        $segment = Segment::create(['name' => 'Lifecycle', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Lifecycle', 'subject' => 'Hello', 'html_content' => '<p>Hello</p>']);
        $sender = SenderIdentity::create(['name' => 'Lifecycle', 'email' => 'sender@example.test']);
        $campaign = Campaign::create([
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'name' => 'Lifecycle',
            'email_verification_policy' => Campaign::VERIFICATION_VERIFIED_ONLY,
        ]);

        return CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => uniqid('lifecycle-', true),
            'run_at' => now(),
        ]);
    }
}
