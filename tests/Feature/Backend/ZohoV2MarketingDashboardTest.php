<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoUserMapping;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();
    }

    public function test_route_is_get_only_and_requires_the_specific_permission(): void
    {
        $denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $denied->givePermissionTo('backend.access');
        $this->actingAs($denied)->get('/admin/dashboard/marketing')->assertForbidden();
        $route = collect(app('router')->getRoutes()->getRoutes())->firstWhere('uri', 'admin/dashboard/marketing');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_commercial_sees_mapping_required_empty_state_without_widening_scope(): void
    {
        $this->actingAs($this->commercial)->get('/admin/dashboard/marketing?commercial=99999')
            ->assertOk()->assertSee('Votre portefeuille Zoho doit être confirmé')->assertDontSee('raw_payload');
    }

    public function test_mapping_required_scope_is_not_reported_as_a_missing_sync(): void
    {
        $response = $this->actingAs($this->commercial)->get('/admin/dashboard/marketing');

        $response->assertOk()
            ->assertSee('Périmètre indisponible')
            ->assertSee('non évalué')
            ->assertDontSee('Jamais synchronisé');
    }

    public function test_ceo_control_tower_renders_the_read_only_decision_hierarchy(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=365d')
            ->assertOk()->assertSeeText('Tour de contrôle commerciale')->assertSeeText('Simulation uniquement')
            ->assertSee('data-testid="period-365"', false)->assertSee('data-testid="ceo-quotes"', false)
            ->assertSee('data-testid="ceo-queue-filters"', false)->assertSee('Filtrer par propriétaire')
            ->assertSee('aria-disabled="true" data-ceo-priority="P1" disabled', false)
            ->assertSee('data-ceo-root', false)->assertSeeText('Briefing du matin')
            ->assertSeeText('À enrichir')->assertSeeText('Dernière action humaine prouvée')
            ->assertSeeText('Tâches créées ce mois, actuellement terminées')->assertSeeText('Réunions')->assertSeeText('Appels')->assertSeeText('Notes')
            ->assertSeeText('Preuves par propriétaire')->assertSeeText('Mix des axes')
            ->assertSee('tabindex="-1" data-ceo-tab="pilotage"', false)
            ->assertSee('data-ceo-owner', false)->assertSeeText('Propriétaires des lignes affichées')
            ->assertSeeText('date de fin indisponible')
            ->assertDontSee('Taux de gain des devis')->assertDontSee('raw_payload');
    }

    public function test_ceo_control_tower_rejects_malformed_or_unapproved_filters(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=custom&from=2026-08-09&to=2026-08-01&owner_zoho_id=secret')
            ->assertRedirect()->assertSessionHasErrors('filters');
    }

    public function test_dashboard_defaults_to_the_ninety_day_ceo_window(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/dashboard/marketing');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/class="btn btn-sm btn-primary" href="[^"]*period=90d" data-testid="period-90"/',
            $response->getContent(),
        );
    }

    public function test_partial_contract_keeps_available_p1_and_cross_priority_signals_visible(): void
    {
        $unavailable = 'Indisponible';
        $item = [
            'priority' => 'P1',
            'signals' => ['P1', 'P3'],
            'account' => 'Compte P1 partiel',
            'contact' => 'Contact nommé · identité masquée',
            'reasons' => ['Décision à obtenir avant expiration', 'Message ouvert sans réponse'],
            'quote_context' => 'DEVIS-1 · Aérien',
            'last_proven_action' => $unavailable,
            'channel' => 'Téléphone compte masqué',
            'owner' => 'Commercial Test',
            'deadline' => '2026-08-11',
            'recommended_action' => 'Appeler pour obtenir une décision.',
            'confidence' => 'Partiel',
        ];
        $ceo = [
            'period' => ['timezone' => 'Europe/Paris'],
            'freshness' => [
                'state' => 'Partiel', 'last_synced_at' => '2026-08-10T08:00:00+00:00',
                'status' => 'Échec récent', 'affected_modules' => ['deals', 'activities', 'contacts'], 'age_minutes' => 60,
                'modules' => [
                    'deals' => [
                        'available' => true, 'status' => 'Échec récent',
                        'failed_submodules' => ['deals'], 'missing_submodules' => [],
                    ],
                    'activities' => [
                        'available' => false, 'status' => 'Synchronisation incomplète',
                        'failed_submodules' => [], 'missing_submodules' => ['notes'],
                    ],
                    'contacts' => [
                        'available' => true, 'status' => 'Synchronisation partielle',
                        'failed_submodules' => [], 'partial_submodules' => ['contacts'], 'missing_submodules' => [],
                    ],
                ],
            ],
            'metrics' => [
                'quotes' => 1, 'missing_status' => 1, 'tasks_created' => $unavailable,
                'not_started_tasks' => $unavailable, 'completed_tasks' => $unavailable,
                'meetings' => $unavailable, 'calls' => $unavailable, 'notes' => $unavailable,
                'proven_human_follow_up' => $unavailable,
            ],
            'briefing' => [
                'reachable_accounts' => 1, 'highest_deadline' => '2026-08-11',
                'owner_escalations' => $unavailable,
                'completeness' => [
                    'reachable_accounts' => 'partial', 'highest_deadline' => 'partial',
                    'owner_escalations' => 'unavailable',
                ],
            ],
            'decision_cards' => [
                'P1' => ['value' => 1, 'note' => 'P1 disponible.', 'confidence' => 'Partiel'],
                'P2' => ['value' => 0, 'note' => 'Sous-ensemble connu seulement.', 'confidence' => 'Partiel'],
                'P3' => ['value' => 1, 'note' => 'Signal doux.', 'confidence' => 'Partiel'],
                'enrichment' => ['value' => 0, 'note' => 'Sous-ensemble connu seulement.', 'confidence' => 'Partiel'],
            ],
            'queue' => [
                'items' => [$item], 'truncated' => false,
                'total_by_priority' => ['P1' => 1, 'P2' => 0, 'P3' => 0, 'enrichment' => 0],
                'displayed_by_priority' => ['P1' => 1, 'P2' => 0, 'P3' => 0, 'enrichment' => 0],
                'availability_by_priority' => ['P1' => true, 'P2' => true, 'P3' => true, 'enrichment' => true],
                'completeness_by_priority' => ['P1' => 'complete', 'P2' => 'partial', 'P3' => 'complete', 'enrichment' => 'partial'],
            ],
            'owners' => [],
            'monthly' => [[
                'month' => '2026-08', 'quotes' => 1, 'current_decisions' => 0,
                'tasks_created' => $unavailable, 'completed_tasks' => $unavailable,
                'meetings' => $unavailable, 'calls' => $unavailable, 'notes' => $unavailable,
                'deals' => $unavailable,
            ]],
            'monthly_meta' => [
                'current_month' => '2026-08', 'as_of' => '2026-08-10',
                'completion_basis' => 'current_status_of_tasks_created_in_month',
            ],
            'funnel' => array_fill_keys(['quotes', 'named_contacts', 'linked_deals', 'current_decisions', 'completed_quote_tasks'], $unavailable),
            'quote_risks' => array_fill_keys(['expired_missing_decision', 'due_7_days', 'due_30_days', 'unreachable', 'future_date_anomalies'], $unavailable),
            'transport_mix' => [], 'lane_mix' => [],
            'confidence' => [
                'briefing' => $unavailable,
                'metrics' => ['quotes' => 'Partiel', 'missing_status' => 'Partiel', 'tasks_created' => $unavailable, 'proven_human_follow_up' => $unavailable],
                'decision_cards' => ['P1' => 'Partiel', 'P2' => 'Partiel', 'P3' => 'Partiel', 'enrichment' => 'Partiel'],
                'panels' => ['monthly' => 'Partiel', 'funnel' => $unavailable, 'quote_risks' => $unavailable, 'mix' => 'Partiel', 'readiness' => 'Partiel', 'owners' => $unavailable],
            ],
            'readiness' => ['source' => 'Miroir partiel.'],
            'meta' => [
                'filter_applicability' => ['applied' => [], 'partial' => ['country'], 'ignored' => []],
                'filter_scope' => [
                    'requested' => ['country'], 'briefing' => 'Partiel', 'queue' => 'Partiel', 'monthly' => 'Partiel',
                    'metrics' => [
                        'quotes' => 'Filtré', 'missing_status' => 'Filtré',
                        'tasks_created' => 'Non filtré', 'proven_human_follow_up' => 'Non filtré',
                    ],
                    'panels' => [
                        'monthly' => 'Partiel', 'funnel' => 'Partiel', 'quote_risks' => 'Filtré',
                        'mix' => 'Filtré', 'readiness' => 'Non filtré', 'owners' => 'Partiel',
                    ],
                ],
                'task_completion_basis' => 'current_status_of_tasks_created_in_period; completion_timestamp_unavailable',
            ],
        ];

        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
        $view = $this->actingAs($this->admin)->view('backend.contents.marketing-dashboard.index', [
            'ceo' => $ceo,
            'dashboard' => ['scope' => [], 'meta' => ['stale_or_unavailable' => true]],
            'controls' => ['period' => '90d', 'from' => null, 'to' => null],
            'drilldowns' => [],
        ]);

        $view->assertSee('Compte P1 partiel')
            ->assertSee('data-signals="P1,P3"', false)
            ->assertSee('d-flex flex-wrap gap-1', false)
            ->assertSeeText('P2 0 partiel')
            ->assertSeeText('À enrichir 0 partiel')
            ->assertSeeText('Sources : comptes/échéance — Sous-ensemble connu · partiel')
            ->assertSeeText('sources sous-ensemble connu · partiel')
            ->assertSeeText('Fraîcheur du miroir : Échec récent')
            ->assertSeeText('sous-modules en échec : opportunités')
            ->assertSeeText('sous-modules partiels : contacts')
            ->assertSeeText('sous-modules manquants : notes')
            ->assertSeeText('Filtre du briefing : Partiel')
            ->assertSeeText('Filtre de cet indicateur : Non filtré')
            ->assertSeeText('Filtre de la file : Partiel')
            ->assertSeeText('2026-08 · en cours au 10/08/2026')
            ->assertSeeText('tâches créées ce mois et dont le statut actuel est terminé')
            ->assertSee("['Contact masqué'", false)
            ->assertSee("['Échéance'", false)
            ->assertSee("['Confiance'", false)
            ->assertSee("['Signaux'", false)
            ->assertSee('.ceo-action-table tr[data-ceo-row]{display:grid', false)
            ->assertSee('.ceo-decision-card[aria-pressed="true"]', false)
            ->assertSee('Aucune action prouvée dans le sous-ensemble connu · sources partielles.', false)
            ->assertDontSee('innerHTML', false)
            ->assertDontSee('aucun compte ne peut être proposé', false);
    }

    public function test_explorer_drilldowns_keep_only_safe_dashboard_filters(): void
    {
        ZohoUserMapping::create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        $response = $this->actingAs($this->commercial)->get(
            '/admin/dashboard/marketing?period=7d&from=2026-08-01&to=2026-08-07&sector=Logistique&country=France&transport=Air&currency=EUR'
        );

        $response->assertOk();
        $explorer = $this->explorerMarkup($response->getContent());

        $this->assertStringContainsString('admin/zoho/records/accounts', $explorer);
        $this->assertStringContainsString('admin/zoho/records/contacts', $explorer);
        $this->assertStringContainsString('admin/zoho/records/deals', $explorer);
        $this->assertStringNotContainsString('admin/zoho/records/leads', $explorer);
        $this->assertStringNotContainsString('admin/zoho/records/quotes', $explorer);

        foreach (['accounts', 'contacts', 'deals'] as $module) {
            $this->assertStringNotContainsString('admin/zoho/records/'.$module.'?', $explorer);
        }
    }

    public function test_commercial_requested_filter_is_normalized_before_scope_metadata_and_drilldowns(): void
    {
        ZohoUserMapping::create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        $anotherCommercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $anotherCommercial->assignRole('commercial');

        $response = $this->actingAs($this->commercial)->get('/admin/dashboard/marketing?period=7d&commercial='.$anotherCommercial->id);

        $response->assertOk();
        $explorer = $this->explorerMarkup($response->getContent());

        $this->assertStringContainsString('admin/zoho/records/accounts?commercial='.$this->commercial->id, $explorer);
        $this->assertStringContainsString('admin/zoho/records/contacts?commercial='.$this->commercial->id, $explorer);
        $this->assertStringContainsString('admin/zoho/records/deals?commercial='.$this->commercial->id, $explorer);
        $this->assertStringNotContainsString('commercial='.$anotherCommercial->id, $explorer);
        $this->assertStringNotContainsString('?period=', $explorer);
    }

    public function test_year_drilldowns_omit_period_bounds_and_unsupported_modules(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=365d');

        $response->assertOk();
        $explorer = $this->explorerMarkup($response->getContent());

        foreach (['accounts', 'contacts', 'deals'] as $module) {
            $this->assertStringContainsString('admin/zoho/records/'.$module, $explorer);
            $this->assertStringNotContainsString('admin/zoho/records/'.$module.'?', $explorer);
        }
        $this->assertStringNotContainsString('admin/zoho/records/leads', $explorer);
        $this->assertStringNotContainsString('admin/zoho/records/quotes', $explorer);
    }

    public function test_explorer_drilldowns_are_hidden_when_the_permission_is_missing(): void
    {
        $marketingOnly = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $marketingOnly->givePermissionTo(['backend.access', 'view marketing dashboard']);
        $this->actingAs($marketingOnly)->get('/admin/dashboard/marketing')->assertOk()->assertDontSee('admin/zoho/records/', false);
    }

    public function test_dashboard_uses_the_ceo_contract_not_legacy_forecast_claims(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=30d&currency=EUR')
            ->assertOk()->assertSeeText('Entonnoir de traçabilité')->assertSeeText('Mix de volume, pas de revenu')
            ->assertSee('aucun forecast ni taux de gain')->assertDontSee('Taux de gain des devis')->assertDontSee('Pipeline actif');
    }
    private function explorerMarkup(string $html): string
    {
        $matched = preg_match('/<nav class="mt-6" aria-label="Explorer le CRM">(?<markup>.*?)<\/nav>/s', $html, $matches);
        $this->assertSame(1, $matched, 'The CRM Explorer block must be rendered.');

        return $matches['markup'];
    }
}
