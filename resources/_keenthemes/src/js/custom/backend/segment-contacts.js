"use strict";

/**
 * segment-contacts.js — Hybrid smart-list contact management for a segment.
 *
 * Vanilla JS (no jQuery dependency here).
 * Relies on:
 *   - #segment_contacts_wrapper  (data-list-url)
 *   - #segment_contacts_count
 *   - #segment_contact_search    (data-search-url inside the picker modal)
 *   - window.KTSegmentForm.refresh()  (optional — called after pin mutations)
 *   - CSRF meta[name="csrf-token"]
 */
(function () {

    /* ── Module-level state ─────────────────────────────────────────────── */
    var _liveQuery    = '';     // serialised query string for the current live filter
    var _reloadSeq    = 0;      // monotonically increasing sequence counter
    var _activeReload = null;   // AbortController for the in-flight reload fetch
    var _observer     = null;   // IntersectionObserver (nullable after disarm)

    /* ── CSRF ───────────────────────────────────────────────────────────── */
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */
    function getWrapper() {
        return document.getElementById('segment_contacts_wrapper');
    }

    function getCountBadge() {
        return document.getElementById('segment_contacts_count');
    }

    function showToast(msg, type) {
        if (typeof toastr !== 'undefined') {
            if (type === 'error') { toastr.error(msg); }
            else { toastr.success(msg); }
        }
    }

    function setButtonLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = loading;
        if (loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm align-middle me-1"></span>';
        } else if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
        }
    }

    /* ── Update count badge from the fragment root data attribute ───────── */
    function updateCountFromFragment(wrapper) {
        var el    = wrapper ? wrapper.querySelector('[data-contacts-count]') : null;
        var badge = getCountBadge();
        if (el && badge) {
            badge.textContent = el.getAttribute('data-contacts-count');
        }
    }

    /* ── Fetch and swap the contacts wrapper HTML (sequence-guarded) ────── */
    function loadContacts(url, btn) {
        var wrapper = getWrapper();
        if (!wrapper) return Promise.resolve();

        /* Abort any in-flight reload */
        if (_activeReload) {
            _activeReload.abort();
            _activeReload = null;
        }

        /* Capture this request's sequence token */
        _reloadSeq += 1;
        var mySeq = _reloadSeq;

        /* Create AbortController for this fetch */
        var controller = new AbortController();
        _activeReload  = controller;

        return fetch(url, {
            signal: controller.signal,
            headers: {
                'Accept': 'text/html',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            }
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(function (html) {
            /* Stale-response guard: discard if a newer reload started */
            if (mySeq !== _reloadSeq) return;

            /* Swap the wrapper content */
            wrapper.innerHTML = html;

            /* Update count badge from the fragment root data attribute */
            updateCountFromFragment(wrapper);

            /* Re-bind excluded toggle after swap */
            bindExcludedToggle();

            /* Clear the AbortController reference for this completed fetch */
            if (_activeReload === controller) {
                _activeReload = null;
            }
        })
        .catch(function (err) {
            /* Silently ignore aborted fetches */
            if (err && err.name === 'AbortError') return;

            if (btn) {
                showToast('Échec, réessayez', 'error');
            }

            if (_activeReload === controller) {
                _activeReload = null;
            }
        });
    }

    /* ── Update count badge from a JSON counts object (pin path) ────────── */
    function updateCount(counts) {
        if (!counts) return;
        var badge = getCountBadge();
        if (badge && counts.contacts_count !== undefined) {
            badge.textContent = counts.contacts_count;
        }
    }

    /* ── Disarm the IntersectionObserver (called before a live reload) ──── */
    function disarmObserver() {
        if (_observer) {
            _observer.disconnect();
            _observer = null;
        }
    }

    /* ── Lazy-load on first scroll-into-view (D5) ───────────────────────── */
    function initLazyLoad() {
        var wrapper = getWrapper();
        if (!wrapper) return;

        var listUrl = wrapper.dataset.listUrl;
        if (!listUrl) return;

        if (!('IntersectionObserver' in window)) {
            /* Eager fallback for older browsers */
            loadContacts(listUrl, null);
            return;
        }

        _observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    obs.disconnect();
                    _observer = null;
                    loadContacts(listUrl, null);
                }
            });
        }, { threshold: 0.1 });

        _observer.observe(wrapper);
    }

    /* ── Pin mutation (POST or DELETE) ──────────────────────────────────── */
    function doPin(url, method, body, btn) {
        setButtonLoading(btn, true);

        var options = {
            method: method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (body) {
            options.body = JSON.stringify(body);
        }

        return fetch(url, options)
        .then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                throw new Error('server error');
            }
            /* Update count badge via JSON path (fast, before fragment lands) */
            updateCount(result.data.counts);

            /* Re-fetch and swap the contacts fragment */
            var wrapper = getWrapper();
            var listUrl = wrapper ? wrapper.dataset.listUrl : null;
            if (listUrl) {
                /* Preserve _liveQuery when reloading after a pin mutation */
                var reloadUrl = listUrl.split('?')[0] + (_liveQuery ? '?' + _liveQuery : '');
                return loadContacts(reloadUrl, btn).then(function () {
                    /* Refresh the audience bandeau */
                    if (window.KTSegmentForm && typeof window.KTSegmentForm.refresh === 'function') {
                        window.KTSegmentForm.refresh();
                    }
                    setButtonLoading(btn, false);
                });
            }
        })
        .catch(function () {
            setButtonLoading(btn, false);
            showToast('Échec, réessayez', 'error');
        });
    }

    /* ── Event delegation on document ──────────────────────────────────── */
    document.addEventListener('click', function (e) {

        /* Exclude (filter → manually excluded) */
        var excludeBtn = e.target.closest('.btn-segment-exclude');
        if (excludeBtn) {
            e.preventDefault();
            var pinUrl     = excludeBtn.dataset.pinUrl;
            var contactId  = excludeBtn.dataset.contactId;
            if (pinUrl && contactId) {
                doPin(pinUrl, 'POST', { contact_id: parseInt(contactId, 10), mode: 'exclude' }, excludeBtn);
            }
            return;
        }

        /* Unpin (pinned → removed from segment) */
        var unpinBtn = e.target.closest('.btn-segment-unpin');
        if (unpinBtn) {
            e.preventDefault();
            var unpinUrl = unpinBtn.dataset.unpinUrl;
            if (unpinUrl) {
                doPin(unpinUrl, 'DELETE', null, unpinBtn);
            }
            return;
        }

        /* Reinclude (excluded → unpin the exclude) */
        var reincludeBtn = e.target.closest('.btn-segment-reinclude');
        if (reincludeBtn) {
            e.preventDefault();
            var reincUrl = reincludeBtn.dataset.unpinUrl;
            if (reincUrl) {
                doPin(reincUrl, 'DELETE', null, reincludeBtn);
            }
            return;
        }

        /* Pagination — preserve _liveQuery */
        var pageBtn = e.target.closest('.segment-contacts-page');
        if (pageBtn) {
            e.preventDefault();
            var page    = pageBtn.dataset.page;
            var wrapper = getWrapper();
            var listUrl = wrapper ? wrapper.dataset.listUrl : null;
            if (listUrl && page) {
                var pagedUrl = listUrl.split('?')[0] + '?page=' + page + (_liveQuery ? '&' + _liveQuery : '');
                loadContacts(pagedUrl, null);
            }
            return;
        }

        /* Pick contact from modal */
        var pickBtn = e.target.closest('.btn-pick-contact');
        if (pickBtn) {
            e.preventDefault();
            var pickPinUrl    = pickBtn.dataset.pinUrl;
            var pickContactId = pickBtn.dataset.contactId;
            if (pickPinUrl && pickContactId) {
                doPin(pickPinUrl, 'POST', { contact_id: parseInt(pickContactId, 10), mode: 'include' }, pickBtn)
                .then(function () {
                    /* Close the modal */
                    var modal = document.getElementById('segment_contacts_modal');
                    if (modal) {
                        var bsModal = bootstrap && bootstrap.Modal ? bootstrap.Modal.getInstance(modal) : null;
                        if (bsModal) bsModal.hide();
                    }
                });
            }
            return;
        }

    });

    /* ── Excluded chip toggle (D4) ──────────────────────────────────────── */
    function bindExcludedToggle() {
        var toggleBtn  = document.getElementById('segment_excluded_toggle');
        var toggleList = document.getElementById('segment_excluded_list');
        var chevron    = document.getElementById('segment_excluded_chevron');
        if (!toggleBtn || !toggleList) return;

        toggleBtn.addEventListener('click', function () {
            var isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
            isExpanded = !isExpanded;
            toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            toggleList.classList.toggle('d-none', !isExpanded);
            if (chevron) {
                chevron.classList.toggle('bi-chevron-down', !isExpanded);
                chevron.classList.toggle('bi-chevron-up', isExpanded);
            }
        });
    }

    /* ── Picker search ──────────────────────────────────────────────────── */
    function initPickerSearch() {
        var searchInput = document.getElementById('segment_contact_search');
        if (!searchInput) return;

        var resultsEl   = document.getElementById('segment_contact_results');
        var emptyEl     = document.getElementById('segment_contact_search_empty');
        var errorEl     = document.getElementById('segment_contact_search_error');
        var hintEl      = document.getElementById('segment_contact_search_hint');
        var spinnerEl   = document.getElementById('segment_contact_search_spinner');

        var debounce    = null;

        function clearResults() {
            if (resultsEl)  resultsEl.innerHTML = '';
            if (emptyEl)    emptyEl.classList.add('d-none');
            if (errorEl)    errorEl.classList.add('d-none');
        }

        function showSpinner(on) {
            if (spinnerEl) spinnerEl.classList.toggle('d-none', !on);
        }

        function renderResults(results) {
            if (!resultsEl) return;
            resultsEl.innerHTML = '';

            if (!results || results.length === 0) {
                if (emptyEl) emptyEl.classList.remove('d-none');
                return;
            }

            if (emptyEl) emptyEl.classList.add('d-none');

            /* Build pinUrl from the search URL (replace /search with contacts endpoint) */
            var searchUrl  = searchInput.dataset.searchUrl || '';
            var basePinUrl = searchInput.dataset.pinUrl || searchUrl.replace('/contacts/search', '/contacts');

            results.forEach(function (contact) {
                var item = document.createElement('div');
                item.className = 'd-flex align-items-center justify-content-between py-3 border-bottom';
                item.setAttribute('role', 'option');
                item.innerHTML =
                    '<div class="d-flex flex-column">'
                    + '<span class="fw-semibold text-gray-800 fs-7">' + escHtml(contact.name) + '</span>'
                    + '<span class="text-muted fs-8">' + escHtml(contact.email) + ' · ' + escHtml(contact.company || '—') + '</span>'
                    + '</div>'
                    + '<button type="button"'
                    + ' class="btn btn-sm btn-light-primary btn-pick-contact"'
                    + ' style="min-height:38px;"'
                    + ' data-contact-id="' + escAttr(String(contact.id)) + '"'
                    + ' data-pin-url="' + escAttr(basePinUrl) + '"'
                    + ' aria-label="Ajouter ' + escAttr(contact.name) + '">'
                    + '<i class="bi bi-plus fs-6 me-1"></i>Ajouter'
                    + '</button>';
                resultsEl.appendChild(item);
            });
        }

        function doSearch(q) {
            clearResults();
            if (q.length < 2) {
                if (hintEl) hintEl.classList.remove('d-none');
                return;
            }
            if (hintEl) hintEl.classList.add('d-none');

            var searchUrl = searchInput.dataset.searchUrl;
            if (!searchUrl) return;

            showSpinner(true);

            fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                }
            })
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                showSpinner(false);
                var results = data.results || [];
                renderResults(results);
            })
            .catch(function () {
                showSpinner(false);
                if (errorEl) errorEl.classList.remove('d-none');
            });
        }

        searchInput.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                doSearch(searchInput.value.trim());
            }, 300);
        });

        /* Clear on modal hidden */
        var modal = document.getElementById('segment_contacts_modal');
        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                searchInput.value = '';
                clearResults();
                if (hintEl) hintEl.classList.remove('d-none');
            });
            /* Focus input when modal opens */
            modal.addEventListener('shown.bs.modal', function () {
                searchInput.focus();
            });
        }

        /* Keyboard: Esc closes modal if focused in search */
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var modalEl = document.getElementById('segment_contacts_modal');
                if (modalEl) {
                    var bsModal = bootstrap && bootstrap.Modal ? bootstrap.Modal.getInstance(modalEl) : null;
                    if (bsModal) bsModal.hide();
                }
            }
        });
    }

    /* ── HTML escape helpers ────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;');
    }

    /* ── Init ───────────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        initLazyLoad();
        initPickerSearch();
        bindExcludedToggle();
    });

    /* ── Public API — called by segment-form.js on filter change ────────── */
    window.KTSegmentContacts = {
        /**
         * Reload the contacts pane with a live filter query string.
         * queryString: e.g. "scope=client&filter[sector][]=Transport"
         */
        reload: function (queryString) {
            _liveQuery = queryString || '';
            disarmObserver();
            var wrapper = getWrapper();
            var base    = wrapper ? wrapper.dataset.listUrl : null;
            if (base) {
                loadContacts(base.split('?')[0] + (_liveQuery ? '?' + _liveQuery : ''), null);
            }
        },
        /**
         * Clear the live query string (called when scope becomes empty).
         */
        clearLive: function () {
            _liveQuery = '';
            disarmObserver();
            var wrapper = getWrapper();
            var base = wrapper ? wrapper.dataset.listUrl : null;
            if (base) loadContacts(base.split('?')[0], null);
        }
    };

})();

