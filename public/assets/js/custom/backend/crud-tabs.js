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
            l.setAttribute('tabindex', isTarget ? '0' : '-1');
            try {
                var p = document.querySelector(href);
                if (p) {
                    p.classList.toggle('active', isTarget);
                    p.classList.toggle('show', isTarget);
                    p.hidden = !isTarget;
                    p.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
                    p.setAttribute('role', 'tabpanel');
                    if (l.id) p.setAttribute('aria-labelledby', l.id);
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
        return new URLSearchParams(new FormData(form)).toString();
    }

    function installDirtyNavigationGuard() {
        var forms = Array.prototype.slice.call(document.querySelectorAll('#form_crud, #campaign_template_translation_form'));
        if (!forms.length) return;

        var navigationConfirmed = false;

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
            if (navigationConfirmed || !hasDirtyForm()) return;
            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 ||
                event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            var link = event.target.closest('a[href]');
            if (!link || link.hasAttribute('download') || link.target && link.target !== '_self') return;
            if (link.getAttribute('data-bs-toggle') === 'tab') {
                // Local panes retain the current document and its form data.
                // Only cross-route navigation can discard unsaved edits.
                return;
            }

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

            if (!hasDirtyForm()) {
                navigationConfirmed = true;
                setTimeout(function () { navigationConfirmed = false; }, 0);
                return;
            }

            event.preventDefault();
            if (!window.confirm('Des modifications non enregistrées seront perdues. Continuer ?')) return;

            navigationConfirmed = true;
            window.location.assign(nextUrl.href);
        });
    }

    // ── Main ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {

        moveOutOfFormPanesIntoTabContent();
        installDirtyNavigationGuard();

        // Synchronize the default tab and panes before applying an optional deep link.
        var defaultLink = document.querySelector('a[data-bs-toggle="tab"].active')
            || document.querySelector('a[data-bs-toggle="tab"]');
        if (defaultLink) activateTabById(defaultLink.getAttribute('href'));

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

                event.preventDefault();
                event.stopImmediatePropagation();
                activateTabById(href);
                replaceHash(href);
            }, true);

            link.addEventListener('keydown', function (event) {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

                var tablist = link.closest('[role="tablist"]');
                if (!tablist) return;
                var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[role="tab"]'));
                var current = tabs.indexOf(link);
                var next = event.key === 'Home' ? 0
                    : event.key === 'End' ? tabs.length - 1
                    : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                var target = tabs[next];
                var href = target.getAttribute('href');

                event.preventDefault();
                target.focus();
                activateTabById(href);
                replaceHash(href);
            });
        });

    });

    // ── Back/forward + hash-only navigation ─────────────────────────────────
    //
    // A page can push/replace history entries whose hash names one of these
    // tabs without going through the click handler above (e.g. a review
    // workspace hosted in a pane pushes state for its own in-pane navigation,
    // and a plain `<a href="#pane_id">` changes the hash without a SPA
    // router). Re-run the same activation on both events so the visible pane
    // always matches the address bar after Back/Forward or a hash-only link.
    window.addEventListener('hashchange', function () {
        if (window.location.hash) activateTabById(window.location.hash);
    });
    window.addEventListener('popstate', function () {
        if (window.location.hash) activateTabById(window.location.hash);
    });

})();
