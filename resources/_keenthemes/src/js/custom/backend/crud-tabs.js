"use strict";

/**
 * crud-tabs.js — generic CRUD detail-page tab manager.
 *
 * Responsibilities:
 *  (a) On DOMContentLoaded: read location.hash; if it matches a native
 *      data-bs-toggle="tab" link on this page, activate that tab.
 *      Otherwise leave the default (first active) tab alone.
 *  (b) On local tab click: write the shown pane id to location.hash via
 *      history.replaceState (no scroll jump).
 *  (c) Keep dirty form data protected when route links leave the page.
 *
 * Model-agnostic: pane ids and the out-of-form list are NOT hardcoded.
 * The tab-content element must have [data-crud-tab-content] or the id
 * pattern "{prefix}_tab_content" where prefix is derived from the first
 * data-bs-toggle="tab" link's href (e.g. "#company_apercu" → "company").
 *
 * Out-of-form detection strategy (checked in order):
 *   1. [data-out-of-form-panes] JSON attr on the tab-content element,
 *      e.g. data-out-of-form-panes='["company_contacts","company_activity"]'
 *   2. Any element with [data-crud-pane] that is NOT already inside the
 *      tab-content container.
 *
 * Mirrors company-tabs.js but fully model-agnostic.
 */

(function () {
    var hasDirtyForm = function () { return false; };


    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Activate a Bootstrap tab by its pane id (e.g. "#company_contacts").
     * Returns true if a matching native tab link was found and activated.
     */
    function activateTabById(paneId) {
        if (!paneId) return false;
        var normalized = paneId.startsWith('#') ? paneId : '#' + paneId;
        var link = document.querySelector('a[data-bs-toggle="tab"][href="' + normalized + '"]');
        if (!link) return false;

        var pane = document.querySelector(normalized);
        if (!pane) return false;

        // Direct DOM switch — mirrors clic2loc switchTabDirect (no animation on initial load)
        document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (l) {
            var href = l.getAttribute('href');
            if (!href || !href.startsWith('#')) return;
            var isTarget = href === normalized;
            l.classList.toggle('active', isTarget);
            l.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            try {
                var p = document.querySelector(href);
                if (p) {
                    if (isTarget) {
                        p.classList.add('active', 'show');
                    } else {
                        p.classList.remove('active', 'show');
                    }
                }
            } catch (e) { /* ignore */ }
        });

        link.dispatchEvent(new Event('shown.bs.tab', { bubbles: true }));

        return true;
    }

    function replaceHash(hash) {
        if (!hash || !hash.startsWith('#')) return;
        try {
            history.replaceState(null, '', hash);
        } catch (err) { /* cross-origin guard */ }
    }
    function findTabContent() {
        var tabContent = document.querySelector('[data-crud-tab-content], [data-out-of-form-panes]');
        if (tabContent) return tabContent;

        var firstLink = document.querySelector('a[data-bs-toggle="tab"][href^="#"]');
        if (!firstLink) return null;

        var paneId = (firstLink.getAttribute('href') || '').slice(1);
        var separator = paneId.indexOf('_');
        if (separator < 1) return null;

        return document.getElementById(paneId.slice(0, separator) + '_tab_content');
    }

    function moveOutOfFormPanesIntoTabContent() {
        var tabContent = findTabContent();
        if (!tabContent) return;

        var paneIds = [];
        var configuredPanes = tabContent.getAttribute('data-out-of-form-panes');
        if (configuredPanes) {
            try {
                var decoded = JSON.parse(configuredPanes);
                if (Array.isArray(decoded)) paneIds = decoded;
            } catch (error) {
                paneIds = [];
            }
        }

        if (!paneIds.length) {
            document.querySelectorAll('[data-crud-pane]').forEach(function (pane) {
                if (pane.id && !tabContent.contains(pane)) paneIds.push(pane.id);
            });
        }

        paneIds.forEach(function (id) {
            var pane = document.getElementById(id);
            if (pane && !tabContent.contains(pane)) tabContent.appendChild(pane);
        });
    }


    function serialiseForm(form) {
        if (!form) return '';

        if (typeof tinymce !== 'undefined') {
            form.querySelectorAll('textarea[id]').forEach(function (textarea) {
                var editor = tinymce.get(textarea.id);
                if (editor) {
                    textarea.value = editor.getContent();
                }
            });
        }

        return new URLSearchParams(new FormData(form)).toString();
    }

    function installDirtyNavigationGuard() {
        var forms = Array.prototype.slice.call(document.querySelectorAll('#form_crud, #campaign_template_translation_form'));
        if (!forms.length) return;

        forms.forEach(function (form) {
            form.dataset.cleanSnapshot = serialiseForm(form);
            form.addEventListener('crud:form-saved', function () {
                form.dataset.cleanSnapshot = serialiseForm(form);
            });
        });

        hasDirtyForm = function () {
            return forms.some(function (form) {
                return form.dataset.cleanSnapshot !== serialiseForm(form);
            });
        };

        window.addEventListener('beforeunload', function (event) {
            if (!hasDirtyForm()) return;
            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', function (event) {
            var link = event.target.closest('a[href]');
            if (!link) return;
            if (link.getAttribute('data-bs-toggle') === 'tab') {
                // Local panes retain the current document and its form data.
                // Only cross-route navigation can discard unsaved edits.
                return;
            }
            if (link.target && link.target !== '_self') return;

            var href = link.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#') return;

            var nextUrl;
            try {
                nextUrl = new URL(href, window.location.href);
            } catch (e) {
                return;
            }

            if (nextUrl.origin !== window.location.origin ||
                nextUrl.pathname === window.location.pathname && nextUrl.search === window.location.search) {
                return;
            }

            if (hasDirtyForm() && !window.confirm('Des modifications non enregistrées seront perdues. Continuer ?')) {
                event.preventDefault();
            }
        });
    }

    // ── Main ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {

        moveOutOfFormPanesIntoTabContent();
        installDirtyNavigationGuard();

        // Step 1: hash → active tab on load
        var hash = window.location.hash;
        if (hash) {
            requestAnimationFrame(function () {
                activateTabById(hash);

                if (window.location.hash === hash) {
                    window.scrollTo({ top: 0, behavior: 'auto' });
                }
            });
        }

        // Step 2: local tab switch. Panes may live outside the main form.
        document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var href = link.getAttribute('href');
                if (!href || !href.startsWith('#')) return;

                if (hasDirtyForm() && !window.confirm('Des modifications non enregistr\u00e9es seront perdues. Continuer ?')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();
                activateTabById(href);
                replaceHash(href);
            }, true);
        });

    });

})();
