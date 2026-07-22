"use strict";

/**
 * campaign-template-builder.js — CampaignTemplate "builder" (create/edit).
 *
 * Guided composer for prospection email templates: header/footer variant
 * cards, a middle-block picker (departures | kpi | benefits), an AI
 * ("Générer avec l'IA") content-brief flow, manual slot editing with
 * repeater bounds mirroring the server (App\Services\Campaign\TemplateBuilder\
 * SectionCatalog / BuilderStateValidator — the single validation authority),
 * a debounced live preview, and the builder ⇄ classic (raw HTML / TinyMCE)
 * mode toggle.
 *
 * Dependencies (all global): jQuery, axios, Swal, KTUtil, tinymce (via
 * tinymce-html-field.js, loaded before this file — see form.blade.php).
 *
 * State hydration: window.__campaignTemplateBuilder, written by
 * resources/views/backend/contents/campaign_templates/crud/form.blade.php
 * via Illuminate\Support\Js::from() — never hand-built JSON.
 *
 * builder_state contract (mirrors BuilderStateValidator::validate() input):
 *   {
 *     header_variant, hero_variant, middle_variant, footer_variant,
 *     preview_text,
 *     cta: { intent, label },
 *     slots: {
 *       hero_title, intro: [...], bullets: [...],
 *       departures: [{origin,frequency}] | kpis: [{value,label}] | benefits: [{title,text}]
 *     }
 *   }
 *
 * Slot inputs deliberately carry NO `name` attribute and NO `required`
 * attribute — the server-side BuilderStateValidator is the single
 * validation authority for builder_state; the only thing actually
 * submitted is the serialized JSON in the #campaign_template_builder_state
 * hidden input. Inactive middle-variant sections are `disabled` as a
 * defense-in-depth measure (belt + suspenders — see plan "Consultant-challenge
 * fixes folded into implementation" #2).
 */
