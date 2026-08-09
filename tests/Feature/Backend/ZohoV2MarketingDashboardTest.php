<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoUserMapping;
use Carbon\CarbonImmutable;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZohoV2MarketingDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->commercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->commercial->assignRole('commercial');
        config()->set('zoho-v2.features.marketing_dashboard_enabled', true);
        Cache::flush();
    }

    public function test_route_is_get_only_and_requires_the_flag_and_specific_permission(): void
    {
        config()->set('zoho-v2.features.marketing_dashboard_enabled', false);
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing')->assertNotFound();

        config()->set('zoho-v2.features.marketing_dashboard_enabled', true);
        $denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $denied->givePermissionTo('backend.access');
        $this->actingAs($denied)->get('/admin/dashboard/marketing')->assertForbidden();

        $route = collect(app('router')->getRoutes()->getRoutes())->firstWhere('uri', 'admin/dashboard/marketing');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_commercial_sees_mapping_required_empty_state_without_widening_scope(): void
    {
        $this->actingAs($this->commercial)->get('/admin/dashboard/marketing?commercial=99999')
            ->assertOk()
            ->assertSee('Votre portefeuille Zoho doit être confirmé')
            ->assertDontSee('raw_payload');
    }

    public function test_dashboard_renders_french_native_currency_and_non_attribution_contract(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=30d&currency=EUR')
            ->assertOk()
            ->assertSeeText('Pilotage marketing & commercial', false)
            ->assertSee('Valeurs par devise native')
            ->assertSee('sans attribution causale')
            ->assertDontSee('raw_payload');
    }

    public function test_dashboard_rejects_malformed_or_unapproved_filters(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/dashboard/marketing?period=custom&from=2026-08-09&to=2026-08-01&owner_zoho_id=secret')
            ->assertRedirect()
            ->assertSessionHasErrors('filters');
    }

    public function test_explorer_drilldowns_keep_only_safe_dashboard_filters(): void
    {
        config()->set('zoho-v2.features.explorer_enabled', true);
        ZohoUserMapping::create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);

        $this->actingAs($this->commercial)
            ->get('/admin/dashboard/marketing?period=7d&sector=Logistique')
            ->assertOk()
            ->assertSee('admin/zoho/records/leads')
            ->assertSee('period=7d')
            ->assertSee('sector=Logistique')
            ->assertDontSee('owner_zoho_id=secret');
    }

    public function test_explorer_drilldowns_are_hidden_when_the_flag_or_permission_is_missing(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/dashboard/marketing')
            ->assertOk()
            ->assertDontSee('admin/zoho/records/', false);

        config()->set('zoho-v2.features.explorer_enabled', true);
        $marketingOnly = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $marketingOnly->givePermissionTo(['backend.access', 'view marketing dashboard']);

        $this->actingAs($marketingOnly)
            ->get('/admin/dashboard/marketing')
            ->assertOk()
            ->assertDontSee('admin/zoho/records/', false);
    }

    public function test_dashboard_renders_current_and_previous_campaign_outcomes_rates_stages_and_transitions(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00 Europe/Paris');

        try {
            [$campaignId, $contacts] = $this->marketingCampaignWithContacts(3);
            $currentRun = DB::table('campaign_runs')->insertGetId([
                'campaign_id' => $campaignId,
                'occurrence_key' => 'dashboard-current',
                'run_at' => '2026-08-05 10:00:00',
                'status' => 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $previousRun = DB::table('campaign_runs')->insertGetId([
                'campaign_id' => $campaignId,
                'occurrence_key' => 'dashboard-previous',
                'run_at' => '2026-07-05 10:00:00',
                'status' => 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('campaign_recipients')->insert([
                ['campaign_run_id' => $currentRun, 'contact_id' => $contacts[0], 'status' => 'opened', 'sent_at' => '2026-08-05 10:00:00', 'opened_at' => '2026-08-05 10:01:00', 'clicked_at' => null, 'replied_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['campaign_run_id' => $currentRun, 'contact_id' => $contacts[1], 'status' => 'clicked', 'sent_at' => '2026-08-05 10:00:00', 'opened_at' => '2026-08-05 10:01:00', 'clicked_at' => '2026-08-05 10:02:00', 'replied_at' => null, 'created_at' => now(), 'updated_at' => now()],
                ['campaign_run_id' => $previousRun, 'contact_id' => $contacts[2], 'status' => 'replied', 'sent_at' => '2026-07-05 10:00:00', 'opened_at' => '2026-07-05 10:01:00', 'clicked_at' => null, 'replied_at' => '2026-07-05 10:02:00', 'created_at' => now(), 'updated_at' => now()],
            ]);
            DB::table('demandes')->insert([
                ['contact_id' => $contacts[0], 'campaign_id' => $campaignId, 'captured_at' => '2026-08-06 10:00:00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
                ['contact_id' => $contacts[2], 'campaign_id' => $campaignId, 'captured_at' => '2026-07-06 10:00:00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ]);

            $this->mirror('zoho_leads', 'dashboard-lead', ['status' => 'Qualifié VIP']);
            $this->mirror('zoho_deals', 'dashboard-deal-won', ['stage' => 'Closed Won', 'amount' => 200, 'weighted_amount' => 200, 'currency_code' => 'EUR']);
            $this->mirror('zoho_deals', 'dashboard-deal-open', ['stage' => 'Négociation spéciale', 'amount' => 100, 'weighted_amount' => 40, 'currency_code' => 'EUR']);
            $this->mirror('zoho_deals', 'dashboard-deal-no-value', ['stage' => 'Qualification', 'amount' => null, 'weighted_amount' => null, 'currency_code' => 'EUR']);
            $this->mirror('zoho_deals', 'dashboard-deal-no-currency', ['stage' => 'Qualification', 'amount' => 50, 'weighted_amount' => 20, 'currency_code' => '']);
            $this->mirror('zoho_deals', 'dashboard-deal-previous', ['stage' => 'Closed Lost', 'zoho_created_at' => '2026-07-05 10:00:00']);
            $this->mirror('zoho_quotes', 'dashboard-quote-won', ['follow_up_status' => 'Affaire gagnée', 'line_items_total' => 500, 'line_items_total_complete' => true, 'currency_code' => 'EUR']);
            $this->mirror('zoho_quotes', 'dashboard-quote-open', ['follow_up_status' => 'Devis prioritaire']);
            $this->mirror('zoho_quotes', 'dashboard-quote-previous', ['follow_up_status' => 'Affaire perdue', 'zoho_created_at' => '2026-07-05 10:00:00']);
            $this->history('zoho_deal_stage_history', 'dashboard-deal-history', 'deal_zoho_id', 'dashboard-deal-won', 'stage', 'Closed Won');
            $this->history('zoho_quote_status_history', 'dashboard-quote-history', 'quote_zoho_id', 'dashboard-quote-won', 'status', 'Affaire gagnée', ['previous_status' => 'En cours']);

            Cache::flush();
            $response = $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=30d');

            $response->assertOk()
                ->assertSee('data-testid="campaign-sent-current">2</span>', false)
                ->assertSee('data-testid="campaign-sent-previous">1</span>', false)
                ->assertSee('data-testid="campaign-engagement-rate-current">100,0 %</strong>', false)
                ->assertSee('data-testid="campaign-engagement-rate-previous">100,0 %</span>', false)
                ->assertSee('data-testid="campaign-demande-rate-current">50,0 %</strong>', false)
                ->assertSee('data-testid="campaign-demande-rate-previous">100,0 %</span>', false)
                ->assertSee('data-testid="deal-won-current">1</span>', false)
                ->assertSee('data-testid="deal-won-previous">0</span>', false)
                ->assertSee('data-testid="deal-lost-current">0</span>', false)
                ->assertSee('data-testid="deal-lost-previous">1</span>', false)
                ->assertSee('data-testid="quote-won-current">1</span>', false)
                ->assertSee('data-testid="quote-won-previous">0</span>', false)
                ->assertSee('data-testid="quote-lost-current">0</span>', false)
                ->assertSee('data-testid="quote-lost-previous">1</span>', false)
                ->assertSee('data-testid="quote-win-rate-current">100,0 %</strong>', false)
                ->assertSee('data-testid="quote-win-rate-previous">0,0 %</span>', false)
                ->assertSee('data-testid="pipeline-amount-missing-value">1</span>', false)
                ->assertSee('data-testid="pipeline-amount-missing-currency">1</span>', false)
                ->assertSee('data-testid="pipeline-weighted-missing-value">1</span>', false)
                ->assertSee('data-testid="pipeline-weighted-missing-currency">1</span>', false)
                ->assertSeeText('Qualifié VIP')
                ->assertSeeText('Négociation spéciale')
                ->assertSeeText('Devis prioritaire')
                ->assertSeeText('Transitions de statut observées')
                ->assertSee('data-testid="deal-transition-closed-won">1</span>', false)
                ->assertSee('data-testid="quote-transition-affaire-gagnee">1</span>', false);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /** @return array{int,list<int>} */
    private function marketingCampaignWithContacts(int $count): array
    {
        $suffix = uniqid();
        $segment = DB::table('segments')->insertGetId(['name' => 'Segment '.$suffix, 'scope' => 'client', 'created_at' => now(), 'updated_at' => now()]);
        $template = DB::table('campaign_templates')->insertGetId(['name' => 'Template '.$suffix, 'subject' => 'Subject', 'html_content' => '<p>Body</p>', 'created_at' => now(), 'updated_at' => now()]);
        $sender = DB::table('sender_identities')->insertGetId(['name' => 'Sender '.$suffix, 'email' => $suffix.'@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $campaign = DB::table('campaigns')->insertGetId(['segment_id' => $segment, 'template_id' => $template, 'sender_identity_id' => $sender, 'name' => 'Campagne comparaison', 'created_at' => now(), 'updated_at' => now()]);
        $company = DB::table('companies')->insertGetId(['name' => 'Entreprise '.$suffix, 'source' => 'manual', 'country' => 'FR', 'sector' => 'Transport', 'relationship' => 'prospect', 'qualification_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $contacts = [];
        foreach (range(1, $count) as $index) {
            $contacts[] = DB::table('contacts')->insertGetId([
                'company_id' => $company,
                'assigned_to' => $this->commercial->id,
                'email' => "dashboard-{$suffix}-{$index}@example.test",
                'name' => "Contact {$index}",
                'source' => 'manual',
                'status' => 'new',
                'legal_basis' => 'legitimate_interest',
                'email_kind' => 'role',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$campaign, $contacts];
    }

    /** @param array<string,mixed> $extra */
    private function mirror(string $table, string $zohoId, array $extra = []): void
    {
        DB::table($table)->insert(array_merge([
            'zoho_id' => $zohoId,
            'owner_zoho_id' => 'owner-a',
            'raw_payload' => '{}',
            'payload_hash' => str_repeat('a', 64),
            'zoho_created_at' => '2026-08-05 10:00:00',
            'last_seen_at' => now(),
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function history(string $table, string $zohoId, string $parentColumn, string $parentId, string $valueColumn, string $value, array $extra = []): void
    {
        DB::table($table)->insert(array_merge([
            'zoho_id' => $zohoId,
            $parentColumn => $parentId,
            $valueColumn => $value,
            'occurred_at' => '2026-08-06 10:00:00',
            'raw_payload' => '{}',
            'payload_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
