<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DiscoveryProgressUiContractTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->views = dirname(__DIR__, 2).'/resources/views/backend/contents/prospect_criteria';
    }

    public function test_shared_tracker_is_included_on_index_view_and_edit(): void
    {
        $index = file_get_contents($this->views.'/crud/index.blade.php');
        $view = file_get_contents($this->views.'/crud/view.blade.php');
        $form = file_get_contents($this->views.'/crud/form.blade.php');

        $this->assertStringContainsString('partials._discovery-script', $index);
        $this->assertStringContainsString('partials._discovery-script', $view);
        $this->assertStringContainsString('partials._discovery-script', $form);
        $this->assertStringNotContainsString('window.launchDiscovery = function', $index);
    }

    public function test_completed_tracker_is_compact_while_live_tracker_keeps_full_progress_details(): void
    {
        $view = file_get_contents($this->views.'/crud/view.blade.php');
        $form = file_get_contents($this->views.'/crud/form.blade.php');
        $status = file_get_contents($this->views.'/partials/_discovery-status.blade.php');

        $viewTracker = strpos($view, 'partials._discovery-status');
        $viewTabs = strpos($view, '<div class="tab-content">');
        $formTracker = strpos($form, 'partials._discovery-status');

        $this->assertNotFalse($viewTracker);
        $this->assertNotFalse($viewTabs);
        $this->assertLessThan($viewTabs, $viewTracker);
        $this->assertNotFalse($formTracker);
        $this->assertStringContainsString('data-discovery-tracker', $status);
        $this->assertStringContainsString('data-discovery-summary', $status);
        $this->assertStringContainsString('data-discovery-active-panel', $status);
        $this->assertStringContainsString("\$isCompact = \$progress['status'] === 'completed';", $status);
        $this->assertStringContainsString('data-discovery-searches', $status);
        $this->assertStringContainsString('data-discovery-contact-attempts', $status);
        $this->assertStringContainsString('data-discovery-domains', $status);
        $this->assertStringContainsString('data-discovery-low-score', $status);
        $this->assertStringContainsString('Recherches d’entreprises', $status);
        $this->assertStringContainsString('Domaines exploitables', $status);
        $this->assertStringContainsString('Domaines analysés par l’IA', $status);
        $this->assertStringContainsString('Tentatives d’enrichissement', $status);
        $this->assertStringContainsString('entreprise(s) enregistrée(s)', $status);
        $this->assertStringContainsString('sous le seuil d’enrichissement', $status);
        $this->assertStringContainsString('contacts créé(s)', $status);
        $this->assertStringContainsString('data-discovery-candidates', $status);
        $this->assertStringNotContainsString('Candidats traités', $status);
        $this->assertStringNotContainsString('id="discovery-status-panel"', $status);
        $this->assertStringNotContainsString('id="discovery-status-badge"', $status);

        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');
        $this->assertStringContainsString('function renderTrackerLayout(root, status)', $script);
        $this->assertStringContainsString("summary.classList.toggle('d-none', status !== 'completed');", $script);
        $this->assertStringContainsString("activePanel.classList.toggle('d-none', status === 'completed');", $script);
        $this->assertStringContainsString('renderTrackerLayout(root, status);', $script);
    }

    public function test_every_discovery_launch_control_uses_the_shared_data_contract(): void
    {
        $header = file_get_contents($this->views.'/partials/_header-actions.blade.php');
        $results = file_get_contents($this->views.'/partials/_results-tab.blade.php');
        $datatable = file_get_contents(dirname(__DIR__, 2).'/app/DataTables/Backend/ProspectCriteriaDataTable.php');
        $view = file_get_contents($this->views.'/crud/view.blade.php');
        $viewConfig = file_get_contents(dirname(__DIR__, 2).'/app/Crud/ViewConfigs/ProspectCriteriaViewConfig.php');

        foreach ([$header, $results, $datatable] as $source) {
            $this->assertStringContainsString('data-discovery-launch', $source);
            $this->assertStringContainsString('data-criteria-id', $source);
            $this->assertStringContainsString('data-launch-url', $source);
            $this->assertStringContainsString('data-status-url', $source);
            $this->assertStringContainsString('data-discovery-static-disabled', $source);
        }

        $this->assertStringNotContainsString('onclick="launchDiscovery', $header);
        $this->assertStringNotContainsString('onclick="launchDiscovery', $results);
        $this->assertStringNotContainsString('onclick="launchDiscovery', $datatable);
        $this->assertStringContainsString("'data-discovery-launch'", $viewConfig);
        $this->assertStringContainsString("'data-launch-url'", $viewConfig);
        $this->assertStringContainsString("'data-status-url'", $viewConfig);
        $this->assertStringContainsString("'data-csrf-token'", $viewConfig);
        $this->assertStringContainsString("'data-discovery-static-disabled'", $viewConfig);
        $this->assertStringNotContainsString('launchDiscovery(', $viewConfig);
        $this->assertStringNotContainsString("foreach (\$criteriaApercuConfig['quick_actions']", $view);
    }

    public function test_tracker_script_has_unbounded_transient_backoff_and_page_specific_terminal_behavior(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('3000, 6000, 12000, 24000, 30000', $script);
        $this->assertStringContainsString('var TRANSIENT_STATUSES = [408, 425, 429];', $script);
        $this->assertStringContainsString('isTransientStatus(response.status)', $script);
        $this->assertStringContainsString('[401, 403, 404, 419]', $script);
        $this->assertStringContainsString('ajax.reload(null, false)', $script);
        $this->assertStringContainsString("contexts.indexOf('view')", $script);
        $this->assertStringContainsString("contexts.indexOf('edit')", $script);
        $this->assertSame(2, substr_count($script, 'refreshTerminalContexts(contexts);'));
        $this->assertStringContainsString('state.blocked = true', $script);
        $this->assertStringContainsString('current && current.blocked', $script);
        $this->assertStringContainsString('state.launching = true', $script);
        $this->assertStringContainsString('current && current.launching', $script);
        $this->assertStringContainsString('current && current.active', $script);
        $this->assertStringContainsString('state.lastData', $script);
        $this->assertStringNotContainsString('POLL_MAX_TICKS', $script);
        $this->assertStringNotContainsString('POLL_MAX_ERRORS', $script);
    }

    public function test_ambiguous_launch_is_reconciled_before_the_ui_can_roll_back(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('function reconcileLaunch(state, genericStatusUrl, failureMessage)', $script);
        $this->assertStringContainsString('button.dataset.statusUrl', $script);
        $this->assertStringContainsString('scheduleReconciliation(state, genericStatusUrl, failureMessage, delay)', $script);
        $this->assertStringContainsString("data.status === 'pending' || data.status === 'running'", $script);
        $this->assertStringContainsString('exactStatusUrl(genericStatusUrl, data)', $script);
        $this->assertStringContainsString("exact.searchParams.set('run_id', String(data.run_id))", $script);
        $this->assertStringContainsString('failLaunch(state, resolvedFailure);', $script);
    }

    public function test_reconciliation_waits_through_idle_or_old_terminal_and_accepts_a_new_terminal_run(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('var RECONCILIATION_GRACE_MS = 15000;', $script);
        $this->assertStringContainsString('runId: Math.max(Number(state.runId || 0), renderedRunId)', $script);
        $this->assertStringContainsString('state.reconcileStartedAt = state.reconcileStartedAt || Date.now();', $script);
        $this->assertStringContainsString('var previousRunId = Number(state.launchRollback && state.launchRollback.runId || 0);', $script);
        $this->assertStringContainsString("var serverRunIsTerminal = data.status === 'completed' || data.status === 'failed';", $script);
        $this->assertStringContainsString('var newTerminalRun = serverRunIsTerminal && serverRunId > previousRunId;', $script);
        $this->assertStringContainsString('if (serverHasActiveRun || newTerminalRun)', $script);
        $this->assertStringContainsString('Date.now() - state.reconcileStartedAt < RECONCILIATION_GRACE_MS', $script);
    }

    public function test_all_tracker_fetches_have_an_application_timeout_distinct_from_normal_abort(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('var REQUEST_TIMEOUT_MS = 15000;', $script);
        $this->assertStringContainsString('function beginRequest(state)', $script);
        $this->assertStringContainsString('request.timedOut = true;', $script);
        $this->assertStringContainsString('request.controller.abort();', $script);
        $this->assertStringContainsString('clearTimeout(state.request.timeoutId);', $script);
        $this->assertSame(3, substr_count($script, 'signal: request.controller.signal'));
        $this->assertSame(3, substr_count($script, "error.name === 'AbortError' && !request.timedOut"));
    }

    public function test_dynamic_toasts_are_html_escaped_per_notification(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('var TOAST_OPTIONS = { escapeHtml: true };', $script);
        $this->assertStringContainsString('function showToast(type, message, title)', $script);
        $this->assertStringContainsString('toastr[type](String(message || \'\'), title, TOAST_OPTIONS);', $script);
        $this->assertStringNotContainsString('toastr.success(', $script);
        $this->assertStringNotContainsString('toastr.info(', $script);
        $this->assertStringNotContainsString('toastr.error(', $script);
    }

    public function test_tracker_invalidates_late_fetches_and_restarts_after_bfcache_restore(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString('state.generation = Number(state.generation || 0) + 1;', $script);
        $this->assertStringContainsString('state.controller.abort();', $script);
        $this->assertStringContainsString("error.name === 'AbortError'", $script);
        $this->assertStringContainsString("window.addEventListener('pageshow', function (event)", $script);
        $this->assertStringContainsString('if (event.persisted) scanTrackers();', $script);
    }

    public function test_static_disabled_launch_controls_restore_their_original_markup(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertMatchesRegularExpression(
            '/button\.innerHTML = button\.dataset\.discoveryOriginalHtml;\s*}\s*if \(button\.dataset\.discoveryStaticDisabled === \'true\'\) \{\s*button\.disabled = true;/s',
            $script
        );
    }

    public function test_heartbeat_uses_one_explicit_timezone_before_and_after_polling(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');
        $status = file_get_contents($this->views.'/partials/_discovery-status.blade.php');

        $this->assertStringContainsString("var DISCOVERY_TIMEZONE = @json(config('app.timezone', 'UTC'));", $script);
        $this->assertStringContainsString('timeZone: DISCOVERY_TIMEZONE', $script);
        $this->assertStringContainsString("'Dernière activité (' + DISCOVERY_TIMEZONE + ') : '", $script);
        $this->assertStringContainsString("\$discoveryTimezone = config('app.timezone', 'UTC');", $status);
        $this->assertStringContainsString('->timezone($discoveryTimezone)->format(\'d/m/Y H:i:s\')', $status);
        $this->assertStringContainsString('Dernière activité ({{ $discoveryTimezone }}) :', $status);
    }

    public function test_server_rendered_progress_aria_matches_terminal_state(): void
    {
        $status = file_get_contents($this->views.'/partials/_discovery-status.blade.php');
        $datatable = file_get_contents(dirname(__DIR__, 2).'/app/DataTables/Backend/ProspectCriteriaDataTable.php');

        $this->assertStringContainsString("\$renderedProgress = \$progress['status'] === 'completed' ? 100 : (int) (\$progress['progress_percent'] ?? 0);", $status);
        $this->assertStringContainsString("\$progressIndeterminate = \$isActive && \$progress['progress_percent'] === null;", $status);
        $this->assertStringContainsString('@unless($progressIndeterminate) aria-valuenow="{{ $renderedProgress }}" @endunless', $status);
        $this->assertStringContainsString("\$initialProgress = \$status === 'completed' ? 100 : 0;", $datatable);
        $this->assertStringContainsString("\$progressAria = \$active ? '' : ' aria-valuenow=\"'.\$initialProgress.'\"';", $datatable);
    }

    public function test_index_failure_text_is_rendered_durably_across_datatable_draws(): void
    {
        $datatable = file_get_contents(dirname(__DIR__, 2).'/app/DataTables/Backend/ProspectCriteriaDataTable.php');
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString("\$runError = \$status === 'failed'", $datatable);
        $this->assertStringContainsString('->publicError($run)', $datatable);
        $this->assertStringNotContainsString('(string) ($run?->error', $datatable);
        $this->assertStringContainsString("data-discovery-error>'.e(\$runError)", $datatable);
        $this->assertStringContainsString('Number(root.dataset.runId || 0) === Number(current.runId || 0)', $script);
    }

    public function test_tracker_and_history_keep_enrichment_attempts_separate_from_contacts_created(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');
        $history = file_get_contents($this->views.'/partials/_discovery-history.blade.php');

        $this->assertStringContainsString('data.contact_attempts_consumed', $script);
        $this->assertStringContainsString('data.contact_attempts_reserved', $script);
        $this->assertStringContainsString('data.contacts_count', $script);
        $this->assertStringContainsString('data.successful_enrichments', $script);
        $this->assertStringContainsString('Tentatives d’enrichissement', $history);
        $this->assertStringContainsString('Enrichissements réussis', $history);
        $this->assertStringContainsString('Contacts créés', $history);
    }

    public function test_fourth_progress_card_distinguishes_success_objective_from_attempt_quota(): void
    {
        $status = file_get_contents($this->views.'/partials/_discovery-status.blade.php');

        preg_match_all('/class="col-xl-3 col-md-6"/', $status, $cards, PREG_OFFSET_CAPTURE);
        $this->assertCount(4, $cards[0]);

        $resultsStart = strpos($status, 'Résultats de cette exécution :');
        $this->assertNotFalse($resultsStart);
        $fourthCard = substr($status, $cards[0][3][1], $resultsStart - $cards[0][3][1]);

        $this->assertStringContainsString('Réussites — objectif :', $fourthCard);
        $this->assertStringContainsString('data-discovery-successes>', $fourthCard);
        $this->assertStringContainsString('data-discovery-successes-target>', $fourthCard);
        $this->assertStringContainsString('Tentatives consommées — quota :', $fourthCard);
        $this->assertStringContainsString('data-discovery-contact-attempts>', $fourthCard);
        $this->assertStringContainsString('data-discovery-contact-attempts-total>', $fourthCard);
        $this->assertSame(2, preg_match_all('/class="[^"]*fw-bold text-gray-800[^"]*"/', $fourthCard));
        $this->assertSame(1, substr_count($status, 'data-discovery-successes>'));
        $this->assertSame(1, substr_count($status, 'data-discovery-successes-target>'));
        $this->assertSame(1, substr_count($status, 'data-discovery-contact-attempts>'));
        $this->assertSame(1, substr_count($status, 'data-discovery-contact-attempts-total>'));
        $this->assertStringNotContainsString('enrichissement(s) réussi(s),', $status);
    }

    public function test_polling_updates_domain_snapshot_and_low_score_counters(): void
    {
        $script = file_get_contents($this->views.'/partials/_discovery-script.blade.php');

        $this->assertStringContainsString("setText(root, '[data-discovery-domains]', Number(data.candidates_total || 0));", $script);
        $this->assertStringContainsString("setText(root, '[data-discovery-low-score]', Number(data.low_score_count || 0));", $script);
    }

    public function test_results_explain_the_serpapi_persistence_boundary_and_use_precise_statuses(): void
    {
        $queryResults = file_get_contents($this->views.'/partials/_query-results.blade.php');
        $results = file_get_contents($this->views.'/partials/_results-tab.blade.php');

        foreach ([$queryResults, $results] as $source) {
            $this->assertStringContainsString('Les résultats bruts SerpAPI ne sont pas affichés', $source);
        }

        $this->assertStringContainsString('entreprise(s) enregistrée(s)', $queryResults);
        $this->assertStringContainsString('non exclue(s)', $queryResults);
        $this->assertStringContainsString("\$company->enrichment_status === 'skipped_low_score'", $queryResults);
        $this->assertStringContainsString('badge-light-warning">Sous le seuil de contacts', $queryResults);
        $this->assertStringContainsString('badge-light-success">Non exclue', $queryResults);
        $this->assertStringContainsString('Entreprises enregistrées non exclues', $results);
        $this->assertStringContainsString('Voir les entreprises enregistrées', $results);
    }

    public function test_results_tab_renders_every_enrichment_reason_from_shared_config(): void
    {
        $results = file_get_contents($this->views.'/partials/_results-tab.blade.php');
        $config = require dirname(__DIR__, 2).'/config/global/data.php';

        $this->assertStringContainsString('company_enrichment_statuses', $results);
        $this->assertStringContainsString('company_enrichment_status_null', $results);

        foreach ([
            'enriched',
            'hunter_empty',
            'hunter_failed',
            'enriching',
            'skipped_budget',
            'skipped_provider_unavailable',
            'skipped_low_score',
            'skipped_enrich_off',
            'skipped_excluded',
        ] as $status) {
            $this->assertArrayHasKey($status, $config['company_enrichment_statuses']);
            $this->assertNotSame('', $config['company_enrichment_statuses'][$status]['label']);
        }
    }
}
