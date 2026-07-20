<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Onboarding\FirstUseChecklistService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstUseChecklistTest extends TestCase
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

    public function test_campaign_index_does_not_show_first_use_checklist(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/campaigns')
            ->assertOk()
            ->assertDontSee('Première campagne : ordre de préparation', false);
    }

    public function test_checklist_marks_existing_setup_complete(): void
    {
        config([
            'services.zoho.driver' => 'zoho',
            'services.zoho.campaigns.refresh_token' => 'test-refresh-token',
            'prospecting.cold_send_enabled' => true,
            'app.url' => 'https://fretiq.test',
        ]);

        $sender = SenderIdentity::create([
            'name' => 'TCL France',
            'email' => 'prospection@tcl.test',
            'is_active' => true,
        ]);
        $segment = Segment::create(['name' => 'Chargeurs France', 'scope' => 'prospect']);
        $template = CampaignTemplate::create([
            'name' => 'Premier contact',
            'subject' => 'Votre transport fret',
            'html_content' => '<p>Bonjour</p>',
        ]);
        Campaign::create([
            'name' => 'Campagne test',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);

        $this->actingAs($this->superadmin);

        $checklist = app(FirstUseChecklistService::class)->checklist();

        $this->assertTrue($checklist['complete']);
        $this->assertTrue(collect($checklist['items'])->every(fn (array $item) => $item['complete']));
    }

    public function test_sender_identity_action_uses_acl_permission_entity(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo('create sender_identities');

        $this->actingAs($user);

        $item = collect(app(FirstUseChecklistService::class)->checklist()['items'])
            ->firstWhere('label', 'Identité expéditeur');

        $this->assertNotNull($item);
        $this->assertSame(route('admin.sender_identities.create'), $item['url']);
        $this->assertSame('Configurer', $item['action']);
    }

}
