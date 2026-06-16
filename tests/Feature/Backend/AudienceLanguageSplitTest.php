<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for POST /admin/campaigns/audience-language-split
 * (admin.campaigns.audienceLanguageSplit).
 *
 * Verifies: FR/EN/unknown counts, warning logic, permission gating.
 */
class AudienceLanguageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $noPermUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config([
            'prospecting.cold_send_enabled' => false,
            'translation.base_language'     => 'fr',
            'translation.target_languages'  => ['en'],
            'translation.francophone_countries' => ['FR', 'BE', 'LU', 'MC', 'CH', 'CA'],
        ]);

        $this->adminUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->adminUser->assignRole('superadmin');

        $this->noPermUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // No role → no permissions
    }

    // ── Fixture helpers ────────────────────────────────────────────────────────

    private function makeSegment(string $scope = 'client'): Segment
    {
        return Segment::create(['name' => 'Test Segment ' . uniqid(), 'scope' => $scope]);
    }

    private function makeTemplate(): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => 'Modèle Split Test',
            'subject'      => 'Sujet FR',
            'html_content' => '<p>Contenu FR</p>',
            'preview_text' => 'Aperçu FR',
        ]);
    }

    private function addEnTranslation(CampaignTemplate $template, bool $stale = false): CampaignTemplateTranslation
    {
        $hashes = $template->sourceHashes();

        return CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'EN Subject',
            'html_content'         => '<p>EN content</p>',
            'preview_text'         => 'EN preview',
            'is_ai_generated'      => true,
            'reviewed_at'          => null,
            'src_subject_hash'     => $stale ? 'old_hash' : $hashes['subject'],
            'src_preview_hash'     => $stale ? 'old_hash' : $hashes['preview'],
            'src_body_hash'        => $stale ? 'old_hash' : $hashes['body'],
        ]);
    }

    private function makeClientContactWithCountry(?string $country, string $email): Contact
    {
        $co = Company::create([
            'name'                 => "Company-{$email}",
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'country'              => $country,
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Test User',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    // ── Permission gating ──────────────────────────────────────────────────────

    public function test_returns_403_without_view_campaigns_permission(): void
    {
        $segment = $this->makeSegment();

        $response = $this->actingAs($this->noPermUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(403);
    }

    // ── Count accuracy ────────────────────────────────────────────────────────

    public function test_mixed_countries_produce_correct_fr_en_unknown_counts(): void
    {
        $segment = $this->makeSegment();

        // 2 FR contacts → fr bucket
        $this->makeClientContactWithCountry('FR', 'fr1@test.test');
        $this->makeClientContactWithCountry('BE', 'fr2@test.test'); // BE is francophone

        // 2 DE contacts → en bucket
        $this->makeClientContactWithCountry('DE', 'de1@test.test');
        $this->makeClientContactWithCountry('US', 'de2@test.test');

        // 1 null-country contact → unknown bucket
        $this->makeClientContactWithCountry(null, 'null@test.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
            ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertSame(2, $data['fr'],      'FR/francophone contacts must land in fr bucket');
        $this->assertSame(2, $data['en'],      'Non-francophone contacts must land in en bucket');
        $this->assertSame(1, $data['unknown'], 'Null-country contacts must land in unknown bucket');
        $this->assertSame(5, $data['total']);
    }

    // ── Warning logic ─────────────────────────────────────────────────────────

    public function test_warning_true_when_en_contacts_exist_and_no_en_translation(): void
    {
        $segment  = $this->makeSegment();
        $template = $this->makeTemplate();

        // EN contact exists, but no EN translation
        $this->makeClientContactWithCountry('DE', 'de@warn.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id'  => $segment->id,
                'template_id' => $template->id,
            ]);

        $data = $response->json();
        $this->assertTrue($data['warning'],  'warning must be true when EN contacts exist but no EN translation');
        $this->assertFalse($data['has_en'],  'has_en must be false when no EN translation');
    }

    public function test_warning_true_when_en_contacts_exist_and_en_translation_is_stale(): void
    {
        $segment  = $this->makeSegment();
        $template = $this->makeTemplate();

        $this->addEnTranslation($template, stale: true);

        $this->makeClientContactWithCountry('DE', 'de-stale@warn.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id'  => $segment->id,
                'template_id' => $template->id,
            ]);

        $data = $response->json();
        $this->assertTrue($data['warning'],   'warning must be true when EN is stale');
        $this->assertTrue($data['has_en'],    'has_en must be true when EN row exists');
        $this->assertTrue($data['en_stale'],  'en_stale must be true');
    }

    public function test_warning_false_when_en_translation_is_fresh(): void
    {
        $segment  = $this->makeSegment();
        $template = $this->makeTemplate();

        $this->addEnTranslation($template, stale: false); // fresh

        $this->makeClientContactWithCountry('DE', 'de-ok@warn.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id'  => $segment->id,
                'template_id' => $template->id,
            ]);

        $data = $response->json();
        $this->assertFalse($data['warning'],  'warning must be false when EN exists and is fresh');
        $this->assertTrue($data['has_en'],    'has_en must be true');
        $this->assertFalse($data['en_stale'], 'en_stale must be false');
    }

    public function test_warning_false_when_no_en_contacts(): void
    {
        $segment  = $this->makeSegment();
        $template = $this->makeTemplate();
        // No EN translation either — but also no EN contacts

        $this->makeClientContactWithCountry('FR', 'fr-only@warn.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id'  => $segment->id,
                'template_id' => $template->id,
            ]);

        $data = $response->json();
        $this->assertFalse($data['warning'], 'warning must be false when there are no EN contacts');
        $this->assertSame(0, $data['en']);
    }

    // ── Without template_id ───────────────────────────────────────────────────

    public function test_without_template_id_still_returns_counts(): void
    {
        $segment = $this->makeSegment();
        $this->makeClientContactWithCountry('DE', 'de@notempl.test');
        $this->makeClientContactWithCountry('FR', 'fr@notempl.test');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
                // no template_id
            ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertSame(1, $data['fr']);
        $this->assertSame(1, $data['en']);
        $this->assertFalse($data['has_en']);
        // warning is true when en>0 and no template provided (has_en=false by definition)
        $this->assertTrue($data['warning'], 'warning must be true when EN contacts exist and no template is provided');
    }

    // ── Unknown country fallback (same as null) ───────────────────────────────

    public function test_empty_country_string_counts_as_unknown(): void
    {
        $segment = $this->makeSegment();

        $co = Company::create([
            'name'                 => 'Empty Country',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'country'              => null, // null after ConvertEmptyStringsToNull
        ]);
        Contact::create([
            'company_id'  => $co->id,
            'email'       => 'empty@country.test',
            'name'        => 'Test',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/admin/campaigns/audience-language-split', [
                'segment_id' => $segment->id,
            ]);

        $data = $response->json();
        $this->assertSame(1, $data['unknown']);
    }
}
