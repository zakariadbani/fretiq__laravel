<script>
(function () {
    'use strict';

    var root = document.querySelector('[data-zoho-live-progress]');
    if (!root) return;

    var timer = null;
    var controller = null;
    var stopped = false;
    var transientFailures = 0;
    var backoffMs = [5000, 10000, 30000];
    var fatalStatuses = [401, 403, 404];
    var numberFormatter = new Intl.NumberFormat('fr-FR');
    var dateFormatter = new Intl.DateTimeFormat('fr-FR', {
        timeZone: @json(config('app.timezone', 'UTC')),
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
    });
    var phaseLabels = {
        waiting: 'En attente',
        enumerating: 'Énumération',
        hydrating: 'Hydratation',
        retrying: 'Nouvelle tentative',
        paused: 'En pause',
        completed: 'Terminée',
        error: 'En erreur'
    };
    var batchStatusLabels = {
        queued: 'En attente',
        running: 'En cours',
        paused: 'En pause',
        success: 'Terminée',
        partial: 'Terminée avec anomalies',
        error: 'En erreur'
    };

    function setText(scope, selector, value) {
        var element = scope.querySelector(selector);
        if (element) element.textContent = String(value);
    }

    function setAllText(scope, selector, value) {
        scope.querySelectorAll(selector).forEach(function (element) {
            element.textContent = String(value);
        });
    }

    function formatNumber(value) {
        return numberFormatter.format(Math.max(0, Number(value) || 0));
    }

    function formatDate(value) {
        if (!value) return 'aucune activité';
        var date = new Date(value);
        return Number.isNaN(date.getTime()) ? 'date indisponible' : dateFormatter.format(date);
    }

    function renderBar(bar, percent, indeterminate, animated) {
        if (!bar) return;
        var bounded = Math.max(0, Math.min(100, Number(percent) || 0));
        bar.style.width = (indeterminate ? 100 : bounded) + '%';
        bar.classList.toggle('progress-bar-striped', indeterminate);
        bar.classList.toggle('progress-bar-animated', indeterminate && animated);
        if (indeterminate) {
            bar.removeAttribute('aria-valuenow');
        } else {
            bar.setAttribute('aria-valuenow', String(bounded));
        }
    }

    function renderProgress(progressRoot, payload) {
        var summary = payload.summary || {};
        var batch = payload.batch || {};
        var active = !batch.terminal && (batch.status === 'queued' || batch.status === 'running');
        var determinate = Boolean(summary.determinate);

        progressRoot.dataset.batchStatus = String(batch.status || '');
        progressRoot.dataset.pollAfterMs = String(payload.poll_after_ms || 5000);
        setAllText(progressRoot, '[data-zoho-progress-status]', batchStatusLabels[batch.status] || batch.status || 'Inconnu');
        setText(progressRoot, '[data-zoho-progress-observed-at]', formatDate(payload.observed_at));
        setText(progressRoot, '[data-zoho-progress-modules]', formatNumber(summary.modules_completed) + ' / ' + formatNumber(summary.modules_total));
        setText(progressRoot, '[data-zoho-progress-discovered]', formatNumber(summary.discovered));
        setText(progressRoot, '[data-zoho-progress-processed]', formatNumber(summary.processed));
        setText(progressRoot, '[data-zoho-progress-queued]', formatNumber(summary.queued));
        setText(progressRoot, '[data-zoho-progress-processing]', formatNumber(summary.processing));
        setText(progressRoot, '[data-zoho-progress-quarantined]', formatNumber(summary.quarantined));
        setText(progressRoot, '[data-zoho-progress-last-activity]', formatDate(payload.last_activity_at));
        setText(progressRoot, '[data-zoho-progress-message]', determinate ? 'Fiches Traitées' : 'Énumération des identifiants — le total augmente');
        setText(progressRoot, '[data-zoho-progress-percent]', determinate ? (Number(summary.percent) || 0) + ' %' : 'Total en cours de calcul');

        var stalled = progressRoot.querySelector('[data-zoho-progress-stalled]');
        if (stalled) stalled.classList.toggle('d-none', !payload.stalled);
        renderBar(progressRoot.querySelector(':scope > .card-body > .progress [data-zoho-progress-bar]'), summary.percent, !determinate, active);

        var modules = payload.modules || {};
        progressRoot.querySelectorAll('[data-zoho-progress-module]').forEach(function (moduleRoot) {
            var module = modules[moduleRoot.dataset.zohoProgressModule];
            if (!module) {
                moduleRoot.classList.add('d-none');
                return;
            }

            var moduleDeterminate = Boolean(module.enumeration_complete);
            var moduleActive = active && ['paused', 'completed', 'error'].indexOf(module.phase) === -1;
            moduleRoot.classList.toggle('d-none', module.phase === 'completed');
            setText(moduleRoot, '[data-zoho-progress-module-label]', module.label || module.key || 'Module');
            setText(moduleRoot, '[data-zoho-progress-phase]', phaseLabels[module.phase] || module.phase || 'Inconnu');
            setText(moduleRoot, '[data-zoho-progress-module-percent]', moduleDeterminate ? (Number(module.percent) || 0) + ' %' : 'total en cours');
            setText(moduleRoot, '[data-zoho-progress-discovered]', formatNumber(module.discovered));
            setText(moduleRoot, '[data-zoho-progress-processed]', formatNumber(module.processed));
            setText(moduleRoot, '[data-zoho-progress-queued]', formatNumber(module.queued));
            setText(moduleRoot, '[data-zoho-progress-processing]', formatNumber(module.processing));
            setText(moduleRoot, '[data-zoho-progress-quarantined]', formatNumber(module.quarantined));
            setText(moduleRoot, '[data-zoho-progress-last-activity]', formatDate(module.last_activity_at));
            renderBar(moduleRoot.querySelector('[data-zoho-progress-bar]'), module.percent, !moduleDeterminate, moduleActive);
        });
    }

    function schedule(delay) {
        clearTimeout(timer);
        if (stopped || document.hidden) return;
        timer = setTimeout(poll, Math.max(1000, Number(delay) || 5000));
    }

    function stop(message) {
        stopped = true;
        clearTimeout(timer);
        if (controller) controller.abort();
        controller = null;
        if (message) setText(root, '[data-zoho-progress-message]', message);
    }

    async function poll() {
        if (stopped || document.hidden) return;
        if (controller) controller.abort();
        controller = new AbortController();
        var requestController = controller;

        try {
            var response = await fetch(root.dataset.statusUrl, {
                headers: {'Accept': 'application/json'},
                cache: 'no-store',
                signal: requestController.signal
            });
            if (fatalStatuses.indexOf(response.status) !== -1) {
                stop('Suivi interrompu — actualisez la page');
                return;
            }
            if (!response.ok) {
                var transientError = new Error('Transient progress response');
                transientError.transient = response.status === 429 || response.status >= 500;
                throw transientError;
            }

            var payload = await response.json();
            if (!payload.batch || String(payload.batch.id) !== root.dataset.batchId) {
                stop('Suivi interrompu — actualisez la page');
                return;
            }

            var previousStatus = root.dataset.batchStatus;
            renderProgress(root, payload);
            transientFailures = 0;

            if (payload.batch.terminal || (previousStatus && previousStatus !== String(payload.batch.status))) {
                window.location.reload();
                return;
            }
            schedule(payload.poll_after_ms || (payload.batch.status === 'paused' ? 15000 : 5000));
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (error.transient === false) {
                stop('Suivi interrompu — actualisez la page');
                return;
            }
            setText(root, '[data-zoho-progress-message]', 'Suivi temporairement indisponible — nouvelle tentative');
            var delay = backoffMs[Math.min(transientFailures, backoffMs.length - 1)];
            transientFailures += 1;
            schedule(delay);
        } finally {
            if (controller === requestController) controller = null;
        }
    }

    document.addEventListener('visibilitychange', function () {
        clearTimeout(timer);
        if (document.hidden) {
            if (controller) controller.abort();
            return;
        }
        poll();
    });
    window.addEventListener('pagehide', function () { stop(); }, {once: true});

    schedule(Number(root.dataset.pollAfterMs) || 5000);
}());
</script>
