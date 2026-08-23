"use strict";

/**
 * company-tabs.js — fretiq Company detail page tab manager.
 *
 * Responsibilities:
 *  (a) On DOMContentLoaded: read location.hash; if it matches an existing .tab-pane
 *      on this page that has a corresponding native data-bs-toggle="tab" link,
 *      activate that tab. Otherwise, leave the default (first active) tab alone.
 *  (b) On shown.bs.tab: write the shown pane id to location.hash via
 *      history.replaceState (no scroll jump).
 *  (c) Edit page only: move #company_contacts and #company_activity panes
 *      (rendered after </form>) into #company_tab_content so Bootstrap tab
 *      toggling works. Detection: #company_tab_content exists inside a <form>.
 *
 * Mirrors clic2loc tab-manager.js but minimal / vanilla + Bootstrap 5.
 * No external dependencies beyond Bootstrap 5 (already loaded by Metronic).
 */

(function () {

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

        // Check the pane actually exists on this page
        var pane = document.querySelector(normalized);
        if (!pane) return false;

        // Direct DOM switch (no Bootstrap animation for initial load — mirrors clic2loc switchTabDirect)
        document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (l) {
            var href = l.getAttribute('href');
            if (!href || !href.startsWith('#')) return;
            var isTarget = href === normalized;
            l.classList.toggle('active', isTarget);
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

        return true;
    }

    /**
     * On the edit page, Contacts and Activity panes are rendered after </form>
     * (to avoid nested-form problems). Move them into #company_tab_content so
     * Bootstrap's tab toggle can find them.
     */
    function moveOutOfFormPanesIntoTabContent() {
        var tabContent = document.getElementById('company_tab_content');
        if (!tabContent) return;

        // Only do this on the edit page: tab-content is inside a <form>
        var parentForm = tabContent.closest('form');
        if (!parentForm) return;

        var paneIds = ['company_contacts', 'company_activity'];
        paneIds.forEach(function (id) {
            var pane = document.getElementById(id);
            if (pane && !tabContent.contains(pane)) {
                tabContent.appendChild(pane);
            }
        });
    }

    // ── Main ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {

        // Step 1: move out-of-form panes into tab-content (edit page only)
        moveOutOfFormPanesIntoTabContent();

        // Step 2: hash → active tab on load
        var hash = window.location.hash;
        if (hash) {
            // Small delay so Bootstrap's own tab initialisation fires first
            requestAnimationFrame(function () {
                activateTabById(hash);

                // Prevent browser from scrolling to the anchor element
                if (window.location.hash === hash) {
                    window.scrollTo({ top: 0, behavior: 'auto' });
                }
            });
        }

        // Step 3: on tab shown, update URL hash (no scroll jump)
        document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (link) {
            link.addEventListener('shown.bs.tab', function (e) {
                var href = e.target.getAttribute('href');
                if (href && href.startsWith('#')) {
                    try {
                        history.replaceState(null, '', href);
                    } catch (err) { /* cross-origin guard */ }
                }
            });
        });

    });

})();
