<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Package;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudHeaderActionsTest extends TestCase
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

    public function test_all_crud_view_and_edit_pages_share_header_actions_and_toolbar(): void
    {
        foreach ($this->records() as $module => [$model, $operationalMarker]) {
            $view = $this->actingAs($this->superadmin)->get("/admin/{$module}/{$model->id}");
            $edit = $this->actingAs($this->superadmin)->get("/admin/{$module}/{$model->id}/edit");

            $view->assertOk();
            $edit->assertOk();

            $viewBody = $view->getContent();
            $editBody = $edit->getContent();
            $editHref = route("admin.{$module}.edit", $model->id);

            $this->assertSame(1, substr_count($viewBody, 'Retour à la liste'), "{$module} view toolbar");
            $this->assertSame(1, substr_count($editBody, 'Retour à la liste'), "{$module} edit toolbar");
            $this->assertStringContainsString('d-flex align-items-center gap-2 gap-lg-3', $viewBody, "{$module} view top toolbar");
            $this->assertStringContainsString('d-flex align-items-center gap-2 gap-lg-3', $editBody, "{$module} edit top toolbar");
            $this->assertStringContainsString('d-flex flex-wrap gap-2 mb-2', $viewBody, "{$module} view hero actions");
            $this->assertStringContainsString('d-flex flex-wrap gap-2 mb-2', $editBody, "{$module} edit hero actions");
            $this->assertStringContainsString('href="' . $editHref . '" class="btn btn-sm btn-primary"', $viewBody, "{$module} view edit action");
            $this->assertStringNotContainsString('href="' . $editHref . '" class="btn btn-sm btn-primary"', $editBody, "{$module} edit must not repeat edit action");

            if ($operationalMarker !== null) {
                $this->assertStringContainsString($operationalMarker, $viewBody, "{$module} view operational action");
                $this->assertStringContainsString($operationalMarker, $editBody, "{$module} edit operational action");
            }
        }
    }

    public function test_archived_company_keeps_archive_return_and_restore_action(): void
    {
        $company = Company::create([
            'name' => 'Entreprise archivée',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'rejected',
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->superadmin)->get("/admin/companies/{$company->id}");

        $response->assertOk();
        $body = $response->getContent();
        $this->assertSame(1, substr_count($body, 'Retour aux archives'));
        $this->assertSame(0, substr_count($body, 'Retour à la liste'));
        $this->assertStringContainsString(route('admin.companies.archive'), $body);
        $this->assertStringContainsString('Restaurer', $body);
        $this->assertStringNotContainsString(route('admin.companies.edit', $company->id), $body);
    }

    /** @return array<string, array{Model, string|null}> */
    private function records(): array
    {
        $company = Company::create([
            'name' => 'Entreprise matrice',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
            'is_active' => true,
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'name' => 'Contact matrice',
            'email' => 'contact-matrice@example.test',
            'source' => 'manual',
            'status' => 'new',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $targetUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $targetUser->assignRole('admin');

        return [
            'companies' => [$company, 'company_id=' . $company->id],
            'contacts' => [$contact, 'contact_id=' . $contact->id],
            'demandes' => [Demande::create(['kind' => 'reply', 'status' => 'pending', 'captured_at' => now()]), null],
            'campaign_templates' => [CampaignTemplate::create(['name' => 'Modèle matrice', 'subject' => 'Sujet', 'html_content' => '<p>Test</p>']), 'template_id='],
            'packages' => [Package::create(['name' => 'Pack matrice', 'daily_credits' => 10, 'price_monthly' => 10, 'is_active' => true, 'sort_order' => 1]), null],
            'prospect_criteria' => [ProspectCriteria::create(['name' => 'Critère matrice', 'sectors' => ['Transport'], 'countries' => ['FR'], 'daily_limit' => 10, 'is_active' => true]), 'data-discovery-launch'],
            'segments' => [Segment::create(['name' => 'Segment matrice', 'scope' => 'client']), 'segment_id='],
            'sender_identities' => [SenderIdentity::create(['name' => 'Expéditeur matrice', 'email' => 'sender-matrice@example.test', 'is_default' => false, 'is_active' => true]), null],
            'sequences' => [Sequence::create(['name' => 'Séquence matrice', 'is_active' => true, 'stop_on_reply' => false]), null],
            'suppressions' => [Suppression::create(['email' => 'blocked-matrice@example.test', 'reason' => 'manual', 'source' => 'manual']), null],
            'users' => [$targetUser, null],
        ];
    }
}
