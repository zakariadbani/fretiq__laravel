<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    private function dataTableJson(string $path, array $columns, string $search)
    {
        return $this->actingAs($this->superadmin)
            ->call('GET', $path, [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'search' => ['value' => $search],
                'columns' => array_map(fn ($column) => [
                    'data' => $column,
                    'name' => $column,
                    'searchable' => 'true',
                    'orderable' => $column === 'id' ? 'true' : 'false',
                ], $columns),
                'order' => [['column' => 0, 'dir' => 'asc']],
            ], [], [], [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_ACCEPT' => 'application/json',
            ]);
    }

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Company ' . uniqid(),
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'is_active' => true,
        ], $overrides));
    }

    public function test_companies_global_search_matches_visible_domain(): void
    {
        $matching = $this->makeCompany(['name' => 'Alpha Logistics', 'domain' => 'visible-domain.test']);
        $other = $this->makeCompany(['name' => 'Beta Logistics', 'domain' => 'other-domain.test']);

        $response = $this->dataTableJson('/admin/companies', ['id', 'name', 'sector', 'country'], 'visible-domain');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->name)
            ->assertDontSee($other->name);
    }

    public function test_contacts_global_search_matches_related_company(): void
    {
        $matchingCompany = $this->makeCompany(['name' => 'Needle Transport']);
        $otherCompany = $this->makeCompany(['name' => 'Haystack Transport']);
        $matching = Contact::create([
            'company_id' => $matchingCompany->id,
            'email' => 'match@example.test',
            'name' => 'Match Contact',
        ]);
        $other = Contact::create([
            'company_id' => $otherCompany->id,
            'email' => 'other@example.test',
            'name' => 'Other Contact',
        ]);

        $response = $this->dataTableJson('/admin/contacts', ['id', 'name', 'email', 'position', 'company'], 'needle');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->email)
            ->assertDontSee($other->email);
    }

    public function test_campaigns_global_search_matches_related_sender(): void
    {
        $segment = Segment::create(['name' => 'Default Segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Default Template',
            'subject' => 'Hello',
            'html_content' => '<p>Hello</p>',
        ]);
        $matchingSender = SenderIdentity::create(['name' => 'Needle Sender', 'email' => 'needle@example.test']);
        $otherSender = SenderIdentity::create(['name' => 'Other Sender', 'email' => 'other@example.test']);
        $matching = Campaign::create([
            'name' => 'Match Campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $matchingSender->id,
        ]);
        $other = Campaign::create([
            'name' => 'Other Campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $otherSender->id,
        ]);

        $response = $this->dataTableJson('/admin/campaigns', [
            'id',
            'name',
            'segment_id',
            'template_id',
            'sender_identity_id',
        ], 'needle sender');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->name)
            ->assertDontSee($other->name);
    }

    public function test_demandes_global_search_matches_related_company_and_source(): void
    {
        $matchingCompany = $this->makeCompany(['name' => 'Needle Freight']);
        $otherCompany = $this->makeCompany(['name' => 'Other Freight']);
        $matchingContact = Contact::create([
            'company_id' => $matchingCompany->id,
            'email' => 'demande-match@example.test',
            'name' => 'Match Contact',
        ]);
        $otherContact = Contact::create([
            'company_id' => $otherCompany->id,
            'email' => 'demande-other@example.test',
            'name' => 'Other Contact',
        ]);
        $sequence = Sequence::create(['name' => 'Needle Sequence', 'is_active' => true, 'stop_on_reply' => false]);
        $matching = Demande::create([
            'contact_id' => $matchingContact->id,
            'sequence_id' => $sequence->id,
            'kind' => 'reply',
            'captured_at' => now(),
        ]);
        $other = Demande::create([
            'contact_id' => $otherContact->id,
            'kind' => 'reply',
            'captured_at' => now(),
        ]);

        $response = $this->dataTableJson('/admin/demandes', [
            'id',
            'contact',
            'company',
            'source',
            'kind',
            'status',
        ], 'needle');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->contact->email)
            ->assertDontSee($other->contact->email);
    }
}
