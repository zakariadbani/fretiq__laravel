<div class="card"><div class="card-body p-6 p-lg-10">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 mb-8">
        <div><h3 class="mb-2">Discover IA</h3><p class="text-gray-600 mb-0">Vérifier la cible et préparer l’estimation ne consomme aucun crédit. Vous confirmerez le lancement et son coût sur le lot.</p></div>
        <span class="badge badge-light-warning align-self-start">Les exclusions restent imparfaites</span>
    </div>
    <form id="hunter-discover-form" data-preview-url="{{ route('admin.prospect_criteria.hunter_discover_preview', $model->id) }}" data-import-url="{{ route('admin.prospect_criteria.hunter_discover_import', $model->id) }}">
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
</div></div>
