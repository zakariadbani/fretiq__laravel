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

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.prospect_criteria.index'])
@endsection

{{--
    ProspectCriteria create/edit form — hero + tabbar + sticky contract.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with tab strip.
        - Général (active) is the only native form pane INSIDE <form>.
        - Aperçu tab deep-links to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).

    No select2 used — all inputs are text/number or plain multi-select.
    Both form-actions calls preserved: toolbar variant above + sticky variant at bottom.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.prospect_criteria.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-sliders text-primary fs-3 me-2"></i>
                    Ajouter un critère de découverte
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#criteria_general">
                    <i class="bi bi-sliders me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    <div class="tab-content" id="criteria_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="criteria_general" role="tabpanel">

            {{-- General information card --}}
            <div class="card mb-5">
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

            {{-- Targeting card --}}
            <div class="card mb-5">
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

                            {{-- Tailles d'entreprise — plain multi-select (no select2: hidden at init would break width) --}}
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
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.prospect_criteria.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
