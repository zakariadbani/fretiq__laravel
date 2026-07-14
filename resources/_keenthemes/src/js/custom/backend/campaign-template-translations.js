"use strict";

/**
 * campaign-template-translations.js — Traductions IA tab for CampaignTemplate edit page.
 *
 * Dependencies (all global): jQuery, axios (with CSRF already wired), toastr, KTBlockUI, Swal.
 * tinymce is available after tinymce-html-field.js runs.
 *
 * Pattern mirrors segment-form.js (IIFE module, meta[name=csrf-token] CSRF, stale-response guard).
 *
 * Guard: runs only when #traductions-pane-root exists.
 */

(function () {

    // ── DOM handles ──────────────────────────────────────────────────────────

    var root = document.getElementById('traductions-pane-root');
    if (!root) return; // Not on the translation tab page — bail out.

    // Data attrs from root card
    var translateUrl = root.getAttribute('data-translate-url');
    var saveUrl      = root.getAttribute('data-save-url');
    var reviewUrl    = root.getAttribute('data-review-url');

    var sourceLanguage = root.getAttribute('data-source-language') || 'fr';
    var targetLanguage = root.getAttribute('data-target-language') || 'en';

    // State read from data attrs (kept in sync by JS as server responds)
    var hasTranslation = root.getAttribute('data-has-en-translation') === '1';
    var isAiGenerated  = root.getAttribute('data-ai') === '1';
    var isStaleServer  = root.getAttribute('data-en-stale') === '1';

    // Live-stale watcher fires only once
    var liveStaleFired = false;

    // ── CSRF helper ──────────────────────────────────────────────────────────

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ── Axios helper — always sends CSRF header ──────────────────────────────

    function axiosPost(url, payload) {
        return axios.post(url, payload, {
            headers: {
                'X-CSRF-TOKEN':  getCsrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        });
    }

    // ── TinyMCE helper — safe get (editor may not be ready yet) ──────────────

    function getEnEditor() {
        return (typeof tinymce !== 'undefined') ? tinymce.get('tr_en_html') : null;
    }

    function getBaseFrEditor() {
        return (typeof tinymce !== 'undefined') ? tinymce.get('html_content') : null;
    }

    // ── Tab badge helper ─────────────────────────────────────────────────────

    /**
     * Find the nav-link that points to #template_traductions and set/clear a badge.
     * @param {string|null} text  null = remove badge; else badge text.
     * @param {string}      cls   Bootstrap badge class suffix (e.g. 'badge-light-warning').
     */
    function setTabBadge(text, cls) {
        var link = document.querySelector('a[data-bs-toggle="tab"][href="#template_traductions"]');
        if (!link) return;

        // Remove existing badge if present
        var existing = link.querySelector('.tr-tab-badge');
        if (existing) existing.remove();

        if (!text) return;

        var badge = document.createElement('span');
        badge.className = 'badge ' + (cls || 'badge-light-warning') + ' ms-2 tr-tab-badge';
        badge.textContent = text;
        link.appendChild(badge);
    }

    // ── Initialise tab badge on load ─────────────────────────────────────────

    function initTabBadge() {
        if (isStaleServer) {
            setTabBadge('Obsolète', 'badge-light-warning');
        } else if (hasTranslation) {
            setTabBadge('À jour', 'badge-light-success');
        }
        // else: no translation yet → no badge
    }

    // ── Update provenance badge ───────────────────────────────────────────────

    function updateProvenance(isAi) {
        var el = document.getElementById('tr-provenance-badge');
        if (!el) return;
        if (isAi) {
            el.textContent = 'Traduction automatique';
            el.className = 'badge badge-light-info';
        } else {
            el.textContent = 'Modifiée manuellement';
            el.className = 'badge badge-light-primary';
        }
    }

    // ── Update review state UI ────────────────────────────────────────────────

    /**
     * @param {string|null} reviewedAt  Human-readable relative date, or null if not reviewed.
     */
    function updateReviewState(reviewedAt) {
        var stateEl = document.getElementById('tr-review-state');
        var btnEl   = document.getElementById('tr-review-btn');

        if (!stateEl || !btnEl) return;

        if (reviewedAt) {
            stateEl.textContent = 'Relue ' + reviewedAt;
            stateEl.className   = 'badge badge-light-success';
            btnEl.textContent   = 'Marquer comme non relue';
            btnEl.classList.remove('d-none');
        } else {
            stateEl.textContent = 'Non relue';
            stateEl.className   = 'badge badge-light-secondary';
            btnEl.textContent   = 'Marquer comme relue';
            btnEl.classList.remove('d-none');
        }
    }

    // ── Populate EN fields from a translation object ──────────────────────────

    /**
     * @param {Object} t  Translation shape from server: {subject, preview_text, html_content, …}
     */
    function populateEnFields(t) {
        var subjectEl = document.getElementById('tr_en_subject');
        var previewEl = document.getElementById('tr_en_preview');

        if (subjectEl) subjectEl.value = t.subject || '';
        if (previewEl) previewEl.value = t.preview_text || '';

        var editor = getEnEditor();
        if (editor) {
            editor.setContent(t.html_content || '');
        }

        // Refresh iframe preview srcdoc
        refreshPreviewIframe(t.html_content || '');
    }

    // ── Refresh EN iframe ─────────────────────────────────────────────────────

    function refreshPreviewIframe(html) {
        var iframe = document.getElementById('tr-en-iframe');
        if (!iframe) return;

        // Show the iframe, hide the empty placeholder if present
        iframe.classList.remove('d-none');
        var emptyEl = document.getElementById('tr-preview-empty');
        if (emptyEl) emptyEl.classList.add('d-none');

        iframe.srcdoc = html;
        // Reset height so the load event resizes correctly
        iframe.style.height = '400px';
    }

    // ── KTBlockUI helpers ─────────────────────────────────────────────────────

    var _blockUI = null;

    function blockPane(message) {
        if (!root || typeof KTBlockUI === 'undefined') return;
        try {
            // Reuse an existing instance if Metronic already attached one to the node;
            // some builds return an instance without .block when re-constructed on the same node.
            var existing = (typeof KTBlockUI.getInstance === 'function') ? KTBlockUI.getInstance(root) : null;
            _blockUI = existing || new KTBlockUI(root, {
                message: '<div class="blockui-message"><span class="spinner-border text-primary me-3"></span>' +
                         (message || 'Chargement…') + '</div>',
            });
            if (_blockUI && typeof _blockUI.block === 'function') {
                _blockUI.block();
            } else {
                _blockUI = null; // could not block — proceed without the cosmetic overlay
            }
        } catch (e) {
            _blockUI = null; // a cosmetic block failure must never abort the translate
        }
    }

    function unblockPane() {
        try {
            if (_blockUI && typeof _blockUI.release === 'function') {
                _blockUI.release();
            }
        } catch (e) { /* ignore */ }
        _blockUI = null;
    }

    // ── Translate button ──────────────────────────────────────────────────────

    var translateBtn = document.getElementById('tr-translate-btn');

    if (translateBtn) {
        translateBtn.addEventListener('click', function () {

            // Confirm before overwriting manual EN edits.
            if (targetLanguage === 'en' && hasTranslation && !isAiGenerated) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Écraser la traduction ?',
                        text:  'La traduction a été modifiée manuellement. La remplacer par une traduction IA ?',
                        icon:  'warning',
                        showCancelButton:  true,
                        confirmButtonText: 'Oui, retraduire',
                        cancelButtonText:  'Annuler',
                        customClass: {
                            confirmButton: 'btn btn-primary',
                            cancelButton:  'btn btn-light',
                        },
                        buttonsStyling: false,
                    }).then(function (result) {
                        if (result.isConfirmed) doTranslate(true); // user confirmed → send overwrite=1
                    });
                } else {
                    if (confirm('La traduction a été modifiée manuellement. La remplacer par une traduction IA ?')) {
                        doTranslate(true); // user confirmed → send overwrite=1
                    }
                }
            } else {
                doTranslate();
            }
        });
    }

    function doTranslate(overwrite) {
        if (!translateBtn) return;

        // Indicator on
        translateBtn.setAttribute('data-kt-indicator', 'on');
        translateBtn.disabled = true;
        blockPane('Traduction en cours — jusqu\'à 60 s');

        var payload = {
            source_language: sourceLanguage,
            target_language: targetLanguage
        };
        if (overwrite) payload.overwrite = 1;

        axiosPost(translateUrl, payload)
            .then(function (resp) {
                var data = resp.data;
                if (data.success && targetLanguage === 'fr') {
                    toastr.success(data.message || 'Version française générée avec succès.');
                    window.setTimeout(function () { window.location.reload(); }, 700);
                    return;
                }

                if (data.success && data.translations && data.translations.length) {
                    var t = data.translations[0];

                    populateEnFields(t);
                    if (translationForm) {
                        translationForm.dispatchEvent(new CustomEvent('crud:form-saved', { bubbles: true }));
                    }
                    updateProvenance(true);
                    updateReviewState(null);

                    // Clear stale banners
                    var serverBanner = document.getElementById('en-stale-server');
                    if (serverBanner) serverBanner.classList.add('d-none');
                    var liveBanner = document.getElementById('en-stale-live');
                    if (liveBanner) liveBanner.classList.add('d-none');

                    // Update tab badge
                    setTabBadge('À jour', 'badge-light-success');
                    root.setAttribute('data-en-stale', '0');

                    // Update state flags
                    hasTranslation = true;
                    isAiGenerated  = true;
                    root.setAttribute('data-has-en-translation', '1');
                    root.setAttribute('data-ai', '1');

                    // Update translate button label
                    var labelEl = translateBtn.querySelector('.indicator-label');
                    if (labelEl) labelEl.innerHTML = '<i class="bi bi-magic me-1"></i>Mettre à jour la traduction';

                    // Show review button if hidden
                    var reviewBtn = document.getElementById('tr-review-btn');
                    if (reviewBtn) reviewBtn.classList.remove('d-none');

                    toastr.success(data.message || 'Traduction générée avec succès.');

                    // Re-arm the live-stale watcher after a successful translate
                    armLiveStaleWatcher();
                } else {
                    toastr.error(data.message || 'La traduction a échoué. Réessayez.');
                }
            })
            .catch(function (err) {
                // ── 409: server-side overwrite guard (cross-tab manual edit) ────
                if (err.response && err.response.status === 409 &&
                    err.response.data && err.response.data.requires_confirmation) {

                    // Release UI first so user can interact with Swal
                    translateBtn.setAttribute('data-kt-indicator', 'off');
                    translateBtn.disabled = false;
                    unblockPane();

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Traduction modifiée manuellement',
                            text:  err.response.data.message ||
                                   'Cette traduction a été modifiée manuellement. Confirmer le remplacement par une traduction IA ?',
                            icon:  'warning',
                            showCancelButton:  true,
                            confirmButtonText: 'Oui, remplacer',
                            cancelButtonText:  'Annuler',
                            customClass: {
                                confirmButton: 'btn btn-primary',
                                cancelButton:  'btn btn-light',
                            },
                            buttonsStyling: false,
                        }).then(function (result) {
                            if (result.isConfirmed) doTranslate(true); // retry with overwrite=1
                        });
                    } else {
                        if (confirm(err.response.data.message ||
                            'Traduction modifiée manuellement. Remplacer ?')) {
                            doTranslate(true);
                        }
                    }
                    return; // already released UI above
                }

                var msg = (err.response && err.response.data && err.response.data.message)
                    ? err.response.data.message
                    : 'Erreur lors de la traduction.';
                toastr.error(msg);
            })
            .finally(function () {
                translateBtn.setAttribute('data-kt-indicator', 'off');
                translateBtn.disabled = false;
                unblockPane();
            });
    }

    // ── Save button ───────────────────────────────────────────────────────────

    var saveBtn = document.getElementById('tr-save-btn');
    var translationForm = document.getElementById('campaign_template_translation_form');

    function saveTranslation(event) {
        if (event) event.preventDefault();
        if (!saveBtn) return;

            var subjectEl = document.getElementById('tr_en_subject');
            var previewEl = document.getElementById('tr_en_preview');
            var editor    = getEnEditor();

            var subject    = subjectEl ? subjectEl.value.trim() : '';
            var preview    = previewEl ? previewEl.value.trim() : '';
            var htmlContent = editor ? editor.getContent() : '';

            // Client-side validation
            if (!subject) {
                toastr.error('Le sujet anglais est requis.');
                return;
            }
            if (!htmlContent || htmlContent.trim() === '') {
                toastr.error('Le contenu HTML anglais est requis.');
                return;
            }

            saveBtn.setAttribute('data-kt-indicator', 'on');
            saveBtn.disabled = true;

            axiosPost(saveUrl, {
                language:     'en',
                subject:      subject,
                preview_text: preview,
                html_content: htmlContent,
            })
                .then(function (resp) {
                    var data = resp.data;
                    if (data.success) {
                        // Update provenance to manual
                        updateProvenance(false);
                        isAiGenerated = false;
                        root.setAttribute('data-ai', '0');

                        // Clear review
                        updateReviewState(null);

                        // Refresh preview iframe
                        refreshPreviewIframe(htmlContent);

                        // Hide live stale banner
                        var liveBanner = document.getElementById('en-stale-live');
                        if (liveBanner) liveBanner.classList.add('d-none');

                        // Update translation existence flag
                        hasTranslation = true;
                        root.setAttribute('data-has-en-translation', '1');

                        // Update translate button label
                        if (translateBtn) {
                            var labelEl = translateBtn.querySelector('.indicator-label');
                            if (labelEl) labelEl.innerHTML = '<i class="bi bi-magic me-1"></i>Mettre à jour la traduction';
                        }

                        // Show review button
                        var reviewBtn = document.getElementById('tr-review-btn');
                        if (reviewBtn) reviewBtn.classList.remove('d-none');

                        // Tab badge: saved manually → "À jour" (hashes were updated server-side)
                        setTabBadge('À jour', 'badge-light-success');
                        root.setAttribute('data-en-stale', '0');

                        // Re-arm the live-stale watcher so the next base edit fires again
                        armLiveStaleWatcher();

                        if (translationForm) {
                            translationForm.dispatchEvent(new CustomEvent('crud:form-saved', { bubbles: true }));
                        }

                        toastr.success(data.message || 'Traduction enregistrée.');
                    } else {
                        toastr.error(data.message || 'Échec de l\'enregistrement.');
                    }
                })
                .catch(function (err) {
                    var msg = (err.response && err.response.data && err.response.data.message)
                        ? err.response.data.message
                        : 'Erreur lors de l\'enregistrement.';
                    toastr.error(msg);
                })
                .finally(function () {
                    saveBtn.setAttribute('data-kt-indicator', 'off');
                    saveBtn.disabled = false;
                });
    }

    if (translationForm) {
        translationForm.addEventListener('submit', saveTranslation);
    } else if (saveBtn) {
        saveBtn.addEventListener('click', saveTranslation);
    }

    // ── Review toggle ─────────────────────────────────────────────────────────

    var reviewBtn = document.getElementById('tr-review-btn');

    if (reviewBtn) {
        reviewBtn.addEventListener('click', function () {
            var stateEl   = document.getElementById('tr-review-state');
            var currently = stateEl ? stateEl.textContent.trim().startsWith('Relue') : false;
            var nextState = !currently; // true = mark as reviewed; false = mark as not reviewed

            reviewBtn.disabled = true;

            axiosPost(reviewUrl, { language: 'en', reviewed: nextState })
                .then(function (resp) {
                    var data = resp.data;
                    if (data.success) {
                        updateReviewState(data.reviewed_at || null);
                        // Tab badge: review state doesn't change staleness — keep current
                    } else {
                        toastr.error('Échec de la mise à jour du statut de relecture.');
                    }
                })
                .catch(function () {
                    toastr.error('Erreur lors de la mise à jour du statut de relecture.');
                })
                .finally(function () {
                    reviewBtn.disabled = false;
                });
        });
    }

    // ── Live-stale watcher ────────────────────────────────────────────────────
    // Only relevant when a translation already exists (data-has-en-translation='1').
    //
    // armLiveStaleWatcher() is called on load AND after each successful translate
    // or save.  It resets liveStaleFired so the next base edit will fire again.
    // Listeners are persistent (NOT {once:true}) and guard internally on the flag,
    // so re-arming is safe even when the same listener is already attached.

    function fireLiveStale() {
        if (liveStaleFired) return;
        liveStaleFired = true;

        var banner = document.getElementById('en-stale-live');
        if (banner) banner.classList.remove('d-none');

        setTabBadge('Obsolète', 'badge-light-warning');
        root.setAttribute('data-en-stale', '1');
    }

    // Persistent handlers stored so we never add duplicate listeners.
    var _subjectHandler = null;
    var _previewHandler = null;
    var _editorBound    = false;

    function armLiveStaleWatcher() {
        if (!hasTranslation) return;

        // Reset fired flag so a new base edit will trigger stale again.
        liveStaleFired = false;

        // ── FR subject input ──────────────────────────────────────────────────
        var subjectInput = document.querySelector('[name="subject"]');
        if (subjectInput && !_subjectHandler) {
            _subjectHandler = function () { fireLiveStale(); };
            subjectInput.addEventListener('input', _subjectHandler);
        }

        // ── FR preview_text input ─────────────────────────────────────────────
        var previewInput = document.querySelector('[name="preview_text"]');
        if (previewInput && !_previewHandler) {
            _previewHandler = function () { fireLiveStale(); };
            previewInput.addEventListener('input', _previewHandler);
        }

        // ── Base FR TinyMCE editor (html_content) ─────────────────────────────
        // TinyMCE may not be initialized yet at DOMContentLoaded — poll briefly.
        // Bind only once; subsequent armLiveStaleWatcher() calls just reset the flag.
        if (!_editorBound) {
            var attempts = 0;
            var maxAttempts = 20; // 20 × 250 ms = 5 s
            var poll = setInterval(function () {
                attempts++;
                var editor = getBaseFrEditor();
                if (editor) {
                    clearInterval(poll);
                    _editorBound = true;
                    editor.on('input change undo redo ExecCommand', function () {
                        if (editor.isDirty()) fireLiveStale();
                    });
                } else if (attempts >= maxAttempts) {
                    clearInterval(poll);
                }
            }, 250);
        }
    }

    // ── Compare toggle ────────────────────────────────────────────────────────

    var compareBtn     = document.getElementById('tr-compare-toggle');
    var editingSurface = document.getElementById('tr-editing-surface');

    if (compareBtn && editingSurface) {
        compareBtn.addEventListener('click', function () {
            editingSurface.classList.toggle('tr-two-col');
            var isTwoCol = editingSurface.classList.contains('tr-two-col');
            compareBtn.innerHTML = isTwoCol
                ? '<i class="bi bi-layout-text-window me-1"></i>Vue simple'
                : '<i class="bi bi-layout-split me-1"></i>Comparer FR / EN';
        });
    }

    // ── Preview toggle ────────────────────────────────────────────────────────

    var previewToggleBtn = document.getElementById('tr-preview-toggle');
    var previewWrapper   = document.getElementById('tr-preview-wrapper');

    if (previewToggleBtn && previewWrapper) {
        previewToggleBtn.addEventListener('click', function () {
            previewWrapper.classList.toggle('d-none');
        });
    }

    // ── Open sibling CRUD tabs from inside this pane ──────────────────────────

    document.querySelectorAll('[data-template-open-tab]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();

            var target = link.getAttribute('data-template-open-tab') || link.getAttribute('href');
            if (!target) return;

            var tabLink = document.querySelector('a[data-bs-toggle="tab"][href="' + target + '"]');
            if (!tabLink) {
                window.location.hash = target;
                return;
            }

            tabLink.click();

            try {
                history.replaceState(null, '', target);
            } catch (e) { /* ignore */ }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });

    // ── Boot ─────────────────────────────────────────────────────────────────

    initTabBadge();
    armLiveStaleWatcher();

})();
