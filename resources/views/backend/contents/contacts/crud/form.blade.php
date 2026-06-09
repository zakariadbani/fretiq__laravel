<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le contact — ' . e($model->name) : 'Ajouter un contact' }}
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
            <a href="{{ route('admin.contacts.index') }}" class="text-muted text-hover-primary">Contacts</a>
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
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.contacts.index'])
@endsection

{{--
    Contact create/edit form — unified 2-tab UX (clic2loc parity).

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 2-tab strip.
        - Général (active) is the single form pane INSIDE <form>.
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).
          Aperçu is irrelevant until the contact exists.

    All selects on the Général pane use data-control="select2" (auto-init via Metronic).
    No out-of-form panes — contacts module has no domain sub-tabs at this time.

    Both @include('backend.elements.form-actions', ...) calls are preserved:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of <form>.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + 2-tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.contacts.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-person text-primary fs-3 me-2"></i>
                    Ajouter un contact
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#contact_general">
                    <i class="bi bi-person me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    {{-- No out-of-form panes for contacts — attribute omitted intentionally. --}}
    <div class="tab-content" id="contact_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        {{-- All required/validated fields are here. --}}
        {{-- select2 selects live here — they render correctly in the default-active pane. --}}
        <div class="tab-pane fade show active" id="contact_general" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Informations du contact</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Entreprise (select2 — active pane, safe width) --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Entreprise</label>
                                <select name="company_id"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-placeholder="Sélectionner une entreprise..."
                                        required>
                                    <option value="">Sélectionner une entreprise...</option>
                                    @foreach($companies as $company)
                                        <option value="{{ $company->id }}"
                                            {{ old('company_id', $model->company_id ?? '') == $company->id ? 'selected' : '' }}>
                                            {{ $company->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom complet</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Jean Dupont"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Email --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Email</label>
                                <input type="email"
                                       name="email"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : j.dupont@entreprise.com"
                                       value="{{ old('email', $model->email ?? '') }}"
                                       required />
                            </div>

                            {{-- Poste --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Poste</label>
                                <input type="text"
                                       name="position"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Directeur achats"
                                       value="{{ old('position', $model->position ?? '') }}" />
                            </div>

                            {{-- Téléphone --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Téléphone</label>
                                <input type="text"
                                       name="phone"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : +33 6 12 34 56 78"
                                       value="{{ old('phone', $model->phone ?? '') }}" />
                            </div>

                            {{-- URL source --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">URL source</label>
                                <input type="text"
                                       name="source_url"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : https://linkedin.com/in/jean-dupont"
                                       value="{{ old('source_url', $model->source_url ?? '') }}" />
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Statut (select2 — active pane, safe width) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Statut</label>
                                <select name="status"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true"
                                        data-placeholder="Sélectionner un statut...">
                                    <option value="">Sélectionner un statut...</option>
                                    @foreach($statuses as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('status', $model->status ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Source (select2 — active pane, safe width) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Source</label>
                                <select name="source"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true"
                                        data-placeholder="Sélectionner une source...">
                                    <option value="">Sélectionner une source...</option>
                                    @foreach($sources as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('source', $model->source ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Base légale RGPD (select2 — active pane, safe width) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Base légale (RGPD)</label>
                                <select name="legal_basis"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true"
                                        data-placeholder="Sélectionner une base légale...">
                                    <option value="">Sélectionner une base légale...</option>
                                    @foreach($legalBases as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('legal_basis', $model->legal_basis ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Type d'email (select2 — active pane, safe width) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Type d'email</label>
                                <select name="email_kind"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true"
                                        data-placeholder="Sélectionner un type...">
                                    <option value="">Sélectionner un type...</option>
                                    @foreach($emailKinds as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('email_kind', $model->email_kind ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Date de consentement --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Date de consentement</label>
                                <input type="datetime-local"
                                       name="consent_at"
                                       class="form-control form-control-solid"
                                       value="{{ old('consent_at', isset($model->consent_at) ? $model->consent_at?->format('Y-m-d\TH:i') : '') }}" />
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
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.contacts.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
