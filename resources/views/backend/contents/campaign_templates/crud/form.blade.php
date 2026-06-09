<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? "Modifier le modèle — " . e($model->name) : "Ajouter un modèle d'email" }}
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
            <a href="{{ route('admin.campaign_templates.index') }}" class="text-muted text-hover-primary">Modèles d'email</a>
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

        {{-- Main info card --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-envelope text-primary fs-3 me-2"></i>
                        Informations du modèle
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du modèle</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Email de prospection transport"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Sujet --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Sujet de l'email</label>
                                <input type="text"
                                       name="subject"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Optimisez votre logistique avec TCL France"
                                       value="{{ old('subject', $model->subject ?? '') }}"
                                       required />
                                <div class="form-text text-muted mt-1">
                                    Variables disponibles : <code>@verbatim{{contact.name}}@endverbatim</code>, <code>@verbatim{{company.name}}@endverbatim</code>
                                </div>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Texte de prévisualisation --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Texte de prévisualisation <span class="text-muted fs-7">(optionnel)</span></label>
                                <input type="text"
                                       name="preview_text"
                                       class="form-control form-control-solid"
                                       placeholder="Aperçu affiché dans la liste des emails..."
                                       value="{{ old('preview_text', $model->preview_text ?? '') }}"
                                       maxlength="255" />
                                <div class="form-text text-muted mt-1">
                                    Texte court visible dans les clients email avant ouverture (≤ 255 car.).
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </div>

        {{-- HTML content card --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-code-slash text-info fs-3 me-2"></i>
                        Contenu HTML de l'email
                    </h3>
                    <div class="card-toolbar">
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge badge-light-primary">
                                <code class="fs-8">@verbatim{{contact.name}}@endverbatim</code> — Prénom contact
                            </span>
                            <span class="badge badge-light-primary">
                                <code class="fs-8">@verbatim{{company.name}}@endverbatim</code> — Société
                            </span>
                            <span class="badge badge-light-warning">
                                <code class="fs-8">@verbatim{{unsubscribe_url}}@endverbatim</code> — Lien désabonnement
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body border-top p-9">

                    <div class="fv-row mb-0">
                        <label class="required fw-semibold fs-6 mb-2">Contenu HTML</label>
                        {{-- Plain textarea for MVP — TinyMCE initialization not included in this kit build --}}
                        <textarea name="html_content"
                                  class="form-control form-control-solid font-monospace"
                                  rows="20"
                                  id="html_content"
                                  placeholder="&lt;p&gt;Bonjour @verbatim{{contact.name}}@endverbatim,&lt;/p&gt;..."
                                  required>{{ old('html_content', $model->html_content ?? '') }}</textarea>
                        <div class="form-text text-muted mt-1">
                            HTML complet de l'email. Insérez <code>@verbatim{{unsubscribe_url}}@endverbatim</code> dans le lien de désabonnement.
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
                @can('view campaign_templates')
                    <a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-secondary">
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
