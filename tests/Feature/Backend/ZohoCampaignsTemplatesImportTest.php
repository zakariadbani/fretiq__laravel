<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoCampaignsTemplatesImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config([
            'services.zoho.campaigns.refresh_token' => 'fake-refresh-token',
            'services.zoho.campaigns.client_id' => 'fake-client-id',
            'services.zoho.campaigns.client_secret' => 'fake-client-secret',
            'services.zoho.campaigns.api_url' => 'https://campaigns.zoho.com/api/v1.1',
            'services.zoho.crm.accounts_url' => 'https://accounts.zoho.com',
        ]);
    }

    public function test_campaign_templates_import_uses_zoho_campaigns_oauth_sent_campaign_previews(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://accounts.zoho.com/oauth/v2/token' => Http::response([
                'access_token' => 'fake-campaigns-token',
                'expires_in' => 3600,
            ], 200),
            'https://campaigns.zoho.com/api/v1.1/recentcampaigns?*status=sent*' => Http::response([
                'recent_campaigns' => [
                    [
                        'campaign_key' => 'CK-001',
                        'campaign_name' => 'Newsletter Transport',
                        'subject' => 'Sujet transport',
                        'campaign_status' => 'Sent',
                        'sent_date_string' => '06 Jul 2026, 09:09 AM',
                        'campaign_preview' => 'preview.test/campaigns/CK-001',
                    ],
                    [
                        'campaign_key' => 'CK-002',
                        'campaign_name' => 'Relance Maritime',
                        'subject' => 'Sujet maritime',
                        'campaign_status' => 'Sent',
                        'sent_date_string' => '05 Jul 2026, 09:09 AM',
                        'campaign_preview' => 'https://preview.test/campaigns/CK-002',
                    ],
                ],
                'campaign_count' => 2,
            ], 200),
            'https://preview.test/campaigns/CK-001' => Http::response('<html><body><h1>Transport</h1></body></html>', 200),
            'https://preview.test/campaigns/CK-002' => Http::response('<html><body><p>Maritime</p></body></html>', 200),
        ]);

        $this->actingAs($this->makeAdmin())
            ->post('/admin/campaign_templates/import-zoho')
            ->assertRedirect(route('admin.campaign_templates.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, CampaignTemplate::count());
        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => 'campaigns:CK-001',
            'name' => 'Newsletter Transport',
            'subject' => 'Sujet transport',
        ]);
        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => 'campaigns:CK-002',
            'name' => 'Relance Maritime',
            'subject' => 'Sujet maritime',
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), 'recentcampaigns')
                && str_contains($request->url(), 'status=sent')
                && ($request->header('Authorization')[0] ?? '') === 'Zoho-oauthtoken fake-campaigns-token';
        });
    }

    public function test_english_zoho_campaign_import_creates_en_translation_instead_of_fr_base(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://accounts.zoho.com/oauth/v2/token' => Http::response([
                'access_token' => 'fake-campaigns-token',
                'expires_in' => 3600,
            ], 200),
            'https://campaigns.zoho.com/api/v1.1/recentcampaigns?*status=sent*' => Http::response([
                'recent_campaigns' => [
                    [
                        'campaign_key' => 'CK-EN',
                        'campaign_name' => 'TCL Morocco 3PL',
                        'subject' => 'Dear partners, your Morocco freight solution',
                        'campaign_status' => 'Sent',
                        'sent_date_string' => '09 Jul 2026, 09:09 AM',
                        'campaign_preview' => 'https://preview.test/campaigns/CK-EN',
                    ],
                ],
                'campaign_count' => 1,
            ], 200),
            'https://preview.test/campaigns/CK-EN' => Http::response('<html><body><p>Dear Partners, hope you are doing well.</p><p>Please note that we operate weekly trailers to Morocco.</p></body></html>', 200),
        ]);

        $this->actingAs($this->makeAdmin())
            ->post('/admin/campaign_templates/import-zoho')
            ->assertRedirect(route('admin.campaign_templates.index'))
            ->assertSessionHas('success');

        $template = CampaignTemplate::with('translations')->where('zoho_template_id', 'campaigns:CK-EN')->firstOrFail();

        $this->assertSame('', $template->subject);
        $this->assertSame('', $template->html_content);

        $translation = $template->translationFor('en');
        $this->assertNotNull($translation);
        $this->assertSame('Dear partners, your Morocco freight solution', $translation->subject);
        $this->assertStringContainsString('Dear Partners', $translation->html_content);
        $this->assertFalse($translation->is_ai_generated);
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }
}
