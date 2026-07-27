<div class="card"><div class="card-body p-6 p-lg-10">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 mb-8">
        <div><h3 class="mb-2">Discover IA</h3><p class="text-gray-600 mb-0">L’aperçu coûte 1 des 50 recherches IA mensuelles. L’import ne consomme ni recherche e-mail ni quota Fretiq.</p></div>
        <span class="badge badge-light-warning align-self-start">Les exclusions restent imparfaites</span>
    </div>
    <form id="hunter-discover-form" data-preview-url="{{ route('admin.prospect_criteria.hunter_discover_preview', $model->id) }}" data-import-url="{{ route('admin.prospect_criteria.hunter_discover_import', $model->id) }}">
        @csrf
        <div class="row g-5">
            <div class="col-12 col-lg-6"><label class="form-label" for="hunter-target">Cible</label><textarea id="hunter-target" name="target" class="form-control" rows="4" maxlength="2000" placeholder="Ex. transitaires indépendants exportant depuis la France">{{ $model->ai_target }}</textarea></div>
            <div class="col-12 col-lg-6"><label class="form-label" for="hunter-exclude">Exclure</label><textarea id="hunter-exclude" name="exclude" class="form-control" rows="4" maxlength="2000" placeholder="Ex. grands groupes, concurrents directs">{{ $model->ai_exclude }}</textarea></div>
        </div>
        <div class="d-flex align-items-center gap-3 mt-6"><button class="btn btn-primary" type="submit" data-hunter-preview><span class="indicator-label"><i class="bi bi-stars me-2"></i>Prévisualiser</span><span class="indicator-progress">Recherche… <span class="spinner-border spinner-border-sm ms-2"></span></span></button><span class="text-danger small" data-hunter-error role="alert"></span></div>
    </form>
    <div class="mt-8 d-none" data-hunter-results>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><h4 class="mb-0">Entreprises proposées</h4><button class="btn btn-success" type="button" data-hunter-import disabled>Importer la sélection</button></div>
        <div class="table-responsive"><table class="table table-row-dashed align-middle"><thead><tr><th class="w-40px"></th><th>Entreprise</th><th>Domaine</th><th>E-mails trouvés</th><th>Statut</th></tr></thead><tbody data-hunter-rows></tbody></table></div>
        <div class="text-center text-gray-600 py-8 d-none" data-hunter-empty>Aucune entreprise exploitable trouvée.</div>
    </div>
</div></div>
