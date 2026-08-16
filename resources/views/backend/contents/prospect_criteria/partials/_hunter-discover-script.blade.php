<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('hunter-discover-form');
    if (!form || form.dataset.hunterDiscoverBound === '1') return;
    form.dataset.hunterDiscoverBound = '1';

    const previewButton = form.querySelector('[data-hunter-preview]');
    const continueButton = document.querySelector('[data-hunter-import]');
    const results = document.querySelector('[data-hunter-results]');
    const prompt = document.querySelector('[data-hunter-prompt]');
    const callCount = document.querySelector('[data-hunter-call-count]');
    const creditCount = document.querySelector('[data-hunter-credit-count]');
    const error = form.querySelector('[data-hunter-error]');

    // Launch confirmation panel (revealed in place once hunterDiscoverImport
    // has created a draft batch) and the progress panel that follows it.
    // Both poll admin.prospect_batches.status, never
    // admin.prospect_criteria.discovery_status — that endpoint is bound to
    // discovery_runs (the SerpAPI engine) and cannot see a Discover batch.
    const confirmPanel = document.querySelector('[data-hunter-confirm]');
    const confirmItems = document.querySelector('[data-hunter-confirm-items]');
    const confirmCredits = document.querySelector('[data-hunter-confirm-credits]');
    const confirmCheckbox = document.querySelector('[data-hunter-confirm-checkbox]');
    const confirmSubmit = document.querySelector('[data-hunter-confirm-submit]');
    const confirmError = document.querySelector('[data-hunter-confirm-error]');

    const progressPanel = document.querySelector('[data-hunter-progress]');
    const progressBar = document.querySelector('[data-hunter-progress-bar]');
    const progressPercent = document.querySelector('[data-hunter-progress-percent]');
    const progressSpinner = document.querySelector('[data-hunter-progress-spinner]');
    const progressSuccess = document.querySelector('[data-hunter-progress-success]');
    const progressTitle = document.querySelector('[data-hunter-progress-title]');
    const progressMessage = document.querySelector('[data-hunter-progress-message]');
    const workerWaiting = document.querySelector('[data-hunter-worker-waiting]');
    const viewLink = document.querySelector('[data-hunter-view-link]');
    const reviewLink = document.querySelector('[data-hunter-review-link]');

    let preparedDraft = null;
    let activeBatchId = null;
    let statusUrl = null;
    let pollAttempt = 0;
    let polling = false;

    const message = value => { error.textContent = value || ''; };
    const confirmMessage = value => { confirmError.textContent = value || ''; };
    const csrf = () => form.querySelector('[name=_token]').value;
    const normalizedPayload = () => {
        const data = new FormData(form);

        return {
            target: String(data.get('target') || '').trim(),
            exclude: String(data.get('exclude') || '').trim(),
            quality_preset: 'balanced',
        };
    };

    // Editing the target/exclude after a draft exists doesn't touch that
    // already-created batch — but it invalidates what's on screen, so hide
    // the confirm/progress panels and stop any in-flight polling rather than
    // leave a stale launch state showing next to a changed prompt.
    const resetLaunch = () => {
        polling = false;
        activeBatchId = null;
        statusUrl = null;
        confirmPanel.classList.add('d-none');
        progressPanel.classList.add('d-none');
        confirmMessage('');
    };

    form.querySelectorAll('textarea').forEach(field => field.addEventListener('input', () => {
        preparedDraft = null;
        continueButton.disabled = true;
        results.classList.add('d-none');
        message('');
        resetLaunch();
    }));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (previewButton.disabled) return;

        previewButton.disabled = true;
        previewButton.setAttribute('data-kt-indicator', 'on');
        continueButton.disabled = true;
        preparedDraft = null;
        message('');
        results.classList.add('d-none');
        resetLaunch();

        try {
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()},
                body: new FormData(form),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'La vérification a échoué.');

            preparedDraft = data.draft;
            prompt.textContent = data.prompt || '';
            callCount.textContent = String(data.estimate?.calls?.hunter_discover ?? 0);
            creditCount.textContent = String(data.estimate?.reserved_units?.hunter ?? 0);
            results.classList.remove('d-none');
            continueButton.disabled = false;
        } catch (exception) {
            message(exception.message);
        } finally {
            previewButton.disabled = false;
            previewButton.removeAttribute('data-kt-indicator');
        }
    });

    // "Continuer vers la confirmation" creates (or reuses) the draft batch,
    // then reveals the launch confirmation in place — it must never navigate
    // away. hunterDiscoverImport's JSON response is untouched by this change:
    // batch_id/status/estimate/redirect_url all still ship, redirect_url is
    // just no longer used.
    continueButton.addEventListener('click', async () => {
        if (!preparedDraft || continueButton.disabled) return;

        continueButton.disabled = true;
        message('');

        try {
            const response = await fetch(form.dataset.importUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({...normalizedPayload(), ...preparedDraft}),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Le lot n’a pas pu être créé.');

            activeBatchId = data.batch_id;
            confirmItems.textContent = String(data.estimate?.items ?? '—');
            confirmCredits.textContent = String(data.estimate?.reserved_units?.hunter ?? 0);
            confirmCheckbox.checked = false;
            confirmSubmit.disabled = false;
            confirmMessage('');
            results.classList.add('d-none');
            confirmPanel.classList.remove('d-none');
            confirmPanel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        } catch (exception) {
            message(exception.message);
        } finally {
            continueButton.disabled = false;
        }
    });

    // admin.prospect_batches.confirm replies {message:'error', code:'...'} on
    // failure, not a human-readable message — mirrors
    // prospect-batch-wizard.js's friendlyFailure() so the same failure reads
    // the same way in both places.
    const friendlyConfirmFailure = (status, payload) => {
        const code = payload?.code;
        if (status === 403) return 'Vous n’avez pas la permission de lancer ce traitement.';
        if (status === 409 || code === 'prospect_discover_active_batch_exists') return 'Un autre lot Discover est déjà en cours pour ce critère.';
        if (status === 429) return 'Le quota fournisseur est temporairement atteint. Réessayez plus tard.';
        if (payload?.errors) {
            const first = Object.values(payload.errors).flat()[0];
            if (typeof first === 'string') return first;
        }
        if (code === 'prospect_batch_estimate_stale') return 'Le lot a changé. Revenez à « Vérifier la cible » avant de lancer.';
        if (code === 'prospect_batch_already_confirmed') return 'Ce lot a déjà été lancé.';
        return 'L’action n’a pas pu être terminée. Réessayez.';
    };

    confirmSubmit.addEventListener('click', async () => {
        if (confirmSubmit.disabled || !activeBatchId) return;
        if (!confirmCheckbox.checked) {
            confirmMessage('Confirmez le lancement avant de continuer.');
            return;
        }

        confirmSubmit.disabled = true;
        confirmSubmit.setAttribute('data-kt-indicator', 'on');
        confirmMessage('');

        try {
            const confirmUrl = form.dataset.confirmUrlTemplate.replace('__BATCH_ID__', String(activeBatchId));
            const response = await fetch(confirmUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({confirm_cost: true}),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw {status: response.status, payload};

            statusUrl = payload.status_url;
            if (viewLink) viewLink.href = payload.view_url || '#';
            if (reviewLink) reviewLink.classList.add('d-none');
            if (progressBar) progressBar.style.width = '0%';
            if (progressPercent) progressPercent.textContent = '0 % — 0/0';
            if (progressSpinner) progressSpinner.classList.remove('d-none');
            if (progressSuccess) progressSuccess.classList.add('d-none');
            progressTitle.textContent = 'Traitement en cours';
            progressMessage.textContent = 'Vous pouvez quitter cet onglet ; le lot continue en arrière-plan.';

            confirmPanel.classList.add('d-none');
            progressPanel.classList.remove('d-none');
            progressPanel.scrollIntoView({behavior: 'smooth', block: 'nearest'});

            polling = true;
            pollAttempt = 0;
            poll();
        } catch (failure) {
            confirmMessage(friendlyConfirmFailure(failure.status, failure.payload));
        } finally {
            confirmSubmit.disabled = false;
            confirmSubmit.removeAttribute('data-kt-indicator');
        }
    });

    // Same shape and cadence as prospect-batch-wizard.js's updateProcessing()
    // / poll() for step 4, so a Discover-created batch and a wizard-created
    // batch report progress identically.
    const updateProgress = payload => {
        const percent = Number(payload.progress?.percent || 0);
        if (progressBar) progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        if (progressPercent) progressPercent.textContent = percent + ' % — ' + Number(payload.progress?.processed || 0) + '/' + Number(payload.progress?.total || 0);
        if (workerWaiting) workerWaiting.classList.toggle('d-none', payload.worker_waiting !== true);
        if (viewLink && payload.view_url) viewLink.href = payload.view_url;

        if (!payload.terminal) return false;

        polling = false;
        if (progressSpinner) progressSpinner.classList.add('d-none');
        if (progressSuccess) progressSuccess.classList.remove('d-none');
        if (payload.status === 'review') {
            progressTitle.textContent = 'Des éléments sont à revoir';
            progressMessage.textContent = 'Les données sûres sont conservées. Choisissez uniquement les correspondances ambiguës.';
            if (reviewLink && payload.review_url) {
                reviewLink.href = payload.review_url;
                reviewLink.classList.remove('d-none');
            }
        } else if (payload.status === 'completed') {
            progressTitle.textContent = 'Traitement terminé';
            progressMessage.textContent = 'Le lot est prêt.';
        } else {
            progressTitle.textContent = 'Traitement interrompu';
            progressMessage.textContent = 'Ouvrez le lot pour relancer ou revoir les éléments concernés.';
        }

        return true;
    };

    const poll = async () => {
        if (!polling || !statusUrl) return;

        try {
            const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            if (!response.ok) throw new Error('status_failed');
            const done = updateProgress(await response.json());
            if (done) return;
            pollAttempt += 1;
            const delays = [3000, 5000, 10000, 15000];
            window.setTimeout(poll, delays[Math.min(pollAttempt, delays.length - 1)]);
        } catch (exception) {
            window.setTimeout(poll, 15000);
        }
    };
});
</script>
