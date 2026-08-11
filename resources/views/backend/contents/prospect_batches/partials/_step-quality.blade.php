<div data-kt-stepper-element="content" data-prospect-step="2">
    <div class="mb-7">
        <h2 class="fs-4 fw-bold mb-2">Choisir la qualité</h2>
        <p class="text-muted">« Équilibré » convient à la plupart des listes. Vous pourrez revoir les correspondances ambiguës.</p>
    </div>
    <div class="row g-5">
        @foreach([
            'lean' => ['Rapide', '10 contacts maximum par entreprise', 'bi-lightning'],
            'balanced' => ['Équilibré', '100 contacts maximum, recommandé', 'bi-stars'],
            'deep' => ['Approfondi', '200 contacts maximum', 'bi-search'],
        ] as $key => [$label, $hint, $icon])
            <div class="col-12 col-md-4">
                <input class="btn-check" type="radio" name="quality_preset" id="quality_{{ $key }}"
                       value="{{ $key }}" @checked(old('quality_preset', $model->quality_preset ?: 'balanced') === $key)>
                <label class="card border h-100 cursor-pointer" for="quality_{{ $key }}">
                    <span class="card-body">
                        <i class="bi {{ $icon }} fs-2x text-primary"></i>
                        <span class="d-block fw-bold fs-5 mt-4">{{ $label }}</span>
                        <span class="d-block text-muted fs-7 mt-2">{{ $hint }}</span>
                        @if($key === 'balanced')<span class="badge badge-light-primary mt-3">Recommandé</span>@endif
                    </span>
                </label>
            </div>
        @endforeach
    </div>
    <div class="accordion mt-7" id="prospect_advanced_options">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#prospect_advanced_panel" aria-expanded="false">
                    Options avancées
                </button>
            </h2>
            <div id="prospect_advanced_panel" class="accordion-collapse collapse" data-bs-parent="#prospect_advanced_options">
                <div class="accordion-body">
                    <label for="domain_search_max_results" class="form-label">Maximum de contacts par entreprise</label>
                    <input id="domain_search_max_results" name="domain_search_max_results" type="number" min="10" max="500" step="10"
                           class="form-control w-150px" value="{{ old('domain_search_max_results', data_get($model->quality_settings, 'domain_search_max_results')) }}">
                    <div class="form-text">Laissez vide pour utiliser la valeur du mode choisi.</div>
                </div>
            </div>
        </div>
    </div>
</div>
