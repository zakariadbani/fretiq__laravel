<div data-kt-stepper-element="content" data-prospect-step="3">
    <div id="prospect-estimate-loading" class="text-center py-10 d-none" role="status">
        <span class="spinner-border text-primary"></span>
        <div class="text-muted mt-3">Calcul du coût…</div>
    </div>
    <div id="prospect-estimate-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="prospect-estimate-panel">
        <h2 class="fs-4 fw-bold">Confirmer le coût</h2>
        <p class="text-muted">Aucun appel Hunter ou SerpAPI ne part avant cette confirmation.</p>
        <div class="row g-5 mb-7">
            <div class="col-6 col-lg-3"><div class="card bg-light"><div class="card-body"><div class="text-muted fs-7">Entreprises</div><div class="fs-2 fw-bold" data-estimate="items">—</div></div></div></div>
            <div class="col-6 col-lg-3"><div class="card bg-light"><div class="card-body"><div class="text-muted fs-7">Hunter max.</div><div class="fs-2 fw-bold" data-estimate="hunter">—</div></div></div></div>
            <div class="col-6 col-lg-3"><div class="card bg-light"><div class="card-body"><div class="text-muted fs-7">SerpAPI max.</div><div class="fs-2 fw-bold" data-estimate="serpapi">—</div></div></div></div>
            <div class="col-6 col-lg-3"><div class="card bg-light"><div class="card-body"><div class="text-muted fs-7">Mode</div><div class="fs-5 fw-bold" data-estimate="preset">Équilibré</div></div></div></div>
        </div>
        <div class="table-responsive mb-7">
            <table class="table table-row-dashed align-middle"><thead><tr><th>Opération</th><th class="text-end">Maximum</th></tr></thead><tbody data-estimate-calls></tbody></table>
        </div>
        <label class="form-check form-check-custom form-check-solid p-4 border rounded">
            <input class="form-check-input" type="checkbox" name="confirm_cost" value="1" id="confirm_cost">
            <span class="form-check-label fw-semibold">J’ai vérifié le coût et je lance le traitement.</span>
        </label>
    </div>
</div>
