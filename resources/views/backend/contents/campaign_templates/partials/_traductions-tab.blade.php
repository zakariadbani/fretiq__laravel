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
     data-lang="en">

    {{-- ── Header / status row ──────────────────────────────────────────── --}}
    <div class="card-header border-0 pt-5">
        <h3 class="card-title align-items-start flex-column">
            <span class="card-label fw-bold fs-3 mb-1">
                <i class="bi bi-translate text-primary me-2 fs-3"></i>
                Traductions IA
            </span>
            <span class="text-muted mt-1 fw-semibold fs-7">
                Version anglaise du modèle (FR → EN via Gemini)
            </span>
        </h3>
        <div class="card-toolbar d-flex align-items-center gap-3 flex-wrap">
            {{-- Provenance badge --}}
            <span class="badge {{ $provenanceBadge }}" id="tr-provenance-badge">
                {{ $provenanceLabel }}
            </span>

            {{-- Review state + toggle (only when a translation exists) --}}
            @if($tr)
                <span class="badge {{ $reviewBadge }}" id="tr-review-state">
                    {{ $reviewLabel }}
                </span>
                <button type="button"
                        class="btn btn-sm btn-light btn-active-light-primary"
                        id="tr-review-btn">
                    {{ $reviewBtnLabel }}
                </button>
            @else
                <span class="badge badge-light-secondary" id="tr-review-state">Non relue</span>
                <button type="button"
                        class="btn btn-sm btn-light btn-active-light-primary d-none"
                        id="tr-review-btn">
                    Marquer comme relue
                </button>
            @endif
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

        {{-- ── Compare toggle button ─────────────────────────────────────── --}}
        <div class="mb-5">
            <button type="button"
                    class="btn btn-sm btn-light-primary btn-active-primary"
                    id="tr-compare-toggle">
                <i class="bi bi-layout-split me-1"></i>
                Comparer FR / EN
            </button>
        </div>

        {{-- ── Editing surface ───────────────────────────────────────────── --}}
        <div id="tr-editing-surface" class="row g-7">

            {{-- Left: FR base (read-only reference) --}}
            <div class="col-12 col-lg-6 tr-fr-col">
                <h5 class="fw-bold text-gray-700 mb-5">
                    <span class="badge badge-light-secondary me-2">FR</span>
                    Référence (version française)
                </h5>

                {{-- FR subject --}}
                <div class="fv-row mb-7">
                    <label class="fw-semibold fs-6 mb-2 text-muted">Sujet</label>
                    <div class="form-control form-control-solid bg-light-secondary text-muted">
                        {{ $model->subject ?? '—' }}
                    </div>
                </div>

                {{-- FR preview_text --}}
                <div class="fv-row mb-7">
                    <label class="fw-semibold fs-6 mb-2 text-muted">Texte de prévisualisation</label>
                    <div class="form-control form-control-solid bg-light-secondary text-muted">
                        {{ $model->preview_text ?? '—' }}
                    </div>
                </div>

                {{-- FR body note --}}
                <div class="fv-row mb-0">
                    <label class="fw-semibold fs-6 mb-2 text-muted">Contenu HTML</label>
                    <div class="bg-light-secondary border border-dashed rounded p-4 text-muted fs-7">
                        <i class="bi bi-info-circle me-1"></i>
                        Voir l'onglet <strong>Général</strong> pour éditer le HTML source français.
                    </div>
                </div>
            </div>

            {{-- Right: EN editable --}}
            <div class="col-12 col-lg-6 tr-en-col">
                <h5 class="fw-bold text-gray-700 mb-5">
                    <span class="badge badge-light-info me-2">EN</span>
                    Traduction anglaise <span class="text-muted fs-7 fw-normal">(éditable)</span>
                </h5>

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
                    <label class="fw-semibold fs-6 mb-2" for="tr_en_html">Contenu HTML (EN)</label>
                    <textarea id="tr_en_html"
                              class="form-control form-control-solid font-monospace"
                              rows="18"
                              data-tinymce-html-field>{{ $tr->html_content ?? '' }}</textarea>
                </div>

            </div>
        </div>
        {{-- end editing surface --}}

        {{-- ── Action buttons ───────────────────────────────────────────── --}}
        <div class="d-flex gap-3 flex-wrap mt-8 pt-6 border-top">

            {{-- Translate / re-translate --}}
            <button type="button"
                    class="btn btn-primary"
                    id="tr-translate-btn"
                    data-kt-indicator="off"
                    @if($emptyBody || !$hasKey) disabled @endif>
                <span class="indicator-label">
                    <i class="bi bi-magic me-1"></i>
                    {{ $tr ? 'Mettre à jour la traduction' : 'Traduire avec l\'IA' }}
                </span>
                <span class="indicator-progress">
                    Traduction en cours…
                    <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                </span>
            </button>

            {{-- Save manual edits --}}
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

        </div>

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

{{-- Compare-toggle CSS: two-column layout on desktop --}}
<style>
    #tr-editing-surface.tr-two-col .tr-fr-col,
    #tr-editing-surface.tr-two-col .tr-en-col {
        flex: 0 0 50%;
        max-width: 50%;
    }
    @media (max-width: 991px) {
        #tr-editing-surface.tr-two-col .tr-fr-col,
        #tr-editing-surface.tr-two-col .tr-en-col {
            flex: 0 0 100%;
            max-width: 100%;
        }
    }
</style>
