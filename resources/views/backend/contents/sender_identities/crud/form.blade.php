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

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sender_identities.index'])
@endsection

{{--
    SenderIdentity create/edit form — hero + tabbar + sticky contract.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with tab strip.
        - Général (active) is the only native form pane INSIDE <form>.
        - Aperçu tab deep-links to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).

    No select2 used — all inputs are plain text/checkbox. No hidden panes.
    Both form-actions calls preserved: toolbar variant above + sticky variant at bottom.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.sender_identities.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-person-badge text-primary fs-3 me-2"></i>
                    Ajouter une identité d'expéditeur
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#sender_general">
                    <i class="bi bi-person-badge me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    <div class="tab-content" id="sender_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        <div class="tab-pane fade show active" id="sender_general" role="tabpanel">

            {{-- Identity information card --}}
            <div class="card mb-5">
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
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.sender_identities.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
