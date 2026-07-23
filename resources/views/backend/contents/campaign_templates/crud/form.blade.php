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

    ── Builder vs classic ──────────────────────────────────────────────────
    $openInBuilder decides which of the two mutually-exclusive panes
    (#builder_pane / #classic_pane) is visible on load: create always starts
    in the builder; edit opens the builder iff a builder_state was stored,
    otherwise it opens classic (raw HTML) — see
    App\Http\Controllers\Backend\CampaignTemplateController::beforeSave().

    CRITICAL: the html_content textarea only carries `required` +
    data-tinymce-html-field when NOT $openInBuilder. Rendering those
    attributes unconditionally would (a) make crud-form-handler.js register
    a `required` field that stays hidden and can never be filled when the
    page opens in builder mode, silently blocking every submit, and
    (b) auto-init TinyMCE on a hidden element. campaign-template-builder.js
    adds both attributes itself, lazily, the first time the user switches
    INTO classic mode (mirrors the tinymce-html-field.js safety-net pattern).

    The textarea is also rendered `disabled` whenever $openInBuilder is true —
    not just non-required. A disabled field is excluded from form submission
    by the browser itself, independent of any FormValidation [required]
    snapshot quirk: on a builder-mode EDIT page the textarea still carries the
    stored HTML value, and `disabled` is the hard guarantee that stale value
    can never reach the server alongside builder_state. beforeSave() also
    unconditionally overwrites html_content from the composed builder_state in
    builder mode, so a disabled/absent submission is safe either way.
    campaign-template-builder.js re-enables it the first time the user
    switches INTO classic mode, and re-disables it switching back.

    Both form-actions calls are preserved:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of <form>.
--}}

@php
    /** @var \App\Models\CampaignTemplate $model */
    $openInBuilder = ! isset($model->id) || filled($model->builder_state ?? null);

    $headerLabels = [
        'logo_center'  => 'Logo centré',
        'logo_tagline' => 'Logo + signature',
    ];
    $footerLabels = [
        'detailed' => 'Détaillé',
        'compact'  => 'Compact',
    ];
    $middleLabels = [
        'process'    => 'Chaîne logistique',
        'departures' => 'Départs',
        'kpi'        => 'Indicateurs clés',
        'benefits'   => 'Avantages',
    ];
    $middleIcons = [
        'process'    => 'bi-diagram-3',
        'departures' => 'bi-signpost-2',
        'kpi'        => 'bi-bar-chart-line',
        'benefits'   => 'bi-award',
    ];

    $builderInitialState = ($openInBuilder && isset($model->id) && filled($model->builder_state ?? null))
        ? $model->builder_state
        : null;
