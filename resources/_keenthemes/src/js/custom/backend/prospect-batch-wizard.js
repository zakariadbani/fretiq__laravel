(function () {
    'use strict';

    const ready = function (callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    };

    ready(function () {
        const wizard = document.getElementById('prospect_batch_wizard');
        if (!wizard || wizard.dataset.bound === '1') return;
        wizard.dataset.bound = '1';

        const form = document.getElementById('prospect_batch_form');
        const panels = Array.from(wizard.querySelectorAll('[data-prospect-step]'));
        const nav = Array.from(wizard.querySelectorAll('[data-step-nav]'));
        const back = wizard.querySelector('[data-wizard-back]');
        const next = wizard.querySelector('[data-wizard-next]');
        const nextLabel = wizard.querySelector('[data-next-label]');
        const spinner = wizard.querySelector('[data-next-spinner]');
        const alert = document.getElementById('prospect-wizard-alert');
        const batchId = wizard.dataset.batchId || '';
        let step = Math.max(1, Math.min(4, Number(wizard.dataset.initialStep || 1)));
        let pollAttempt = 0;
        let polling = false;

        const csrf = function () {
            return form.querySelector('input[name="_token"]')?.value || '';
        };

        const setBusy = function (busy) {
            next.disabled = busy;
            spinner?.classList.toggle('d-none', !busy);
        };

        const showAlert = function (message, type) {
            if (!alert) return;
            alert.textContent = message;
            alert.className = 'alert alert-' + (type || 'danger');
        };

        const clearAlert = function () {
            if (!alert) return;
            alert.textContent = '';
            alert.className = 'alert d-none';
        };

        const renderStep = function () {
            panels.forEach(function (panel) {
                const isCurrent = Number(panel.dataset.prospectStep) === step;

                panel.classList.toggle('d-none', !isCurrent);
                panel.classList.toggle('current', isCurrent);
                panel.classList.add('flex-column');
            });
            nav.forEach(function (item) {
                const itemStep = Number(item.dataset.stepNav);
                item.classList.toggle('current', itemStep === step);
                item.classList.toggle('completed', itemStep < step);
                item.setAttribute('aria-current', itemStep === step ? 'step' : 'false');
            });
            back.classList.toggle('invisible', step === 1 || step === 4);
            next.classList.toggle('d-none', step === 4);
            nextLabel.textContent = step === 1
                ? (batchId ? 'Choisir la qualité' : 'Importer la liste')
                : (step === 2 ? 'Vérifier le lot' : 'Lancer le traitement');
            clearAlert();
            panels.find(function (panel) { return Number(panel.dataset.prospectStep) === step; })
                ?.querySelector('input, textarea, button')?.focus({ preventScroll: true });
        };

        const selectedQuality = function () {
            return form.querySelector('input[name="quality_preset"]:checked')?.value || 'balanced';
        };

        const request = async function (url, data) {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify(data),
            });
            let payload = {};
            try { payload = await response.json(); } catch (error) { payload = {}; }
            if (!response.ok) {
                const failure = new Error(payload.code || 'request_failed');
                failure.status = response.status;
                failure.payload = payload;
                throw failure;
            }
            return payload;
        };

        const friendlyFailure = function (error) {
            if (error.status === 403) return 'Vous n’avez pas la permission de lancer ce traitement.';
            if (error.status === 409) return 'Un autre lot Discover est déjà en cours pour ce critère.';
            if (error.status === 429) return 'Le quota fournisseur est temporairement atteint. Réessayez plus tard.';
            if (error.payload?.errors) {
                const first = Object.values(error.payload.errors).flat()[0];
                if (typeof first === 'string') return first;
            }
            if (error.message === 'prospect_batch_estimate_stale') return 'Le lot a changé. Actualisez le résumé avant de lancer.';
            return 'L’action n’a pas pu être terminée. Réessayez.';
        };

        const renderEstimate = function (estimate) {
            const text = function (selector, value) {
                const element = wizard.querySelector(selector);
                if (element) element.textContent = String(value ?? '—');
            };
            text('[data-estimate="items"]', estimate.items ?? 0);
            const labels = { lean: 'Rapide', balanced: 'Équilibré', deep: 'Approfondi' };
            text('[data-estimate="preset"]', labels[selectedQuality()] || selectedQuality());
        };

        const estimate = async function () {
            const max = form.querySelector('[name="domain_search_max_results"]')?.value;
            const data = { quality_preset: selectedQuality() };
            if (max) data.domain_search_max_results = Number(max);
            const payload = await request(wizard.dataset.estimateUrl, data);
            renderEstimate(payload.estimate || {});
            step = 3;
            renderStep();
        };

        const updateProcessing = function (payload) {
            const percent = Number(payload.progress?.percent || 0);
            const bar = wizard.querySelector('[data-processing-bar]');
            if (bar) bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
            const progress = wizard.querySelector('[data-processing-progress]');
            if (progress) progress.textContent = percent + ' % — ' + Number(payload.progress?.processed || 0) + '/' + Number(payload.progress?.total || 0);
            wizard.querySelector('[data-worker-waiting]')?.classList.toggle('d-none', payload.worker_waiting !== true);
            const view = wizard.querySelector('[data-batch-view]');
            if (view && payload.view_url) view.href = payload.view_url;
            const review = wizard.querySelector('[data-review-link]');
            if (review && payload.review_url) review.href = payload.review_url;

            if (!payload.terminal) return false;
            polling = false;
            wizard.querySelector('[data-processing-spinner]')?.classList.add('d-none');
            wizard.querySelector('[data-processing-success]')?.classList.remove('d-none');
            const title = wizard.querySelector('[data-processing-title]');
            const message = wizard.querySelector('[data-processing-message]');
            if (payload.status === 'review') {
                if (title) title.textContent = 'Des éléments sont à revoir';
                if (message) message.textContent = 'Les données sûres sont conservées. Choisissez uniquement les correspondances ambiguës.';
                review?.classList.remove('d-none');
            } else if (payload.status === 'completed') {
                if (title) title.textContent = 'Traitement terminé';
                if (message) message.textContent = 'Le lot est prêt.';
            } else {
                if (title) title.textContent = 'Traitement interrompu';
                if (message) message.textContent = 'Ouvrez le lot pour relancer ou revoir les éléments concernés.';
            }
            return true;
        };

        const poll = async function () {
            if (!polling || !wizard.dataset.statusUrl) return;
            try {
                const response = await fetch(wizard.dataset.statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('status_failed');
                const done = updateProcessing(await response.json());
                if (done) return;
                pollAttempt += 1;
                const delays = [3000, 5000, 10000, 15000];
                window.setTimeout(poll, delays[Math.min(pollAttempt, delays.length - 1)]);
            } catch (error) {
                window.setTimeout(poll, 15000);
            }
        };

        const confirm = async function () {
            if (!form.querySelector('[name="confirm_cost"]')?.checked) {
                showAlert('Confirmez le lancement avant de continuer.', 'warning');
                return;
            }
            const payload = await request(wizard.dataset.confirmUrl, { confirm_cost: true });
            if (payload.status_url) wizard.dataset.statusUrl = payload.status_url;
            step = 4;
            renderStep();
            polling = true;
            pollAttempt = 0;
            poll();
        };

        next.addEventListener('click', async function () {
            clearAlert();
            if (step === 1 && !batchId) {
                setBusy(true);
                form.requestSubmit();
                return;
            }
            if (step === 1) {
                step = 2;
                renderStep();
                return;
            }
            setBusy(true);
            try {
                if (step === 2) await estimate();
                else if (step === 3) await confirm();
            } catch (error) {
                showAlert(friendlyFailure(error), 'danger');
            } finally {
                setBusy(false);
            }
        });

        back.addEventListener('click', function () {
            if (step > 1 && step < 4) {
                step -= 1;
                renderStep();
            }
        });

        renderStep();
        if (step === 4 && wizard.dataset.statusUrl) {
            polling = true;
            poll();
        }
    });
})();
