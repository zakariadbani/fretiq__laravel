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
    <a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light btn-active-light-primary">
        <i class="ki-duotone ki-arrow-left fs-4"><span class="path1"></span><span class="path2"></span></i>
        Retour à la liste
    </a>
    <button type="button" name="saveandcontinue" class="btn btn-sm fw-bold btn-success submit" data-form-id="form_crud">
        <span class="indicator-label">
            <i class="ki-duotone ki-check-circle fs-4 me-1"><span class="path1"></span><span class="path2"></span></i>
            Enregistrer &amp; continuer
        </span>
        <span class="indicator-progress">
            Veuillez patienter...
            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
        </span>
    </button>
    <button type="button" name="save" class="btn btn-sm fw-bold btn-primary submit" data-form-id="form_crud">
        <span class="indicator-label">
            <i class="ki-duotone ki-check fs-4 me-1"><span class="path1"></span><span class="path2"></span></i>
            Enregistrer
        </span>
        <span class="indicator-progress">
            Veuillez patienter...
            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
        </span>
    </button>
@endsection

{{--
    Company create/edit form — prototype parity.
    Single <form> wrapping everything; @method('PUT') injected on edit.
    Tabs are Bootstrap panes — hidden panes still submit via FormData.
    Native form-select only (no select2 — avoids width:0 bug in hidden tabs).
    Sticky save bar uses stock Bootstrap 5 .sticky-bottom (no custom CSS needed).
    All required/validated fields are in the default-active Général tab.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- Hero card (edit only) --}}
    @if(isset($model) && $model->id)
        <x-companies.hero :model="$model">
            <x-slot:actions>
                <a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light">
                    <i class="bi bi-x-circle me-1"></i>
                    Annuler
                </a>
            </x-slot:actions>

            {{-- Tab nav inside hero card --}}
            <ul class="nav nav-stretch nav-line-tabs nav-line-tabs-2x border-transparent fs-5 fw-bold">
                <li class="nav-item mt-2">
                    <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                       data-bs-toggle="tab" href="#ce_general">Général</a>
                </li>
                <li class="nav-item mt-2">
                    <a class="nav-link text-active-primary ms-0 me-10 py-5"
                       data-bs-toggle="tab" href="#ce_contact">Coordonnées</a>
                </li>
                <li class="nav-item mt-2">
                    <a class="nav-link text-active-primary ms-0 me-10 py-5"
                       data-bs-toggle="tab" href="#ce_enrichment">Enrichissement</a>
                </li>
            </ul>
        </x-companies.hero>
    @else
        {{-- Create: simple header instead of hero --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-building text-primary fs-3 me-2"></i>
                    Ajouter une entreprise
                </h2>
            </div>
        </div>
        {{-- Tab nav for create (outside hero) --}}
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#ce_general">Général</a>
            </li>
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5"
                   data-bs-toggle="tab" href="#ce_contact">Coordonnées</a>
            </li>
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5"
                   data-bs-toggle="tab" href="#ce_enrichment">Enrichissement</a>
            </li>
        </ul>
    @endif

    {{-- Tab content --}}
    <div class="tab-content">

        {{-- ── Tab 1: Général (default active) ───────────────────────────────── --}}
        {{-- All required/validated fields are here so validation errors surface on the visible pane. --}}
        <div class="tab-pane fade show active" id="ce_general" role="tabpanel">
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

                            {{-- Pays (select from company_countries map) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Pays</label>
                                @php
                                    $currentCountry = strtoupper(old('country', $model->country ?? ''));
                                @endphp
                                <select name="country" class="form-select form-select-solid">
                                    <option value="">Sélectionner un pays...</option>
                                    {{-- Inject the stored value as a fallback option if it's not in the map --}}
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

                            {{-- Taille estimée (select from company_size_buckets) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Taille estimée</label>
                                @php
                                    $currentSize = old('estimated_size', $model->estimated_size ?? '');
                                @endphp
                                <select name="estimated_size" class="form-select form-select-solid">
                                    <option value="">Sélectionner une taille...</option>
                                    {{-- Inject the stored value as a fallback option if it's not a known bucket --}}
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
                                <select name="relationship" class="form-select form-select-solid">
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
                                <select name="source" class="form-select form-select-solid">
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
                                <select name="qualification_status" class="form-select form-select-solid">
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

        {{-- ── Tab 2: Coordonnées ──────────────────────────────────────────────── --}}
        <div class="tab-pane fade" id="ce_contact" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Coordonnées</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    {{-- Téléphone --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Téléphone</label>
                        <input type="text"
                               name="phone"
                               class="form-control form-control-solid"
                               placeholder="Ex : +33 1 23 45 67 89"
                               value="{{ old('phone', $model->phone ?? '') }}" />
                    </div>

                    {{-- Contacts mini-list (edit only, read-only) --}}
                    @if(isset($model) && $model->id)
                        <div class="separator my-6"></div>
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <h4 class="fw-bold fs-5 m-0">
                                Contacts liés
                                <span class="badge badge-light-primary ms-2">{{ $model->contacts->count() }}</span>
                            </h4>
                            @can('create contacts')
                                <a href="{{ route('admin.contacts.create') }}"
                                   class="btn btn-sm btn-light-primary">
                                    <i class="bi bi-plus fs-4 me-1"></i>
                                    Ajouter un contact
                                </a>
                            @endcan
                        </div>

                        @if($model->contacts->isEmpty())
                            <div class="text-center py-8 text-muted">
                                <i class="bi bi-people fs-2x mb-3 d-block"></i>
                                Aucun contact pour cette entreprise.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
                                    <thead>
                                        <tr class="fw-bold text-muted">
                                            <th class="min-w-140px">Nom</th>
                                            <th class="min-w-120px">Email</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($model->contacts as $contact)
                                            <tr>
                                                <td>
                                                    <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                                       class="text-gray-900 fw-bold text-hover-primary fs-6">
                                                        {{ $contact->name }}
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="mailto:{{ $contact->email }}"
                                                       class="text-gray-700 text-hover-primary fs-7">
                                                        {{ $contact->email }}
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif

                </div>
            </div>
        </div>

        {{-- ── Tab 3: Enrichissement ───────────────────────────────────────────── --}}
        <div class="tab-pane fade" id="ce_enrichment" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Enrichissement IA</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">

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
                        <h5 class="fw-bold fs-5 mb-5">Données brutes d'enrichissement</h5>

                        @php $enrichData = $model->enrichment_data; @endphp
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
                                                <td class="text-gray-800">
                                                    @if(is_array($value) || is_object($value))
                                                        <code class="fs-7">{{ json_encode($value) }}</code>
                                                    @else
                                                        {{ $value }}
                                                    @endif
                                                </td>
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

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar (stock Bootstrap 5 .sticky-bottom — no custom CSS needed) --}}
    <div class="sticky-bottom bg-body border-top shadow-sm py-4 mt-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-end gap-3">
                <a href="{{ route('admin.companies.index') }}" class="btn btn-light btn-active-light-primary">
                    <i class="bi bi-arrow-left fs-4 me-1"></i>
                    Retour
                </a>
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