@endphp

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- Builder mode plumbing — read by campaign-template-builder.js on submit. --}}
    <input type="hidden" name="editor_mode" id="campaign_template_editor_mode" value="{{ $openInBuilder ? 'builder' : 'classic' }}" />
    <input type="hidden" name="builder_state" id="campaign_template_builder_state" value="" />

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.campaign_templates.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: guided composer shell --}}
        <header class="campaign-template-create-head mb-5" data-campaign-template-create-shell>
            <h1 class="fs-2hx fw-bold text-gray-900 mb-2">Créer un modèle d'email</h1>
            <p class="fs-6 text-muted mb-0">Composez un email réutilisable pour vos campagnes de prospection.</p>
        </header>

        <nav class="campaign-template-create-steps mb-5" aria-label="Étapes de création">
            <div class="campaign-template-create-step is-complete">
                <span class="campaign-template-create-step-number"><i class="bi bi-check-lg"></i></span>
                <span><strong>1. Informations</strong><small>Nom, sujet, aperçu</small></span>
            </div>
            <div class="campaign-template-create-step is-active" aria-current="step">
                <span class="campaign-template-create-step-number">2</span>
                <span><strong>2. Composer</strong><small>Mise en page et contenu</small></span>
            </div>
            <div class="campaign-template-create-step">
                <span class="campaign-template-create-step-number">3</span>
                <span><strong>3. Vérifier</strong><small>Aperçu et enregistrement</small></span>
            </div>
        </nav>
    @endif

    {{-- ── Tab content ────────────────────────────────────────────────── --}}
    <div class="tab-content" id="template_tab_content">

        {{-- ── Général (default active) ──────────────────────────────── --}}
        <div class="tab-pane fade show active" id="template_general" role="tabpanel">

            @unless(isset($model) && $model->id)
                <div class="campaign-template-create-workspace">
                    <div class="campaign-template-create-editor-stack">
            @endunless

            {{-- Main info card --}}
            <div class="card mb-5 campaign-template-create-card campaign-template-create-info-card">
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
                                       id="campaign_template_subject"
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
                                       id="campaign_template_preview_text"
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

            {{-- Mode toggle --}}
            <div class="card mb-5 campaign-template-create-card campaign-template-create-mode-card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-magic text-primary fs-3 me-2"></i>
                        Composition de l'email
                    </h3>
                    <div class="card-toolbar">
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input" type="checkbox" id="campaign_template_mode_toggle" {{ $openInBuilder ? '' : 'checked' }} />
                            <label class="form-check-label fw-semibold" for="campaign_template_mode_toggle">Mode avancé (HTML brut)</label>
                        </div>
                    </div>
                </div>
                <div class="card-body border-top p-9">
                    <div class="text-muted fs-7">
                        Le générateur compose un email prêt à l'envoi à partir de blocs prédéfinis (en-tête, accroche, contenu, bouton d'action, pied de page) — sans bloc de désabonnement,
                        Zoho Campaigns s'en charge à l'envoi (voir l'aide sous le champ HTML). Activez le « Mode avancé » pour éditer directement le HTML brut (import Zoho, collage manuel).
                    </div>
                </div>
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 BUILDER — visible iff $openInBuilder
                 ══════════════════════════════════════════════════════════ --}}
            <div id="builder_pane" class="{{ $openInBuilder ? '' : 'd-none' }}">

                @unless(isset($model) && $model->id)
                    {{-- The AI entry point is a dedicated create-mode card; edit keeps it inside Content below. --}}
                    <section class="card mb-5 campaign-template-create-card campaign-template-create-start-card" aria-labelledby="campaign-template-start-title">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title fw-bolder m-0" id="campaign-template-start-title">
                                <span class="campaign-template-create-card-icon"><i class="bi bi-stars"></i></span>
                                Point de départ
                            </h3>
                        </div>
                        <div class="card-body border-top p-9">
                            @unless($builderHasAiKey)
                                <div class="bg-light-warning border-warning border border-dashed rounded p-4 mb-6 d-flex align-items-start gap-3">
                                    <i class="bi bi-exclamation-triangle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
                                    <div>
                                        <span class="fw-bold text-gray-800">Assistant IA non configuré</span><br>
                                        <span class="text-muted fs-7">L'assistant IA n'est pas activé. Vous pouvez composer le contenu manuellement ci-dessous.</span>
                                    </div>
                                </div>
                            @endunless

                            <div class="fv-row mb-0">
                                <label class="fw-semibold fs-6 mb-2" for="builder_brief_input">Brief de contenu <span class="text-muted fs-7">(pour l'IA)</span></label>
                                <div class="campaign-template-create-ai-panel">
                                    <textarea id="builder_brief_input"
                                              class="form-control form-control-solid"
                                              rows="3"
                                              maxlength="5000"
                                              placeholder="Ex : Prospection transitaires France → Espagne/Portugal, insister sur la fréquence des départs et le suivi documentaire."></textarea>
                                    <button type="button"
                                            class="btn btn-sm btn-primary flex-shrink-0"
                                            id="builder_ai_generate_btn"
                                            data-kt-indicator="off"
                                            @unless($builderHasAiKey) disabled @endunless>
                                        <span class="indicator-label"><i class="bi bi-magic me-1"></i>Générer avec l'IA</span>
                                        <span class="indicator-progress">Génération… <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
                                    </button>
                                </div>
                                <div class="form-text text-muted mt-2">L'IA prépare une première version. Vous gardez le contrôle sur chaque champ.</div>
                            </div>
                        </div>
                    </section>
                @endunless

                {{-- Layout card — header / footer variant pickers + middle-block pills --}}
                <div class="card mb-5 campaign-template-create-card campaign-template-create-layout-card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-layout-text-window-reverse text-info fs-3 me-2"></i>
                            Mise en page
                        </h3>
                    </div>
                    <div class="card-body border-top p-9">

                        <div class="row g-6">
                            <div class="col-lg-6">
                                <label class="fw-semibold fs-6 mb-3">En-tête</label>
                                <div class="row g-3" id="builder_header_variants">
                                    @foreach($builderCatalog['headers'] as $header)
                                        <div class="col-6">
                                            <div class="builder-variant-card" data-variant-group="header_variant" data-variant-value="{{ $header }}" tabindex="0" role="button">
                                                <div class="builder-variant-preview-frame">
                                                    <iframe class="builder-variant-preview-iframe"
                                                            srcdoc="{{ $builderVariantPreviews['headers'][$header] }}"
                                                            sandbox="allow-same-origin"
                                                            tabindex="-1"
                                                            title="Aperçu en-tête {{ $headerLabels[$header] ?? $header }}"></iframe>
                                                </div>
                                                <div class="builder-variant-card-label">{{ $headerLabels[$header] ?? $header }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <label class="fw-semibold fs-6 mb-3">Pied de page</label>
                                <div class="row g-3" id="builder_footer_variants">
                                    @foreach($builderCatalog['footers'] as $footer)
                                        <div class="col-6">
                                            <div class="builder-variant-card" data-variant-group="footer_variant" data-variant-value="{{ $footer }}" tabindex="0" role="button">
                                                <div class="builder-variant-preview-frame">
                                                    <iframe class="builder-variant-preview-iframe"
                                                            srcdoc="{{ $builderVariantPreviews['footers'][$footer] }}"
                                                            sandbox="allow-same-origin"
                                                            tabindex="-1"
                                                            data-scroll="bottom"
                                                            title="Aperçu pied de page {{ $footerLabels[$footer] ?? $footer }}"></iframe>
                                                </div>
                                                <div class="builder-variant-card-label">{{ $footerLabels[$footer] ?? $footer }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="mt-7">
                            <label class="fw-semibold fs-6 mb-3">Bloc central</label>
                            <div class="d-flex gap-2 flex-wrap" id="builder_middle_pills">
                                @foreach($builderCatalog['middles'] as $middle)
                                    <button type="button" class="btn btn-sm builder-middle-pill" data-middle-value="{{ $middle }}">
                                        <i class="bi {{ $middleIcons[$middle] ?? 'bi-square' }} me-1"></i>
                                        {{ $middleLabels[$middle] ?? $middle }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                    </div>
                </div>

                {{-- Content card — AI brief + manual slot editing --}}
                <div class="card mb-5 campaign-template-create-card campaign-template-create-content-card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-pencil-square text-success fs-3 me-2"></i>
                            Contenu
                            @unless(isset($model) && $model->id)
                                <span class="badge badge-light-success fs-8 ms-2">Contenu TCL pré-rempli</span>
                            @endunless
                        </h3>
                    </div>
                    <div class="card-body border-top p-9">

                        {{-- Create starts from current public TCL institutional copy. Operational
                             figures stay empty because prices, volumes and frequencies age quickly. --}}
                        @unless(isset($model) && $model->id)
                            <div class="bg-light-warning border-warning border border-dashed rounded p-4 mb-6 d-flex align-items-start gap-3">
                                <i class="bi bi-info-circle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
                                <div>
                                    <span class="fw-bold text-gray-800">Contenu TCL vérifié</span><br>
                                    <span class="text-muted fs-7">
                                        Le point de départ reprend les informations institutionnelles publiées sur
                                        <a href="https://tcltransport.com/" target="_blank" rel="noopener noreferrer">tcltransport.com</a>.
                                        Les prix, volumes, fréquences et chiffres datés ne sont pas pré-remplis.
                                    </span>
                                </div>
                            </div>
                        @endunless

                        @if(isset($model) && $model->id && ! $builderHasAiKey)
                            <div class="bg-light-warning border-warning border border-dashed rounded p-4 mb-6 d-flex align-items-start gap-3">
                                <i class="bi bi-exclamation-triangle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
                                <div>
                                    <span class="fw-bold text-gray-800">Assistant IA non configuré</span><br>
                                    <span class="text-muted fs-7">
                                        L'assistant IA n'est pas activé. Vous pouvez composer le contenu manuellement ci-dessous.
                                    </span>
                                </div>
                            </div>
                        @endif

                        @if(isset($model) && $model->id)
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Brief de contenu <span class="text-muted fs-7">(pour l'IA)</span></label>
                            <textarea id="builder_brief_input"
                                      class="form-control form-control-solid"
                                      rows="3"
                                      maxlength="5000"
                                      placeholder="Ex : Prospection transitaires France → Espagne/Portugal, insister sur la fréquence des départs et le suivi documentaire."></textarea>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="form-text text-muted">Décrivez le message en quelques phrases (20 caractères minimum) — l'IA rédige le contenu ci-dessous, à ajuster librement ensuite.</div>
                                <button type="button"
                                        class="btn btn-sm btn-primary flex-shrink-0 ms-3"
                                        id="builder_ai_generate_btn"
                                        data-kt-indicator="off"
                                        @unless($builderHasAiKey) disabled @endunless>
                                    <span class="indicator-label">
                                        <i class="bi bi-magic me-1"></i>
                                        Générer avec l'IA
                                    </span>
                                    <span class="indicator-progress">
                                        Génération…
                                        <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <hr class="my-7">
                        @endif

                        <div id="builder_slots_root">

                            {{-- hero_title --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Titre principal</label>
                                <input type="text" id="slot_hero_title" class="form-control form-control-solid"
                                       maxlength="{{ $builderCatalog['slotSchema']['hero_title']['max'] }}" />
                            </div>

                            {{-- intro --}}
                            <div class="fv-row mb-7">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="fw-semibold fs-6 m-0">Paragraphes d'introduction</label>
                                    <button type="button" class="btn btn-sm btn-light-primary" id="slot_intro_add">
                                        <i class="bi bi-plus-lg"></i> Ajouter
                                    </button>
                                </div>
                                <div id="slot_intro_list"></div>
                                <div class="form-text text-muted">
                                    {{ $builderCatalog['slotSchema']['intro']['min'] }} à {{ $builderCatalog['slotSchema']['intro']['max'] }} paragraphes.
                                </div>
                            </div>

                            {{-- bullets --}}
                            <div class="fv-row mb-7">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="fw-semibold fs-6 m-0">Pourquoi TCL Transport (arguments)</label>
                                    <button type="button" class="btn btn-sm btn-light-primary" id="slot_bullets_add">
                                        <i class="bi bi-plus-lg"></i> Ajouter
                                    </button>
                                </div>
                                <div id="slot_bullets_list"></div>
                                <div class="form-text text-muted">
                                    {{ $builderCatalog['slotSchema']['bullets']['min'] }} à {{ $builderCatalog['slotSchema']['bullets']['max'] }} arguments courts.
                                </div>
                            </div>

                            {{-- middle-specific — only the active one is visible; inactive blocks stay
                                 disabled so they're excluded from any future FormData collection
                                 (defense in depth — none of these controls carry a name attribute,
                                 the server-side BuilderStateValidator is the single validation
                                 authority for builder_state). --}}
                            <div id="slot_middle_process" class="builder-middle-slot fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Chaîne logistique (3 étapes)</label>
                                <div id="slot_process_steps_list"></div>
                                <label class="fw-semibold fs-6 mt-4 mb-2" for="slot_process_highlight">Message clé</label>
                                <textarea id="slot_process_highlight"
                                          class="form-control form-control-solid form-control-sm"
                                          rows="2"
                                          maxlength="{{ $builderCatalog['slotSchema']['process_highlight']['max'] }}"></textarea>
                            </div>

                            <div id="slot_middle_departures" class="builder-middle-slot fv-row mb-7">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="fw-semibold fs-6 m-0">Départs (origine / fréquence)</label>
                                    <button type="button" class="btn btn-sm btn-light-primary" id="slot_departures_add">
                                        <i class="bi bi-plus-lg"></i> Ajouter
                                    </button>
                                </div>
                                <div id="slot_departures_list"></div>
                            </div>

                            <div id="slot_middle_kpi" class="builder-middle-slot fv-row mb-7 d-none">
                                <label class="fw-semibold fs-6 mb-2">Indicateurs clés (3)</label>
                                <div id="slot_kpis_list"></div>
                            </div>

                            <div id="slot_middle_benefits" class="builder-middle-slot fv-row mb-7 d-none">
                                <label class="fw-semibold fs-6 mb-2">Avantages (3)</label>
                                <div id="slot_benefits_list"></div>
                            </div>

                            {{-- closing_line — optional; falls back to the default sentence when left empty. --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Phrase de clôture <span class="text-muted fs-7">(optionnel)</span></label>
                                <textarea id="slot_closing_line"
                                          class="form-control form-control-solid form-control-sm"
                                          rows="2"
                                          maxlength="{{ $builderCatalog['slotSchema']['closing_line']['max'] }}"
                                          placeholder="N'hésitez pas à revenir vers nous pour toute question ou précision."></textarea>
                                <div class="form-text text-muted mt-1">
                                    Laissez vide pour conserver la phrase par défaut. La signature (« Cordialement, L'équipe TCL Transport ») reste fixe.
                                </div>
                            </div>

                            {{-- CTA --}}
                            <div class="row g-6">
                                <div class="col-lg-6">
                                    <label class="fw-semibold fs-6 mb-2">Intention du bouton d'action</label>
                                    <select id="slot_cta_intent" class="form-select form-select-solid">
                                        @foreach($builderCtaIntents as $key => $intent)
                                            <option value="{{ $key }}">{{ $intent['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <label class="fw-semibold fs-6 mb-2">Libellé du bouton</label>
                                    <input type="text" id="slot_cta_label" class="form-control form-control-solid" maxlength="60" />
                                </div>
                            </div>

                        </div>
                        {{-- end #builder_slots_root --}}

                    </div>
                </div>

                {{-- Live preview card --}}
                @if(isset($model) && $model->id)
                <aside class="card campaign-template-create-card campaign-template-create-preview-card" aria-label="Aperçu en direct">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-eye text-success fs-3 me-2"></i>
                            Aperçu en direct
                        </h3>
                        @unless(isset($model) && $model->id)
                            <div class="campaign-template-preview-tools" role="group" aria-label="Largeur de l'aperçu">
                                <button type="button" class="campaign-template-preview-size is-active" data-preview-size="desktop" aria-pressed="true">
                                    <i class="bi bi-display me-1"></i><span>Bureau</span>
                                </button>
                                <button type="button" class="campaign-template-preview-size" data-preview-size="mobile" aria-pressed="false">
                                    <i class="bi bi-phone me-1"></i><span>Mobile</span>
                                </button>
                            </div>
                        @endunless
                    </div>
                    <div class="card-body border-top p-0 @unless(isset($model) && $model->id) campaign-template-preview-canvas @endunless"
                         @unless(isset($model) && $model->id) data-preview-canvas="desktop" @endunless>
                        <div id="builder_preview_error" class="alert alert-danger d-none m-5" role="alert"></div>
                        <iframe id="builder_preview_iframe"
                                class="w-100 border-0"
                                @if(isset($model) && $model->id) style="min-height: 640px;" @endif
                                sandbox=""
                                title="Aperçu du générateur"></iframe>
                    </div>
                </aside>
                @endif

            </div>
            {{-- end #builder_pane --}}

            {{-- ══════════════════════════════════════════════════════════
                 CLASSIC — visible iff NOT $openInBuilder
                 ══════════════════════════════════════════════════════════ --}}
            <div id="classic_pane" class="{{ $openInBuilder ? 'd-none' : '' }}">

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
                            </div>
                            <div class="form-text text-muted mt-2 fs-8">
                                Les variables de personnalisation sont converties automatiquement par le driver d'envoi.
                            </div>
                        </div>
                    </div>
                    <div class="card-body border-top p-9">

                        {{-- html_content — TinyMCE WYSIWYG (fullpage : HTML email complet <html><head><style>).
                             `required` + `data-tinymce-html-field` render ONLY when the page opens in classic
                             mode — see the header comment at the top of this file. --}}
                        <div class="fv-row mb-0">
                            <label class="required fw-semibold fs-6 mb-2" for="html_content">Contenu HTML</label>

                            <textarea name="html_content"
                                      class="form-control form-control-solid font-monospace"
                                      rows="20"
                                      id="html_content"
                                      @unless($openInBuilder) data-tinymce-html-field required @endunless
                                      @if($openInBuilder) disabled @endif>{{ old('html_content', $model->html_content ?? '') }}</textarea>

                            <div class="form-text text-muted mt-1">
                                HTML complet de l'email. Les modèles composés avec le générateur ne contiennent aucun bloc de désabonnement :
                                Zoho Campaigns gère automatiquement le lien de désabonnement lors d'un envoi via Zoho. En développement local,
                                le driver d'envoi local ajoute lui-même un lien de désabonnement de secours, uniquement pour les tests.
                                Le placeholder historique <code>@verbatim{{unsubscribe_url}}@endverbatim</code> reste accepté ici pour un placement personnalisé en HTML classique (import Zoho, collage manuel).
                                Bouton <code>&lt;/&gt;</code> de la barre d'outils pour éditer le code source.
                            </div>
                        </div>

                    </div>
                </div>

            </div>
            {{-- end #classic_pane --}}

            @unless(isset($model) && $model->id)
                    </div>
                    {{-- end create editor stack --}}

                    <aside class="card campaign-template-create-card campaign-template-create-preview-card" aria-label="Aperçu en direct">
                        <div class="card-header border-0 pt-5">
                            <h3 class="card-title fw-bolder m-0">
                                <i class="bi bi-eye text-success fs-3 me-2"></i>
                                Aperçu en direct
                            </h3>
                            <div class="campaign-template-preview-tools" role="group" aria-label="Largeur de l'aperçu">
                                <button type="button" class="campaign-template-preview-size is-active" data-preview-size="desktop" aria-pressed="true">
                                    <i class="bi bi-display me-1"></i><span>Bureau</span>
                                </button>
                                <button type="button" class="campaign-template-preview-size" data-preview-size="mobile" aria-pressed="false">
                                    <i class="bi bi-phone me-1"></i><span>Mobile</span>
                                </button>
                            </div>
                        </div>
                        <div class="card-body border-top p-0 campaign-template-preview-canvas" data-preview-canvas="desktop">
                            <div id="builder_preview_error" class="alert alert-danger d-none m-5" role="alert"></div>
                            <iframe id="builder_preview_iframe"
                                    class="w-100 border-0"
                                    sandbox=""
                                    title="Aperçu du générateur"></iframe>
                        </div>
                    </aside>

                </div>
                {{-- end create workspace --}}
            @endunless

        </div>
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.campaign_templates.index'])

</form>

{{-- ── Out-of-form panes (edit mode only) ───────────────────────────────── --}}
{{-- External tab panes stay outside #form_crud so scoped forms do not nest. --}}
@if(isset($model) && $model->id)

    <div class="tab-pane fade" id="template_traductions" role="tabpanel">
        @include('backend.contents.campaign_templates.partials._traductions-tab', ['model' => $model])
    </div>

@endif

{{-- ── Builder state hydration — Js::from() only, never hand-built JSON ──── --}}
<script>
    window.__campaignTemplateBuilder = {
        catalog:         {!! \Illuminate\Support\Js::from($builderCatalog) !!},
        ctaIntents:      {!! \Illuminate\Support\Js::from($builderCtaIntents) !!},
        hasAiKey:        {!! \Illuminate\Support\Js::from($builderHasAiKey) !!},
        defaultState:    {!! \Illuminate\Support\Js::from($builderDefaultState) !!},
        initialState:    {!! \Illuminate\Support\Js::from($builderInitialState) !!},
        openInBuilder:   {!! \Illuminate\Support\Js::from($openInBuilder) !!},
        previewUrl:      {!! \Illuminate\Support\Js::from(route('admin.campaign_templates.builder_preview')) !!},
        suggestUrl:      {!! \Illuminate\Support\Js::from(route('admin.campaign_templates.builder_suggest')) !!}
    };
</script>

<style>
    /* Create-only shell inspired by the approved prototype. Edit keeps the
       established CRUD composition because every selector is rooted here. */
    .campaign-template-create-head h1 {
        letter-spacing: -.025em;
    }
    .campaign-template-create-steps {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        overflow: hidden;
        background: var(--bs-body-bg, #fff);
        border: 1px solid var(--bs-gray-300, #e4e6ef);
        border-radius: .85rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 8px 24px rgba(16, 24, 40, .04);
    }
    .campaign-template-create-step {
        position: relative;
        display: flex;
        align-items: center;
        gap: .85rem;
        min-width: 0;
        padding: 1.1rem 1.4rem;
    }
    .campaign-template-create-step:not(:last-child)::after {
        position: absolute;
        top: 1rem;
        right: 0;
        bottom: 1rem;
        width: 1px;
        background: var(--bs-gray-300, #e4e6ef);
        content: '';
    }
    .campaign-template-create-step-number {
        display: inline-flex;
        flex: 0 0 2rem;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        color: var(--bs-gray-600, #7e8299);
        font-weight: 700;
        background: var(--bs-gray-200, #eff2f5);
        border-radius: 50%;
    }
    .campaign-template-create-step.is-active .campaign-template-create-step-number {
        color: #fff;
        background: var(--bs-primary, #009ef7);
    }
    .campaign-template-create-step.is-complete .campaign-template-create-step-number {
        color: var(--bs-success, #50cd89);
        background: var(--bs-success-light, #e8fff3);
    }
    .campaign-template-create-step strong,
    .campaign-template-create-step small {
        display: block;
    }
    .campaign-template-create-step strong {
        color: var(--bs-gray-800, #3f4254);
        font-size: .95rem;
    }
    .campaign-template-create-step small {
        overflow: hidden;
        margin-top: .15rem;
        color: var(--bs-gray-600, #7e8299);
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .campaign-template-create-workspace {
        display: grid;
        grid-template-columns: minmax(0, 1.08fr) minmax(360px, .92fr);
        gap: 1.25rem;
        align-items: start;
    }
    .campaign-template-create-editor-stack {
        display: grid;
        grid-column: 1;
        gap: 1.25rem;
        min-width: 0;
    }
    .campaign-template-create-editor-stack > #builder_pane:not(.d-none),
    .campaign-template-create-editor-stack > #classic_pane:not(.d-none) {
        display: grid;
        gap: 1.25rem;
    }
    .campaign-template-create-editor-stack .campaign-template-create-card {
        grid-column: 1;
        margin-bottom: 0 !important;
    }
    .campaign-template-create-workspace .campaign-template-create-card {
        border: 1px solid var(--bs-gray-300, #e4e6ef);
        border-radius: .85rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 8px 24px rgba(16, 24, 40, .04);
    }
    .campaign-template-create-workspace .campaign-template-create-card > .card-header {
        min-height: auto;
        padding: 1rem 1.25rem !important;
    }
    .campaign-template-create-workspace .campaign-template-create-card > .card-body {
        padding: 1.25rem !important;
    }
    .campaign-template-create-workspace .campaign-template-create-preview-card > .card-body {
        padding: 0 !important;
    }
    .campaign-template-create-card-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        margin-right: .65rem;
        color: var(--bs-primary, #009ef7);
        background: var(--bs-primary-light, #f1faff);
        border-radius: .55rem;
    }
    .campaign-template-create-ai-panel {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: .85rem;
        align-items: end;
        padding: 1rem;
        background: var(--bs-primary-light, #f1faff);
        border: 1px solid rgba(0, 158, 247, .18);
        border-radius: .7rem;
    }
    .campaign-template-create-ai-panel textarea {
        min-height: 76px;
        background: var(--bs-body-bg, #fff) !important;
    }
    .campaign-template-create-workspace .campaign-template-create-preview-card {
        position: sticky;
        z-index: 2;
        top: 6.5rem;
        grid-column: 2 !important;
        overflow: hidden;
    }
    .campaign-template-preview-tools {
        display: inline-flex;
        gap: .2rem;
        padding: .2rem;
        background: var(--bs-gray-200, #eff2f5);
        border-radius: .55rem;
    }
    .campaign-template-preview-size {
        padding: .45rem .7rem;
        color: var(--bs-gray-600, #7e8299);
        font-size: .85rem;
        font-weight: 600;
        background: transparent;
        border: 0;
        border-radius: .4rem;
    }
    .campaign-template-preview-size:hover,
    .campaign-template-preview-size:focus-visible {
        color: var(--bs-gray-800, #3f4254);
    }
    .campaign-template-preview-size:focus-visible {
        outline: 2px solid var(--bs-primary, #009ef7);
        outline-offset: 1px;
    }
    .campaign-template-preview-size.is-active {
        color: var(--bs-gray-800, #3f4254);
        background: var(--bs-body-bg, #fff);
        box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
    }
    .campaign-template-create-workspace .campaign-template-preview-canvas {
        min-height: 680px;
        padding: 1.25rem !important;
        overflow: auto;
        background: var(--bs-gray-200, #eff2f5);
    }
    .campaign-template-create-workspace .campaign-template-preview-canvas iframe {
        display: block;
        max-width: 100%;
        min-height: 640px;
        margin: 0 auto;
        background: #fff;
        box-shadow: 0 6px 24px rgba(28, 43, 73, .12);
        transition: width .2s ease;
    }
    .campaign-template-create-workspace .campaign-template-preview-canvas[data-preview-canvas="mobile"] iframe {
        width: 390px !important;
    }

    .builder-variant-card {
        cursor: pointer;
        border: 2px solid #e4e6ef;
        border-radius: 8px;
        overflow: hidden;
        background: #ffffff;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .builder-variant-card:hover {
        border-color: #b5c3ff;
    }
    .builder-variant-card.is-active {
        border-color: #0548a5;
        box-shadow: 0 0 0 3px rgba(5, 72, 165, .12);
    }
    .builder-variant-preview-frame {
        position: relative;
        width: 100%;
        height: 200px;
        overflow: hidden;
        background-color: #f5f8fa;
    }
    .builder-variant-preview-frame iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 640px;
        height: 900px;
        border: 0;
        transform: scale(0.34);
        transform-origin: top left;
        pointer-events: none;
    }
    .builder-variant-card-label {
        padding: 8px 12px;
        font-weight: 600;
        font-size: .925rem;
        color: #3f4254;
        text-align: center;
        border-top: 1px solid #e4e6ef;
    }
    .builder-middle-pill {
        border: 1px solid #e4e6ef;
        background-color: #ffffff;
        color: #3f4254;
    }
    .builder-middle-pill.is-active {
        background-color: #0548a5;
        border-color: #0548a5;
        color: #ffffff;
    }

    @media (max-width: 1199.98px) {
        .campaign-template-create-workspace {
            grid-template-columns: minmax(0, 1fr);
        }
        .campaign-template-create-workspace .campaign-template-create-preview-card {
            position: relative;
            top: auto;
            grid-column: 1 !important;
            grid-row: auto;
        }
        .campaign-template-create-workspace .campaign-template-preview-canvas {
            min-height: 560px;
        }
        .campaign-template-create-workspace .campaign-template-preview-canvas iframe {
            min-height: 520px;
        }
    }

    @media (max-width: 767.98px) {
        .campaign-template-create-step {
            gap: .5rem;
            padding: .85rem .65rem;
        }
        .campaign-template-create-step-number {
            flex-basis: 1.75rem;
            width: 1.75rem;
            height: 1.75rem;
        }
        .campaign-template-create-step strong {
            font-size: .78rem;
        }
        .campaign-template-create-step small {
            display: none;
        }
        .campaign-template-create-ai-panel {
            grid-template-columns: minmax(0, 1fr);
        }
        .campaign-template-create-ai-panel .btn {
            width: 100%;
        }
        .campaign-template-preview-tools span {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .campaign-template-create-workspace .campaign-template-preview-canvas {
            min-height: 500px;
            padding: .75rem !important;
        }
        .campaign-template-create-workspace .campaign-template-preview-canvas iframe {
            min-height: 475px;
        }
    }
</style>

@push('scripts')
    @php
        $assetVersion = static function (string $asset): string {
            $path = public_path($asset);
            $modifiedAt = file_exists($path) ? filemtime($path) : false;

            return $modifiedAt === false ? '1' : (string) $modifiedAt;
        };
    @endphp
    <script src="{{ asset('assets/plugins/custom/tinymce/tinymce.js') }}?v={{ $assetVersion('assets/plugins/custom/tinymce/tinymce.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/tinymce-html-field.js') }}?v={{ $assetVersion('assets/js/custom/backend/tinymce-html-field.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/campaign-template-builder.js') }}?v={{ $assetVersion('assets/js/custom/backend/campaign-template-builder.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}?v={{ $assetVersion('assets/js/custom/backend/crud-tabs.js') }}"></script>
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
    <script src="{{ asset('assets/js/custom/backend/campaign-template-translations.js') }}?v={{ $assetVersion('assets/js/custom/backend/campaign-template-translations.js') }}"></script>
@endpush

</x-default-layout>
