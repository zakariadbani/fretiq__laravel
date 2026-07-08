<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier l\'entreprise — ' . e($model->name) : 'Ajouter une entreprise' }}
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
            <a href="{{ route('admin.companies.index') }}" class="text-muted text-hover-primary">Entreprises</a>
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
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.companies.index'])
@endsection

{{--
    Company create/edit form — unified 5-tab UX (clic2loc parity).

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 5-tab strip.
        - Général (active, with phone folded in) + Enrichissement are native form panes INSIDE <form>.
        - Contacts + Activité panes are rendered AFTER </form> and moved into
          #company_tab_content by company-tabs.js (avoids nested forms).
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card + minimal nav (Général + Enrichissement only).
          Contacts/Activité are irrelevant until the company exists.

    All selects on the Général pane use data-control="select2" (auto-init via Metronic).
    The contact modal uses plain <select> — no select2 — to avoid width:0 in hidden container.

    Both @include('backend.elements.form-actions', ...) calls are preserved:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of <form>.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + 5-tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.companies.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général + Enrichissement) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-building text-primary fs-3 me-2"></i>
                    Ajouter une entreprise
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#company_general">
                    <i class="bi bi-building me-1"></i>
                    Général
                </a>
            </li>
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5"
                   data-bs-toggle="tab" href="#company_enrichment">
                    <i class="bi bi-database me-1"></i>
                    Enrichissement
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    {{-- data-out-of-form-panes: consumed by crud-tabs.js to move out-of-form panes into this container. --}}
    <div class="tab-content" id="company_tab_content"
         data-out-of-form-panes='["company_contacts","company_activity"]'>

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        {{-- All required/validated fields are here — validation errors surface on the visible pane. --}}
        {{-- All select2 selects live here — they render correctly in the default-active pane. --}}
        <div class="tab-pane fade show active" id="company_general" role="tabpanel">
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
                                <label class="required fw-semibold fs-6 mb-2">Nom de l'entreprise</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Geodis SA"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Domaine --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Domaine</label>
                                <input type="text"
                                       name="domain"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : geodis.com"
                                       value="{{ old('domain', $model->domain ?? '') }}" />
                            </div>

                            {{-- Secteur --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Secteur</label>
                                <input type="text"
                                       name="sector"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Transport & Logistique"
                                       value="{{ old('sector', $model->sector ?? '') }}" />
                            </div>

                            {{-- Téléphone (moved from the old Coordonnées tab) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Téléphone</label>
                                <input type="text"
                                       name="phone"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : +33 1 23 45 67 89"
                                       value="{{ old('phone', $model->phone ?? '') }}" />
                            </div>

                            {{-- Pays (select — select2 safe on active pane) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Pays</label>
                                @php
                                    $currentCountry = strtoupper(old('country', $model->country ?? ''));
                                @endphp
                                <select name="country" class="form-select form-select-solid" data-control="select2" data-placeholder="Sélectionner un pays...">
                                    <option value="">Sélectionner un pays...</option>
                                    @if($currentCountry && !isset($countries[$currentCountry]))
                                        <option value="{{ $currentCountry }}" selected>{{ $currentCountry }}</option>
                                    @endif
                                    @foreach($countries as $iso => $label)
                                        <option value="{{ $iso }}"
                                            {{ $currentCountry === $iso ? 'selected' : '' }}>
                                            {{ $label }} ({{ $iso }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Taille estimée --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Taille estimée</label>
                                @php
                                    $currentSize = old('estimated_size', $model->estimated_size ?? '');
                                @endphp
                                <select name="estimated_size" class="form-select form-select-solid" data-control="select2" data-hide-search="true" data-placeholder="Sélectionner une taille...">
                                    <option value="">Sélectionner une taille...</option>
                                    @if($currentSize !== '' && !isset($sizeBuckets[$currentSize]))
                                        <option value="{{ $currentSize }}" selected>{{ $currentSize }}</option>
                                    @endif
                                    @foreach($sizeBuckets as $key => $label)
                                        <option value="{{ $key }}"
                                            {{ $currentSize === $key ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                        </div>

                        <div class="col-lg-6">

                            {{-- Relation --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Relation</label>
                                <select name="relationship" class="form-select form-select-solid" data-control="select2" data-hide-search="true" data-placeholder="Sélectionner une relation...">
                                    <option value="">Sélectionner une relation...</option>
                                    @foreach($relationships as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('relationship', $model->relationship ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Source --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Source</label>
                                <select name="source" class="form-select form-select-solid" data-control="select2" data-hide-search="true" data-placeholder="Sélectionner une source...">
                                    <option value="">Sélectionner une source...</option>
                                    @foreach($sources as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('source', $model->source ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Statut qualification --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Statut de qualification</label>
                                <select name="qualification_status" class="form-select form-select-solid" data-control="select2" data-hide-search="true" data-placeholder="Sélectionner un statut...">
                                    <option value="">Sélectionner un statut...</option>
                                    @foreach($qualificationStatuses as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('qualification_status', $model->qualification_status ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Score IA --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Score IA</label>
                                <input type="number"
                                       name="ai_score"
                                       class="form-control form-control-solid"
                                       placeholder="0 – 100"
                                       min="0"
                                       max="100"
                                       value="{{ old('ai_score', $model->ai_score ?? '') }}" />
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- end Général --}}

        {{-- ── Enrichissement ─────────────────────────────────────────────── --}}
        <div class="tab-pane fade" id="company_enrichment" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Enrichissement IA</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    {{-- Score recap button (showCard=false — we are inside the existing Enrichissement card) --}}
                    @include('backend.contents.companies.partials._score-recap', ['model' => $model, 'showCard' => false])

                    {{-- Explication IA --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Explication IA</label>
                        <textarea name="ai_explanation"
                                  class="form-control form-control-solid"
                                  rows="4"
                                  placeholder="Justification du score IA...">{{ old('ai_explanation', $model->ai_explanation ?? '') }}</textarea>
                    </div>

                    {{-- Enrichment data (read-only key/value display) --}}
                    @if(isset($model) && $model->id)
                        <div class="separator my-6"></div>
                        <h5 class="fw-bold fs-5 mb-5">Données d'enrichissement</h5>

                        @php
                            $enrichData = $model->enrichment_data;

                            $formatScalar = static function ($value): string {
                                if (is_bool($value)) {
                                    return $value
                                        ? '<span class="badge badge-light-success">Oui</span>'
                                        : '<span class="badge badge-light-secondary">Non</span>';
                                }

                                if ($value === null || $value === '') {
                                    return '<span class="text-muted">—</span>';
                                }

                                return e((string) $value);
                            };

                            $renderSources = static function (array $sources): string {
                                if ($sources === []) {
                                    return '<span class="text-muted">Aucune source</span>';
                                }

                                $visibleSources = array_slice($sources, 0, 3);
                                $sourceLinks = collect($visibleSources)->map(function ($source): string {
                                    $uri = $source['uri'] ?? null;
                                    $domain = $source['domain'] ?? parse_url((string) $uri, PHP_URL_HOST) ?: 'source';
                                    $label = e($domain);

                                    if (!$uri) {
                                        return '<span class="badge badge-light">' . $label . '</span>';
                                    }

                                    return '<a href="' . e($uri) . '" target="_blank" rel="noopener" class="badge badge-light-primary text-hover-primary">'
                                        . $label
                                        . '</a>';
                                })->implode(' ');

                                $remainingCount = count($sources) - count($visibleSources);
                                $remaining = $remainingCount > 0
                                    ? ' <span class="badge badge-light">+' . $remainingCount . ' autres</span>'
                                    : '';

                                return '<div class="d-flex flex-wrap gap-2">' . $sourceLinks . $remaining . '</div>';
                            };

                            $renderValue = function ($value, string $key = '') use (&$renderValue, $formatScalar, $renderSources): \Illuminate\Support\HtmlString {
                                if ($key === 'emails' && is_array($value)) {
                                    if ($value === []) {
                                        return new \Illuminate\Support\HtmlString('<span class="text-muted">Aucun email trouvé</span>');
                                    }

                                    $html = '<div class="d-flex flex-column gap-3">';

                                    foreach ($value as $email) {
                                        if (!is_array($email)) {
                                            $html .= '<div>' . $formatScalar($email) . '</div>';
                                            continue;
                                        }

                                        $address = $email['value'] ?? null;
                                        $fullName = trim(($email['first_name'] ?? '') . ' ' . ($email['last_name'] ?? ''));
                                        $type = $email['type'] ?? 'email';
                                        $confidence = $email['confidence'] ?? null;
                                        $sources = is_array($email['sources'] ?? null) ? $email['sources'] : [];
                                        $sourceCount = count($sources);

                                        $html .= '<div class="border rounded bg-light p-3">';
                                        $html .= '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">';
                                        $html .= $address
                                            ? '<a href="mailto:' . e($address) . '" class="fw-bold text-gray-900 text-hover-primary">' . e($address) . '</a>'
                                            : '<span class="fw-bold text-muted">Email inconnu</span>';
                                        $html .= '<span class="badge badge-light-info">' . e($type) . '</span>';

                                        if ($confidence !== null) {
                                            $html .= '<span class="badge badge-light-success">Confiance ' . e((string) $confidence) . '%</span>';
                                        }

                                        $html .= '<span class="badge badge-light">' . $sourceCount . ' source' . ($sourceCount > 1 ? 's' : '') . '</span>';
                                        $html .= '</div>';

                                        if ($fullName !== '') {
                                            $html .= '<div class="text-muted fs-7 mb-2">Contact : ' . e($fullName) . '</div>';
                                        }

                                        $html .= $renderSources($sources);
                                        $html .= '</div>';
                                    }

                                    $html .= '</div>';

                                    return new \Illuminate\Support\HtmlString($html);
                                }

                                if (is_array($value)) {
                                    if ($value === []) {
                                        return new \Illuminate\Support\HtmlString('<span class="badge badge-light">Aucun élément</span>');
                                    }

                                    if (array_is_list($value)) {
                                        $allScalar = collect($value)->every(fn ($item) => !is_array($item) && !is_object($item));

                                        if ($allScalar) {
                                            $html = collect($value)->map(fn ($item) => '<span class="badge badge-light me-1 mb-1">' . $formatScalar($item) . '</span>')->implode('');
                                            return new \Illuminate\Support\HtmlString('<div class="d-flex flex-wrap gap-1">' . $html . '</div>');
                                        }

                                        $html = '<div class="d-flex flex-column gap-2">';
                                        foreach ($value as $index => $item) {
                                            $html .= '<div class="border rounded p-3 bg-light">';
                                            $html .= '<div class="fw-semibold text-muted fs-8 mb-2">Élément ' . e((string) ($index + 1)) . '</div>';
                                            $html .= $renderValue($item)->toHtml();
                                            $html .= '</div>';
                                        }
                                        $html .= '</div>';

                                        return new \Illuminate\Support\HtmlString($html);
                                    }

                                    $html = '<dl class="row mb-0 gy-2">';
                                    foreach ($value as $nestedKey => $nestedValue) {
                                        $html .= '<dt class="col-sm-4 text-muted fw-semibold">' . e((string) $nestedKey) . '</dt>';
                                        $html .= '<dd class="col-sm-8 mb-0">' . $renderValue($nestedValue)->toHtml() . '</dd>';
                                    }
                                    $html .= '</dl>';

                                    return new \Illuminate\Support\HtmlString($html);
                                }

                                if (is_object($value)) {
                                    return $renderValue((array) $value);
                                }

                                return new \Illuminate\Support\HtmlString($formatScalar($value));
                            };
                        @endphp
                        @if(!empty($enrichData))
                            <div class="table-responsive">
                                <table class="table table-row-bordered table-row-gray-200 align-middle gs-0 gy-3">
                                    <thead>
                                        <tr class="fw-bold text-muted">
                                            <th class="min-w-120px">Clé</th>
                                            <th>Valeur</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($enrichData as $key => $value)
                                            <tr>
                                                <td class="fw-semibold text-gray-700">{{ $key }}</td>
                                                <td class="text-gray-800">{{ $renderValue($value, (string) $key) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center py-8 text-muted">
                                <i class="bi bi-database fs-2x mb-3 d-block"></i>
                                Aucune donnée d'enrichissement disponible.
                            </div>
                        @endif
                    @endif

                </div>
            </div>
        </div>
        {{-- end Enrichissement --}}

        {{--
            Contacts and Activité panes are NOT inside the form.
            They are rendered AFTER </form> (below) and moved into this
            #company_tab_content div by company-tabs.js on page load.
            This avoids nested-form issues while letting Bootstrap tab toggle work.
        --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.companies.index'])

</form>

{{-- ── Out-of-form panes (edit mode only) ───────────────────────────────── --}}
{{-- company-tabs.js moves these into #company_tab_content after DOMContentLoaded --}}
@if(isset($model) && $model->id)

    <div class="tab-pane fade" id="company_contacts" role="tabpanel" data-crud-pane>
        @include('backend.contents.companies.partials._contacts-tab', ['model' => $model])
    </div>

    <div class="tab-pane fade" id="company_activity" role="tabpanel" data-crud-pane>
        @include('backend.contents.companies.partials._activity-tab', ['model' => $model])
    </div>

    {{-- Contact modal --}}
    @include('backend.contents.companies.partials._contact-modal', ['model' => $model])

@endif

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
