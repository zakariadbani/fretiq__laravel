<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? "Modifier l'identité — " . e($model->name) : "Ajouter une identité d'expéditeur" }}
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
            <a href="{{ route('admin.sender_identities.index') }}" class="text-muted text-hover-primary">Identités d'expéditeur</a>
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
                        <i class="bi bi-person-badge text-primary fs-3 me-2"></i>
                        Informations de l'identité
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom d'affichage</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : TCL France Prospection"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Email --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Adresse email</label>
                                <input type="email"
                                       name="email"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : contact@tcl-france.fr"
                                       value="{{ old('email', $model->email ?? '') }}"
                                       required />
                            </div>

                            {{-- Reply-To --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Répondre à <span class="text-muted fs-7">(optionnel)</span></label>
                                <input type="email"
                                       name="reply_to"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : reponses@tcl-france.fr"
                                       value="{{ old('reply_to', $model->reply_to ?? '') }}" />
                                <div class="form-text text-muted mt-1">
                                    Si vide, les réponses iront vers l'adresse email principale.
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Par défaut --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-3">Par défaut</label>
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="is_default"
                                           id="is_default"
                                           value="1"
                                           {{ old('is_default', $model->is_default ?? false) ? 'checked' : '' }} />
                                    <label class="form-check-label fw-semibold text-gray-700 ms-3" for="is_default">
                                        Identité par défaut
                                    </label>
                                </div>
                                <div class="form-text text-muted mt-1">
                                    Une seule identité peut être marquée par défaut — la précédente sera automatiquement décochée.
                                </div>
                            </div>

                            {{-- Actif --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-3">Statut</label>
                                <div class="form-check form-switch form-check-custom form-check-solid">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="is_active"
                                           id="is_active"
                                           value="1"
                                           {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                                    <label class="form-check-label fw-semibold text-gray-700 ms-3" for="is_active">
                                        Identité active
                                    </label>
                                </div>
                            </div>

                        </div>
                    </div>

                    {{-- Signature HTML --}}
                    <div class="row">
                        <div class="col-12">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Signature HTML <span class="text-muted fs-7">(optionnel)</span></label>
                                <textarea name="signature_html"
                                          class="form-control form-control-solid font-monospace"
                                          rows="8"
                                          placeholder="<p>Cordialement,<br><strong>Votre nom</strong><br>TCL France</p>">{{ old('signature_html', $model->signature_html ?? '') }}</textarea>
                                <div class="form-text text-muted mt-1">
                                    HTML brut — sera inséré en bas de chaque email.
                                </div>
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
                @can('view sender_identities')
                    <a href="{{ route('admin.sender_identities.index') }}" class="btn btn-secondary">
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
