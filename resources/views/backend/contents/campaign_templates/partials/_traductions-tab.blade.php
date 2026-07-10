{{--
    _traductions-tab.blade.php — Traductions IA pane for CampaignTemplate edit page.

    Required: $model (CampaignTemplate, already loaded, id set).

    This pane is relocated INTO #template_tab_content by crud-tabs.js so it participates
    in Bootstrap tab toggling. CRITICAL: all EN inputs carry NO `name` attribute — they
    would pollute the base-template save FormData if they did.

    JS controller: public/assets/js/custom/backend/campaign-template-translations.js
--}}

@php
    $tr       = $model->translationFor('en');
    $stale    = $tr ? $model->staleFieldsFor($tr) : [];
    $hasKey   = filled(config('services.gemini.api_key'));
    $emptyBody = blank($model->html_content);

    $provenanceLabel = $tr
        ? ($tr->is_ai_generated ? 'Traduction automatique' : 'Modifiée manuellement')
        : 'Non traduite';
    $provenanceBadge = $tr
        ? ($tr->is_ai_generated ? 'badge-light-info' : 'badge-light-primary')
        : 'badge-light-secondary';

    $isReviewed     = $tr && $tr->reviewed_at;
    $reviewLabel    = $isReviewed ? 'Relue ' . $tr->reviewed_at->diffForHumans() : 'Non relue';
    $reviewBadge    = $isReviewed ? 'badge-light-success' : 'badge-light-secondary';
    $reviewBtnLabel = $isReviewed ? 'Marquer comme non relue' : 'Marquer comme relue';

    $translateUrl = route('admin.campaign_templates.translate',     $model->id);
    $saveUrl      = route('admin.campaign_templates.saveTranslation', $model->id);
    $reviewUrl    = route('admin.campaign_templates.markReviewed',  $model->id);

    $staleFieldLabels = [
        'subject' => 'Objet modifié',
        'preview' => 'Aperçu modifié',
        'body'    => 'Contenu modifié',
    ];

    $frSubjectReady = filled($model->subject);
    $frBodyReady    = filled($model->html_content);
    $frReady        = $frSubjectReady && $frBodyReady;
    $enSubjectReady = $tr && filled($tr->subject);
    $enBodyReady    = $tr && filled($tr->html_content);
    $enReady        = $enSubjectReady && $enBodyReady;
    $frUpdatedLabel = $model->updated_at ? $model->updated_at->format('d/m/Y H:i') : '—';
    $enUpdatedLabel = ($tr && $tr->updated_at) ? $tr->updated_at->format('d/m/Y H:i') : 'Non créée';

    $directionSource = (!$frReady && $enReady) ? 'en' : 'fr';
    $directionTarget = $directionSource === 'en' ? 'fr' : 'en';
    $directionLabel  = $directionTarget === 'fr' ? 'Générer FR depuis EN' : ($tr ? 'Mettre à jour EN depuis FR' : 'Générer EN depuis FR');
    $directionDisabled = !$hasKey || ($directionSource === 'fr' ? !$frBodyReady : !$enBodyReady);

    $translationStateLabel = $frReady && $enReady
        ? (count($stale) ? 'Versions à synchroniser' : 'Versions prêtes')
        : 'Version à compléter';
    $translationStateBadge = $frReady && $enReady
        ? (count($stale) ? 'badge-light-warning' : 'badge-light-success')
        : 'badge-light-primary';
    $nextActionLabel = $directionTarget === 'fr'
        ? 'Créer la version française depuis la version anglaise importée'
        : ($enReady ? 'Mettre à jour la version anglaise depuis le français' : 'Créer la version anglaise depuis le français');
@endphp

