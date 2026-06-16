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

    All multi-value fields use <x-crud.select-multi> component (select2, data-tags where allowed).
    is_active uses hidden input + checkbox pattern (D9) so deactivation always posts is_active=0.
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
                                    <span class="input-group-text fw-semibold text-gray-500">entreprises / jour</span>
                                </div>
                                <div class="form-text text-muted mt-1">
                                    Nombre maximum d'entreprises découvertes par jour pour ce critère. Chaque entreprise déclenche un enrichissement (consommation de crédits) — cette limite plafonne le coût quotidien et la cadence d'envoi.
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Actif — D9: hidden input ensures is_active=0 always posts when unchecked --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2 d-block">Statut</label>
                                <div class="d-flex align-items-center justify-content-between border border-dashed rounded p-4">
                                    <div>
                                        <div class="fw-semibold text-gray-800 fs-6">Activer ce critère</div>
                                        <div class="text-muted fs-7">Un critère inactif ne peut pas lancer de découverte.</div>
                                    </div>
                                    <div class="form-check form-check-solid form-switch ms-4">
                                        <input type="hidden" name="is_active" value="0" />
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

                            {{-- Secteurs — select2 with free-tag entry --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Secteurs</label>
                                <x-crud.select-multi
                                    name="sectors"
                                    :options="$sectorsList"
                                    :selected="old('sectors', $model->sectors ?? [])"
                                    :tags="true"
                                    placeholder="Recherchez et sélectionnez — entrées libres autorisées."
                                    hint="Recherchez et sélectionnez — entrées libres autorisées." />
                            </div>

                            {{-- Pays — strict select2 (no tags) + FR+MA preselect on create --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Pays</label>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-light-primary" id="btn_ue27">
                                        <i class="bi bi-globe-europe-africa me-1"></i>UE-27
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light-danger" id="btn_clear_countries">
                                        <i class="bi bi-x-circle me-1"></i>Effacer
                                    </button>
                                </div>
                                <x-crud.select-multi
                                    name="countries"
                                    :options="$countries"
                                    :selected="old('countries', $model->countries ?? ($model->id ?? null ? [] : ['FR', 'MA']))"
                                    :tags="false"
                                    placeholder="Recherchez et sélectionnez un ou plusieurs pays."
                                    hint="Vide = découverte sur France + Maroc par défaut."
                                    :labelSuffix="true" />
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Tailles d'entreprise — select2 multi --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Tailles d'entreprise<span class="badge badge-light-warning fs-8 ms-2 text-nowrap">Phase 2/3</span></label>
                                <x-crud.select-multi
                                    name="company_sizes"
                                    :options="$companySizes"
                                    :selected="old('company_sizes', $model->company_sizes ?? [])"
                                    placeholder="Sélectionner des tailles..."
                                    hint="Optionnel. Laisser vide si inconnu — sans effet sur la découverte actuelle." />
                            </div>

                            {{-- Postes cibles — grouped optgroups with free-tag entry --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Postes cibles<span class="badge badge-light-warning fs-8 ms-2 text-nowrap">Phase 2/3</span></label>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-light-info" id="btn_postes_reco">
                                        <i class="bi bi-stars me-1"></i>Postes recommandés
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light-danger" id="btn_clear_positions">
                                        <i class="bi bi-x-circle me-1"></i>Effacer
                                    </button>
                                    <span id="postes_cap_note" class="text-warning fs-8 align-self-center d-none">Limité à 50 postes.</span>
                                </div>
                                <x-crud.select-multi
                                    name="target_positions"
                                    :options="$positionGroups"
                                    :selected="old('target_positions', $model->target_positions ?? [])"
                                    :tags="true"
                                    placeholder="Recherchez et sélectionnez — entrées libres autorisées."
                                    hint="Optionnel — préremplit l'intention de ciblage (sans effet sur la découverte actuelle). Cliquez « Postes recommandés » ou laissez vide." />
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </div>
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- ── Discovery query preview (edit mode only — needs persisted record) ── --}}
    @if(isset($model) && $model->id)
    <div class="card mb-5" id="card-query-preview">
        <div class="card-header border-0 pt-5">
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-search text-warning fs-3 me-2"></i>
                Aperçu des requêtes de découverte
            </h3>
            <div class="card-toolbar">
                <span class="text-muted fs-7 me-3">Basé sur les critères enregistrés</span>
                <button type="button" class="btn btn-sm btn-light-warning" id="btn-refresh-queries">
                    <i class="bi bi-arrow-clockwise me-1"></i>
                    Actualiser
                </button>
            </div>
        </div>
        <div class="card-body border-top p-9">
            <div id="query-preview-content">
                <div class="text-muted fs-7">
                    <i class="bi bi-hourglass-split me-1"></i>
                    Chargement des requêtes…
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.prospect_criteria.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script>
        @if(isset($model) && $model->id)
        (function () {
            var previewUrl = '{{ route('admin.prospect_criteria.preview_queries', $model->id) }}';
            var $container = $('#query-preview-content');

            function loadQueries() {
                $container.html(
                    '<div class="text-muted fs-7"><i class="bi bi-hourglass-split me-1"></i>Chargement des requêtes…</div>'
                );
                $.getJSON(previewUrl, function (data) {
                    var queries = data.queries || [];
                    if (queries.length === 0) {
                        $container.html(
                            '<div class="text-muted fs-7"><i class="bi bi-exclamation-circle me-1"></i>Aucune requête générée — renseignez au moins un secteur ou un pays.</div>'
                        );
                        return;
                    }
                    var html = '<ul class="list-unstyled mb-0">';
                    $.each(queries, function (i, q) {
                        html += '<li class="d-flex align-items-start mb-2">'
                              + '<span class="badge badge-light-warning me-2 mt-1 fs-8">' + (i + 1) + '</span>'
                              + '<span class="text-gray-700 fs-7">' + $('<div>').text(q).html() + '</span>'
                              + '</li>';
                    });
                    html += '</ul>';
                    $container.html(html);
                }).fail(function () {
                    $container.html(
                        '<div class="text-danger fs-7"><i class="bi bi-x-circle me-1"></i>Erreur lors du chargement des requêtes.</div>'
                    );
                });
            }

            // Load on page ready
            $(loadQueries);

            // Refresh button
            $('#btn-refresh-queries').on('click', loadQueries);
        })();
        @endif

        $(function () {
            // UE-27 / Effacer quick-pick buttons (D6, union semantics).
            // EU27 list sourced from PHP config — never hard-coded in JS.
            const EU27 = @json($euCodes);
            const $c = $('select[name="countries[]"]');

            // UE-27: union — keeps already-selected non-EU codes (e.g. MA).
            $('#btn_ue27').on('click', function () {
                const current = $c.val() || [];
                $c.val([...new Set([...current, ...EU27])]).trigger('change');
            });

            // Effacer: clear the countries select.
            $('#btn_clear_countries').on('click', function () {
                $c.val([]).trigger('change');
            });

            // Postes recommandés / Effacer quick-pick buttons.
            const RECO = @json($recommendedPositions);
            const $p = $('#form_crud select[name="target_positions[]"]');
            const POS_MAX = 50; // mirrors ProspectCriteria rules() max:50
            $('#btn_postes_reco').on('click', function () {
                const union = [...new Set([...($p.val() || []), ...RECO])];
                const capped = union.slice(0, POS_MAX);
                $p.val(capped).trigger('change');
                $('#postes_cap_note').toggleClass('d-none', union.length <= POS_MAX);
            });
            $('#btn_clear_positions').on('click', function () {
                $p.val([]).trigger('change');
                $('#postes_cap_note').addClass('d-none');
            });

            // Guarded select2 fallback init — Metronic data-control="select2" auto-init may
            // already cover these; only initialise controls that have not been touched yet.
            $('#form_crud select[multiple]').not('.select2-hidden-accessible').select2();
        });
    </script>
@endpush

</x-default-layout>