var KTCampaignTemplateBuilder = function () {

    var cfg = null;

    // Root/DOM handles — resolved in init().
    var builderPane, classicPane, modeToggle, editorModeInput, builderStateInput;
    var htmlContentTextarea;
    var briefInput, aiGenerateBtn;
    var heroTitleInput, ctaIntentSelect, ctaLabelInput;
    var previewIframe, previewErrorEl;

    // Mutable module state.
    var state = null;
    var classicEditorInited = false;
    var previewDebounceTimer = null;
    var previewSeq = 0;

    // Bounds read from cfg.catalog.slotSchema once cfg is available.
    var bounds = {};

    // ── Helpers ──────────────────────────────────────────────────────────────

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function axiosPost(url, payload) {
        return axios.post(url, payload, {
            headers: {
                'X-CSRF-TOKEN': getCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = String(str == null ? '' : str);
        return div.innerHTML;
    }

    function swalConfirm(options) {
        if (typeof Swal === 'undefined') {
            return Promise.resolve({ isConfirmed: window.confirm(options.text || options.title) });
        }
        return Swal.fire(Object.assign({
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Confirmer',
            cancelButtonText: 'Annuler',
            buttonsStyling: false,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-light' },
        }, options));
    }

    function swalError(text) {
        if (typeof Swal === 'undefined') {
            window.alert(text);
            return;
        }
        Swal.fire({
            icon: 'error',
            text: text,
            buttonsStyling: false,
            confirmButtonText: 'Ok, compris !',
            customClass: { confirmButton: 'btn btn-primary' },
        });
    }

    // ── Preview ──────────────────────────────────────────────────────────────

    function hidePreviewError() {
        if (previewErrorEl) previewErrorEl.classList.add('d-none');
    }

    function showPreviewError(data) {
        if (!previewErrorEl) return;

        var messages = [];
        if (data && data.errors) {
            Object.keys(data.errors).forEach(function (key) {
                (data.errors[key] || []).forEach(function (m) { messages.push(m); });
            });
        }

        var html = '<strong>' + escapeHtml((data && data.message) || 'État du générateur invalide.') + '</strong>';
        if (messages.length) {
            html += '<ul class="mb-0 mt-2">' + messages.map(function (m) {
                return '<li>' + escapeHtml(m) + '</li>';
            }).join('') + '</ul>';
        }

        previewErrorEl.innerHTML = html;
        previewErrorEl.classList.remove('d-none');
    }

    /**
     * Debounced (400ms) preview POST → iframe srcdoc. Race guard: each call
     * increments a sequence token AT SCHEDULE TIME (not when the request
     * actually fires) — a response is only applied if its captured token is
     * still the latest when it resolves. Incrementing at fire time would let
     * a response land INSIDE the 400ms debounce window (i.e. before the next
     * keystroke's own request has even been scheduled) and still be treated
     * as "current", painting stale HTML for a few hundred ms.
     */
    function schedulePreview() {
        window.clearTimeout(previewDebounceTimer);

        var seq = ++previewSeq;
        previewDebounceTimer = window.setTimeout(function () {
            sendPreview(seq);
        }, 400);
    }

    function sendPreview(seq) {
        if (!previewIframe) return;

        axiosPost(cfg.previewUrl, { builder_state: state })
            .then(function (resp) {
                if (seq !== previewSeq) return; // stale — a newer request has since been scheduled
                if (resp.data && resp.data.success) {
                    previewIframe.srcdoc = resp.data.html;
                    hidePreviewError();
                }
            })
            .catch(function (err) {
                if (seq !== previewSeq) return; // stale
                if (err.response && err.response.status === 422) {
                    showPreviewError(err.response.data);
                } else if (err.response && err.response.status === 429) {
                    showPreviewError({
                        message: "Trop de demandes d'aperçu envoyées — patientez quelques secondes avant de continuer à modifier.",
                    });
                }
            });
    }

    /**
     * One-shot (non-debounced) compose used by the builder→classic mode
     * switch — resolves with the composed HTML or rejects.
     */
    function fetchComposedHtml() {
        return axiosPost(cfg.previewUrl, { builder_state: state }).then(function (resp) {
            if (resp.data && resp.data.success) {
                return resp.data.html;
            }
            throw new Error('builder_preview did not return success');
        });
    }

    // ── Serialize state → hidden input ─────────────────────────────────────

    function serializeState() {
        if (builderStateInput) {
            builderStateInput.value = JSON.stringify(state);
        }
    }

    // ── Variant selection (header / footer cards) ──────────────────────────

    function renderVariantSelection() {
        document.querySelectorAll('[data-variant-group]').forEach(function (card) {
            var group = card.getAttribute('data-variant-group');
            var value = card.getAttribute('data-variant-value');
            card.classList.toggle('is-active', state[group] === value);
        });
    }

    function selectVariant(group, value) {
        state[group] = value;

        if (group === 'header_variant') {
            // Explicit key→value map from the server (CampaignTemplateController
            // ::getViewVars(), sourced from SectionCatalog::heroForHeader()) —
            // NOT a positional index across two independent arrays, which would
            // only match the server by coincidence of declaration order.
            var hero = cfg.catalog.heroForHeader && cfg.catalog.heroForHeader[value];
            if (hero) {
                state.hero_variant = hero;
            }
        }

        renderVariantSelection();
        schedulePreview();
        serializeState();
    }

    function bindVariantCards() {
        document.querySelectorAll('[data-variant-group]').forEach(function (card) {
            card.addEventListener('click', function () {
                selectVariant(card.getAttribute('data-variant-group'), card.getAttribute('data-variant-value'));
            });
            card.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectVariant(card.getAttribute('data-variant-group'), card.getAttribute('data-variant-value'));
                }
            });
        });

        // Footer preview cards scroll to the bottom of their (non-interactive,
        // pointer-events:none) iframe once loaded, so the card actually shows
        // the footer rather than the top of a 600px-wide, ~4x taller email.
        document.querySelectorAll('.builder-variant-preview-iframe[data-scroll="bottom"]').forEach(function (iframe) {
            iframe.addEventListener('load', function () {
                try {
                    var doc = iframe.contentDocument;
                    if (doc && doc.body) {
                        iframe.contentWindow.scrollTo(0, doc.body.scrollHeight);
                    }
                } catch (e) { /* cross-origin or not-yet-ready — ignore, purely cosmetic */ }
            });
        });
    }

    // ── Middle-variant pills ────────────────────────────────────────────────

    function ensureMiddleSlotDefaults(variant) {
        if (variant === 'departures' && !Array.isArray(state.slots.departures)) {
            state.slots.departures = [
                { origin: '', frequency: '' },
                { origin: '', frequency: '' },
            ];
        } else if (variant === 'kpi' && !Array.isArray(state.slots.kpis)) {
            state.slots.kpis = [
                { value: '', label: '' },
                { value: '', label: '' },
                { value: '', label: '' },
            ];
        } else if (variant === 'benefits' && !Array.isArray(state.slots.benefits)) {
            state.slots.benefits = [
                { title: '', text: '' },
                { title: '', text: '' },
                { title: '', text: '' },
            ];
        }
    }

    function renderMiddlePills() {
        document.querySelectorAll('[data-middle-value]').forEach(function (pill) {
            pill.classList.toggle('is-active', pill.getAttribute('data-middle-value') === state.middle_variant);
        });
    }

    function renderMiddleSlots() {
        ['departures', 'kpi', 'benefits'].forEach(function (variant) {
            var el = document.getElementById('slot_middle_' + variant);
            if (!el) return;

            var active = state.middle_variant === variant;
            el.classList.toggle('d-none', !active);
            el.querySelectorAll('input, textarea, button').forEach(function (control) {
                control.disabled = !active;
            });
        });

        renderDepartures();
        renderKpis();
        renderBenefits();
    }

    function selectMiddleVariant(variant) {
        state.middle_variant = variant;
        ensureMiddleSlotDefaults(variant);
        renderMiddlePills();
        renderMiddleSlots();
        schedulePreview();
        serializeState();
    }

    function bindMiddlePills() {
        document.querySelectorAll('[data-middle-value]').forEach(function (pill) {
            pill.addEventListener('click', function () {
                selectMiddleVariant(pill.getAttribute('data-middle-value'));
            });
        });
    }

    // ── hero_title ───────────────────────────────────────────────────────────

    function renderHeroTitle() {
        if (heroTitleInput) heroTitleInput.value = state.slots.hero_title || '';
    }

    function bindHeroTitle() {
        if (!heroTitleInput) return;
        heroTitleInput.addEventListener('input', function () {
            state.slots.hero_title = this.value;
            schedulePreview();
            serializeState();
        });
    }

    // ── intro / bullets (bounded string-array repeaters) ────────────────────

    function renderIntro() {
        var list = document.getElementById('slot_intro_list');
        if (!list) return;

        var values = state.slots.intro || [];
        list.innerHTML = '';

        values.forEach(function (value, index) {
            var row = document.createElement('div');
            row.className = 'd-flex gap-2 mb-2 align-items-start';

            var textarea = document.createElement('textarea');
            textarea.rows = 2;
            textarea.className = 'form-control form-control-solid form-control-sm';
            textarea.maxLength = bounds.intro.item_max;
            textarea.placeholder = "Paragraphe d'introduction…";
            textarea.value = value || '';
            textarea.addEventListener('input', function () {
                state.slots.intro[index] = this.value;
                schedulePreview();
                serializeState();
            });

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-icon btn-light-danger flex-shrink-0';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.disabled = values.length <= bounds.intro.min;
            removeBtn.addEventListener('click', function () {
                if (state.slots.intro.length <= bounds.intro.min) return;
                state.slots.intro.splice(index, 1);
                renderIntro();
                schedulePreview();
                serializeState();
            });

            row.appendChild(textarea);
            row.appendChild(removeBtn);
            list.appendChild(row);
        });

        var addBtn = document.getElementById('slot_intro_add');
        if (addBtn) addBtn.disabled = values.length >= bounds.intro.max;
    }

    function renderBullets() {
        var list = document.getElementById('slot_bullets_list');
        if (!list) return;

        var values = state.slots.bullets || [];
        list.innerHTML = '';

        values.forEach(function (value, index) {
            var row = document.createElement('div');
            row.className = 'd-flex gap-2 mb-2 align-items-center';

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-solid form-control-sm';
            input.maxLength = bounds.bullets.item_max;
            input.placeholder = 'Argument court…';
            input.value = value || '';
            input.addEventListener('input', function () {
                state.slots.bullets[index] = this.value;
                schedulePreview();
                serializeState();
            });

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-icon btn-light-danger flex-shrink-0';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.disabled = values.length <= bounds.bullets.min;
            removeBtn.addEventListener('click', function () {
                if (state.slots.bullets.length <= bounds.bullets.min) return;
                state.slots.bullets.splice(index, 1);
                renderBullets();
                schedulePreview();
                serializeState();
            });

            row.appendChild(input);
            row.appendChild(removeBtn);
            list.appendChild(row);
        });

        var addBtn = document.getElementById('slot_bullets_add');
        if (addBtn) addBtn.disabled = values.length >= bounds.bullets.max;
    }

    function bindIntroBullets() {
        var introAdd = document.getElementById('slot_intro_add');
        if (introAdd) {
            introAdd.addEventListener('click', function () {
                if (!Array.isArray(state.slots.intro)) state.slots.intro = [];
                if (state.slots.intro.length >= bounds.intro.max) return;
                state.slots.intro.push('');
                renderIntro();
                schedulePreview();
                serializeState();
            });
        }

        var bulletsAdd = document.getElementById('slot_bullets_add');
        if (bulletsAdd) {
            bulletsAdd.addEventListener('click', function () {
                if (!Array.isArray(state.slots.bullets)) state.slots.bullets = [];
                if (state.slots.bullets.length >= bounds.bullets.max) return;
                state.slots.bullets.push('');
                renderBullets();
                schedulePreview();
                serializeState();
            });
        }
    }

    // ── departures (bounded object-array repeater, 2 fields) ────────────────

    function renderDepartures() {
        var list = document.getElementById('slot_departures_list');
        if (!list) return;

        var rows = state.slots.departures || [];
        list.innerHTML = '';

        rows.forEach(function (row, index) {
            var wrap = document.createElement('div');
            wrap.className = 'row g-2 mb-2 align-items-center';
            wrap.innerHTML =
                '<div class="col-5"></div>' +
                '<div class="col-5"></div>' +
                '<div class="col-2 text-end"></div>';

            var originInput = document.createElement('input');
            originInput.type = 'text';
            originInput.className = 'form-control form-control-solid form-control-sm';
            originInput.maxLength = bounds.departures.fields.origin.max;
            originInput.placeholder = 'Origine';
            originInput.value = row.origin || '';
            originInput.addEventListener('input', function () {
                state.slots.departures[index].origin = this.value;
                schedulePreview();
                serializeState();
            });

            var freqInput = document.createElement('input');
            freqInput.type = 'text';
            freqInput.className = 'form-control form-control-solid form-control-sm';
            freqInput.maxLength = bounds.departures.fields.frequency.max;
            freqInput.placeholder = 'Fréquence';
            freqInput.value = row.frequency || '';
            freqInput.addEventListener('input', function () {
                state.slots.departures[index].frequency = this.value;
                schedulePreview();
                serializeState();
            });

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-icon btn-light-danger';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.disabled = rows.length <= bounds.departures.min;
            removeBtn.addEventListener('click', function () {
                if (state.slots.departures.length <= bounds.departures.min) return;
                state.slots.departures.splice(index, 1);
                renderDepartures();
                schedulePreview();
                serializeState();
            });

            wrap.children[0].appendChild(originInput);
            wrap.children[1].appendChild(freqInput);
            wrap.children[2].appendChild(removeBtn);
            list.appendChild(wrap);
        });

        var addBtn = document.getElementById('slot_departures_add');
        if (addBtn) addBtn.disabled = rows.length >= bounds.departures.max;
    }

    function bindDepartures() {
        var addBtn = document.getElementById('slot_departures_add');
        if (!addBtn) return;
        addBtn.addEventListener('click', function () {
            if (!Array.isArray(state.slots.departures)) state.slots.departures = [];
            if (state.slots.departures.length >= bounds.departures.max) return;
            state.slots.departures.push({ origin: '', frequency: '' });
            renderDepartures();
            schedulePreview();
            serializeState();
        });
    }

    // ── kpis / benefits (fixed-count object-array, no add/remove) ──────────

    function renderKpis() {
        var list = document.getElementById('slot_kpis_list');
        if (!list) return;

        var rows = state.slots.kpis || [];
        list.innerHTML = '';

        rows.forEach(function (row, index) {
            var wrap = document.createElement('div');
            wrap.className = 'row g-2 mb-2';
            wrap.innerHTML = '<div class="col-5"></div><div class="col-7"></div>';

            var valueInput = document.createElement('input');
            valueInput.type = 'text';
            valueInput.className = 'form-control form-control-solid form-control-sm';
            valueInput.maxLength = bounds.kpis.fields.value.max;
            valueInput.placeholder = 'Valeur (ex : 48h)';
            valueInput.value = row.value || '';
            valueInput.addEventListener('input', function () {
                state.slots.kpis[index].value = this.value;
                schedulePreview();
                serializeState();
            });

            var labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'form-control form-control-solid form-control-sm';
            labelInput.maxLength = bounds.kpis.fields.label.max;
            labelInput.placeholder = 'Libellé';
            labelInput.value = row.label || '';
            labelInput.addEventListener('input', function () {
                state.slots.kpis[index].label = this.value;
                schedulePreview();
                serializeState();
            });

            wrap.children[0].appendChild(valueInput);
            wrap.children[1].appendChild(labelInput);
            list.appendChild(wrap);
        });
    }

    function renderBenefits() {
        var list = document.getElementById('slot_benefits_list');
        if (!list) return;

        var rows = state.slots.benefits || [];
        list.innerHTML = '';

        rows.forEach(function (row, index) {
            var wrap = document.createElement('div');
            wrap.className = 'row g-2 mb-3';
            wrap.innerHTML = '<div class="col-4"></div><div class="col-8"></div>';

            var titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.className = 'form-control form-control-solid form-control-sm';
            titleInput.maxLength = bounds.benefits.fields.title.max;
            titleInput.placeholder = 'Titre';
            titleInput.value = row.title || '';
            titleInput.addEventListener('input', function () {
                state.slots.benefits[index].title = this.value;
                schedulePreview();
                serializeState();
            });

            var textInput = document.createElement('textarea');
            textInput.rows = 2;
            textInput.className = 'form-control form-control-solid form-control-sm';
            textInput.maxLength = bounds.benefits.fields.text.max;
            textInput.placeholder = 'Texte';
            textInput.value = row.text || '';
            textInput.addEventListener('input', function () {
                state.slots.benefits[index].text = this.value;
                schedulePreview();
                serializeState();
            });

            wrap.children[0].appendChild(titleInput);
            wrap.children[1].appendChild(textInput);
            list.appendChild(wrap);
        });
    }

    // ── closing_line (optional — falls back server-side when blank) ────────

    function renderClosingLine() {
        var el = document.getElementById('slot_closing_line');
        if (el) el.value = state.slots.closing_line || '';
    }

    function bindClosingLine() {
        var el = document.getElementById('slot_closing_line');
        if (!el) return;
        el.addEventListener('input', function () {
            state.slots.closing_line = this.value;
            schedulePreview();
            serializeState();
        });
    }

    // ── CTA ──────────────────────────────────────────────────────────────────

    function renderCta() {
        if (ctaIntentSelect) ctaIntentSelect.value = state.cta.intent;
        if (ctaLabelInput) ctaLabelInput.value = state.cta.label;
    }

    function bindCta() {
        if (ctaIntentSelect) {
            ctaIntentSelect.addEventListener('change', function () {
                state.cta.intent = this.value;
                schedulePreview();
                serializeState();
            });
        }
        if (ctaLabelInput) {
            ctaLabelInput.addEventListener('input', function () {
                state.cta.label = this.value;
                schedulePreview();
                serializeState();
            });
        }
    }

    // ── preview_text (top-level field, shared with the model column) ───────

    /**
     * Mirrors state.preview_text into the visible #campaign_template_preview_text
     * input — the hidden builder_state must always reflect the visible form.
     * Without this, renderAll() never touched the field: on create it stayed
     * empty (old('preview_text','')) while state.preview_text carried
     * defaultState()'s canned preheader, so the composed email shipped text the
     * user never saw and the preview_text COLUMN saved empty — permanently
     * divergent from builder_state.preview_text.
     */
    function renderPreviewText() {
        var input = document.getElementById('campaign_template_preview_text');
        if (input) input.value = state.preview_text || '';
    }

    function bindPreviewTextMirror() {
        var input = document.getElementById('campaign_template_preview_text');
        if (!input) return;
        input.addEventListener('input', function () {
            state.preview_text = this.value;
            schedulePreview();
            serializeState();
        });
    }

    // ── Full render ──────────────────────────────────────────────────────────

    function renderAll() {
        renderVariantSelection();
        renderMiddlePills();
        renderMiddleSlots(); // also renders departures/kpis/benefits
        renderHeroTitle();
        renderIntro();
        renderBullets();
        renderClosingLine();
        renderCta();
        renderPreviewText();
    }

    // ── AI suggestion ────────────────────────────────────────────────────────

    function applySuggestion(suggestion) {
        var subjectInput = document.getElementById('campaign_template_subject');
        var previewTextInput = document.getElementById('campaign_template_preview_text');

        if (subjectInput) subjectInput.value = suggestion.subject || '';
        if (previewTextInput) previewTextInput.value = suggestion.preview_text || '';

        state.preview_text = suggestion.preview_text || '';
        state.cta.intent = suggestion.cta_intent;
        state.cta.label = suggestion.cta_label;
        state.slots.hero_title = suggestion.slots.hero_title;
        state.slots.intro = (suggestion.slots.intro || []).slice();
        state.slots.bullets = (suggestion.slots.bullets || []).slice();

        state.middle_variant = suggestion.middle_variant;
        if (suggestion.middle_variant === 'departures') {
            state.slots.departures = (suggestion.slots.departures || []).slice();
        } else if (suggestion.middle_variant === 'kpi') {
            state.slots.kpis = (suggestion.slots.kpis || []).slice();
        } else if (suggestion.middle_variant === 'benefits') {
            state.slots.benefits = (suggestion.slots.benefits || []).slice();
        }

        renderMiddlePills();
        renderMiddleSlots();
        renderHeroTitle();
        renderIntro();
        renderBullets();
        renderCta();
        schedulePreview();
        serializeState();
    }

    function bindAiGenerate() {
        if (!aiGenerateBtn) return;

        aiGenerateBtn.addEventListener('click', function () {
            var brief = (briefInput && briefInput.value ? briefInput.value : '').trim();

            if (brief.length < 20) {
                if (typeof toastr !== 'undefined') {
                    toastr.error('Le brief doit contenir au moins 20 caractères.');
                } else {
                    swalError('Le brief doit contenir au moins 20 caractères.');
                }
                return;
            }

            aiGenerateBtn.setAttribute('data-kt-indicator', 'on');
            aiGenerateBtn.disabled = true;

            axiosPost(cfg.suggestUrl, { brief: brief })
                .then(function (resp) {
                    if (resp.data && resp.data.success) {
                        applySuggestion(resp.data.suggestion);
                    } else {
                        swalError((resp.data && resp.data.message) || "La suggestion IA n'a pas pu être générée.");
                    }
                })
                .catch(function (err) {
                    var msg;
                    if (err.response && err.response.status === 429) {
                        msg = 'Trop de demandes de suggestion IA — patientez une minute avant de réessayer.';
                    } else {
                        msg = (err.response && err.response.data && err.response.data.message)
                            ? err.response.data.message
                            : "La suggestion IA a échoué — vous pouvez continuer manuellement.";
                    }
                    swalError(msg);
                })
                .finally(function () {
                    aiGenerateBtn.removeAttribute('data-kt-indicator');
                    aiGenerateBtn.disabled = false;
                });
        });
    }

    // ── Mode toggle (builder ⇄ classic) ─────────────────────────────────────

    function setBuilderVisible(visible) {
        if (builderPane) builderPane.classList.toggle('d-none', !visible);
        if (classicPane) classicPane.classList.toggle('d-none', visible);
    }

    /**
     * First switch to classic ever: add `required` + data-tinymce-html-field
     * to the textarea and lazy-init TinyMCE via the existing public entrypoint
     * (mirrors tinymce-html-field.js's own byte-fidelity discipline — no
     * double-bind on subsequent toggles, see plan risk #3).
     *
     * Also re-enables the textarea — form.blade.php renders it `disabled`
     * whenever the page opens in builder mode (belt + suspenders: a disabled
     * field can never submit stale/hidden HTML alongside the builder_state,
     * regardless of any FormValidation registration quirk — see switchToBuilder()).
     */
    function applyHtmlToClassicEditor(html) {
        htmlContentTextarea.disabled = false;

        if (!classicEditorInited) {
            htmlContentTextarea.setAttribute('required', 'required');
            htmlContentTextarea.setAttribute('data-tinymce-html-field', '');
            htmlContentTextarea.value = html;

            if (typeof KTTinymceHtmlField !== 'undefined') {
                KTTinymceHtmlField.init();
            }
            classicEditorInited = true;
            return;
        }

        var editor = (typeof tinymce !== 'undefined') ? tinymce.get('html_content') : null;
        if (editor) {
            editor.setContent(html); // programmatic setContent does not dirty the editor
        } else {
            htmlContentTextarea.value = html;
        }
    }

    function switchToClassic() {
        return swalConfirm({
            title: 'Passer en mode avancé ?',
            html: "Le HTML devient éditable librement&nbsp;; l'état du générateur sera effacé à l'enregistrement.",
            confirmButtonText: 'Oui, passer en HTML',
        }).then(function (result) {
            if (!result.isConfirmed) return false;

            modeToggle.disabled = true;

            return fetchComposedHtml()
                .then(function (html) {
                    applyHtmlToClassicEditor(html);
                    editorModeInput.value = 'classic';
                    setBuilderVisible(false);
                    return true;
                })
                .catch(function () {
                    swalError("Impossible de générer l'aperçu HTML — vérifiez le contenu du générateur puis réessayez.");
                    return false;
                })
                .finally(function () {
                    modeToggle.disabled = false;
                });
        });
    }

    /**
     * classic → builder: NO reverse-parsing of the raw HTML. Starts from
     * whichever builder_state is already held in memory (the originally
     * stored state, or defaultState() if none existed) — see plan
     * "Consultant-challenge fixes folded into implementation" #5. Cancel
     * leaves the classic HTML byte-for-byte untouched because nothing is
     * mutated until the user confirms.
     */
    function switchToBuilder() {
        return swalConfirm({
            title: 'Repasser au générateur ?',
            html: "Le contenu HTML actuel sera <strong>remplacé</strong> par la composition du générateur lors de l'enregistrement.",
            confirmButtonText: 'Oui, utiliser le générateur',
        }).then(function (result) {
            if (!result.isConfirmed) return false;

            editorModeInput.value = 'builder';
            setBuilderVisible(true);
            // Undo what applyHtmlToClassicEditor() added on the first switch to
            // classic — a `required` field left inside a d-none pane is one
            // refactor away from silently blocking every submit (today it is
            // harmless only because crud-form-handler.js snapshots [required]
            // once at init, before this attribute is added). `disabled` is the
            // hard guarantee underneath that belt-and-suspenders: a disabled
            // field is excluded from form submission by the browser itself,
            // independent of any FormValidation registration snapshot.
            if (htmlContentTextarea) {
                htmlContentTextarea.removeAttribute('required');
                htmlContentTextarea.removeAttribute('data-tinymce-html-field');
                htmlContentTextarea.disabled = true;
            }
            renderAll();
            schedulePreview();
            serializeState();
            return true;
        });
    }

    function bindModeToggle() {
        if (!modeToggle) return;

        modeToggle.addEventListener('change', function () {
            var wantsClassic = modeToggle.checked;
            modeToggle.checked = !wantsClassic; // revert until the Swal confirms

            var transition = wantsClassic ? switchToClassic() : switchToBuilder();

            transition.then(function (confirmed) {
                if (confirmed) modeToggle.checked = wantsClassic;
            });
        });
    }

    // ── Submit-time safety net (capture phase — mirrors tinymce-html-field.js) ──

    function bindSubmitSafetyNet() {
        document.addEventListener('click', function (e) {
            if (!e.target.closest || !e.target.closest('.submit')) return;
            if (editorModeInput && editorModeInput.value === 'builder') {
                serializeState();
            }
        }, true);
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    function resolveDom() {
        builderPane = document.getElementById('builder_pane');
        classicPane = document.getElementById('classic_pane');
        modeToggle = document.getElementById('campaign_template_mode_toggle');
        editorModeInput = document.getElementById('campaign_template_editor_mode');
        builderStateInput = document.getElementById('campaign_template_builder_state');
        htmlContentTextarea = document.getElementById('html_content');

        briefInput = document.getElementById('builder_brief_input');
        aiGenerateBtn = document.getElementById('builder_ai_generate_btn');

        heroTitleInput = document.getElementById('slot_hero_title');
        ctaIntentSelect = document.getElementById('slot_cta_intent');
        ctaLabelInput = document.getElementById('slot_cta_label');

        previewIframe = document.getElementById('builder_preview_iframe');
        previewErrorEl = document.getElementById('builder_preview_error');
    }

    return {
        init: function () {
            cfg = window.__campaignTemplateBuilder;
            if (!cfg) return; // not on a campaign_templates create/edit page

            resolveDom();
            if (!builderPane || !classicPane || !editorModeInput || !builderStateInput) return;

            // KTUtil.onDOMContentLoaded fires on both 'DOMContentLoaded' and
            // 'livewire:navigated' — guard against double-binding exactly like
            // crud-form-handler.js does.
            if (builderPane.dataset.builderBound === '1') return;
            builderPane.dataset.builderBound = '1';

            bounds = cfg.catalog.slotSchema;
            state = deepClone(cfg.initialState || cfg.defaultState);
            classicEditorInited = !cfg.openInBuilder;

            // Edit mode: the preview_text COLUMN (this input's server-rendered
            // value) is authoritative over whatever builder_state.preview_text
            // happened to hold historically — seed state from it before the
            // first renderAll() so the two can never diverge going forward.
            if (cfg.initialState) {
                var previewTextInput = document.getElementById('campaign_template_preview_text');
                if (previewTextInput) state.preview_text = previewTextInput.value || '';
            }

            bindVariantCards();
            bindMiddlePills();
            bindHeroTitle();
            bindIntroBullets();
            bindDepartures();
            bindClosingLine();
            bindCta();
            bindPreviewTextMirror();
            bindAiGenerate();
            bindModeToggle();
            bindSubmitSafetyNet();

            renderAll();
            serializeState();

            if (cfg.openInBuilder) {
                schedulePreview();
            }
        }
    };
}();

KTUtil.onDOMContentLoaded(function () {
    KTCampaignTemplateBuilder.init();
});
