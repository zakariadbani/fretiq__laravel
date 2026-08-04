<?php

namespace Tests\Feature\Backend;

use App\Crud\ViewConfigs\CompanyViewConfig;
use App\Crud\ViewConfigs\ContactViewConfig;
use App\Crud\ViewConfigs\SegmentViewConfig;
use App\Crud\ViewConfigs\CampaignTemplateViewConfig;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceContextSelectionTest extends TestCase
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

    public function test_contacts_search_is_permission_gated(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo(['backend.access', 'create demandes']);

        $this->actingAs($user)
            ->getJson('/admin/demandes/contacts/search')
            ->assertForbidden();
    }

    public function test_demande_store_does_not_persist_hidden_contact_id_without_contact_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo(['backend.access', 'create demandes']);

        $contact = Contact::factory()->create();

        $this->actingAs($user)
            ->postJson('/admin/demandes', [
                'contact_id' => $contact->id,
                'kind' => 'hidden-contact-proof',
                'status' => 'pending',
                'captured_at' => now()->toDateTimeString(),
            ])
            ->assertOk();

        $this->assertDatabaseMissing('demandes', [
            'contact_id' => $contact->id,
            'kind' => 'hidden-contact-proof',
        ]);
        $this->assertDatabaseHas('demandes', [
            'contact_id' => null,
            'kind' => 'hidden-contact-proof',
        ]);
    }

    public function test_contacts_search_returns_disambiguating_paginated_results(): void
    {
        $company = Company::factory()->create(['name' => 'Acme Logistics']);

        foreach (range(1, 21) as $i) {
            Contact::factory()->create([
                'company_id' => $company->id,
                'name' => sprintf('Jordan Contact %02d', $i),
                'email' => sprintf('jordan%02d@acme.test', $i),
            ]);
        }

        $response = $this->actingAs($this->superadmin)
            ->getJson('/admin/demandes/contacts/search?q=Acme&page=1');

        $response->assertOk()
            ->assertJsonPath('pagination.more', true)
            ->assertJsonCount(20, 'results');

        $first = $response->json('results.0.text');
        $this->assertStringContainsString('Jordan Contact', $first);
        $this->assertStringContainsString('@acme.test', $first);
        $this->assertStringContainsString('Acme Logistics', $first);

        $this->actingAs($this->superadmin)
            ->getJson('/admin/demandes/contacts/search?q=Acme&page=2')
            ->assertOk()
            ->assertJsonPath('pagination.more', false)
            ->assertJsonCount(1, 'results');
    }

    public function test_demande_create_preserves_contact_context_without_full_contact_preload(): void
    {
        $selected = Contact::factory()->create([
            'name' => 'Selected Contact',
            'email' => 'selected@example.test',
        ]);
        Contact::factory()->count(25)->create(['name' => 'Unrelated Contact']);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/demandes/create?contact_id=' . $selected->id);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('value="' . $selected->id . '" selected', $body);
        $this->assertStringContainsString('selected@example.test', $body);
        $this->assertStringNotContainsString('Unrelated Contact', $body);
    }

    public function test_campaign_create_preserves_authorized_segment_and_template_sources(): void
    {
        SenderIdentity::create(['name' => 'TCL', 'email' => 'tcl@example.test']);
        $segment = Segment::create(['name' => 'Segment Maritime', 'scope' => 'prospect']);
        $template = CampaignTemplate::create([
            'name' => 'Template Ports',
            'subject' => 'Ports',
            'html_content' => '<p>Ports</p>',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaigns/create?segment_id=' . $segment->id . '&template_id=' . $template->id);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('value="' . $segment->id . '"' . "\n                                            selected", $body);
        $this->assertStringContainsString('value="' . $template->id . '"', $body);
        $this->assertStringContainsString('Contexte source conserve', $body);
    }

    public function test_invalid_or_unauthorized_source_ids_are_ignored_on_create_forms(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo(['backend.access', 'create campaigns', 'create demandes']);

        $this->actingAs($user)
            ->get('/admin/campaigns/create?segment_id=999999&template_id=999999&company_id=999999')
            ->assertOk()
            ->assertDontSee('Contexte source conserve', false);

        $contact = Contact::factory()->create(['name' => 'Hidden Contact']);

        $this->actingAs($user)
            ->get('/admin/demandes/create?contact_id=' . $contact->id)
            ->assertOk()
            ->assertDontSee('Hidden Contact', false);
    }

    public function test_source_quick_actions_keep_route_context(): void
    {
        $company = Company::factory()->create();
        Contact::factory()->for($company)->create();
        $contact = Contact::factory()->create();
        $segment = Segment::create(['name' => 'Segment Test', 'scope' => 'prospect']);
        $template = CampaignTemplate::create([
            'name' => 'Template Test',
            'subject' => 'Subject',
            'html_content' => '<p>Body</p>',
        ]);

        $this->assertSame(
            route('admin.campaigns.create', ['company_id' => $company->id]),
            CompanyViewConfig::make($company)['quick_actions'][0]['href']
        );
        $this->assertSame(
            route('admin.demandes.create', ['contact_id' => $contact->id]),
            ContactViewConfig::make($contact)['quick_actions'][1]['href']
        );
        $this->assertSame(
            route('admin.campaigns.create', ['segment_id' => $segment->id]),
            SegmentViewConfig::make($segment, ['contacts_count' => 0])['quick_actions'][0]['href']
        );
        $this->assertSame(
            route('admin.campaigns.create', ['template_id' => $template->id]),
            CampaignTemplateViewConfig::make($template)['quick_actions'][0]['href']
        );
    }
}
