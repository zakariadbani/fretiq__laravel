<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le critère — ' . e($model->name) : 'Ajouter un critère de découverte' }}
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.prospect_criteria.index') }}" class="text-muted text-hover-primary">Critères de découverte</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            {{ isset($model) && $model->id ? 'Modifier' : 'Ajouter' }}
        </li>
    </ul>
@endsection

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    <div class="row g-5">
        {{-- General information card --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-gear text-primary fs-3 me-2"></i>
                        Informations générales
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du critère</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Transitaires France — Directeurs"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Limite / jour --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Limite / jour</label>
                                <div class="input-group input-group-solid">
                                    <input type="number"
                                           name="daily_limit"
                                           class="form-control form-control-solid"
                                           value="{{ old('daily_limit', $model->daily_limit ?? 20) }}"
                                           min="1"
                                           max="500" />
                                    <span class="input-group-text fw-semibold text-gray-500">contacts / jour</span>
                                </div>
                                <div class="form-text text-muted mt-1">
                                    Nombre maximum de contacts découverts par jour pour ce critère.
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Actif --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2 d-block">Statut</label>
                                <div class="d-flex align-items-center justify-content-between border border-dashed rounded p-4">
                                    <div>
                                        <div class="fw-semibold text-gray-800 fs-6">Activer ce critère</div>
                                        <div class="text-muted fs-7">Active le critère pour les prochains runs de découverte.</div>
                                    </div>
                                    <div class="form-check form-check-solid form-switch ms-4">
                                        <input class="form-check-input h-20px w-30px"
                                               type="checkbox"
                                               name="is_active"
                                               id="is_active"
                                               value="1"
                                               {{ old('is_active', ($model->is_active ?? true) ? '1' : '0') == '1' ? 'checked' : '' }} />
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Targeting card --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-crosshair text-info fs-3 me-2"></i>
                        Ciblage
                    </h3>
                    <div class="card-toolbar">
                        <span class="text-muted fs-7">Secteurs, pays, tailles d'entreprise et postes cibles</span>
                    </div>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row g-7">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Secteurs --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Secteurs</label>
                                <input type="text"
                                       name="sectors"
                                       class="form-control form-control-solid"
                                       placeholder="Transport, Logistique, Maritime..."
                                       value="{{ old('sectors', is_array($model->sectors ?? null) ? implode(', ', $model->sectors) : ($model->sectors ?? '')) }}" />
                                <div class="form-text text-muted mt-1">
                                    Séparez par des virgules.
                                </div>
                            </div>

                            {{-- Pays --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Pays</label>
                                <input type="text"
                                       name="countries"
                                       class="form-control form-control-solid"
                                       placeholder="France, Allemagne, Belgique..."
                                       value="{{ old('countries', is_array($model->countries ?? null) ? implode(', ', $model->countries) : ($model->countries ?? '')) }}" />
                                <div class="form-text text-muted mt-1">
                                    Séparez par des virgules.
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Tailles d'entreprise --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Tailles d'entreprise</label>
                                <select name="company_sizes[]"
                                        class="form-select form-select-solid"
                                        multiple>
                                    @foreach($companySizes as $key => $label)
                                        <option value="{{ $key }}"
                                            {{ in_array($key, old('company_sizes', $model->company_sizes ?? [])) ? 'selected' : '' }}>
                                            {{ is_array($label) ? ($label['label'] ?? $key) : $label }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text text-muted mt-1">
                                    Maintenez Ctrl pour sélectionner plusieurs tailles.
                                </div>
                            </div>

                            {{-- Postes cibles --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Postes cibles</label>
                                <input type="text"
                                       name="target_positions"
                                       class="form-control form-control-solid"
                                       placeholder="Directeur Achats, DSI, Responsable Logistique..."
                                       value="{{ old('target_positions', is_array($model->target_positions ?? null) ? implode(', ', $model->target_positions) : ($model->target_positions ?? '')) }}" />
                                <div class="form-text text-muted mt-1">
                                    Séparez par des virgules.
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Action buttons --}}
    <div class="row g-5 mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-end gap-3">
                @can('view prospect_criteria')
                    <a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </a>
                @endcan

                <button type="submit" class="btn btn-primary submit" id="submit_btn">
                    <span class="indicator-label">
                        <i class="bi bi-check-circle me-2"></i>
                        Enregistrer
                    </span>
                    <span class="indicator-progress">
                        <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                    </span>
                </button>
            </div>
        </div>
    </div>

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
@endpush

</x-default-layout>