{{-- ── Root card — JS reads data-* attrs ───────────────────────────────── --}}
<div class="card"
     id="traductions-pane-root"
     data-has-en-translation="{{ $tr ? 1 : 0 }}"
     data-en-stale="{{ ($tr && count($stale)) ? 1 : 0 }}"
     data-ai="{{ $tr && $tr->is_ai_generated ? 1 : 0 }}"
     data-translate-url="{{ $translateUrl }}"
     data-save-url="{{ $saveUrl }}"
     data-review-url="{{ $reviewUrl }}"
     data-lang="en"
     data-source-language="{{ $directionSource }}"
     data-target-language="{{ $directionTarget }}">

    {{-- ── Header / status row ──────────────────────────────────────────── --}}
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold fs-3 mb-1">
                <i class="bi bi-translate text-primary me-2 fs-3"></i>
                Versions linguistiques
            </span>
            <span class="text-muted mt-1 fw-semibold fs-7">
                Gérez les versions FR et EN du modèle selon la langue disponible.
            </span>
        </h3>
        <div class="card-toolbar d-flex align-items-center gap-2 flex-wrap">
            <span class="badge {{ $translationStateBadge }}">
                {{ $translationStateLabel }}
            </span>
            <span class="badge {{ $provenanceBadge }}" id="tr-provenance-badge">
                {{ $provenanceLabel }}
            </span>
            <span class="badge {{ $reviewBadge }}" id="tr-review-state">
                {{ $reviewLabel }}
            </span>
        </div>
    </div>

    <div class="card-body border-top p-9">

        {{-- ── Setup notice (no API key) ────────────────────────────────── --}}
        @if(!$hasKey)
            <div class="bg-light-warning border-warning border border-dashed rounded p-6 mb-7 d-flex align-items-start gap-3">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
                <div>
                    <span class="fw-bold text-gray-800">Clé API Gemini non configurée</span><br>
                    <span class="text-muted fs-7">
                        Renseignez <code>GEMINI_API_KEY</code> dans votre <code>.env</code> pour activer la traduction automatique.
                    </span>
                </div>
            </div>
        @endif

        {{-- ── Server stale banner ───────────────────────────────────────── --}}
        @if($tr && count($stale))
            <div class="bg-light-warning border-warning border border-dashed rounded p-6 mb-7 d-flex align-items-start gap-3"
                 id="en-stale-server">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
                <div>
                    <span class="fw-bold text-gray-800">La version anglaise n'est plus à jour avec la version française.</span><br>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        @foreach($stale as $field)
                            <span class="badge badge-light-warning">{{ $staleFieldLabels[$field] ?? $field }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- ── Live stale banner (always rendered, hidden; JS reveals it) ── --}}
        <div id="en-stale-live"
             class="bg-light-warning border-warning border border-dashed rounded p-6 mb-7 d-flex align-items-start gap-3 d-none">
            <i class="bi bi-exclamation-triangle-fill text-warning fs-3 mt-1 flex-shrink-0"></i>
            <div>
                <span class="fw-bold text-gray-800">Vous avez modifié la version française</span><br>
                <span class="text-muted fs-7">
                    Enregistrez les modifications FR puis retraduisez pour mettre à jour la version anglaise.
                </span>
            </div>
        </div>

        {{-- ── Command bar ───────────────────────────────────────────────── --}}
        <div class="tr-command-bar bg-light-primary rounded border border-primary border-dashed p-5 mb-7">
            <div class="d-flex flex-column flex-xl-row justify-content-between gap-5 align-items-xl-center">
                <div class="d-flex align-items-start gap-4">
                    <div class="symbol symbol-45px symbol-circle flex-shrink-0">
                        <span class="symbol-label bg-primary text-white">
                            <i class="bi bi-magic fs-2 text-white"></i>
                        </span>
                    </div>
                    <div>
                        <div class="text-uppercase text-primary fw-bold fs-8 mb-1">Prochaine étape</div>
                        <div class="fw-bold text-gray-900 fs-5">{{ $nextActionLabel }}</div>
                        <div class="text-muted fs-7 mt-1">
                            Traduisez, ajustez si nécessaire, enregistrez puis marquez la version comme relue.
                        </div>
                        @unless($tr)
                            <div class="badge badge-light-primary mt-3">
                                Aucune version anglaise enregistrée pour l’instant.
                            </div>
                        @endunless
                    </div>
                </div>

                <div class="d-flex gap-3 flex-wrap justify-content-xl-end">
                    <button type="button"
                            class="btn btn-primary"
                            id="tr-translate-btn"
                            data-kt-indicator="off"
                            data-source-language="{{ $directionSource }}"
                            data-target-language="{{ $directionTarget }}"
                            @if($directionDisabled) disabled @endif>
                        <span class="indicator-label">
                            <i class="bi bi-magic me-1"></i>
                            {{ $directionLabel }}
                        </span>
                        <span class="indicator-progress">
                            Traduction en cours…
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                        </span>
                    </button>

                    <button type="button"
                            class="btn btn-success"
                            id="tr-save-btn">
                        <span class="indicator-label">
                            <i class="bi bi-check-circle me-1"></i>
                            Enregistrer la traduction
                        </span>
                        <span class="indicator-progress">
                            Enregistrement…
                            <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                        </span>
                    </button>

                    <button type="button"
                            class="btn btn-light btn-active-light-primary {{ $tr ? '' : 'd-none' }}"
                            id="tr-review-btn">
                        {{ $reviewBtnLabel }}
                    </button>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-5">
            <div>
                <div class="fw-bold text-gray-800">Versions linguistiques du modèle</div>
                <div class="text-muted fs-7">Français et anglais sont gérés comme deux versions éditables.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge {{ $frReady ? 'badge-light-success' : 'badge-light-warning' }}">
                    {{ $frReady ? 'FR créée' : 'FR manquante' }}
                </span>
                <span class="badge {{ $enReady ? 'badge-light-success' : 'badge-light-danger' }}">
                    {{ $enReady ? 'EN créée' : 'EN manquante' }}
                </span>
                @if($tr && count($stale))
                    <span class="badge badge-light-warning">EN obsolète</span>
                @endif
            </div>
        </div>

        {{-- ── Editing surface ───────────────────────────────────────────── --}}
        <div id="tr-editing-surface" class="row g-7">

            {{-- Left: FR base (read-only reference) --}}
            <div class="col-12 col-lg-6 tr-fr-col">
                <div class="card border border-dashed border-gray-300 h-100">
                    <div class="card-header border-0 pt-5">
                        <h5 class="card-title align-items-start flex-column m-0">
                            <span class="fw-bold text-gray-800">
                                <span class="badge badge-light-secondary me-2">FR</span>
                                Version française
                            </span>
                            <span class="text-muted fs-8 mt-1">Dernière mise à jour : {{ $frUpdatedLabel }}</span>
                        </h5>
                        <div class="card-toolbar">
                            <span class="badge {{ $frReady ? 'badge-light-success' : 'badge-light-warning' }}">
                                {{ $frReady ? 'Créée' : 'Incomplète' }}
                            </span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2" for="tr_fr_subject">Sujet (FR)</label>
                            <input type="text"
                                   id="tr_fr_subject"
                                   class="form-control form-control-solid"
                                   value="{{ $model->subject ?? '' }}"
                                   placeholder="Sujet français…"
                                   readonly />
                        </div>

                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2" for="tr_fr_preview">Texte de prévisualisation (FR)</label>
                            <input type="text"
                                   id="tr_fr_preview"
                                   class="form-control form-control-solid"
                                   value="{{ $model->preview_text ?? '' }}"
                                   placeholder="Aperçu français…"
                                   maxlength="255"
                                   readonly />
                        </div>

                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <div class="fw-bold text-gray-700">WYSIWYG français</div>
                                <div class="text-muted fs-8">Lecture seule — éditez le FR depuis l’onglet Général.</div>
                            </div>
                            <a href="#template_general"
                               class="btn btn-sm btn-light"
                               data-template-open-tab="#template_general">
                                Modifier le FR
                            </a>
                        </div>

                        @if($frBodyReady)
                            <textarea id="tr_fr_html"
                                      class="form-control form-control-solid font-monospace"
                                      rows="18"
                                      data-tinymce-html-field
                                      data-tinymce-readonly
                                      aria-label="WYSIWYG français en lecture seule">{{ $model->html_content ?? '' }}</textarea>
                        @else
                            <div class="tr-version-preview d-flex align-items-center justify-content-center border rounded bg-light text-muted text-center p-5">
                                Aucun contenu HTML français n’est encore défini.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right: EN editable --}}
            <div class="col-12 col-lg-6 tr-en-col">
                <div class="card border border-primary border-dashed h-100">
                    <div class="card-header border-0 pt-5">
                        <h5 class="card-title align-items-start flex-column m-0">
                            <span class="fw-bold text-gray-800">
                                <span class="badge badge-light-info me-2">EN</span>
                                Version anglaise
                            </span>
                            <span class="text-muted fs-8 mt-1">Dernière mise à jour : {{ $enUpdatedLabel }}</span>
                        </h5>
                        <div class="card-toolbar d-flex gap-2 flex-wrap">
                            <span class="badge {{ $enReady ? 'badge-light-success' : 'badge-light-danger' }}">
                                {{ $enReady ? 'Créée' : 'Non créée' }}
                            </span>
                            @if($tr && count($stale))
                                <span class="badge badge-light-warning">Obsolète</span>
                            @endif
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        @unless($enReady)
                            <div class="alert alert-dismissible bg-light-warning border border-warning border-dashed d-flex align-items-start p-4 mb-5">
                                <i class="bi bi-exclamation-circle-fill text-warning fs-2 me-3"></i>
                                <div class="text-gray-800 fs-7">
                                    <div class="fw-bold">La version anglaise n’est pas encore créée.</div>
                                    Cliquez sur <strong>Traduire avec l’IA</strong>, puis enregistrez la traduction.
                                </div>
                            </div>
                        @endunless

                        {{-- EN subject — NO name attr --}}
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2" for="tr_en_subject">Sujet (EN)</label>
                            <input type="text"
                                   id="tr_en_subject"
                                   class="form-control form-control-solid"
                                   value="{{ $tr->subject ?? '' }}"
                                   placeholder="Subject line in English…" />
                        </div>

                        {{-- EN preview_text — NO name attr --}}
                        <div class="fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2" for="tr_en_preview">Texte de prévisualisation (EN)</label>
                            <input type="text"
                                   id="tr_en_preview"
                                   class="form-control form-control-solid"
                                   value="{{ $tr->preview_text ?? '' }}"
                                   placeholder="Preview text in English…"
                                   maxlength="255" />
                        </div>

                        {{-- EN html_content — NO name attr; data-tinymce-html-field auto-inits a 2nd TinyMCE --}}
                        <div class="fv-row mb-0">
                            <div class="mb-3">
                                <label class="fw-semibold fs-6 mb-1" for="tr_en_html">WYSIWYG anglais (EN)</label>
                                <div class="text-muted fs-8">Éditable — enregistrez avec le bouton vert en haut.</div>
                            </div>
                            <textarea id="tr_en_html"
                                      class="form-control form-control-solid font-monospace"
                                      rows="18"
                                      data-tinymce-html-field>{{ $tr->html_content ?? '' }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        {{-- end editing surface --}}

        {{-- ── Aperçu EN ─────────────────────────────────────────────────── --}}
        <div class="mt-8 pt-6 border-top">

            <div class="d-flex align-items-center gap-3 mb-4">
                <span class="fw-bold fs-6 text-gray-700">Aperçu anglais</span>
                <button type="button"
                        class="btn btn-sm btn-light btn-active-light-info"
                        id="tr-preview-toggle">
                    <i class="bi bi-eye me-1"></i>
                    Afficher / masquer l'aperçu EN
                </button>
            </div>

            <div id="tr-preview-wrapper" class="d-none">
                @if($tr && $tr->html_content)
                    <iframe
                        id="tr-en-iframe"
                        srcdoc="{{ $tr->html_content }}"
                        class="w-100 border-0 rounded"
                        style="min-height: 400px;"
                        sandbox="allow-same-origin"
                        title="Aperçu EN du modèle"></iframe>
                @else
                    <iframe
                        id="tr-en-iframe"
                        srcdoc=""
                        class="w-100 border-0 rounded d-none"
                        style="min-height: 400px;"
                        sandbox="allow-same-origin"
                        title="Aperçu EN du modèle"></iframe>
                    <div id="tr-preview-empty" class="text-center py-8 text-muted">
                        <i class="bi bi-envelope-x fs-2x mb-3 d-block"></i>
                        Aucune traduction anglaise disponible — traduisez d'abord avec l'IA.
                    </div>
                @endif
            </div>

        </div>

    </div>
    {{-- end card-body --}}

</div>
{{-- end #traductions-pane-root --}}

{{-- Auto-resize EN iframe when loaded --}}
<script>
    (function () {
        document.addEventListener('DOMContentLoaded', function () {
            var iframe = document.getElementById('tr-en-iframe');
            if (!iframe) return;
            iframe.addEventListener('load', function () {
                try {
                    var h = iframe.contentDocument.body.scrollHeight;
                    if (h > 0) iframe.style.height = (h + 40) + 'px';
                } catch (e) {}
            });
        });
    })();
</script>

{{-- Versions layout: FR source left, EN editable right --}}
<style>
    .tr-command-bar {
        background: linear-gradient(135deg, #eef6ff 0%, #f8fbff 100%);
    }

    .tr-command-bar .btn {
        white-space: nowrap;
    }

    .tr-version-preview {
        min-height: 520px;
        height: 520px;
    }

    @media (max-width: 991px) {
        .tr-version-preview {
            min-height: 360px;
            height: 360px;
        }
    }
</style>
