<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le pack — ' . e($model->name) : 'Ajouter un Pack' }}
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
            <a href="{{ route('admin.packages.index') }}" class="text-muted text-hover-primary">Packs</a>
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
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.packages.index'])
@endsection

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.packages.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (General only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-box-seam text-primary fs-3 me-2"></i>
                    Ajouter un Pack
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#package_general">
                    <i class="bi bi-sliders me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- Tab content — no out-of-form panes for this module --}}
    <div class="tab-content" id="package_tab_content"
         data-out-of-form-panes='[]'>

        {{-- ── Général pane (default active) ──────────────────────────────── --}}
        {{-- No select2 needed — no relational selects in this form. --}}
        <div class="tab-pane fade show active" id="package_general" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Informations générales</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">
                        <div class="col-lg-6">

                            {{-- Nom (required) --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du pack</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Starter, Pro, Business, Illimité"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Crédits / jour (nullable) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Crédits / jour</label>
                                <input type="number"
                                       name="daily_credits"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : 50"
                                       min="0"
                                       value="{{ old('daily_credits', isset($model) ? $model->daily_credits : '') }}" />
                                <div class="form-text text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Vide = illimité (aucune limite de découverte journalière).
                                </div>
                            </div>

                        </div>
                        <div class="col-lg-6">

                            {{-- Prix / mois (nullable) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Prix / mois (€)</label>
                                <input type="number"
                                       name="price_monthly"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : 49.00"
                                       step="0.01"
                                       min="0"
                                       value="{{ old('price_monthly', isset($model) ? $model->price_monthly : '') }}" />
                                <div class="form-text text-muted">Affichage uniquement — aucune logique de facturation.</div>
                            </div>

                            {{-- Ordre d'affichage --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Ordre d'affichage</label>
                                <input type="number"
                                       name="sort_order"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : 0"
                                       min="0"
                                       value="{{ old('sort_order', $model->sort_order ?? 0) }}" />
                            </div>

                            {{-- Actif (switch) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Actif</label>
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="is_active"
                                           value="1"
                                           id="is_active_check"
                                           {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                                    <label class="form-check-label fw-semibold text-muted" for="is_active_check">
                                        Pack disponible à l'assignation
                                    </label>
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

    {{-- Sticky save bar --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.packages.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
