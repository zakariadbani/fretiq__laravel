<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? "Modifier le modèle — " . e($model->name) : "Ajouter un modèle d'email" }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Modèles d\'email', 'route' => 'admin.campaign_templates.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.campaign_templates.index'])
@endsection

{{--
    CampaignTemplate create/edit form — 2-tab UX (contract parity).

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with tab strip.
        - Général (active) is the only native form pane — contains all fields.
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card + minimal nav (Général only — no apercu until record exists).

    html_content is edited with TinyMCE 5 (theme vendor bundle, fullpage plugin
    for full-document email HTML). The textarea stays in the DOM as source of
    truth for FormData/FormValidation; tinymce-html-field.js handles sync.
    No select2 — CampaignTemplate has no enum/relation selects; no width issue.

    Both form-actions calls are preserved:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of <form>.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.campaign_templates.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-envelope text-primary fs-3 me-2"></i>
                    Ajouter un modèle d'email
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#template_general">
                    <i class="bi bi-envelope me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content ────────────────────────────────────────────────── --}}
    <div class="tab-content" id="template_tab_content"
         data-out-of-form-panes='["template_traductions"]'>

        {{-- ── Général (default active) ──────────────────────────────── --}}
        <div class="tab-pane fade show active" id="template_general" role="tabpanel">

            {{-- Main info card --}}
            <div class="card mb-5">
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

            {{-- HTML content card --}}
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
                        <div class="form-text text-muted mt-2 fs-8">
                            Envoi via Zoho : ces variables sont converties automatiquement en merge tags Zoho.
                        </div>
                    </div>
                </div>
                <div class="card-body border-top p-9">

                    {{-- html_content — TinyMCE WYSIWYG (fullpage : HTML email complet <html><head><style>) --}}
                    <div class="fv-row mb-0">
                        <label class="required fw-semibold fs-6 mb-2" for="html_content">Contenu HTML</label>

                        <textarea name="html_content"
                                  class="form-control form-control-solid font-monospace"
                                  rows="20"
                                  id="html_content"
                                  data-tinymce-html-field
                                  required>{{ old('html_content', $model->html_content ?? '') }}</textarea>

                        <div class="form-text text-muted mt-1">
                            HTML complet de l'email. Insérez <code>@verbatim{{unsubscribe_url}}@endverbatim</code> dans le lien de désabonnement.
                            Bouton <code>&lt;/&gt;</code> de la barre d'outils pour éditer le code source.
                        </div>
                    </div>

                </div>
            </div>

        </div>
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.campaign_templates.index'])

</form>

{{-- ── Out-of-form panes (edit mode only) ───────────────────────────────── --}}
{{-- crud-tabs.js moves these into #template_tab_content after DOMContentLoaded --}}
@if(isset($model) && $model->id)

    <div class="tab-pane fade" id="template_traductions" role="tabpanel" data-crud-pane>
        @include('backend.contents.campaign_templates.partials._traductions-tab', ['model' => $model])
    </div>

@endif

@push('scripts')
    <script src="{{ asset('assets/plugins/custom/tinymce/tinymce.js') }}?v={{ filemtime(public_path('assets/plugins/custom/tinymce/tinymce.js')) }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}?v={{ filemtime(public_path('assets/js/custom/backend/crud-tabs.js')) }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var translationPane = '#template_traductions';

            function syncTemplateFormActions(href) {
                var activeHref = href;
                if (!activeHref) {
                    var activeLink = document.querySelector('a[data-bs-toggle="tab"].active');
                    activeHref = activeLink ? activeLink.getAttribute('href') : '';
                }

                document.querySelectorAll('[data-crud-form-actions="sticky"]').forEach(function (actions) {
                    var hide = activeHref === translationPane;
                    actions.classList.toggle('d-none', hide);
                    actions.setAttribute('aria-hidden', hide ? 'true' : 'false');
                });
            }

            document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (link) {
                link.addEventListener('shown.bs.tab', function (event) {
                    syncTemplateFormActions(event.target.getAttribute('href'));
                });
            });

            requestAnimationFrame(function () {
                syncTemplateFormActions(window.location.hash || null);
            });
        });
    </script>
    <script src="{{ asset('assets/js/custom/backend/tinymce-html-field.js') }}?v={{ filemtime(public_path('assets/js/custom/backend/tinymce-html-field.js')) }}"></script>
    <script src="{{ asset('assets/js/custom/backend/campaign-template-translations.js') }}?v={{ file_exists(public_path('assets/js/custom/backend/campaign-template-translations.js')) ? filemtime(public_path('assets/js/custom/backend/campaign-template-translations.js')) : '1' }}"></script>
@endpush

</x-default-layout>
