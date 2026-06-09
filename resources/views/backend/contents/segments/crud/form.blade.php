<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le segment — ' . e($model->name) : 'Ajouter un segment' }}
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
            <a href="{{ route('admin.segments.index') }}" class="text-muted text-hover-primary">Segments</a>
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
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.segments.index'])
@endsection

{{--
    Segment create/edit form — 2-tab UX.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 2-tab strip.
        - Général (active) is a native form pane INSIDE <form>.
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).

    Selects in the Général pane use plain <select> — no select2 needed (simple dropdowns).
    Both form-actions calls are present: toolbar variant above + sticky at bottom.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + 2-tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.segments.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-funnel text-primary fs-3 me-2"></i>
                    Ajouter un segment
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#segment_general">
                    <i class="bi bi-funnel me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    <div class="tab-content" id="segment_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="segment_general" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Informations du segment</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du segment</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Prospects transport FR"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Portée (scope) — plain <select>, no select2 needed --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Portée</label>
                                <select name="scope" class="form-select form-select-solid" required>
                                    <option value="">Sélectionner une portée...</option>
                                    @foreach($scopes as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('scope', $model->scope ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Filtre (JSON) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Filtre (JSON)</label>
                                <textarea name="filter"
                                          class="form-control form-control-solid font-monospace"
                                          rows="6"
                                          placeholder='{"sector":"Transport"}'>{{ old('filter', isset($model) && $model->filter ? json_encode($model->filter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                                <div class="form-text text-muted mt-1">
                                    JSON optionnel — laissez vide pour aucun filtre supplémentaire.
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
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.segments.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
