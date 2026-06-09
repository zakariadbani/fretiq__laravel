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

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    <div class="row g-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-person text-primary fs-3 me-2"></i>
                        Informations du contact
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Entreprise --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Entreprise</label>
                                <select name="company_id" class="form-select form-select-solid" required>
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

                            {{-- Statut --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Statut</label>
                                <select name="status" class="form-select form-select-solid">
                                    <option value="">Sélectionner un statut...</option>
                                    @foreach($statuses as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('status', $model->status ?? '') === $key ? 'selected' : '' }}>
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

                            {{-- Base légale RGPD --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Base légale (RGPD)</label>
                                <select name="legal_basis" class="form-select form-select-solid">
                                    <option value="">Sélectionner une base légale...</option>
                                    @foreach($legalBases as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('legal_basis', $model->legal_basis ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Type d'email --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Type d'email</label>
                                <select name="email_kind" class="form-select form-select-solid">
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
    </div>

    {{-- Action buttons --}}
    <div class="row g-5 mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-end gap-3">
                @can('view contacts')
                    <a href="{{ route('admin.contacts.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </a>
                @endcan

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
