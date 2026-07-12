<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le segment — ' . $model->name : 'Ajouter un segment' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Segments', 'route' => 'admin.segments.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.segments.index'])
@endsection

{{--
    Segment create/edit form — stacked single-column UX (redesigned 2026-06).

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 2-tab strip.
        - Général (active) is a native form pane INSIDE <form>.
        - Aperçu tab is a cross-route link to view page.
        - After </form>: Contacts pane (lazy-loaded) + picker modal.

    Create mode:
        - Simple header card + minimal nav (Général only).

    Layout (stacked, top→bottom within Général):
        (1) Informations card (name, scope)
        (2) Ciblage card (filter[sector][], filter[country][], filter[status])
        (3) Audience bandeau (lightweight strip — live preview via segment-form.js)
        (4) Contacts pane — OUTSIDE form, relocated by crud-tabs.js into #segment_tab_content

    B1 NOTE: crud-tabs.js::moveOutOfFormPanesIntoTabContent() appends #segment_contacts
    INTO #segment_tab_content which is INSIDE <form>. Isolation = NO name= attributes
    on any input/button in the contacts pane/rows/modal.

    select2 is available in plugins.bundle.js — used on Ciblage selects only.
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
    <div class="tab-content" id="segment_tab_content"
         data-out-of-form-panes='["segment_contacts"]'>

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="segment_general" role="tabpanel">
            @php
                $isManual = (bool) old('is_manual', $model->is_manual ?? false);
                $savedScope = old('scope', $model->scope ?? 'client');
            @endphp

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
                        Un segment peut être dynamique (portée et filtres) ou constitué uniquement des contacts sélectionnés.
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

                    {{-- Mode d'audience --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-3">Mode d'audience</label>
                        <div class="d-flex flex-column gap-3">
                            <label class="form-check form-check-custom form-check-solid">
                                <input class="form-check-input" type="radio" name="is_manual" value="0"
                                       {{ ! $isManual ? 'checked' : '' }} />
                                <span class="form-check-label">
                                    <span class="fw-semibold d-block">Audience dynamique</span>
                                    <span class="text-muted fs-7">La portée et les filtres sont réévalués à chaque envoi.</span>
                                </span>
                            </label>
                            <label class="form-check form-check-custom form-check-solid">
                                <input class="form-check-input" type="radio" name="is_manual" value="1"
                                       {{ $isManual ? 'checked' : '' }} />
                                <span class="form-check-label">
                                    <span class="fw-semibold d-block">Contacts sélectionnés uniquement</span>
                                    <span class="text-muted fs-7">Seuls les contacts ajoutés ci-dessous sont retenus, après les règles de conformité.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    {{-- Portée (scope): kept as an internal fallback for manual segments. --}}
                    <input type="hidden" id="manual_scope_value" name="scope" value="{{ $savedScope }}" {{ ! $isManual ? 'disabled' : '' }} />
                    <div class="fv-row mb-0" data-segment-dynamic-fields {{ $isManual ? 'hidden' : '' }}>
                        <label class="required fw-semibold fs-6 mb-2">Portée</label>
                        <select id="segment_scope" name="scope" class="form-select form-select-solid" required {{ $isManual ? 'disabled' : '' }}>
                            <option value="">Sélectionner une portée...</option>
                            @foreach($scopes as $key => $data)
                                <option value="{{ $key }}"
                                    {{ $savedScope === $key ? 'selected' : '' }}>
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

            <div class="card mb-5" data-segment-dynamic-fields {{ $isManual ? 'hidden' : '' }}>
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

                    <div class="row g-5">

                        {{-- Secteurs d'activité --}}
                        <div class="col-md-4 fv-row">
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
                        <div class="col-md-4 fv-row">
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
                        <div class="col-md-4 fv-row">
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
            </div>
            {{-- end CARD 2 --}}

            {{-- Audience bandeau (in-form, cause→effect) --}}
            @include('backend.contents.segments.partials._audience-bandeau')

        </div>
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.segments.index'])

</form>

{{-- ── Contacts pane + picker modal — AFTER </form>, edit mode only ── --}}
@if(isset($model) && $model->id)

    {{--
        #segment_contacts has data-crud-pane so crud-tabs.js (strategy 2 fallback)
        can find it. Also listed in data-out-of-form-panes above (strategy 1).
        NO tab-pane / role="tabpanel" — it stays always visible below the form.
        B1: contains zero name= attributes → FormData(form) never serializes it.
    --}}
    <div id="segment_contacts" data-crud-pane class="mt-5">
        @include('backend.contents.segments.partials._contacts-pane')
    </div>

    @include('backend.contents.segments.partials._contacts-picker-modal')

@endif

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/segment-form.js') }}"></script>
    @if(isset($model) && $model->id)
        <script src="{{ asset('assets/js/custom/backend/segment-contacts.js') }}"></script>
    @endif
@endpush

</x-default-layout>
