{{--
    Écran 2 — "Vérifier et lancer" (A4 consolidation).

    Merges the former separate quality-preset screen and cost/confirm screen
    into one: preview → preset → live cost estimate → confirm → launch.
    Changing the preset (or the advanced max-results field) re-estimates in
    place via prospect-batch-wizard.js's runEstimate() — it never navigates
    away from this panel.
--}}
<div data-kt-stepper-element="content" data-prospect-step="2">
    <div class="mb-7">
        <h2 class="fs-4 fw-bold mb-2">Vérifier et lancer</h2>
        <p class="text-muted">Les recherches nécessaires sont configurées automatiquement selon le mode choisi.</p>
    </div>

    @if(($previewItems ?? collect())->isNotEmpty())
        <div class="mb-7">
            <h3 class="fs-6 fw-bold mb-3">Aperçu des entreprises importées</h3>
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-2">
                    <thead>
                        <tr class="text-muted fw-bold fs-7 text-uppercase">
                            <th>#</th>
                            <th>Nom</th>
                            <th>Pays</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($previewItems as $item)
                            <tr>
                                <td>{{ $item->row_number }}</td>
                                <td>{{ $item->company_name }}</td>
                                <td>{{ $item->country }}</td>
                                <td class="text-muted fs-7">{{ \Illuminate\Support\Str::limit(data_get($item->source_metadata, 'description'), 120) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php $previewShown = ($previewItems ?? collect())->count(); @endphp
            @if(($previewTotal ?? 0) > $previewShown)
                <div class="text-muted fs-7 mt-2">… et {{ $previewTotal - $previewShown }} autres.</div>
            @endif
        </div>
    @endif

    <div class="mb-7">
        <h3 class="fs-6 fw-bold mb-2">Choisir la qualité</h3>
        <p class="text-muted fs-7">« Équilibré » convient à la plupart des listes. Vous pourrez revoir les correspondances ambiguës.</p>
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

    <div id="prospect-estimate-loading" class="text-center py-10 d-none" role="status">
        <span class="spinner-border text-primary"></span>
        <div class="text-muted mt-3">Préparation du résumé…</div>
    </div>
    <div id="prospect-estimate-error" class="alert alert-danger d-none" role="alert"></div>
    <div id="prospect-estimate-panel">
        <div class="row g-5 mb-7">
            <div class="col-6"><div class="card bg-light h-100"><div class="card-body"><div class="text-muted fs-7">Entreprises</div><div class="fs-2 fw-bold" data-estimate="items">—</div></div></div></div>
            <div class="col-6"><div class="card bg-light h-100"><div class="card-body"><div class="text-muted fs-7">Mode choisi</div><div class="fs-5 fw-bold" data-estimate="preset">Équilibré</div></div></div></div>
        </div>
        <label class="form-check form-check-custom form-check-solid p-4 border rounded">
            <input class="form-check-input" type="checkbox" name="confirm_cost" value="1" id="confirm_cost">
            <span class="form-check-label fw-semibold">Je confirme le lancement du traitement.</span>
        </label>
    </div>

    @can('delete prospect_batches')
        <div class="text-center mt-7">
            <span class="text-muted fs-7">Ce n’est pas ce que vous attendiez ?</span>
            <button type="button" class="btn btn-link btn-color-danger p-0 fs-7 ms-1" data-wizard-discard
                    data-delete-url="{{ $model->exists ? route('admin.prospect_batches.delete', $model) : '' }}"
                    data-index-url="{{ route('admin.prospect_batches.index') }}">
                Supprimer ce brouillon
            </button>
        </div>
    @endcan
</div>
