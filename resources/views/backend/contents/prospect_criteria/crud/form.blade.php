<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le critère — ' . e($model->name) : 'Ajouter un critère de découverte' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte', 'route' => 'admin.prospect_criteria.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
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

                            {{-- Recherches SerpAPI / jour --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Recherches SerpAPI / jour</label>
                                <div class="input-group input-group-solid">
                                    <input type="number"
                                           name="daily_limit"
                                           class="form-control form-control-solid"
                                           value="{{ old('daily_limit', $model->daily_limit ?? 20) }}"
                                           min="1"
                                           max="500" />
                                    <span class="input-group-text fw-semibold text-gray-500">recherches / jour</span>
                                </div>
                                @php
                                    $overbooked = ($quotaPackage?->daily_credits !== null) && (($activeDailyLimitSum ?? 0) > $quotaPackage->daily_credits);
                                @endphp
                                <div class="form-text mt-1 {{ $overbooked ? 'text-warning' : 'text-muted' }}">
                                    Sur-réservation = priorité demandée, pas réservation garantie : le premier lancement consomme le quota disponible, les suivants attendent.
                                </div>
                                <div class="form-text mt-1 {{ $overbooked ? 'text-warning' : 'text-muted' }}">
                                    Quota package : {{ $quotaPackage?->daily_credits ?? '∞' }} recherches SerpAPI/j &middot; {{ $quotaPackage?->daily_contact_credits ?? '∞' }} contacts/j. Priorité demandée par les critères actifs : {{ $activeDailyLimitSum ?? 0 }} recherches/j. 1 recherche retourne jusqu'à {{ \App\Services\Discovery\CompanyDiscoveryService::PAGE_SIZE }} résultats Google avant filtrage IA.
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

            {{-- Automatisation card --}}
            <div class="card mb-5">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-clock-history text-success fs-3 me-2"></i>
                        Automatisation
                    </h3>
                    <div class="card-toolbar">
                        <span class="text-muted fs-7">Découverte + enrichissement automatiques pour ce critère</span>
                    </div>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- auto_run — hidden input + checkbox switch, same pattern as is_active --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2 d-block">Découverte automatique</label>
                                <div class="d-flex align-items-center justify-content-between border border-dashed rounded p-4">
                                    <div>
                                        <div class="fw-semibold text-gray-800 fs-6">Découverte automatique quotidienne</div>
                                        <div class="text-muted fs-7">Lance la découverte une fois par jour, à l'heure choisie.</div>
                                    </div>
                                    <div class="form-check form-check-solid form-switch ms-4">
                                        <input type="hidden" name="auto_run" value="0" />
                                        <input class="form-check-input h-20px w-30px"
                                               type="checkbox"
                                               name="auto_run"
                                               id="auto_run"
                                               value="1"
                                               {{ old('auto_run', ($model->auto_run ?? false) ? '1' : '0') == '1' ? 'checked' : '' }} />
                                    </div>
                                </div>
                            </div>

                            {{-- run_at_hour — placeholder '' option + 0..23 as HH:00 --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Heure de lancement</label>
                                @php
                                    $currentRunAtHour = old('run_at_hour', $model->run_at_hour ?? null);
                                @endphp
                                <select name="run_at_hour" id="run_at_hour" class="form-select form-select-solid">
                                    <option value="">&mdash;</option>
                                    @for($h = 0; $h <= 23; $h++)
                                        <option value="{{ $h }}" {{ (string) $currentRunAtHour === (string) $h ? 'selected' : '' }}>
                                            {{ sprintf('%02d:00', $h) }}
                                        </option>
                                    @endfor
                                </select>
                                <div class="form-text text-muted mt-1">
                                    Heure ({{ $quotaTz ?? 'Europe/Paris' }}) — lancement une fois par jour, à partir de l'heure choisie.
                                </div>
                            </div>

                            {{-- contact_limit --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Contacts max / exécution</label>
                                <div class="input-group input-group-solid">
                                    <input type="number"
                                           name="contact_limit"
                                           class="form-control form-control-solid"
                                           value="{{ old('contact_limit', $model->contact_limit ?? '') }}"
                                           placeholder="Illimité (borné par le quota package)"
                                           min="1"
                                           max="500" />
                                    <span class="input-group-text fw-semibold text-gray-500">contacts / exécution</span>
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- min_score_enrich --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Score min. d'enrichissement</label>
                                <input type="number"
                                       name="min_score_enrich"
                                       class="form-control form-control-solid"
                                       value="{{ old('min_score_enrich', $model->min_score_enrich ?? '') }}"
                                       placeholder="Hérité : {{ $globalMinScore }}"
                                       min="0"
                                       max="100" />
                                <div class="form-text text-muted mt-1">
                                    Seules les entreprises dont le score dépasse ce seuil sont enrichies automatiquement. Vide = valeur globale.
                                </div>
                            </div>

                            {{-- auto_enrich — plain tri-state select ('' = Hérité), NEVER the hidden-checkbox pattern --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Enrichissement automatique</label>
                                @php
                                    $currentAutoEnrich = $model->auto_enrich ?? null;
                                @endphp
                                <select name="auto_enrich" class="form-select form-select-solid">
                                    <option value="" {{ old('auto_enrich', $currentAutoEnrich === null ? '' : ($currentAutoEnrich ? '1' : '0')) === '' ? 'selected' : '' }}>
                                        Hérité (@if($globalAutoEnrich) activé @else désactivé @endif)
                                    </option>
                                    <option value="1" {{ old('auto_enrich', $currentAutoEnrich === null ? '' : ($currentAutoEnrich ? '1' : '0')) === '1' ? 'selected' : '' }}>
                                        Activé
                                    </option>
                                    <option value="0" {{ old('auto_enrich', $currentAutoEnrich === null ? '' : ($currentAutoEnrich ? '1' : '0')) === '0' ? 'selected' : '' }}>
                                        Désactivé
                                    </option>
                                </select>
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
                        <span class="text-muted fs-7">Décrivez votre cible, ou affinez avec les filtres structurés</span>
                    </div>
                </div>
                <div class="card-body border-top p-9">

                    {{-- Décrivez votre cible — natural-language Cible/Exclure, feeds the AI query builder + scorer --}}
                    <div class="row g-7 mb-7">
                        <div class="col-lg-6">
                            <label class="fw-semibold fs-6 mb-2 d-flex align-items-center">
                                <i class="bi bi-check-circle text-success me-2"></i>
                                Cible — qui trouver
                            </label>
                            <textarea name="ai_target"
                                      class="form-control form-control-solid"
                                      rows="4"
                                      maxlength="2000"
                                      placeholder="Ex : entreprises du textile — fabricants, importateurs, grossistes, marques de vêtements.">{{ old('ai_target', $model->ai_target ?? '') }}</textarea>
                        </div>
                        <div class="col-lg-6">
                            <label class="fw-semibold fs-6 mb-2 d-flex align-items-center">
                                <i class="bi bi-x-circle text-danger me-2"></i>
                                Exclure — qui écarter
                            </label>
                            <textarea name="ai_exclude"
                                      class="form-control form-control-solid"
                                      rows="4"
                                      maxlength="2000"
                                      placeholder="Ex : transporteurs, transitaires, commissionnaires, logisticiens.">{{ old('ai_exclude', $model->ai_exclude ?? '') }}</textarea>
                        </div>
                    </div>

                    @if(empty(config('services.gemini.api_key')))
                        <div class="alert alert-warning d-flex align-items-center mb-7">
                            <i class="bi bi-exclamation-triangle fs-3 me-3"></i>
                            <div>
                                L'exclusion par IA nécessite l'assistant IA (non activé). Contactez l'administrateur.
                            </div>
                        </div>
                    @endif

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
                <span class="text-muted fs-7 me-3">Activez/désactivez chaque requête</span>
                <button type="button" class="btn btn-sm btn-light-primary" id="btn-generate-ai">
                    <i class="bi bi-stars me-1"></i>
                    Générer avec l'IA
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
        // Re-baseline the crud-tabs.js dirty-guard snapshot after on-load PROGRAMMATIC
        // mutations (async ai_queries injection, run_at_hour disable) so an untouched form
        // does not read dirty. Only for automatic on-load changes — never after a user action.
        // ponytail: replicates crud-tabs.js serialiseForm(); safe here (no TinyMCE on this form).
        // If a 2nd module needs this, promote to a shared `crud:rebaseline` event in crud-tabs.js.
        function rebaselineFormSnapshot() {
            var form = document.getElementById('form_crud');
            if (form) form.dataset.cleanSnapshot = new URLSearchParams(new FormData(form)).toString();
        }

        @if(isset($model) && $model->id)
        (function () {
            var previewUrl  = '{{ route('admin.prospect_criteria.preview_queries', $model->id) }}';
            var generateUrl = '{{ route('admin.prospect_criteria.generate_queries', $model->id) }}';
            var csrfToken   = '{{ csrf_token() }}';
            var discoveryCursors = @json($model->discovery_cursors ?? []);
            var $container  = $('#query-preview-content');
            var $genBtn     = $('#btn-generate-ai');

            var executionBudget = null;

            // Renders queries as real form inputs (name="ai_queries[i][q|enabled]") so
            // they submit with Enregistrer — this card is a stateless preview, Enregistrer
            // is the only action that persists ai_queries (beforeSave() on the controller).
            function renderQueries(queries, execution, notice) {
                if (execution && execution.search_budget !== undefined) {
                    executionBudget = parseInt(execution.search_budget, 10);
                    if (isNaN(executionBudget)) {
                        executionBudget = null;
                    }
                }

                if (!queries || queries.length === 0) {
                    // notice is a server-controlled French string (AI-unavailable vs no-criteria);
                    // fall back to the default text when no notice is passed (e.g. loadQueries on ready).
                    $container.html(notice
                        ? '<div class="alert alert-light-warning border border-warning border-dashed p-4 mb-0 fs-7"><i class="bi bi-exclamation-triangle me-1"></i>' + notice + '</div>'
                        : '<div class="text-muted fs-7"><i class="bi bi-exclamation-circle me-1"></i>Aucune requête générée — renseignez une description de cible ou au moins un secteur/pays.</div>'
                    );
                    return;
                }

                var plan = buildExecutionPlan(queries);
                var html = executionSummary(queries, plan);

                $.each(queries, function (i, item) {
                    var q          = item.q != null ? item.q : item;
                    var qTextEsc   = $('<div>').text(q).html();
                    // jQuery's text().html() is safe for text nodes, but it does not
                    // necessarily encode quotes. AI-generated Google queries often contain
                    // quoted phrases; if those raw quotes are injected into value="...", the
                    // hidden input is parsed with a truncated/empty value and Enregistrer
                    // saves incorrect ai_queries even though the preview looked correct.
                    var qAttrEsc   = qTextEsc.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                    var enabled    = item.enabled !== false;
                    var status     = plan.statuses[q] || {state: enabled ? 'queued' : 'disabled'};
                    html += '<div class="border border-gray-300 rounded p-4 mb-3' + (enabled ? '' : ' opacity-50') + '" data-qi="' + i + '">'
                          + '<div class="d-flex align-items-center flex-wrap gap-2">'
                          + '<input type="hidden" name="ai_queries[' + i + '][q]" value="' + qAttrEsc + '" />'
                          + '<label class="form-check form-switch form-check-custom form-check-solid me-2 mb-0">'
                          + '<input type="hidden" name="ai_queries[' + i + '][enabled]" value="0" />'
                          + '<input class="form-check-input h-20px w-35px q-toggle" type="checkbox" name="ai_queries[' + i + '][enabled]" value="1" ' + (enabled ? 'checked' : '') + ' />'
                          + '</label>'
                          + '<span class="badge badge-light-warning me-1">' + (i + 1) + '</span>'
                          + '<span class="text-gray-700 fs-7 flex-grow-1">' + qTextEsc + '</span>'
                          + statusBadges(q, status)
                          + '</div>'
                          + '</div>';
                });
                $container.html(html);
                bindToggles();
            }

            function cursorInfo(q) {
                var found = null;
                $.each(discoveryCursors || {}, function (key, value) {
                    if (key !== '_rotation' && value && value.q === q) {
                        found = { key: key, cursor: value };
                        return false;
                    }
                });
                return found;
            }

            function buildExecutionPlan(queries) {
                var actionable = [];
                var disabled = 0;
                var exhausted = 0;
                var statuses = {};

                $.each(queries, function (i, item) {
                    var q = item.q != null ? item.q : item;
                    var enabled = item.enabled !== false;
                    var info = cursorInfo(q);
                    if (!enabled) {
                        disabled++;
                        statuses[q] = { state: 'disabled' };
                        return;
                    }
                    if (info && info.cursor && info.cursor.exhausted) {
                        exhausted++;
                        statuses[q] = { state: 'exhausted', cursor: info.cursor };
                        return;
                    }
                    actionable.push({ q: q, key: info ? info.key : null, cursor: info ? info.cursor : null });
                });

                var rotationKey = discoveryCursors ? discoveryCursors._rotation : null;
                var start = 0;
                if (rotationKey) {
                    $.each(actionable, function (i, item) {
                        if (item.key === rotationKey) {
                            start = (i + 1) % actionable.length;
                            return false;
                        }
                    });
                }
                var ordered = actionable.length
                    ? actionable.slice(start).concat(actionable.slice(0, start))
                    : [];
                var budget = executionBudget === null ? ordered.length : Math.max(0, executionBudget);

                $.each(ordered, function (i, item) {
                    statuses[item.q] = {
                        state: i < budget ? 'immediate' : 'queued',
                        rank: i + 1,
                        cursor: item.cursor,
                    };
                });

                return {
                    statuses: statuses,
                    prepared: queries.length,
                    actionable: actionable.length,
                    immediate: Math.min(budget, actionable.length),
                    queued: Math.max(0, actionable.length - budget),
                    disabled: disabled,
                    exhausted: exhausted,
                    budget: budget,
                };
            }

            function executionSummary(queries, plan) {
                return '<div class="alert alert-light-info border border-info border-dashed p-4 mb-4">'
                    + '<div class="fw-bold text-gray-800 mb-1">Prochain lancement : jusqu\'à ' + plan.budget + ' recherche(s) SerpAPI exécutée(s) maintenant</div>'
                    + '<div class="text-muted fs-7">' + plan.prepared + ' requête(s) préparée(s) · ' + plan.immediate + ' dans le budget immédiat · ' + plan.queued + ' en attente · ' + plan.exhausted + ' déjà épuisée(s) · ' + plan.disabled + ' désactivée(s).</div>'
                    + '<div class="text-muted fs-8 mt-2"><i class="bi bi-info-circle me-1"></i>L\'aperçu ne consomme aucun crédit. 1 recherche SerpAPI = 1 crédit. Une même requête peut consommer plusieurs pages si Google renvoie une page suivante.</div>'
                    + '</div>';
            }

            function statusBadges(q, status) {
                var html = '';
                if (status.state === 'disabled') {
                    return '<span class="badge badge-light-secondary ms-3">Désactivée</span>';
                }
                if (status.state === 'exhausted') {
                    return '<span class="badge badge-light-danger ms-3">Déjà épuisée</span>';
                }
                if (status.state === 'immediate') {
                    html += '<span class="badge badge-light-success ms-3">Dans le budget · rang ' + status.rank + '</span>';
                } else if (status.state === 'queued') {
                    html += '<span class="badge badge-light-secondary ms-3">En attente · rang ' + status.rank + '</span>';
                }

                if (status.cursor) {
                    var start = parseInt(status.cursor.start || 0, 10);
                    var page = Math.floor((isNaN(start) ? 0 : start) / 10) + 1;
                    html += '<span class="badge badge-light-info ms-2">Page suivante : ' + page + '</span>';
                }

                return html;
            }

            // Toggling only dims the row locally — no server call. The state submits
            // with the rest of the form on Enregistrer.
            function bindToggles() {
                $container.find('.q-toggle').on('change', function () {
                    var $box = $(this).closest('[data-qi]');
                    $box.toggleClass('opacity-50', !this.checked);
                });
            }

            // Reads the currently rendered {q, enabled} list — used to preserve enabled
            // flags across a re-generate and to seed generateQueries()'s preview payload.
            function collectQueries() {
                var queries = [];
                $container.find('[data-qi]').each(function () {
                    var $box = $(this);
                    queries.push({
                        q: $box.find('input[name$="[q]"]').val(),
                        enabled: $box.find('.q-toggle').is(':checked'),
                    });
                });
                return queries;
            }

            function loadQueries() {
                $container.html(
                    '<div class="text-muted fs-7"><i class="bi bi-hourglass-split me-1"></i>Chargement des requêtes…</div>'
                );
                $.getJSON(previewUrl, function (data) {
                    renderQueries(data.queries || [], data.execution || null);
                }).fail(function () {
                    $container.html(
                        '<div class="text-danger fs-7"><i class="bi bi-x-circle me-1"></i>Erreur lors du chargement des requêtes.</div>'
                    );
                }).always(function () {
                    // ponytail: sub-300ms window before this fires; a user edit landing inside it is negligible.
                    rebaselineFormSnapshot();
                });
            }

            // Stateless preview: POSTs the CURRENT (possibly unsaved) textarea values
            // plus the current query list (for enabled-flag preservation). Nothing is
            // persisted here — only Enregistrer saves ai_queries.
            function generateQueries() {
                $genBtn.prop('disabled', true);
                $container.html(
                    '<div class="text-muted fs-7"><i class="bi bi-hourglass-split me-1"></i>Génération des requêtes par l\'IA…</div>'
                );
                $.ajax({
                    url: generateUrl,
                    method: 'POST',
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    data: {
                        ai_target:  $('textarea[name="ai_target"]').val(),
                        ai_exclude: $('textarea[name="ai_exclude"]').val(),
                        queries:    collectQueries(),
                    },
                }).done(function (data) {
                    renderQueries(data.queries || [], null, data.notice);
                }).fail(function () {
                    $container.html(
                        '<div class="text-danger fs-7"><i class="bi bi-x-circle me-1"></i>Erreur lors de la génération des requêtes.</div>'
                    );
                }).always(function () {
                    $genBtn.prop('disabled', false);
                });
            }

            // Load cached/structured preview on page ready — populates the form fields
            // so unmodified queries re-submit unchanged if the user doesn't regenerate.
            $(loadQueries);

            // Générer avec l'IA button
            $genBtn.on('click', generateQueries);
        })();
        @endif

        $(function () {
            // Disable run_at_hour while auto_run is unchecked (cosmetic only —
            // server validation via required_if:auto_run,1 is the real guard).
            function toggleRunAtHour() {
                $('#run_at_hour').prop('disabled', !$('#auto_run').is(':checked'));
            }
            $('#auto_run').on('change', toggleRunAtHour);
            toggleRunAtHour();

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

            // run_at_hour is disabled above when auto_run is off; re-snapshot so that
            // programmatic disable (which drops it from FormData) does not read as dirty.
            rebaselineFormSnapshot();
        });
    </script>
@endpush

</x-default-layout>
