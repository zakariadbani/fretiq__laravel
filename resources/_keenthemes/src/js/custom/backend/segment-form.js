"use strict";

/**
 * Segment Form — Aperçu de l'audience
 *
 * Gère le panneau de prévisualisation en temps réel (bandeau horizontal).
 * Dépendances : jQuery (global), select2 (plugins.bundle.js), CSRF meta tag.
 */

var KTSegmentForm = function () {

    /* ── Configuration ──────────────────────────────────────────────────── */
    var IDS = {
        final:       '#preview_final',
        summary:     '#preview_summary',
        funnel:      '#preview_funnel',
        loading:     '#preview_loading',
        error:       '#preview_error',
        card:        '#segment_preview_card',
        // sample/sampleBody intentionally absent — DOM removed, renderSample is a no-op
    };

    var SEL = {
        scope:          'select[name="scope"]',
        sector:         'select[name="filter[sector][]"]',
        country:        'select[name="filter[country][]"]',
        lifecycleState: 'select[name="filter[lifecycle_state]"]',
        position:       'select[name="filter[position][]"]',
        criteriaId:     'select[name="filter[criteria_id][]"]',
        excludeContacted:      'input[name="filter[exclude_contacted]"]',
        excludeGenericMailbox: 'input[name="filter[exclude_generic_mailbox]"]',
        mode:    'input[name="is_manual"]',
    };

    /*
     * Labels pour les chips d'entonnoir (ordre d'affichage).
     * manually_included is an ANNOTATION row (chip "dont N épinglés") — not a subtraction.
     * manually_excluded is the only new − term.
     */
    var FUNNEL_ROWS = [
        { key: 'matched',             label: 'correspondants',           alwaysShow: true,  bold: false, variant: 'secondary' },
        { key: 'manually_included',   label: 'dont épinglés',            alwaysShow: false, bold: false, variant: 'primary',  prefix: '+' },
        { key: 'suppressed',          label: '− suppression',             alwaysShow: false, bold: false, variant: 'danger'   },
        { key: 'duplicates_excluded', label: '− doublons',                alwaysShow: false, bold: false, variant: 'secondary'},
        { key: 'verification_excluded', label: '− email non vérifié',     alwaysShow: false, bold: false, variant: 'danger'   },
        { key: 'manually_excluded',   label: '− exclus manuellement',     alwaysShow: false, bold: false, variant: 'danger'   },
        { key: 'final',               label: 'destinataires éligibles',             alwaysShow: true,  bold: true,  variant: 'primary'  },
    ];

    /* ── État interne ───────────────────────────────────────────────────── */
    var _debounceTimer  = null;
    var _sequence       = 0;
    var _activeXhr      = null;
    var _lastGoodData   = null;

    function isManual() {
        return $(SEL.mode + ':checked').val() === '1';
    }

    function syncManualMode() {
        var manual = isManual();
        var $scope = $(SEL.scope);
        var $manualScope = $('#manual_scope_value');

        if (manual) {
            $manualScope.val($scope.val() || 'client');
        }

        $('[data-segment-dynamic-fields]')
            .prop('hidden', manual)
            .find('select, input, textarea, button')
            .prop('disabled', manual);
        $manualScope.prop('disabled', !manual);
        $(IDS.card).prop('hidden', manual);
        $('[data-segment-manual-create-guidance]')
            .toggleClass('d-none', !manual)
            .toggleClass('d-flex', manual);
    }

    function scrollToContactsHash() {
        if (window.location.hash !== '#segment_contacts') return;

        // crud-tabs.js registers first and schedules its hash reset in rAF.
        // Queue after it so the relocated pane is the final scroll target and
        // becomes intersectable for segment-contacts.js lazy loading.
        window.requestAnimationFrame(function () {
            var pane = document.querySelector('#segment_contacts[data-crud-pane]');
            if (pane) pane.scrollIntoView({ block: 'start', behavior: 'auto' });
        });
    }

    /* ── Initialisation select2 ─────────────────────────────────────────── */
    function initSelect2() {
        if (!$.fn.select2) return;
        $('[data-control="select2"]').each(function () {
            var $el = $(this);
            if ($el.hasClass('select2-hidden-accessible')) return;
            var placeholder = $el.data('placeholder') || '';
            var allowClear  = !!$el.data('allow-clear');
            $el.select2({
                width:       '100%',
                placeholder: placeholder,
                allowClear:  allowClear,
            });
        });
    }

    /* ── Collecte du payload ────────────────────────────────────────────── */
    function collectPayload() {
        var csrf = $('meta[name="csrf-token"]').attr('content')
                || $('input[name="_token"]', '#form_crud').val()
                || '';

        return {
            _token: csrf,
            scope:  $(SEL.scope).val()  || '',
            filter: {
                sector:                  $(SEL.sector).val()  || [],
                country:                 $(SEL.country).val() || [],
                lifecycle_state:         $(SEL.lifecycleState).val() || '',
                position:                $(SEL.position).val() || [],
                criteria_id:             $(SEL.criteriaId).val() || [],
                exclude_contacted:       $(SEL.excludeContacted).is(':checked') ? 1 : 0,
                exclude_generic_mailbox: $(SEL.excludeGenericMailbox).is(':checked') ? 1 : 0,
            },
        };
    }

    /* ── États visuels ──────────────────────────────────────────────────── */
    function showLoading() {
        $(IDS.loading).removeClass('d-none');
        $(IDS.error).addClass('d-none');
    }

    function hideLoading() {
        $(IDS.loading).addClass('d-none');
    }

    function showError() {
        $(IDS.error).removeClass('d-none');
        if (_lastGoodData) {
            $(IDS.final).addClass('text-muted').removeClass('text-gray-900');
        } else {
            $(IDS.final).text('—');
        }
    }

    /* ── Rendu de l'entonnoir — chips horizontaux ───────────────────────── */
    function renderFunnel(data) {
        var $funnel = $(IDS.funnel);
        $funnel.empty();

        FUNNEL_ROWS.forEach(function (row) {
            var value = data[row.key];
            if (value === undefined || value === null) return;
            if (!row.alwaysShow && value === 0) return;

            /* Build badge chip */
            var badgeCls = 'badge badge-light-' + (row.variant || 'secondary');
            if (row.bold) { badgeCls += ' fw-bold fs-7'; } else { badgeCls += ' fs-8'; }

            var prefix = row.prefix || (row.key === 'final' ? '= ' : '');
            var icon   = '';
            if (row.warning && value > 0) {
                icon = '<i class="bi bi-exclamation-triangle me-1"></i>';
            } else if (row.key === 'final') {
                icon = '<i class="bi bi-people me-1"></i>';
            } else if (row.key === 'manually_included') {
                icon = '<i class="bi bi-pin-fill me-1"></i>';
            } else if (row.key === 'manually_excluded') {
                icon = '<i class="bi bi-dash-circle me-1"></i>';
            }

            var html = '<span class="' + badgeCls + '" title="' + row.label + '">'
                     + icon
                     + prefix + value + ' ' + row.label
                     + '</span>';

            $funnel.append(html);
        });
    }

    /* ── renderSample — no-op (DOM element removed; Contacts pane replaces it) ── */
    function renderSample(sample) {
        /* intentional no-op — #preview_sample no longer exists in the DOM */
    }

    /* ── Rendu complet d'une réponse de prévisualisation ────────────────── */
    function renderResponse(data) {
        _lastGoodData = data;

        var finalCount = (data.final !== undefined && data.final !== null) ? data.final : '—';
        $(IDS.final)
            .text(finalCount)
            .removeClass('text-muted')
            .addClass('text-gray-900');

        $(IDS.summary).text(data.summary || '');

        renderFunnel(data);

        renderSample(data.sample || []);

        $(IDS.error).addClass('d-none');
    }

    /* ── État neutre (portée non encore choisie) ────────────────────────── */
    function showWaiting() {
        if (_activeXhr) {
            _activeXhr.abort();
            _activeXhr = null;
        }
        clearTimeout(_debounceTimer);

        $(IDS.loading).addClass('d-none');
        $(IDS.error).addClass('d-none');
        $(IDS.funnel).empty();
        $(IDS.final).text('—').removeClass('text-gray-900').addClass('text-muted');
        $(IDS.summary).text('Choisissez une portée pour calculer l\'aperçu.').addClass('text-muted');
    }

    /* ── Appel prévisualisation ─────────────────────────────────────────── */
    function doPreview() {
        if (isManual()) {
            return;
        }

        var scope = $(SEL.scope).val();
        if (!scope) {
            showWaiting();
            return;
        }

        var previewUrl = $(IDS.card).data('preview-url');
        if (!previewUrl) return;

        if (_activeXhr) {
            _activeXhr.abort();
            _activeXhr = null;
        }

        var mySeq = ++_sequence;

        showLoading();

        var payload = collectPayload();

        _activeXhr = $.ajax({
            url:         previewUrl,
            method:      'POST',
            data:        payload,
            traditional: false,
            dataType:    'json',
        });

        _activeXhr
            .done(function (data) {
                if (mySeq !== _sequence) return;
                hideLoading();
                renderResponse(data);
            })
            .fail(function (xhr, textStatus) {
                if (textStatus === 'abort') return;
                if (mySeq !== _sequence) return;
                hideLoading();
                showError();
            })
            .always(function () {
                _activeXhr = null;
            });
    }

    /* ── Synchronise le panneau Contacts avec le filtre courant ─────────── */
    function syncContacts() {
        if (!window.KTSegmentContacts) return;
        if (isManual()) {
            if (window.KTSegmentContacts.clearLive) { window.KTSegmentContacts.clearLive(); }
            return;
        }
        var scope = $(SEL.scope).val();
        if (!scope) {
            if (window.KTSegmentContacts.clearLive) { window.KTSegmentContacts.clearLive(); }
            return;
        }
        var payload = collectPayload();
        if (window.KTSegmentContacts.reload) {
            window.KTSegmentContacts.reload($.param({ scope: scope, filter: payload.filter }));
        }
    }

    /* ── Déclencheur avec debounce ──────────────────────────────────────── */
    function refreshPreview() {
        clearTimeout(_debounceTimer);
        _debounceTimer = setTimeout(function () {
            doPreview();
            syncContacts();
        }, 400);
    }

    /* ── Binding des événements ─────────────────────────────────────────── */
    function bindEvents() {
        $(SEL.scope).on('change', refreshPreview);
        $(SEL.mode).on('change', function () {
            syncManualMode();
            refreshPreview();
        });
        $(SEL.sector).on('change', refreshPreview);
        $(SEL.country).on('change', refreshPreview);
        $(SEL.lifecycleState).on('change', refreshPreview);
        $(SEL.position).on('change', refreshPreview);
        $(SEL.criteriaId).on('change', refreshPreview);
        $(SEL.excludeContacted).on('change', refreshPreview);
        $(SEL.excludeGenericMailbox).on('change', refreshPreview);
    }

    /* ── Point d'entrée public ──────────────────────────────────────────── */
    return {
        init: function () {
            initSelect2();
            syncManualMode();
            bindEvents();
            if (!isManual()) {
                doPreview();
            }
            scrollToContactsHash();
        },
        /* Exposed for segment-contacts.js to call after pin mutations */
        refresh: function () {
            doPreview();
        }
    };

}();

$(document).ready(function () {
    KTSegmentForm.init();
});
