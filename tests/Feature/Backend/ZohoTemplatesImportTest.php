<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ZohoTemplatesImportTest — integration tests for Zoho CRM email-template import.
 *
 * Http::fake() stubs all outbound HTTP so no live Zoho calls are made.
 * Payload shapes mirror the live verified response (2026-06-10).
 *
 * Cross-module dedup fixture:
 *   Template id=ZT001 appears in BOTH Contacts AND Leads list responses.
 *   Template id=ZT002 appears only in Contacts.
 * → After import: exactly 2 campaign_templates rows (not 3).
 */
class ZohoTemplatesImportTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixture data ──────────────────────────────────────────────────────────

    private const ID_SHARED  = 'ZT001';
    private const ID_UNIQUE  = 'ZT002';

    private const TEMPLATE_SHARED = [
        'id'      => self::ID_SHARED,
        'name'    => 'Prospection Freight',
        'subject' => 'Bonjour, découvrez nos services',
        'module'  => 'Contacts',
    ];

    private const TEMPLATE_UNIQUE = [
        'id'      => self::ID_UNIQUE,
        'name'    => 'Relance Lead',
        'subject' => 'Suite à notre échange',
        'module'  => 'Contacts',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the full fake stub map for a single import run.
     *
     * Returns an array suitable for Http::fake([...]).
     * Because Http::fake() APPENDS stubs (first-registered wins for a given URL
     * pattern), callers must NOT call Http::fake() a second time on the same
     * patterns. Instead, build the map and call Http::fake([...]) exactly once
     * per logical import run.
     */
    private function buildFakeMap(
        string $sharedSubject = 'Bonjour, découvrez nos services',
        string $uniqueSubject = 'Suite à notre échange',
        string $sharedContent = '<p>Contenu HTML de prospection freight.</p>',
    ): array {
        return [
            // ── Auth token refresh ────────────────────────────────────────────
            'https://accounts.zoho.com/oauth/v2/token' => Http::response([
                'access_token' => 'fake-token-abc',
                'expires_in'   => 3600,
            ], 200),

            // ── CRM modules discovery ─────────────────────────────────────────
            'https://www.zohoapis.com/crm/v8/settings/modules' => Http::response([
                'modules' => [
                    ['api_name' => 'Contacts', 'api_supported' => true],
                    ['api_name' => 'Leads',    'api_supported' => true],
                ],
            ], 200),

            // ── Template list — Contacts ──────────────────────────────────────
            'https://www.zohoapis.com/crm/v8/settings/email_templates?*module=Contacts*' => Http::response([
                'email_templates' => [
                    ['id' => self::ID_SHARED, 'name' => 'Prospection Freight', 'subject' => $sharedSubject, 'module' => 'Contacts'],
                    ['id' => self::ID_UNIQUE, 'name' => 'Relance Lead',        'subject' => $uniqueSubject, 'module' => 'Contacts'],
                ],
                'info' => ['more_records' => false],
            ], 200),

            // ── Template list — Leads (ZT001 duplicate) ───────────────────────
            'https://www.zohoapis.com/crm/v8/settings/email_templates?*module=Leads*' => Http::response([
                'email_templates' => [
                    ['id' => self::ID_SHARED, 'name' => 'Prospection Freight', 'subject' => $sharedSubject, 'module' => 'Leads'],
                ],
                'info' => ['more_records' => false],
            ], 200),

            // ── Template detail — ZT001 (real shape: PLURAL key, single-item array) ──
            'https://www.zohoapis.com/crm/v8/settings/email_templates/' . self::ID_SHARED => Http::response([
                'email_templates' => [
                    [
                        'id'      => self::ID_SHARED,
                        'name'    => 'Prospection Freight',
                        'subject' => $sharedSubject,
                        'content' => $sharedContent,
                    ],
                ],
            ], 200),

            // ── Template detail — ZT002 (legacy singular wrapper — fallback coverage) ──
            'https://www.zohoapis.com/crm/v8/settings/email_templates/' . self::ID_UNIQUE => Http::response([
                'email_template' => [
                    'id'      => self::ID_UNIQUE,
                    'name'    => 'Relance Lead',
                    'subject' => $uniqueSubject,
                    'content' => '<p>Relance suite à notre échange précédent.</p>',
                ],
            ], 200),
        ];
    }

    /**
     * Call Http::fake() once with the full stub map.
     * Separate calls would append stubs; the first-registered match wins per URL.
     */
    private function fakeHttp(
        string $sharedSubject = 'Bonjour, découvrez nos services',
        string $uniqueSubject = 'Suite à notre échange',
        string $sharedContent = '<p>Contenu HTML de prospection freight.</p>',
    ): void {
        Http::preventStrayRequests();
        Http::fake($this->buildFakeMap($sharedSubject, $uniqueSubject, $sharedContent));
    }

    private function makeAdmin(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function makeCommercial(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->assignRole('commercial');

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test: import creates rows with correct field mapping
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_creates_templates_with_mapped_fields(): void
    {
        $this->fakeHttp();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/admin/campaign_templates/import-zoho')
            ->assertRedirect(route('admin.campaign_templates.index'));

        // Cross-module dedup: ZT001 appears in both Contacts + Leads → only 1 row.
        $this->assertSame(2, CampaignTemplate::count(), 'Expected exactly 2 templates (dedup by id)');

        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => self::ID_SHARED,
            'name'             => 'Prospection Freight',
            'subject'          => 'Bonjour, découvrez nos services',
        ]);

        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => self::ID_UNIQUE,
            'name'             => 'Relance Lead',
            'subject'          => 'Suite à notre échange',
        ]);

        // html_content must be populated
        $tpl = CampaignTemplate::where('zoho_template_id', self::ID_SHARED)->firstOrFail();
        $this->assertStringContainsString('<p>', $tpl->html_content);
    }

    public function test_import_discovers_templates_from_all_crm_modules(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://accounts.zoho.com/oauth/v2/token' => Http::response([
                'access_token' => 'fake-token-abc',
                'expires_in'   => 3600,
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/modules' => Http::response([
                'modules' => [
                    ['api_name' => 'Contacts', 'api_supported' => true],
                    ['api_name' => 'Leads',    'api_supported' => true],
                    ['api_name' => 'Deals',    'api_supported' => true],
                ],
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/email_templates?*module=Contacts*' => Http::response([
                'email_templates' => [
                    ['id' => 'ZT-CONTACT', 'name' => 'Template Contact', 'subject' => 'Sujet contact'],
                ],
                'info' => ['more_records' => false],
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/email_templates?*module=Leads*' => Http::response([
                'email_templates' => [],
                'info' => ['more_records' => false],
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/email_templates?*module=Deals*' => Http::response([
                'email_templates' => [
                    ['id' => 'ZT-DEAL', 'name' => 'Template Affaire', 'subject' => 'Sujet affaire'],
                ],
                'info' => ['more_records' => false],
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/email_templates/ZT-CONTACT' => Http::response([
                'email_templates' => [[
                    'id'      => 'ZT-CONTACT',
                    'name'    => 'Template Contact',
                    'subject' => 'Sujet contact',
                    'content' => '<p>Contact</p>',
                ]],
            ], 200),
            'https://www.zohoapis.com/crm/v8/settings/email_templates/ZT-DEAL' => Http::response([
                'email_templates' => [[
                    'id'      => 'ZT-DEAL',
                    'name'    => 'Template Affaire',
                    'subject' => 'Sujet affaire',
                    'content' => '<p>Affaire</p>',
                ]],
            ], 200),
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post('/admin/campaign_templates/import-zoho')
            ->assertRedirect(route('admin.campaign_templates.index'));

        $this->assertSame(2, CampaignTemplate::count());
        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => 'ZT-CONTACT',
            'name'             => 'Template Contact',
        ]);
        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => 'ZT-DEAL',
            'name'             => 'Template Affaire',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test: re-running import updates rows, never duplicates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pre-seeding 2 rows that already have zoho_template_id set simulates a
     * previous import. Running the import again must update those rows and
     * keep the count at 2.
     *
     * Using updated subject/content to confirm the update path fires.
     */
    public function test_reimport_updates_existing_rows_without_duplication(): void
    {
        // Pre-create the 2 rows as if a previous import had already run.
        CampaignTemplate::create([
            'name'             => 'Prospection Freight',
            'subject'          => 'Bonjour, découvrez nos services',
            'html_content'     => '<p>Old content.</p>',
            'zoho_template_id' => self::ID_SHARED,
        ]);
        CampaignTemplate::create([
            'name'             => 'Relance Lead',
            'subject'          => 'Suite à notre échange',
            'html_content'     => '<p>Old lead content.</p>',
            'zoho_template_id' => self::ID_UNIQUE,
        ]);

        $countBefore = CampaignTemplate::count();
        $this->assertSame(2, $countBefore);

        // Register fakes with updated subject + content for ZT001.
        $updatedSubject = 'NOUVEAU — découvrez nos services';
        $updatedContent = '<p>Contenu mis à jour.</p>';

        $this->fakeHttp(
            sharedSubject: $updatedSubject,
            sharedContent: $updatedContent,
        );

        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/admin/campaign_templates/import-zoho');

        // Row count unchanged — update path, not create path.
        $this->assertSame($countBefore, CampaignTemplate::count(), 'No new rows on reimport');

        // ZT001 subject + content were updated.
        $this->assertDatabaseHas('campaign_templates', [
            'zoho_template_id' => self::ID_SHARED,
            'subject'          => $updatedSubject,
        ]);

        $tpl = CampaignTemplate::where('zoho_template_id', self::ID_SHARED)->firstOrFail();
        $this->assertSame($updatedContent, $tpl->html_content);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test: name-match adoption
    // ─────────────────────────────────────────────────────────────────────────

    public function test_name_match_adoption_avoids_duplicate(): void
    {
        // Pre-existing row with same name, no zoho_template_id yet
        $existing = CampaignTemplate::create([
            'name'             => 'Prospection Freight',
            'subject'          => 'Old subject',
            'html_content'     => '<p>Old content</p>',
            'zoho_template_id' => null,
        ]);

        $this->fakeHttp();
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->post('/admin/campaign_templates/import-zoho');

        // Still 2 rows total (1 adopted + 1 created), not 3
        $this->assertSame(2, CampaignTemplate::count(), 'Adopted row must not create a duplicate');

        // The existing row must now carry the zoho_template_id
        $existing->refresh();
        $this->assertSame(self::ID_SHARED, $existing->zoho_template_id);
        $this->assertSame('Bonjour, découvrez nos services', $existing->subject);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test: permission gating on the campaign_templates import route
    // ─────────────────────────────────────────────────────────────────────────

    public function test_user_without_create_permission_gets_403_on_import(): void
    {
        // commercial role has 'create campaign_templates', so use a user with no role
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Assign only backend.access so they can pass auth middleware but lack the permission
        $user->givePermissionTo('backend.access');

        $this->actingAs($user)
            ->post('/admin/campaign_templates/import-zoho')
            ->assertStatus(403);
    }

    public function test_admin_import_redirects_to_index(): void
    {
        $this->fakeHttp();

        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post('/admin/campaign_templates/import-zoho')
            ->assertRedirect(route('admin.campaign_templates.index'));
    }
}
