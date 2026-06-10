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

    Général pane layout: col-lg-8 (Card 1 Informations + Card 2 Ciblage stacked)
    + col-lg-4 (Card 3 Aperçu de l'audience — sticky preview panel).

    select2 is available in plugins.bundle.js — used on all multi-selects.
    Both form-actions calls preserved: toolbar variant above + sticky at bottom.
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

            <div class="row g-5">

                {{-- ── Left column: stacked cards ───────────────────────── --}}
                <div class="col-lg-8">

                    {{-- CARD 1 — Informations du segment ─────────────────── --}}
                    <div class="card mb-5">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title fw-bolder m-0">
                                <i class="bi bi-info-circle text-primary fs-3 me-2"></i>
                                Informations du segment
                            </h3>
                        </div>
                        <div class="card-body border-top p-9">

                            <p class="text-muted fs-7 mb-6">
                                Un segment est une audience dynamique réutilisée par vos campagnes — les filtres sont réévalués à chaque envoi.
                            </p>

                            {{-- Nom du segment --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du segment</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Prospects transport FR"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Portée (scope) --}}
                            <div class="fv-row mb-0">
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
                                <div class="form-text text-muted mt-2">
                                    Prospects = entreprises à conquérir &middot; Clients = relation existante &middot; Mixte = les deux.
                                </div>
                            </div>

                        </div>
                    </div>
                    {{-- end CARD 1 --}}

                    {{-- CARD 2 — Ciblage ──────────────────────────────────── --}}
                    @php
                        /* Belt-and-braces: union of stored values + available list so stale values
                           still render as selected options even if not in the controller's $sectors/$countries. */
                        $storedSectors   = (array) old('filter.sector',  $model->filter['sector']  ?? []);
                        $storedCountries = (array) old('filter.country', $model->filter['country'] ?? []);

                        /* scalar-safe: legacy rows may have stored a plain string */
                        if (is_string($storedSectors))   { $storedSectors   = $storedSectors   ? [$storedSectors]   : []; }
                        if (is_string($storedCountries)) { $storedCountries = $storedCountries ? [$storedCountries] : []; }

                        /* Build option lists as union so nothing stored is ever silently dropped */
                        $sectorOptions   = array_unique(array_merge($sectors, array_diff($storedSectors, $sectors)));
                        /* $countries is assoc iso => label; merge in any stored iso codes missing from it */
                        $countryOptions  = $countries;
                        foreach ($storedCountries as $iso) {
                            if (!isset($countryOptions[$iso])) {
                                $countryOptions[$iso] = $iso; // fallback label = code
                            }
                        }
                    @endphp

                    <div class="card mb-5">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title fw-bolder m-0">
                                <i class="bi bi-crosshair text-info fs-3 me-2"></i>
                                Ciblage
                            </h3>
                            <div class="card-toolbar">
                                <span class="text-muted fs-7">Affinez l'audience — laissez vide pour ne pas filtrer</span>
                            </div>
                        </div>
                        <div class="card-body border-top p-9">

                            {{-- Secteurs d'activité --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Secteurs d'activité</label>
                                <select name="filter[sector][]"
                                        class="form-select form-select-solid"
                                        multiple
                                        data-control="select2"
                                        data-placeholder="Tous les secteurs"
                                        data-allow-clear="true">
                                    @foreach($sectorOptions as $sector)
                                        <option value="{{ $sector }}"
                                            {{ in_array($sector, $storedSectors) ? 'selected' : '' }}>
                                            {{ $sector }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Pays --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Pays</label>
                                <select name="filter[country][]"
                                        class="form-select form-select-solid"
                                        multiple
                                        data-control="select2"
                                        data-placeholder="Tous les pays"
                                        data-allow-clear="true">
                                    @foreach($countryOptions as $iso => $label)
                                        <option value="{{ $iso }}"
                                            {{ in_array($iso, $storedCountries) ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Statut du contact --}}
                            <div class="fv-row mb-0">
                                <label class="fw-semibold fs-6 mb-2">Statut du contact</label>
                                <select name="filter[status]" class="form-select form-select-solid">
                                    <option value="">Tous les statuts</option>
                                    @foreach($contactStatuses as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('filter.status', $model->filter['status'] ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                        </div>
                    </div>
                    {{-- end CARD 2 --}}

                </div>
                {{-- end col-lg-8 --}}

                {{-- ── Right column: preview card (sticky) ───────────────── --}}
                <div class="col-lg-4">
                    <div style="position: sticky; top: 80px;">

                        {{-- CARD 3 — Aperçu de l'audience ────────────────── --}}
                        <div class="card" id="segment_preview_card"
                             data-preview-url="{{ route('admin.segments.preview') }}">
                            <div class="card-header border-0 pt-5">
                                <h3 class="card-title fw-bolder m-0">
                                    <i class="bi bi-people text-primary fs-3 me-2"></i>
                                    Aperçu de l'audience
                                </h3>
                            </div>
                            <div class="card-body border-top p-9">

                                {{-- Big count --}}
                                <div class="text-center mb-4">
                                    <div id="preview_final" class="fs-2hx fw-bolder text-gray-900" aria-live="polite">—</div>
                                    <div class="text-muted fs-7">destinataires</div>
                                </div>

                                {{-- Summary sentence --}}
                                <div id="preview_summary" class="text-muted fs-7 text-center mb-5"></div>

                                {{-- Loading spinner --}}
                                <div id="preview_loading" class="text-center mb-3 d-none">
                                    <span class="spinner-border spinner-border-sm text-primary" role="status"></span>
                                    <span class="text-muted fs-7 ms-2">Calcul en cours…</span>
                                </div>

                                {{-- Error badge --}}
                                <div id="preview_error" class="d-none mb-3">
                                    <span class="badge badge-light-danger fs-7">
                                        <i class="bi bi-exclamation-circle me-1"></i>
                                        Aperçu indisponible
                                    </span>
                                </div>

                                {{-- Funnel --}}
                                <div id="preview_funnel" class="mb-5">
                                    {{-- Rows rendered by segment-form.js --}}
                                </div>

                                {{-- Cold-gate warning banner --}}
                                <div id="preview_warning" class="alert alert-warning d-none py-3 fs-7" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    L'envoi à froid est désactivé — ce segment ne recevra aucun email.
                                </div>

                                {{-- Sample table --}}
                                <div id="preview_sample" class="d-none">
                                    <div class="separator separator-dashed my-4"></div>
                                    <p class="text-muted fs-8 mb-3">Premiers contacts correspondants</p>
                                    <table class="table table-row-dashed table-row-gray-300 align-middle fs-7 gy-2 mb-0">
                                        <thead>
                                            <tr class="fw-semibold text-muted">
                                                <th>Nom</th>
                                                <th>Entreprise</th>
                                                <th>Email</th>
                                            </tr>
                                        </thead>
                                        <tbody id="preview_sample_body">
                                            {{-- Rows rendered by segment-form.js --}}
                                        </tbody>
                                    </table>
                                </div>

                            </div>
                        </div>
                        {{-- end CARD 3 --}}

                    </div>
                </div>
                {{-- end col-lg-4 --}}

            </div>
            {{-- end row --}}

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
    <script src="{{ asset('assets/js/custom/backend/segment-form.js') }}"></script>
@endpush

</x-default-layout>
