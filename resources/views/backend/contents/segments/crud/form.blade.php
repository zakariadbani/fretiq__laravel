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
                                       data-segment-mode="dynamic"
                                       {{ ! $isManual ? 'checked' : '' }} />
                                <span class="form-check-label">
                                    <span class="fw-semibold d-block">Audience dynamique</span>
                                    <span class="text-muted fs-7">La portée et les filtres sont réévalués à chaque envoi.</span>
                                </span>
                            </label>
                            @can('edit segments')
                                <label class="form-check form-check-custom form-check-solid">
                                    <input class="form-check-input" type="radio" name="is_manual" value="1"
                                           data-segment-mode="manual"
                                           {{ $isManual ? 'checked' : '' }} />
                                    <span class="form-check-label">
                                        <span class="fw-semibold d-block">Contacts sélectionnés uniquement</span>
                                        <span class="text-muted fs-7">Seuls les contacts ajoutés ci-dessous sont retenus, après les règles de conformité.</span>
                                    </span>
                                </label>
                            @endcan
                        </div>
                    </div>

                    @if(! isset($model) || ! $model->id)
                        @can('edit segments')
                            <div class="alert alert-info align-items-center mb-7 {{ $isManual ? 'd-flex' : 'd-none' }}"
                                 data-segment-manual-create-guidance>
                                <i class="bi bi-info-circle fs-3 me-3"></i>
                                <span>Enregistrez le segment pour sélectionner ses contacts.</span>
                            </div>
                        @endcan
                    @endif

                    {{-- Portée (scope): kept as an internal fallback for manual segments. --}}
                    <div class="fv-row d-none">
                        <input type="hidden" id="manual_scope_value" name="scope" value="{{ $savedScope }}" {{ ! $isManual ? 'disabled' : '' }} />
                    </div>
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
                $storedSectors   = (array) old('filter.sector',  $model->filter['sector']  ?? []);
                $storedCountries = (array) old('filter.country', $model->filter['country'] ?? []);
                $storedPositions = (array) old('filter.position', $model->filter['position'] ?? []);
                $storedCriteria  = (array) old('filter.criteria_id', $model->filter['criteria_id'] ?? []);

                /* scalar-safe: legacy rows may have stored a plain string */
                if (is_string($storedSectors))   { $storedSectors   = $storedSectors   ? [$storedSectors]   : []; }
                if (is_string($storedCountries)) { $storedCountries = $storedCountries ? [$storedCountries] : []; }
                if (is_string($storedPositions)) { $storedPositions = $storedPositions ? [$storedPositions] : []; }
                if (is_string($storedCriteria))  { $storedCriteria  = $storedCriteria  ? [$storedCriteria]  : []; }
                $storedCriteria = array_map('intval', $storedCriteria);

                $excludeContacted       = (bool) old('filter.exclude_contacted', $model->filter['exclude_contacted'] ?? false);
                $excludeGenericMailbox  = (bool) old('filter.exclude_generic_mailbox', $model->filter['exclude_generic_mailbox'] ?? false);
            @endphp

            <div class="card mb-5" data-segment-dynamic-fields data-segment-targeting-fields {{ $isManual ? 'hidden' : '' }}>
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
                            <x-crud.select-multi
                                name="filter[sector]"
                                :options="$sectors"
                                :selected="$storedSectors"
                                :tags="true"
                                placeholder="Tous les secteurs" />
                        </div>

                        {{-- Pays --}}
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Pays</label>
                            <x-crud.select-multi
                                name="filter[country]"
                                :options="$countries"
                                :selected="$storedCountries"
                                :labelSuffix="true"
                                placeholder="Tous les pays" />
                        </div>

                        {{-- État du contact --}}
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">État du contact</label>
                            <select name="filter[lifecycle_state]" class="form-select form-select-solid">
                                <option value="">Tous les états</option>
                                @foreach($lifecycleStates as $key => $data)
                                    <option value="{{ $key }}"
                                        {{ old('filter.lifecycle_state', $model->filter['lifecycle_state'] ?? '') === $key ? 'selected' : '' }}>
                                        {{ $data['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Postes ciblés --}}
                        <div class="col-md-6 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Postes ciblés</label>
                            <x-crud.select-multi
                                name="filter[position]"
                                :options="$positionGroups"
                                :selected="$storedPositions"
                                :tags="true"
                                placeholder="Tous les postes" />
                        </div>

                        {{-- Familles de prospection --}}
                        <div class="col-md-6 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Familles de prospection</label>
                            <x-crud.select-multi
                                name="filter[criteria_id]"
                                :options="$criteriaOptions"
                                :selected="$storedCriteria"
                                placeholder="Toutes les familles" />
                            <div class="form-text text-muted mt-1">
                                Combiné avec les secteurs en OU (l'un ou l'autre suffit) &middot; combiné avec le pays en ET.
                            </div>
                        </div>

                        {{-- Exclusions --}}
                        <div class="col-md-12 fv-row">
                            <label class="fw-semibold fs-6 mb-2 d-block">Exclusions</label>
                            <div class="d-flex flex-wrap gap-6">
                                <label class="form-check form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" name="filter[exclude_contacted]" value="1"
                                           {{ $excludeContacted ? 'checked' : '' }} />
                                    <span class="form-check-label">Exclure les contacts déjà sollicités</span>
                                </label>
                                <label class="form-check form-check-custom form-check-solid">
                                    <input class="form-check-input" type="checkbox" name="filter[exclude_generic_mailbox]" value="1"
                                           {{ $excludeGenericMailbox ? 'checked' : '' }} />
                                    <span class="form-check-label">Exclure les boîtes génériques (contact@, info@, ...)</span>
                                </label>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
            {{-- end CARD 2 --}}

            {{-- CARD 3 — Engagement ─────────────────────────────────── --}}
            @php
                $storedEngagement = (array) ($model->filter['engagement'] ?? []);
                $storedEngagementCampaignIds = array_map('intval', (array) old('filter.engagement.campaign_id', $storedEngagement['campaign_id'] ?? []));

                $engagementOpenedDefault = array_key_exists('opened', $storedEngagement) ? ($storedEngagement['opened'] ? '1' : '0') : '';
                $engagementOpened = old('filter.engagement.opened', $engagementOpenedDefault);

                $engagementClickedDefault = array_key_exists('clicked', $storedEngagement) ? ($storedEngagement['clicked'] ? '1' : '0') : '';
                $engagementClicked = old('filter.engagement.clicked', $engagementClickedDefault);

                $engagementSansDemande = (bool) old('filter.engagement.sans_demande', $storedEngagement['sans_demande'] ?? false);
            @endphp

            <div class="card mb-5" data-segment-dynamic-fields data-segment-targeting-fields {{ $isManual ? 'hidden' : '' }}>
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-envelope-open text-success fs-3 me-2"></i>
                        Engagement
                    </h3>
                    <div class="card-toolbar">
                        <span class="text-muted fs-7">Ciblez selon le comportement sur les campagnes déjà envoyées</span>
                    </div>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row g-5">

                        {{-- Limiter à ces campagnes --}}
                        <div class="col-md-12 fv-row">
                            <label class="fw-semibold fs-6 mb-2">Limiter à ces campagnes</label>
                            <x-crud.select-multi
                                name="filter[engagement][campaign_id]"
                                :options="$campaigns"
                                :selected="$storedEngagementCampaignIds"
                                placeholder="Toutes les campagnes" />
                            <div class="form-text text-muted mt-1">
                                Laissez vide pour évaluer l'engagement sur toutes les campagnes envoyées.
                            </div>
                        </div>

                        {{-- A ouvert --}}
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">A ouvert</label>
                            <select name="filter[engagement][opened]" class="form-select form-select-solid">
                                <option value="" {{ $engagementOpened === '' ? 'selected' : '' }}>Indifférent</option>
                                <option value="1" {{ $engagementOpened === '1' ? 'selected' : '' }}>Oui</option>
                                <option value="0" {{ $engagementOpened === '0' ? 'selected' : '' }}>Non</option>
                            </select>
                        </div>

                        {{-- A cliqué --}}
                        <div class="col-md-4 fv-row">
                            <label class="fw-semibold fs-6 mb-2">A cliqué</label>
                            <select name="filter[engagement][clicked]" class="form-select form-select-solid">
                                <option value="" {{ $engagementClicked === '' ? 'selected' : '' }}>Indifférent</option>
                                <option value="1" {{ $engagementClicked === '1' ? 'selected' : '' }}>Oui</option>
                                <option value="0" {{ $engagementClicked === '0' ? 'selected' : '' }}>Non</option>
                            </select>
                        </div>

                        {{-- Sans demande de cotation --}}
                        <div class="col-md-4 fv-row d-flex align-items-end">
                            <label class="form-check form-check-custom form-check-solid">
                                <input class="form-check-input" type="checkbox" name="filter[engagement][sans_demande]" value="1"
                                       {{ $engagementSansDemande ? 'checked' : '' }} />
                                <span class="form-check-label">Sans demande de cotation</span>
                            </label>
                        </div>

                    </div>

                </div>
            </div>
            {{-- end CARD 3 --}}

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
