<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoUserMapping;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestView;
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

    public function test_ceo_control_tower_renders_the_executive_hybrid_read_only_dashboard(): void
    {
        $this->actingAs($this->admin)->get('/admin/dashboard/marketing?period=365d')
            ->assertOk()
            ->assertSeeText('Vue commerciale exécutive')
            ->assertSeeText('Comptes à surveiller')
            ->assertSeeText('Production et activité consignée')
            ->assertSeeText('Entonnoir de traçabilité')
            ->assertSeeText('Mix de volume, pas de revenu')
            ->assertSee('data-ceo-executive-hero', false)
            ->assertSee('data-ceo-chart="traceability"', false)
            ->assertSee('data-ceo-chart="monthly"', false)
            ->assertSee('data-ceo-chart="transport"', false)
            ->assertSee('data-testid="period-365"', false)->assertSee('data-testid="ceo-quotes"', false)
            ->assertSee('data-testid="ceo-watchlist-filters"', false)
            ->assertSee('data-ceo-owner', false)
            ->assertSeeText('Dernière activité humaine prouvée')
            ->assertSeeText('date de fin indisponible')
            ->assertDontSee('Action recommandée', false)
            ->assertDontSee('Préparer l’action', false)
            ->assertDontSee('data-ceo-open', false)
            ->assertDontSee('data-ceo-tab', false)
            ->assertDontSee('id="ceo-record-modal"', false)
            ->assertDontSeeText('Taux de gain des devis')
            ->assertDontSee('raw_payload');
    }

    public function test_executive_hero_uses_factual_copy_when_no_quote_is_missing_a_decision(): void
    {
        $this->executiveView(
            ['quotes' => 3, 'missing_status' => 0],
            ['quotes' => 3, 'current_decisions' => 3],
        )
            ->assertSeeText('3 devis émis sur la période · 0 sans décision renseignée.')
            ->assertSeeText('Part sans décision renseignée : 0,0 %.')
            ->assertDontSeeText('La production reste soutenue');
    }

    public function test_executive_hero_uses_neutral_copy_when_quote_metrics_are_unavailable(): void
    {
        $this->executiveView(
            ['quotes' => 'Indisponible', 'missing_status' => 'Indisponible'],
            ['quotes' => 'Indisponible', 'current_decisions' => 'Indisponible'],
        )
            ->assertSeeText('Production et décisions : données insuffisantes pour une synthèse fiable.')
            ->assertSeeText('Part de devis sans décision renseignée : Indisponible.')
            ->assertDontSeeText('La production reste soutenue');
    }

    public function test_executive_ratios_preserve_one_decimal_in_french_format(): void
    {
        $this->executiveView(
            ['quotes' => 3, 'missing_status' => 2],
            ['quotes' => 3, 'current_decisions' => 1],
        )
            ->assertSeeText('Part sans décision renseignée : 66,7 %.')
            ->assertSee('aria-label="33,3 % des devis ont une décision renseignée"', false)
            ->assertSee('data-ceo-traceability-fallback', false)
            ->assertSeeText('Décisions renseignées : 1 / 3 devis');
    }

    public function test_chart_states_distinguish_all_null_monthly_from_known_zero_transport(): void
    {
        $unavailable = 'Indisponible';
        $monthly = collect(range(3, 8))->map(static fn (int $month): array => [
            'month' => sprintf('2026-%02d', $month),
            'quotes' => $unavailable,
            'current_decisions' => $unavailable,
            'tasks_created' => $unavailable,
            'completed_tasks' => $unavailable,
            'meetings' => $unavailable,
            'calls' => $unavailable,
            'notes' => $unavailable,
            'deals' => $unavailable,
        ])->all();

        $html = (string) $this->executiveView(
            ['quotes' => 0, 'missing_status' => 0],
            ['quotes' => 0, 'current_decisions' => 0],
            [
                'monthly' => $monthly,
                'monthly_meta' => ['current_month' => '2026-08', 'as_of' => '2026-08-10'],
                'transport_mix' => [],
                'confidence' => ['panels' => ['monthly' => 'Partiel', 'mix' => 'Partiel']],
            ],
        );

        $this->assertStringContainsString(
            'data-ceo-chart="monthly" data-ceo-chart-state="unavailable"',
            $html,
        );
        $this->assertStringNotContainsString(
            'data-ceo-chart="monthly" data-ceo-chart-state="available"',
            $html,
        );
        $this->assertStringContainsString(
            'data-ceo-chart="transport" data-ceo-chart-state="known-zero"',
            $html,
        );
        $this->assertStringContainsString('Aucun résultat sur la période', $html);
        $this->assertStringContainsString(
            "[data-ceo-chart=\"monthly\"][data-ceo-chart-state=\"available\"]",
            $html,
        );
    }

    public function test_monthly_heading_discloses_fixed_six_month_cohort_independent_of_selected_period(): void
    {
        $monthly = collect(range(3, 8))->map(static fn (int $month): array => [
            'month' => sprintf('2026-%02d', $month),
            'quotes' => 0,
            'current_decisions' => 0,
            'tasks_created' => 0,
            'completed_tasks' => 0,
            'meetings' => 0,
            'calls' => 0,
            'notes' => 0,
            'deals' => 0,
        ])->all();

        $this->executiveView(
            ['quotes' => 0, 'missing_status' => 0],
            ['quotes' => 0, 'current_decisions' => 0],
            [
                'monthly' => $monthly,
                'monthly_meta' => ['current_month' => '2026-08', 'as_of' => '2026-08-10'],
                'confidence' => ['panels' => ['monthly' => 'Fiable']],
            ],
            ['period' => '30d'],
        )
            ->assertSee('data-testid="period-30"', false)
            ->assertSee('data-ceo-monthly-cohort', false)
            ->assertSeeText('Fenêtre fixe de 6 mois calendaires · 2026-03 → 2026-08 · mois courant au 10/08/2026');
    }

    public function test_chart_lifecycle_is_reload_safe_and_serializes_livewire_renders(): void
    {
        $html = (string) $this->executiveView(
            ['quotes' => 3, 'missing_status' => 2],
            ['quotes' => 3, 'current_decisions' => 1],
        );

        $this->assertStringContainsString('(function () {', $html);
        $this->assertStringContainsString("document.removeEventListener('livewire:navigated', lifecycle.handler);", $html);
        $this->assertStringContainsString('lifecycle.queue = lifecycle.queue.then(runBoot, runBoot);', $html);
        $this->assertStringContainsString('await Promise.allSettled(charts.map(chart => Promise.resolve().then(() => chart.destroy())));', $html);
        $this->assertStringContainsString('await chart.render();', $html);
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
            '/class="btn btn-sm btn-primary"\s+href="[^"]*period=90d"\s+data-testid="period-90"/',
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
            'reasons' => ['Devis arrivant à échéance sans décision renseignée', 'Message ouvert sans réponse'],
            'quote_context' => 'DEVIS-1 · Aérien',
            'last_proven_action' => $unavailable,
            'channel' => 'Téléphone compte masqué',
            'owner' => 'Commercial Test',
            'deadline' => '2026-08-11',
            'recommended_action' => 'SENTINEL-RECOMMENDATION-MUST-NOT-RENDER',
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
                'total_by_signal' => ['P1' => 1, 'P2' => 0, 'P3' => 1, 'enrichment' => 0],
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

        $view->assertSee('data-tasks-state="unavailable"', false)
            ->assertSee('data-human-evidence-state="unavailable"', false)
            ->assertSeeText('Valeurs exactes')
            ->assertSeeText('Décisions actuelles')
            ->assertSeeText('Deals')
            ->assertDontSee('data-tasks-state="available" data-tasks-value="0"', false)
            ->assertSee('Compte P1 partiel')
            ->assertSee('data-signals="P1,P3"', false)
            ->assertSee('d-flex flex-wrap gap-1', false)
            ->assertSeeText('P2 0 partiel')
            ->assertSeeText('P3 1')
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
            ->assertSeeText('Devis arrivant à échéance sans décision renseignée')
            ->assertDontSeeText('Décision à obtenir avant expiration')
            ->assertDontSeeText('canal autorisé à enrichir')
            ->assertSeeText('tâches créées ce mois et dont le statut actuel est terminé')
            ->assertDontSee('SENTINEL-RECOMMENDATION-MUST-NOT-RENDER', false)
            ->assertDontSee('data-ceo-open', false)
            ->assertDontSee('innerHTML', false)
            ->assertSee('data-ceo-chart-state="unavailable"', false)
            ->assertSee('.ceo-watchlist tr[data-ceo-row]', false)
            ->assertSee('grid-template-columns: minmax(8rem, 38%) 1fr', false)
            ->assertSee('.ceo-filter-button[aria-pressed="true"]', false);
    }

    public function test_watchlist_empty_states_distinguish_complete_partial_and_unavailable_sources(): void
    {
        $priorities = ['P1', 'P2', 'P3', 'enrichment'];
        $queue = static fn (array $availability, array $completeness): array => [
            'items' => [],
            'truncated' => false,
            'total_by_priority' => array_fill_keys($priorities, 0),
            'total_by_signal' => array_fill_keys($priorities, 0),
            'displayed_by_priority' => array_fill_keys($priorities, 0),
            'availability_by_priority' => $availability,
            'completeness_by_priority' => $completeness,
        ];
        $render = fn (array $queueData): TestView => $this->executiveView(
            ['quotes' => 0, 'missing_status' => 0],
            ['quotes' => 0, 'current_decisions' => 0],
            ['queue' => $queueData],
        );

        $complete = $render($queue(
            array_fill_keys($priorities, true),
            array_fill_keys($priorities, 'complete'),
        ));
        $complete
            ->assertSee('data-ceo-queue-empty-state="complete-zero"', false)
            ->assertSeeText('Aucun résultat sur la période')
            ->assertSee('<tr data-ceo-empty-filter hidden>', false);

        $partial = $render($queue(
            array_fill_keys($priorities, true),
            ['P1' => 'complete', 'P2' => 'partial', 'P3' => 'complete', 'enrichment' => 'partial'],
        ));
        $partial
            ->assertSee('data-ceo-queue-empty-state="partial-subset"', false)
            ->assertSeeText('Aucun compte dans le sous-ensemble connu · sources partielles.');
        $partialHtml = (string) $partial;
        $this->assertMatchesRegularExpression(
            '/data-ceo-priority="all"[^>]*data-ceo-completeness="partial"/',
            $partialHtml,
        );
        $this->assertMatchesRegularExpression(
            '/data-ceo-priority="P2"[^>]*data-ceo-completeness="partial"/',
            $partialHtml,
        );
        $this->assertStringContainsString(
            "selectedCompleteness === 'partial'",
            $partialHtml,
        );
        $this->assertStringContainsString(
            'Aucun compte dans le sous-ensemble connu pour ces filtres.',
            $partialHtml,
        );
        $this->assertStringContainsString('emptyFilterCell.textContent = filteredEmptyMessage;', $partialHtml);

        $unavailable = $render($queue(
            array_fill_keys($priorities, false),
            array_fill_keys($priorities, 'unavailable'),
        ));
        $unavailable
            ->assertSee('data-ceo-queue-empty-state="unavailable"><td colspan="6" class="text-muted">Indisponible</td>', false);

        $html = (string) $complete;
        $this->assertStringContainsString('if (rows.length === 0) {', $html);
        $this->assertStringContainsString('emptyFilter.hidden = true;', $html);
    }

    public function test_readiness_keys_are_presented_with_french_labels(): void
    {
        $this->executiveView(
            ['quotes' => 0, 'missing_status' => 0],
            ['quotes' => 0, 'current_decisions' => 0],
            ['readiness' => [
                'quote_timestamp' => 'Date métier uniquement.',
                'status_history' => 'État courant uniquement.',
                'identity_links' => 'Liens déterministes uniquement.',
                'forecast' => 'Non calculé.',
            ]],
        )
            ->assertSeeText('Horodatage des devis')
            ->assertSeeText('Historique des statuts')
            ->assertSeeText('Liens d’identité')
            ->assertSeeText('Prévisions');
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

    private function executiveView(
        array $metrics,
        array $funnel,
        array $ceoOverrides = [],
        array $controlOverrides = [],
    ): TestView
    {
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);

        $ceo = array_replace([
            'period' => ['timezone' => 'Europe/Paris'],
            'freshness' => [],
            'metrics' => $metrics,
            'briefing' => [],
            'decision_cards' => [],
            'queue' => ['items' => []],
            'owners' => [],
            'monthly' => [],
            'funnel' => $funnel,
            'quote_risks' => [],
            'transport_mix' => [],
            'lane_mix' => [],
            'confidence' => [],
            'readiness' => [],
            'meta' => [],
        ], $ceoOverrides);

        return $this->actingAs($this->admin)->view('backend.contents.marketing-dashboard.index', [
            'ceo' => $ceo,
            'dashboard' => ['scope' => [], 'meta' => []],
            'controls' => array_replace(['period' => '90d', 'from' => null, 'to' => null], $controlOverrides),
            'drilldowns' => [],
        ]);
    }

    private function explorerMarkup(string $html): string
    {
        $matched = preg_match('/<nav class="mt-6" aria-label="Explorer le CRM">(?<markup>.*?)<\/nav>/s', $html, $matches);
        $this->assertSame(1, $matched, 'The CRM Explorer block must be rendered.');

        return $matches['markup'];
    }
}
