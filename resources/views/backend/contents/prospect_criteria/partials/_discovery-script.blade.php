{{-- Shared launch and polling tracker for the index, detail and edit pages. --}}
<script>
(function () {
    'use strict';

    if (window.DiscoveryProgressTracker) return;

    var DISCOVERY_STATUSES = @json(config('global.data.discovery_run_statuses'));
    var DISCOVERY_TIMEZONE = @json(config('app.timezone', 'UTC'));
    var POLL_INTERVAL_MS = 3000;
    var REQUEST_TIMEOUT_MS = 15000;
    var RECONCILIATION_GRACE_MS = 15000;
    var TRANSIENT_BACKOFF_MS = [3000, 6000, 12000, 24000, 30000];
    var TRANSIENT_STATUSES = [408, 425, 429];
    var FATAL_STATUSES = [401, 403, 404, 419];
    var TOAST_OPTIONS = { escapeHtml: true };
    var states = new Map();
    function blockUnsavedCriteriaAction() {
        var form = document.getElementById('form_crud');
        if (!form || form.dataset.cleanSnapshot === undefined) return false;

        var currentSnapshot = new URLSearchParams(new FormData(form)).toString();
        if (form.dataset.cleanSnapshot === currentSnapshot) return false;

        Swal.fire({
            icon: 'warning',
            title: 'Modifications non enregistrées',
            text: 'Enregistrez les critères avant de lancer cette action.',
            buttonsStyling: false,
            confirmButtonText: 'OK',
            customClass: { confirmButton: 'btn btn-primary' },
        });
        return true;
    }

    function showToast(type, message, title) {
        toastr[type](String(message || ''), title, TOAST_OPTIONS);
    }

    function isTransientStatus(status) {
        return TRANSIENT_STATUSES.indexOf(status) !== -1 || status >= 500;
    }

    window.submitPostForm = window.submitPostForm || function (url, csrfToken) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = url;
        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = csrfToken;
        form.appendChild(csrf);
        document.body.appendChild(form);
        form.submit();
    };

    function trackers(criteriaId) {
        return document.querySelectorAll('[data-discovery-tracker][data-criteria-id="' + criteriaId + '"]');
    }

    function launchButtons(criteriaId) {
        return document.querySelectorAll('[data-discovery-launch][data-criteria-id="' + criteriaId + '"]');
    }

    function setButtons(criteriaId, disabled, mode) {
        launchButtons(criteriaId).forEach(function (button) {
            if (!button.dataset.discoveryOriginalHtml) {
                button.dataset.discoveryOriginalHtml = button.innerHTML;
            }

            if (disabled) {
                button.disabled = true;
                if (mode === 'launching') {
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Lancement…';
                } else if (mode === 'running') {
                    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>En cours…';
                }
                return;
            }

            if (button.dataset.discoveryOriginalHtml) {
                button.innerHTML = button.dataset.discoveryOriginalHtml;
            }
            if (button.dataset.discoveryStaticDisabled === 'true') {
                button.disabled = true;
                return;
            }
            button.disabled = false;
        });
    }

    function setText(root, selector, value) {
        var element = root.querySelector(selector);
        if (element) element.textContent = value;
    }

    function formatDate(value) {
        if (!value) return '';
        var date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;

        return new Intl.DateTimeFormat('fr-FR', {
            timeZone: DISCOVERY_TIMEZONE,
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
        }).format(date);
    }

    function phaseLabel(phase) {
        return {
            idle: 'Prête à être lancée',
            queued: 'En attente du worker…',
            collecting: 'Collecte des recherches API…',
            processing: 'Traitement des candidats…',
            completed: 'Découverte terminée',
            failed: 'Découverte échouée',
        }[phase] || 'Travail en cours…';
    }

    function renderProgress(root, data) {
        var bar = root.querySelector('[data-discovery-progress]');
        if (!bar) return;

        var active = data.status === 'pending' || data.status === 'running';
        var percent = data.status === 'completed' ? 100 : data.progress_percent;
        var indeterminate = active && (percent === null || percent === undefined);

        bar.style.width = (indeterminate ? 100 : Math.max(0, Math.min(100, Number(percent || 0)))) + '%';
        bar.classList.toggle('progress-bar-striped', indeterminate);
        bar.classList.toggle('progress-bar-animated', indeterminate);
        if (indeterminate) {
            bar.removeAttribute('aria-valuenow');
        } else {
            bar.setAttribute('aria-valuenow', String(percent || 0));
        }
    }

    function renderTrackerLayout(root, status) {
        var summary = root.querySelector('[data-discovery-summary]');
        var activePanel = root.querySelector('[data-discovery-active-panel]');

        if (summary) summary.classList.toggle('d-none', status !== 'completed');
        if (activePanel) activePanel.classList.toggle('d-none', status === 'completed');
    }

    function renderData(criteriaId, data) {
        trackers(criteriaId).forEach(function (root) {
            var status = data.status || '';
            var statusConfig = DISCOVERY_STATUSES[status] || {};
            var badge = root.querySelector('[data-discovery-status-badge]');
            var error = root.querySelector('[data-discovery-error]');
            var warning = root.querySelector('[data-discovery-worker-warning]');
            var growing = root.querySelector('[data-discovery-total-growing]');
            var spinner = root.querySelector('[data-discovery-spinner]');

            root.dataset.status = status;
            renderTrackerLayout(root, status);
            if (data.run_id) root.dataset.runId = data.run_id;
            if (badge) {
                badge.textContent = statusConfig.label || status || 'Jamais lancée';
                Array.from(badge.classList).forEach(function (name) {
                    if (name.indexOf('badge-light-') === 0) badge.classList.remove(name);
                });
                badge.classList.add('badge-light-' + (statusConfig.color || 'secondary'));
            }

            setText(root, '[data-discovery-phase]', phaseLabel(data.phase));
            setText(root, '[data-discovery-searches]', Number(data.searches_consumed || 0));
            setText(root, '[data-discovery-searches-total]', Number(data.searches_reserved || 0));
            setText(root, '[data-discovery-domains]', Number(data.candidates_total || 0));
            setText(root, '[data-discovery-contact-attempts]', Number(data.contact_attempts_consumed || 0));
            setText(root, '[data-discovery-contact-attempts-total]', Number(data.contact_attempts_reserved || 0));
            setText(root, '[data-discovery-candidates]', Number(data.candidates_processed || 0));
            setText(root, '[data-discovery-candidates-total]', Number(data.candidates_total || 0));
            setText(root, '[data-discovery-companies]', Number(data.companies_count || 0));
            setText(root, '[data-discovery-low-score]', Number(data.low_score_count || 0));
            setText(root, '[data-discovery-contacts]', Number(data.contacts_count || 0));
            setText(root, '[data-discovery-successes]', Number(data.successful_enrichments || 0));
            setText(root, '[data-discovery-successes-target]', Number(data.successful_enrichments_target || 0));
            setText(root, '[data-discovery-excluded]', Number(data.excluded_count || 0));
            setText(root, '[data-discovery-skipped]', Number(data.skipped_count || 0));
            if (data.companies_total !== undefined) {
                setText(root, '[data-discovery-companies-total]', Number(data.companies_total || 0));
            }
            setText(
                root,
                '[data-discovery-heartbeat]',
                data.heartbeat_at
                    ? 'Dernière activité (' + DISCOVERY_TIMEZONE + ') : ' + formatDate(data.heartbeat_at)
                    : ''
            );

            if (growing) growing.classList.toggle('d-none', Boolean(data.candidates_total_final));
            if (warning) warning.classList.toggle('d-none', !data.stale);
            if (spinner) spinner.classList.toggle('d-none', status !== 'pending' && status !== 'running');
            if (error) {
                error.textContent = data.error || '';
                error.classList.toggle('d-none', !data.error);
            }
            renderProgress(root, data);
        });
    }

    function renderMessage(criteriaId, message, fatal) {
        trackers(criteriaId).forEach(function (root) {
            var error = root.querySelector('[data-discovery-error]');
            if (!error) return;
            error.textContent = message;
            error.classList.remove('d-none');
            error.classList.toggle('alert-danger', Boolean(fatal));
        });
    }

    function captureLaunchRollback(state) {
        var snapshots = Array.from(trackers(state.criteriaId)).map(function (root) {
            return {
                context: root.dataset.discoveryContext || '',
                runId: root.dataset.runId || '',
                status: root.dataset.status || '',
                statusUrl: root.dataset.statusUrl || '',
                html: root.innerHTML,
            };
        });
        var renderedRunId = snapshots.reduce(function (latest, snapshot) {
            return Math.max(latest, Number(snapshot.runId || 0));
        }, 0);

        return {
            runId: Math.max(Number(state.runId || 0), renderedRunId),
            statusUrl: state.statusUrl,
            lastData: state.lastData || null,
            trackers: snapshots,
        };
    }

    function beginRequest(state) {
        var request = {
            controller: new AbortController(),
            timedOut: false,
            timeoutId: null,
        };
        request.timeoutId = setTimeout(function () {
            request.timedOut = true;
            request.controller.abort();
        }, REQUEST_TIMEOUT_MS);
        state.controller = request.controller;
        state.request = request;

        return request;
    }

    function finishRequest(state, request) {
        clearTimeout(request.timeoutId);
        if (state.request === request) state.request = null;
        if (state.controller === request.controller) state.controller = null;
    }

    function invalidateRequest(state) {
        clearTimeout(state.timer);
        state.timer = null;
        state.generation = Number(state.generation || 0) + 1;
        if (state.request) {
            clearTimeout(state.request.timeoutId);
            state.request = null;
        }
        if (state.controller) {
            state.controller.abort();
            state.controller = null;
        }
    }

    function failLaunch(state, message) {
        var rollback = state.launchRollback || {};
        var snapshots = rollback.trackers || [];

        invalidateRequest(state);
        state.active = false;
        state.launching = false;
        state.blocked = false;
        state.blockedMessage = null;
        state.retryIndex = 0;
        state.runId = rollback.runId || null;
        state.statusUrl = rollback.statusUrl || null;
        state.lastData = rollback.lastData || null;
        state.launchError = message;

        trackers(state.criteriaId).forEach(function (root, index) {
            var context = root.dataset.discoveryContext || '';
            var snapshot = snapshots[index] || snapshots.find(function (candidate) {
                return candidate.context === context;
            });
            if (!snapshot) return;

            root.dataset.runId = snapshot.runId;
            root.dataset.status = snapshot.status;
            root.dataset.statusUrl = snapshot.statusUrl;
            root.innerHTML = snapshot.html;
        });

        delete state.launchRollback;
        delete state.reconcileStatusUrl;
        delete state.reconcileFailureMessage;
        delete state.reconcileStartedAt;
        delete state.resumeMode;
        setButtons(state.criteriaId, false);
        renderMessage(state.criteriaId, message, true);
    }

    function schedule(state, delay) {
        if (!state.active) return;
        clearTimeout(state.timer);
        var generation = state.generation;
        state.timer = setTimeout(function () {
            if (state.active && state.generation === generation) poll(state);
        }, delay);
    }

    function scheduleReconciliation(state, genericStatusUrl, failureMessage, delay) {
        if (!state.launching || state.blocked) return;
        clearTimeout(state.timer);
        var generation = state.generation;
        state.timer = setTimeout(function () {
            if (state.launching && !state.blocked && state.generation === generation) {
                reconcileLaunch(state, genericStatusUrl, failureMessage);
            }
        }, delay);
    }

    function stop(state, enableLaunch) {
        invalidateRequest(state);
        state.active = false;
        state.launching = false;
        if (enableLaunch) setButtons(state.criteriaId, false);
    }

    function blockTracker(state, message) {
        invalidateRequest(state);
        state.active = false;
        state.launching = false;
        state.blocked = true;
        state.blockedMessage = message;
        setButtons(state.criteriaId, true, 'running');
        renderMessage(state.criteriaId, message, true);
    }

    function indexReload() {
        if (!window.jQuery || !jQuery.fn.DataTable) return;
        var table = jQuery('#prospect_criteria-table');
        if (table.length && jQuery.fn.DataTable.isDataTable(table[0])) {
            table.DataTable().ajax.reload(null, false);
        }
    }

    function refreshTerminalContexts(contexts) {
        if (contexts.indexOf('view') !== -1) {
            window.location.reload();
        } else if (contexts.indexOf('index') !== -1) {
            indexReload();
        }
    }

    function handleTerminal(state, data) {
        var contexts = Array.from(trackers(state.criteriaId)).map(function (root) {
            return root.dataset.discoveryContext || '';
        });

        if (data.status === 'failed') {
            renderMessage(state.criteriaId, data.error || 'La découverte a échoué.', true);
            showToast('error', data.error || 'La découverte a échoué.', 'Erreur de découverte');
            stop(state, true);
            refreshTerminalContexts(contexts);
            return;
        }

        var completion = 'Découverte terminée : '
            + Number(data.contact_attempts_consumed || 0) + '/'
            + Number(data.contact_attempts_reserved || 0) + ' tentatives d’enrichissement, '
            + Number(data.successful_enrichments || 0) + '/'
            + Number(data.successful_enrichments_target || 0) + ' enrichissement(s) réussi(s), '
            + Number(data.contacts_count || 0) + ' contact(s) importé(s).';
        showToast('success', completion, 'Succès');
        stop(state, true);

        if (contexts.indexOf('edit') !== -1) {
            renderMessage(state.criteriaId, 'Découverte terminée. Les modifications non enregistrées ont été conservées.', false);
        }
        refreshTerminalContexts(contexts);
    }

    function poll(state) {
        if (!state.active) return;
        if (document.hidden) {
            schedule(state, POLL_INTERVAL_MS);
            return;
        }

        var generation = state.generation;
        var request = beginRequest(state);

        fetch(state.statusUrl, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal: request.controller.signal,
        })
            .then(function (response) {
                if (!state.active || state.generation !== generation) {
                    throw { kind: 'stale' };
                }
                if (FATAL_STATUSES.indexOf(response.status) !== -1) {
                    throw { kind: 'fatal', status: response.status };
                }
                if (isTransientStatus(response.status)) {
                    throw { kind: 'transient', status: response.status };
                }
                if (!response.ok) throw { kind: 'fatal', status: response.status };
                return response.json();
            })
            .then(function (data) {
                if (!state.active || state.generation !== generation) return;
                state.retryIndex = 0;
                state.lastData = data;
                renderData(state.criteriaId, data);
                setButtons(state.criteriaId, true, 'running');

                if (data.status === 'completed' || data.status === 'failed') {
                    handleTerminal(state, data);
                    return;
                }
                schedule(state, POLL_INTERVAL_MS);
            })
            .catch(function (error) {
                if (state.generation !== generation
                    || !state.active
                    || (error && error.kind === 'stale')
                    || (error && error.name === 'AbortError' && !request.timedOut)
                ) {
                    return;
                }
                if (error && error.kind === 'fatal') {
                    blockTracker(
                        state,
                        'Le suivi a été interrompu. Rechargez la page pour vérifier l’état de la découverte.'
                    );
                    return;
                }

                renderMessage(state.criteriaId, 'Connexion temporairement interrompue — nouvelle tentative automatique…', false);
                var delay = TRANSIENT_BACKOFF_MS[Math.min(state.retryIndex, TRANSIENT_BACKOFF_MS.length - 1)];
                state.retryIndex++;
                schedule(state, delay);
            })
            .finally(function () {
                finishRequest(state, request);
            });
    }

    function attach(criteriaId, runId, statusUrl, initialData) {
        var state = states.get(criteriaId) || {
            criteriaId: criteriaId,
            timer: null,
            retryIndex: 0,
            generation: 0,
            controller: null,
        };
        var previousRunId = Number(state.runId || 0);
        invalidateRequest(state);
        state.runId = Number(runId);
        state.statusUrl = statusUrl;
        state.active = true;
        state.launching = false;
        state.blocked = false;
        state.blockedMessage = null;
        state.launchError = null;
        state.retryIndex = 0;
        state.lastData = initialData || (previousRunId === Number(runId) ? state.lastData : null);
        delete state.launchRollback;
        delete state.reconcileStatusUrl;
        delete state.reconcileFailureMessage;
        delete state.reconcileStartedAt;
        delete state.resumeMode;
        states.set(criteriaId, state);

        trackers(criteriaId).forEach(function (root) {
            root.dataset.runId = runId;
            root.dataset.statusUrl = statusUrl;
        });
        if (state.lastData) renderData(criteriaId, state.lastData);
        setButtons(criteriaId, true, 'running');
        if (initialData && (initialData.status === 'completed' || initialData.status === 'failed')) {
            handleTerminal(state, initialData);
            return;
        }
        poll(state);
    }

    function parseJson(response) {
        return response.json()
            .then(function (data) {
                return { response: response, data: data, parsed: true };
            })
            .catch(function () {
                return { response: response, data: {}, parsed: false };
            });
    }

    function exactStatusUrl(genericStatusUrl, data) {
        var exact = new URL(data.status_url || genericStatusUrl, window.location.href);
        exact.searchParams.set('run_id', String(data.run_id));

        return exact.origin === window.location.origin
            ? exact.pathname + exact.search + exact.hash
            : exact.toString();
    }

    function reconcileLaunch(state, genericStatusUrl, failureMessage) {
        if (!state.launching || state.blocked) return;

        state.reconcileStatusUrl = genericStatusUrl;
        state.reconcileFailureMessage = failureMessage;
        var generation = state.generation;
        var request = beginRequest(state);

        fetch(genericStatusUrl, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal: request.controller.signal,
        })
            .then(parseJson)
            .then(function (result) {
                if (!state.launching || state.generation !== generation) {
                    throw { kind: 'stale' };
                }

                var response = result.response;
                var data = result.data;
                if (FATAL_STATUSES.indexOf(response.status) !== -1) {
                    throw { kind: 'fatal', status: response.status };
                }
                if (isTransientStatus(response.status) || !result.parsed) {
                    throw { kind: 'transient', status: response.status };
                }
                if (!response.ok) throw { kind: 'fatal', status: response.status };

                var serverRunId = Number(data.run_id || 0);
                var previousRunId = Number(state.launchRollback && state.launchRollback.runId || 0);
                var serverHasActiveRun = data.status === 'pending' || data.status === 'running';
                var serverRunIsTerminal = data.status === 'completed' || data.status === 'failed';
                var newTerminalRun = serverRunIsTerminal && serverRunId > previousRunId;
                if (serverHasActiveRun || newTerminalRun) {
                    if (serverRunId <= 0) {
                        throw { kind: 'transient', status: response.status };
                    }

                    state.retryIndex = 0;
                    if (serverHasActiveRun) {
                        showToast('info', 'Une découverte active a été retrouvée. Le suivi reprend.', 'En cours');
                    }
                    attach(state.criteriaId, data.run_id, exactStatusUrl(genericStatusUrl, data), data);
                    return;
                }

                state.reconcileStartedAt = state.reconcileStartedAt || Date.now();
                if (Date.now() - state.reconcileStartedAt < RECONCILIATION_GRACE_MS) {
                    renderMessage(
                        state.criteriaId,
                        'Lancement en cours de confirmation — nouvelle vérification automatique…',
                        false
                    );
                    var graceDelay = TRANSIENT_BACKOFF_MS[Math.min(state.retryIndex, TRANSIENT_BACKOFF_MS.length - 1)];
                    state.retryIndex++;
                    scheduleReconciliation(state, genericStatusUrl, failureMessage, graceDelay);
                    return;
                }

                var resolvedFailure = data.error || failureMessage;
                failLaunch(state, resolvedFailure);
                showToast('error', resolvedFailure, 'Erreur');
            })
            .catch(function (error) {
                if (state.generation !== generation
                    || !state.launching
                    || (error && error.kind === 'stale')
                    || (error && error.name === 'AbortError' && !request.timedOut)
                ) {
                    return;
                }

                if (error && error.kind === 'fatal') {
                    blockTracker(
                        state,
                        'Le lancement reste incertain. Rechargez la page avant toute nouvelle tentative.'
                    );
                    return;
                }

                renderMessage(
                    state.criteriaId,
                    'Lancement incertain — vérification automatique de la découverte en cours…',
                    false
                );
                var delay = TRANSIENT_BACKOFF_MS[Math.min(state.retryIndex, TRANSIENT_BACKOFF_MS.length - 1)];
                state.retryIndex++;
                scheduleReconciliation(state, genericStatusUrl, failureMessage, delay);
            })
            .finally(function () {
                finishRequest(state, request);
            });
    }

    function launch(button) {
        var criteriaId = Number(button.dataset.criteriaId);
        var existing = states.get(criteriaId);
        if (!criteriaId || button.disabled || (existing && (existing.active || existing.launching || existing.blocked))) return;

        Swal.fire({
            title: 'Lancer la découverte ?',
            text: 'La découverte sera exécutée en arrière-plan pour ce critère.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Lancer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#009ef7',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            var current = states.get(criteriaId);
            if (current && (current.active || current.launching || current.blocked)) return;

            var state = current || {
                criteriaId: criteriaId,
                timer: null,
                retryIndex: 0,
                generation: 0,
                controller: null,
            };
            state.launchRollback = captureLaunchRollback(state);
            invalidateRequest(state);
            state.active = false;
            state.launching = true;
            state.blocked = false;
            state.blockedMessage = null;
            state.launchError = null;
            state.lastData = { status: 'pending', phase: 'queued' };
            state.reconcileStatusUrl = button.dataset.statusUrl;
            state.reconcileFailureMessage = 'La découverte n’a pas pu être lancée. Vérifiez votre connexion.';
            states.set(criteriaId, state);

            setButtons(criteriaId, true, 'launching');
            renderData(criteriaId, state.lastData);

            var generation = state.generation;
            var request = beginRequest(state);

            fetch(button.dataset.launchUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': button.dataset.csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
                signal: request.controller.signal,
            })
                .then(parseJson)
                .then(function (result) {
                    if (!state.launching || state.generation !== generation) return;

                    var response = result.response;
                    var data = result.data;
                    var returnedStatus = data.status || (response.status === 409 ? 'running' : 'pending');
                    var canTrack = Number(data.run_id || 0) > 0
                        && (returnedStatus === 'pending' || returnedStatus === 'running');

                    if (canTrack && (response.ok || response.status === 409 || isTransientStatus(response.status))) {
                        showToast(
                            response.status === 409 ? 'info' : 'success',
                            data.text || (response.status === 409
                                ? 'Une découverte est déjà en cours.'
                                : 'Découverte lancée en arrière-plan.'),
                            response.status === 409 ? 'En cours' : 'Lancement'
                        );
                        attach(criteriaId, data.run_id, exactStatusUrl(button.dataset.statusUrl, data), {
                            status: returnedStatus,
                            phase: returnedStatus === 'pending' ? 'queued' : 'collecting',
                            run_id: data.run_id,
                        });
                        return;
                    }

                    var message = data.text || 'La découverte n’a pas pu être lancée.';
                    if (isTransientStatus(response.status) || (response.ok && !canTrack)) {
                        reconcileLaunch(state, button.dataset.statusUrl, message);
                        return;
                    }

                    failLaunch(state, message);
                    showToast('error', message, 'Erreur');
                })
                .catch(function (error) {
                    if (state.generation !== generation
                        || !state.launching
                        || (error && error.name === 'AbortError' && !request.timedOut)
                    ) {
                        return;
                    }
                    var message = 'La découverte n’a pas pu être lancée. Vérifiez votre connexion.';
                    reconcileLaunch(state, button.dataset.statusUrl, message);
                })
                .finally(function () {
                    finishRequest(state, request);
                });
        });
    }

    function scanTrackers() {
        document.querySelectorAll('[data-discovery-tracker]').forEach(function (root) {
            var criteriaId = Number(root.dataset.criteriaId);
            var status = root.dataset.status || '';
            if (!criteriaId) return;

            var current = states.get(criteriaId);
            if (current && current.launchError) {
                var serverHasActiveRun = (status === 'pending' || status === 'running')
                    && Number(root.dataset.runId || 0) > 0
                    && Boolean(root.dataset.statusUrl);
                if (!serverHasActiveRun) {
                    setButtons(criteriaId, false);
                    renderMessage(criteriaId, current.launchError, true);
                    return;
                }
                current.launchError = null;
            }
            if (current && current.launching) {
                if (current.lastData) renderData(criteriaId, current.lastData);
                setButtons(criteriaId, true, 'launching');
                return;
            }
            if (current && current.blocked) {
                setButtons(criteriaId, true, 'running');
                renderMessage(criteriaId, current.blockedMessage, true);
                return;
            }
            if (current && current.active) {
                root.dataset.runId = current.runId || '';
                root.dataset.statusUrl = current.statusUrl || root.dataset.statusUrl;
                if (current.lastData) renderData(criteriaId, current.lastData);
                setButtons(criteriaId, true, 'running');
                return;
            }
            if (current && current.lastData
                && Number(root.dataset.runId || 0) === Number(current.runId || 0)
            ) {
                renderData(criteriaId, current.lastData);
                status = root.dataset.status || '';
            }
            if (status !== 'pending' && status !== 'running') return;
            setButtons(criteriaId, true, 'running');
            if ((!current || !current.active)
                && Number(root.dataset.runId || 0) > 0
                && Boolean(root.dataset.statusUrl)
            ) {
                attach(criteriaId, root.dataset.runId, root.dataset.statusUrl);
            }
        });
    }

    function pauseForPageHide(state) {
        if (state.launching && state.reconcileStatusUrl) {
            state.resumeMode = 'reconcile';
        } else if (state.active && state.runId && state.statusUrl) {
            state.resumeMode = 'poll';
        } else {
            state.resumeMode = null;
        }
        stop(state, false);
    }

    function resumeAfterPageShow(state) {
        var mode = state.resumeMode;
        delete state.resumeMode;

        if (mode === 'poll' && state.runId && state.statusUrl) {
            attach(state.criteriaId, state.runId, state.statusUrl, state.lastData);
            return;
        }
        if (mode === 'reconcile' && state.reconcileStatusUrl) {
            state.active = false;
            state.launching = true;
            state.blocked = false;
            if (state.lastData) renderData(state.criteriaId, state.lastData);
            setButtons(state.criteriaId, true, 'launching');
            reconcileLaunch(
                state,
                state.reconcileStatusUrl,
                state.reconcileFailureMessage || 'La découverte n’a pas pu être lancée.'
            );
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-discovery-launch]');
        if (!button) return;
        event.preventDefault();
        if (blockUnsavedCriteriaAction()) return;
        launch(button);
    });

    document.addEventListener('DOMContentLoaded', scanTrackers);
    if (window.jQuery) {
        jQuery(document).on('draw.dt', '#prospect_criteria-table', function () {
            window.setTimeout(scanTrackers, 0);
        });
    }
    window.addEventListener('pagehide', function () {
        states.forEach(pauseForPageHide);
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        states.forEach(resumeAfterPageShow);
        if (event.persisted) scanTrackers();
    });

    window.DiscoveryProgressTracker = { scan: scanTrackers };

    window.launchMissingContactEnrichment = function (button) {
        if (!button || button.disabled) return;
        if (blockUnsavedCriteriaAction()) return;

        var scoreInput = document.querySelector('[name="min_score_enrich"]');
        if (scoreInput && String(scoreInput.value || '') !== String(button.dataset.savedMinScore || '')) {
            Swal.fire({
                title: 'Enregistrez avant d’enrichir',
                text: 'Le score minimal affiché diffère de la valeur enregistrée.',
                icon: 'warning',
                confirmButtonText: 'Compris',
            });
            return;
        }

        button.disabled = true;
        fetch(button.dataset.previewUrl, {
            headers: { 'Accept': 'application/json' },
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.text || 'Prévisualisation impossible.');
                return data;
            });
        })
        .then(function (data) {
            var eligible = Number(data.eligible_count || 0);
            var callable = Number(data.callable_count || 0);
            var successTarget = Number(data.success_target || 0);
            var attemptLimit = Number(data.attempt_limit || 0);
            var threshold = Number(data.effective_min_score || 0);
            var summary = 'Seuil enregistré : ' + threshold + '/100. ' +
                eligible + ' entreprise(s) éligible(s). ' +
                'Objectif : ' + successTarget + ' enrichissement(s) réussi(s), avec au plus ' + attemptLimit + ' tentative(s). ' +
                'Les appels vides ou en échec consomment quand même le quota package. ' +
                String(data.limit_note || '');

            if (callable === 0) {
                return Swal.fire({
                    title: 'Aucune entreprise à interroger',
                    text: summary,
                    icon: 'info',
                    confirmButtonText: 'Fermer',
                }).then(function () { button.disabled = false; });
            }

            return Swal.fire({
                title: 'Chercher les contacts manquants ?',
                text: summary,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Lancer ' + attemptLimit + ' tentative(s) max.',
                cancelButtonText: 'Annuler',
                confirmButtonColor: '#009ef7',
            }).then(function (result) {
                if (!result.isConfirmed) {
                    button.disabled = false;
                    return;
                }

                return fetch(button.dataset.dispatchUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': button.dataset.csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ approval_token: data.approval_token }),
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        return { status: response.status, data: payload };
                    });
                }).then(function (response) {
                    if (response.status === 202) {
                        showToast('success', response.data.text, 'Recherche lancée');
                        button.disabled = false;
                        return;
                    }
                    if (response.status === 409) {
                        showToast('info', response.data.text, 'En cours');
                    } else {
                        showToast('error', response.data.text || 'La recherche n’a pas pu être lancée.', 'Erreur');
                    }
                    button.disabled = false;
                }).catch(function () {
                    showToast('error', 'La recherche n’a pas pu être lancée.', 'Erreur');
                    button.disabled = false;
                });
            });
        })
        .catch(function (error) {
            button.disabled = false;
            Swal.fire({
                title: 'Erreur',
                text: error.message || 'Prévisualisation impossible.',
                icon: 'error',
            });
        });
    };
})();
</script>
