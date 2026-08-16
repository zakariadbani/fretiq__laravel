<div class="card"><div class="card-body p-6 p-lg-10">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 mb-8">
        <div><h3 class="mb-2">Discover IA</h3><p class="text-gray-600 mb-0">Vérifier la cible et préparer l’estimation ne consomme aucun crédit. Vous confirmerez le lancement et son coût sur le lot.</p></div>
        <span class="badge badge-light-warning align-self-start">Les exclusions restent imparfaites</span>
    </div>
    <form id="hunter-discover-form" data-preview-url="{{ route('admin.prospect_criteria.hunter_discover_preview', $model->id) }}" data-import-url="{{ route('admin.prospect_criteria.hunter_discover_import', $model->id) }}" data-confirm-url-template="{{ route('admin.prospect_batches.confirm', ['id' => '__BATCH_ID__']) }}">
        @csrf
        <div class="row g-5">
            <div class="col-12 col-lg-6"><label class="form-label" for="hunter-target">Cible</label><textarea id="hunter-target" name="target" class="form-control" rows="4" maxlength="2000" placeholder="Ex. transitaires indépendants exportant depuis la France">{{ $model->ai_target }}</textarea></div>
            <div class="col-12 col-lg-6"><label class="form-label" for="hunter-exclude">Exclure</label><textarea id="hunter-exclude" name="exclude" class="form-control" rows="4" maxlength="2000" placeholder="Ex. grands groupes, concurrents directs">{{ $model->ai_exclude }}</textarea></div>
        </div>
        <div class="d-flex align-items-center gap-3 mt-6"><button class="btn btn-primary" type="submit" data-hunter-preview><span class="indicator-label"><i class="bi bi-stars me-2"></i>Vérifier la cible</span><span class="indicator-progress">Vérification… <span class="spinner-border spinner-border-sm ms-2"></span></span></button><span class="text-danger small" data-hunter-error role="alert" aria-live="polite"></span></div>
    </form>
    <div class="mt-8 d-none" data-hunter-results>
        <div class="border border-dashed rounded p-5 mb-5" aria-live="polite">
            <h4 class="mb-3">Cible prête</h4>
            <p class="text-gray-700 mb-3" data-hunter-prompt></p>
            <div class="d-flex flex-wrap gap-3 small text-gray-600">
                <span><strong data-hunter-call-count>0</strong> appel Discover au lancement</span>
                <span><strong data-hunter-credit-count>0</strong> crédit Hunter estimé pour Discover</span>
            </div>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <p class="text-gray-600 mb-0">Aucune entreprise n’est créée avant votre confirmation.</p>
            <button class="btn btn-success" type="button" data-hunter-import disabled>Continuer vers la confirmation</button>
        </div>
    </div>
    {{--
        Discover IA's own launch confirmation + progress, revealed in place —
        never navigates to the batch wizard. Deliberately distinct from the
        permanent SerpAPI banner above the tabs (_discovery-status.blade.php,
        border-primary/"Progression de la découverte"): border-success here,
        its own "Discover IA" heading, so the two engines never read as one.
    --}}
    <div class="mt-8 d-none" data-hunter-confirm>
        <div class="border border-dashed border-success rounded p-5" aria-live="polite">
            <h4 class="mb-3"><i class="bi bi-rocket-takeoff text-success me-2"></i>Lancer le traitement Discover IA</h4>
            <div class="row g-5 mb-5">
                <div class="col-6 col-md-4"><div class="card bg-light h-100"><div class="card-body"><div class="text-muted fs-7">Entreprises (jusqu’à)</div><div class="fs-2 fw-bold" data-hunter-confirm-items>—</div></div></div></div>
                <div class="col-6 col-md-4"><div class="card bg-light h-100"><div class="card-body"><div class="text-muted fs-7">Crédit Hunter estimé</div><div class="fs-2 fw-bold" data-hunter-confirm-credits>—</div></div></div></div>
            </div>
            <label class="form-check form-check-custom form-check-solid p-4 border rounded mb-5">
                <input class="form-check-input" type="checkbox" data-hunter-confirm-checkbox>
                <span class="form-check-label fw-semibold">Je confirme le lancement du traitement.</span>
            </label>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-success" type="button" data-hunter-confirm-submit><span class="indicator-label">Lancer le traitement</span><span class="indicator-progress">Lancement… <span class="spinner-border spinner-border-sm ms-2"></span></span></button>
                <span class="text-danger small" data-hunter-confirm-error role="alert" aria-live="polite"></span>
            </div>
        </div>
    </div>
    <div class="mt-8 d-none" data-hunter-progress>
        <div class="border border-dashed border-success rounded p-8 text-center" aria-live="polite">
            <div class="spinner-border text-primary mb-5" data-hunter-progress-spinner role="status"><span class="visually-hidden">Traitement en cours</span></div>
            <i class="bi bi-check-circle-fill text-success fs-3x d-none mb-5" data-hunter-progress-success></i>
            <h4 class="fw-bold" data-hunter-progress-title>Traitement en cours</h4>
            <p class="text-muted" data-hunter-progress-message>Vous pouvez quitter cet onglet ; le lot continue en arrière-plan.</p>
            <div class="progress h-8px mw-500px mx-auto my-6"><div class="progress-bar bg-primary" data-hunter-progress-bar style="width: 0%"></div></div>
            <div class="fw-semibold" aria-live="polite" data-hunter-progress-percent>0 %</div>
            <div class="alert alert-warning d-none mt-6 text-start" data-hunter-worker-waiting>Le lot attend un worker. Vérifiez que la file « default » est démarrée.</div>
            <div class="d-flex flex-wrap justify-content-center gap-3 mt-7">
                <a href="#" class="btn btn-light-primary" data-hunter-view-link>Voir le lot</a>
                <a href="#" class="btn btn-warning d-none" data-hunter-review-link>À revoir</a>
            </div>
        </div>
    </div>
</div></div>
