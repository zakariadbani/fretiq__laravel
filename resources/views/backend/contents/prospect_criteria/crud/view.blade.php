<x-default-layout>

@section('title')
    Critère — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte', 'route' => 'admin.prospect_criteria.index'], ['label' => $model->name]]" />
@endsection

{{--
    ProspectCriteria view — hero + tabbar + aperçu contract.
    Tab pane IDs: criteria_apercu / criteria_general.
    Aperçu is native (default active); Général deep-links to edit.
    "Lancer la découverte" preserved in hero actions slot AND as a quick_action.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.prospect_criteria.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="criteria_apercu" role="tabpanel">
        {{-- Live discovery status panel — above the generic aperçu --}}
        @include('backend.contents.prospect_criteria.partials._discovery-status', [
            'model' => $model,
        ])

        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => $viewConfig ?? \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make($model),
        ])
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 2: Historique (native on view) ────────────────────────────── --}}
    <div class="tab-pane fade" id="criteria_historique" role="tabpanel">
        @include('backend.contents.prospect_criteria.partials._discovery-history', ['model' => $model])
    </div>
    {{-- end Historique --}}

    {{-- ── Tab 3: Résultats (inline discovered companies) ────────────────── --}}
    <div class="tab-pane fade" id="criteria_resultats" role="tabpanel">
        @include('backend.contents.prospect_criteria.partials._query-results', [
            'model'       => $model,
            'queryGroups' => $queryGroups ?? [],
            'auditMode'   => $auditMode ?? false,
        ])
        @include('backend.contents.prospect_criteria.partials._results-tab', [
            'model'           => $model,
            'resultCompanies' => $resultCompanies,
            'resultsSort'     => $resultsSort ?? 'score',
            'resultsDir'      => $resultsDir ?? 'desc',
        ])
    </div>
    {{-- end Résultats --}}

    {{--
        Tab 4 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script>
        // ── Status map (from PHP config) ─────────────────────────────────────────
        var DISCOVERY_STATUSES = @json(config('global.data.discovery_run_statuses'));

        // ── submitPostForm helper (unchanged) ────────────────────────────────────
        window.submitPostForm = function (url, csrfToken) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            var csrf = document.createElement('input');
            csrf.type  = 'hidden';
            csrf.name  = '_token';
            csrf.value = csrfToken;
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        };

        // ── Polling state ────────────────────────────────────────────────────────
        var _discoveryPollTimer  = null;   // pending setTimeout handle
        var _discoveryPollActive = false;  // guard against double-start
        var _discoveryTickCount  = 0;      // hard-cap counter
        var _discoveryErrCount   = 0;      // consecutive fetch-error counter
        var POLL_INTERVAL_MS     = 3000;
        var POLL_MAX_TICKS       = 100;
        var POLL_MAX_ERRORS      = 3;

        // ── Helpers ──────────────────────────────────────────────────────────────
        function _getLaunchBtn() {
            return document.getElementById('launch-discovery-btn');
        }

        function _getPanel() {
            return document.getElementById('discovery-status-panel');
        }

        // ── stopDiscoveryPolling ─────────────────────────────────────────────────
        // Single stop routine for all halt paths (terminal status, hard cap,
        // pagehide, repeated errors).
        function stopDiscoveryPolling() {
            if (_discoveryPollTimer !== null) {
                clearTimeout(_discoveryPollTimer);
                _discoveryPollTimer = null;
            }
            _discoveryPollActive = false;
            _discoveryTickCount  = 0;
            _discoveryErrCount   = 0;

            // Re-enable the launch button.
            var btn = _getLaunchBtn();
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('disabled');
            }
        }

        // ── updatePanelFromData ──────────────────────────────────────────────────
        // Apply a status-endpoint JSON response to the panel DOM.
        function updatePanelFromData(data) {
            var status = data.status || '';
            var cfg    = DISCOVERY_STATUSES[status] || {};
            var label  = cfg.label  || status || 'Jamais lancée';
            var color  = cfg.color  || 'secondary';

            // Status badge
            var badge = document.getElementById('discovery-status-badge');
            if (badge) {
                badge.textContent = label;
                // Replace the badge-light-* class
                badge.className = badge.className.replace(/badge-light-\S+/, '');
                badge.classList.add('badge-light-' + color);
            }

            // Run throughput counters
            var elCompanies = document.getElementById('discovery-run-companies');
            if (elCompanies) elCompanies.textContent = data.companies_count || 0;

            var elContacts = document.getElementById('discovery-run-contacts');
            if (elContacts) elContacts.textContent = data.contacts_count || 0;

            var elLowScore = document.getElementById('discovery-run-lowscore');
            if (elLowScore) elLowScore.textContent = data.low_score_count || 0;

            // CTA: N from companies_total (attributed total, not run counter)
            var elTotal = document.getElementById('discovery-companies-total');
            if (elTotal && data.companies_total !== undefined) {
                elTotal.textContent = data.companies_total;
            }

            // Last finished-at timestamp (localize ISO-8601 → fr-FR)
            var elFinishedAt = document.getElementById('discovery-finished-at');
            if (elFinishedAt) {
                if (data.finished_at) {
                    try {
                        var d = new Date(data.finished_at);
                        elFinishedAt.textContent = d.toLocaleString('fr-FR');
                    } catch (e) {
                        elFinishedAt.textContent = data.finished_at;
                    }
                } else {
                    elFinishedAt.textContent = '—';
                }
            }

            // Worker-down warning: reveal on stale, keep polling (worker may recover)
            var warning = document.getElementById('discovery-worker-warning');
            if (warning) {
                if (data.stale) {
                    warning.classList.remove('d-none');
                } else {
                    warning.classList.add('d-none');
                }
            }
        }

        // ── pollDiscoveryStatus ──────────────────────────────────────────────────
        function pollDiscoveryStatus() {
            if (!_discoveryPollActive) return;

            // Pause a tick while the page is hidden (tab not visible).
            if (document.hidden) {
                _discoveryPollTimer = setTimeout(pollDiscoveryStatus, POLL_INTERVAL_MS);
                return;
            }

            _discoveryTickCount++;
            if (_discoveryTickCount > POLL_MAX_TICKS) {
                stopDiscoveryPolling();
                return;
            }

            var panel = _getPanel();
            if (!panel) {
                stopDiscoveryPolling();
                return;
            }

            fetch(panel.dataset.statusUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    _discoveryErrCount = 0;  // reset consecutive-error counter on success

                    updatePanelFromData(data);

                    var status = data.status || '';
                    var isTerminal = (status === 'completed' || status === 'failed');

                    if (isTerminal) {
                        if (status === 'completed') {
                            toastr.success(
                                'Découverte terminée : ' + (data.companies_count || 0) +
                                ' entreprises, ' + (data.contacts_count || 0) + ' contacts.',
                                'Découverte terminée'
                            );
                        } else {
                            toastr.error(
                                data.error || 'La découverte a échoué.',
                                'Erreur de découverte'
                            );
                        }
                        stopDiscoveryPolling();
                        return;
                    }

                    // Non-terminal: schedule next tick (chained setTimeout, NOT setInterval)
                    _discoveryPollTimer = setTimeout(pollDiscoveryStatus, POLL_INTERVAL_MS);
                })
                .catch(function () {
                    _discoveryErrCount++;
                    if (_discoveryErrCount >= POLL_MAX_ERRORS) {
                        stopDiscoveryPolling();
                        return;
                    }
                    // Retry after the normal interval
                    _discoveryPollTimer = setTimeout(pollDiscoveryStatus, POLL_INTERVAL_MS);
                });
        }

        // ── startDiscoveryPolling ────────────────────────────────────────────────
        // Idempotent: a second call while polling is already active is a no-op.
        function startDiscoveryPolling() {
            if (_discoveryPollActive) return;

            // Disable the launch button while a run is in progress.
            var btn = _getLaunchBtn();
            if (btn) btn.disabled = true;

            _discoveryPollActive = true;
            _discoveryTickCount  = 0;
            _discoveryErrCount   = 0;

            _discoveryPollTimer = setTimeout(pollDiscoveryStatus, POLL_INTERVAL_MS);
        }

        // ── launchDiscovery ──────────────────────────────────────────────────────
        window.launchDiscovery = function (id, csrfToken) {
            // Guard against double-dispatch while polling is active.
            if (_discoveryPollActive) return;

            Swal.fire({
                title: 'Lancer la découverte ?',
                text: 'La pipeline de découverte sera exécutée en arrière-plan pour ce critère.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Lancer',
                cancelButtonText: 'Annuler',
                confirmButtonColor: '#009ef7',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                fetch('/admin/prospect_criteria/' + id + '/discover', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({}),
                })
                .then(function (response) {
                    var status = response.status;
                    return response.json().then(function (data) {
                        return { status: status, data: data };
                    });
                })
                .then(function (res) {
                    var httpStatus = res.status;
                    var data       = res.data;

                    if (httpStatus === 200 && data.message === 'success') {
                        toastr.success(
                            data.text || 'Découverte lancée en arrière-plan.',
                            'Succès'
                        );
                        startDiscoveryPolling();

                    } else if (httpStatus === 409) {
                        // Already in-flight: attach to existing run
                        toastr.info(
                            'Une découverte est déjà en cours.',
                            'En cours'
                        );
                        startDiscoveryPolling();

                    } else if (httpStatus === 422) {
                        toastr.error(
                            data.text || 'Le critère est inactif ou invalide.',
                            'Erreur'
                        );

                    } else {
                        toastr.error('Une erreur est survenue.', 'Erreur');
                    }
                })
                .catch(function () {
                    toastr.error('Une erreur est survenue.', 'Erreur');
                });
            });
        };

        // ── Auto-start on page load if a run is already in-flight ────────────────
        document.addEventListener('DOMContentLoaded', function () {
            var panel = _getPanel();
            if (panel) {
                var initialStatus = panel.dataset.status || '';
                if (initialStatus === 'pending' || initialStatus === 'running') {
                    startDiscoveryPolling();
                }
            }
        });

        // ── Stop polling when the page is being unloaded ─────────────────────────
        window.addEventListener('pagehide', function () {
            stopDiscoveryPolling();
        });
    </script>
@endpush

</x-default-layout>
