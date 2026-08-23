"use strict";

/**
 * crud-charts.js — generic CRUD ApexCharts initialiser.
 *
 * Reads all [data-crud-chart] elements and initialises an ApexCharts instance
 * for each one. The attribute value must be valid JSON produced by <x-crud.chart>:
 *   {
 *     "type":       "bar"|"line"|"area"|"donut"|"pie"|"radialBar",
 *     "series":     [...],          // flat int[] for donut/pie/radialBar; [{name,data}] for bar/line/area
 *     "categories": [...],          // x-axis categories for bar/line/area
 *     "labels":     [...],          // segment labels for donut/pie/radialBar
 *     "colors":     [...],          // per-segment hex or Metronic token strings (optional)
 *     "options":    {...},          // raw ApexCharts overrides, deep-merged over base
 *     "height":     300,
 *     "color":      "primary",      // fallback single Metronic token when colors[] is empty
 *     "showTotal":  false,          // donut centre-total label
 *     "hollowSize": "60%"           // radialBar hollow size
 *   }
 *
 * Metronic color tokens (primary, success, warning, danger, info, secondary) are
 * resolved to CSS variable hex values via KTUtil.getCssVariableValue.
 * Raw hex strings ('#RRGGBB') pass through unchanged.
 *
 * Panes hidden on page load (offsetParent === null) are skipped; they are
 * re-initialised when the containing Bootstrap tab is shown (shown.bs.tab).
 *
 * Guards: silently skips when ApexCharts is not loaded.
 */

(function () {

    var RENDERED_ATTR = 'data-crud-chart-rendered';

    /**
     * Resolve a Metronic semantic color token to a CSS hex/rgb value.
     * Uses KTUtil.getCssVariableValue when available.
     * Raw CSS color strings (hex, rgb, hsl) pass through unchanged.
     */
    function resolveColor(token) {
        if (!token) return '#009EF7';
        // Already a raw CSS color — pass through
        if (token.charAt(0) === '#' || token.indexOf('rgb') === 0 || token.indexOf('hsl') === 0) {
            return token;
        }
        if (typeof KTUtil !== 'undefined' && KTUtil.getCssVariableValue) {
            var resolved = KTUtil.getCssVariableValue('--bs-' + token);
            if (resolved && resolved.trim() !== '') return resolved.trim();
        }
        return token;
    }

    /**
     * Recursively deep-merge two plain objects.
     * Arrays and scalars in `override` replace those in `base` (no array concat).
     */
    function deepMerge(base, override) {
        if (!override || typeof override !== 'object' || Array.isArray(override)) {
            return override !== undefined ? override : base;
        }
        var result = {};
        // Copy all base keys
        for (var k in base) {
            if (Object.prototype.hasOwnProperty.call(base, k)) {
                result[k] = base[k];
            }
        }
        // Merge / overwrite with override keys
        for (var k in override) {
            if (Object.prototype.hasOwnProperty.call(override, k)) {
                if (
                    typeof override[k] === 'object' &&
                    !Array.isArray(override[k]) &&
                    override[k] !== null &&
                    typeof result[k] === 'object' &&
                    !Array.isArray(result[k]) &&
                    result[k] !== null
                ) {
                    result[k] = deepMerge(result[k], override[k]);
                } else {
                    result[k] = override[k];
                }
            }
        }
        return result;
    }

    /**
     * Build the final ApexCharts options object for the given config.
     * Applies a type-specific base, then deep-merges the per-chart `options` override.
     */
    function buildOptions(cfg) {
        var type       = cfg.type       || 'line';
        var height     = cfg.height     || 300;
        var colorArr   = (cfg.colors && cfg.colors.length)
                            ? cfg.colors.map(resolveColor)
                            : [resolveColor(cfg.color || 'primary')];

        // ── Base common to all types ──────────────────────────────────────────
        var base = {
            chart: {
                type:    type,
                height:  height,
                toolbar: { show: false }
            },
            colors:      colorArr,
            dataLabels:  { enabled: false }
        };

        // ── Type-specific base ────────────────────────────────────────────────
        if (type === 'donut' || type === 'pie') {
            base.series  = cfg.series || [];
            base.labels  = cfg.labels || [];
            base.legend  = { position: 'bottom' };
            if (cfg.showTotal) {
                base.plotOptions = {
                    pie: {
                        donut: {
                            labels: {
                                show: true,
                                total: {
                                    show:  true,
                                    label: 'Total',
                                    formatter: function (w) {
                                        return w.globals.seriesTotals.reduce(function (a, b) {
                                            return a + b;
                                        }, 0);
                                    }
                                }
                            }
                        }
                    }
                };
            }

        } else if (type === 'radialBar') {
            base.series  = cfg.series || [];
            base.labels  = cfg.labels || [];
            base.plotOptions = {
                radialBar: {
                    hollow: { size: cfg.hollowSize || '60%' },
                    dataLabels: {
                        name:  { show: true },
                        value: { show: true }
                    }
                }
            };

        } else {
            // bar / line / area
            base.series = cfg.series || [];
            base.xaxis  = { categories: cfg.categories || [] };
        }

        // ── Deep-merge per-chart override ─────────────────────────────────────
        return deepMerge(base, cfg.options || {});
    }

    /**
     * Initialise an ApexCharts chart for a single [data-crud-chart] element.
     * No-ops if already rendered, if ApexCharts is missing, or if config is invalid.
     */
    function initChart(el) {
        if (typeof ApexCharts === 'undefined') return;
        if (el.getAttribute(RENDERED_ATTR)) return;

        var raw = el.getAttribute('data-crud-chart');
        if (!raw) return;

        var cfg;
        try {
            cfg = JSON.parse(raw);
        } catch (e) {
            return;
        }

        el.setAttribute(RENDERED_ATTR, '1');
        new ApexCharts(el, buildOptions(cfg)).render();
    }

    /**
     * Scan all [data-crud-chart] elements and initialise visible ones.
     */
    function initVisibleCharts() {
        document.querySelectorAll('[data-crud-chart]').forEach(function (el) {
            // Skip hidden panes (they will be handled by shown.bs.tab)
            if (el.offsetParent === null) return;
            initChart(el);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof ApexCharts === 'undefined') return;

        initVisibleCharts();

        // Re-init when a tab pane becomes visible
        document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (link) {
            link.addEventListener('shown.bs.tab', function (e) {
                var href = e.target.getAttribute('href');
                if (!href || !href.startsWith('#')) return;
                var pane = document.querySelector(href);
                if (!pane) return;
                pane.querySelectorAll('[data-crud-chart]').forEach(initChart);
            });
        });
    });

})();

