/**
 * DataTable Utilities
 * Reusable functions for DataTable operations across the application
 *
 * @version 1.0.0
 */

const DataTableUtils = (function() {
    'use strict';

    /**
     * Get DataTable instance by table ID
     * Supports both singular and plural naming conventions
     * @param {string} tableId - The table ID (e.g., 'company', 'contact')
     * @returns {DataTable|null}
     */
    function getDataTable(tableId) {
        if (!window.LaravelDataTables) {
            console.error('LaravelDataTables not initialized');
            return null;
        }

        // Try exact match first
        const exactId = tableId.endsWith('-table') ? tableId : tableId + '-table';
        if (window.LaravelDataTables[exactId]) {
            return window.LaravelDataTables[exactId];
        }

        // Try plural form
        const pluralId = tableId + 's-table';
        if (window.LaravelDataTables[pluralId]) {
            return window.LaravelDataTables[pluralId];
        }

        // Try to find by checking all available instances
        const availableKeys = Object.keys(window.LaravelDataTables);
        console.warn('DataTable not found. Available tables:', availableKeys);

        // Return first match if only one exists
        if (availableKeys.length === 1) {
            console.info('Using available table:', availableKeys[0]);
            return window.LaravelDataTables[availableKeys[0]];
        }

        console.error('DataTable instance not found for:', tableId);
        return null;
    }

    /**
     * Show success toast notification
     * @param {string} message
     */
    function showSuccessToast(message) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: message,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    }

    /**
     * Show error toast notification
     * @param {string} message
     */
    function showErrorToast(message) {
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: message,
            showConfirmButton: false,
            timer: 5000
        });
    }

    /**
     * Extract the most useful error text from a JSON response.
     *
     * Laravel endpoints may return either `msg`, `message`, or validation
     * details in `errors`. Prefer those details over a generic configured
     * fallback so toggles surface actionable reasons to the user.
     *
     * @param {Object} data
     * @param {string} fallback
     * @returns {string}
     */
    function extractErrorMessage(data, fallback) {
        if (data && data.msg) {
            return data.msg;
        }

        if (data && data.errors && typeof data.errors === 'object') {
            const messages = [];

            Object.values(data.errors).forEach(function (value) {
                if (Array.isArray(value)) {
                    value.forEach(function (message) {
                        if (message) messages.push(message);
                    });
                } else if (value) {
                    messages.push(value);
                }
            });

            if (messages.length > 0) {
                return messages.join(' ');
            }
        }

        if (data && data.message && data.message !== 'error') {
            return data.message;
        }

        return fallback;
    }

    /**
     * Show confirmation dialog for delete
     * @param {string} message
     * @param {Function} onConfirm
     * @param {Object} options
     */
    function confirmDelete(message, onConfirm, options = {}) {
        const defaults = {
            confirmButtonText: 'Oui, supprimer !',
            cancelButtonText: 'Non, annuler'
        };
        const config = { ...defaults, ...options };

        Swal.fire({
            text: message,
            icon: 'warning',
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: config.confirmButtonText,
            cancelButtonText: config.cancelButtonText,
            customClass: {
                confirmButton: 'btn fw-bold btn-danger',
                cancelButton: 'btn fw-bold btn-active-light-primary'
            }
        }).then(function(result) {
            if (result.value) {
                onConfirm();
            }
        });
    }

    /**
     * Update DataTable URL with query parameters
     * @param {DataTable} dt
     * @param {Object} params - Key-value pairs of parameters
     */
    function updateUrl(dt, params) {
        if (!dt) return;

        let currentUrl = dt.ajax.url();
        const baseUrl = currentUrl.split('?')[0];

        // Build query string from params
        const queryParams = [];
        for (const [key, value] of Object.entries(params)) {
            if (value) {
                queryParams.push(`${key}=${encodeURIComponent(value)}`);
            }
        }

        const newUrl = queryParams.length > 0
            ? `${baseUrl}?${queryParams.join('&')}`
            : baseUrl;

        return newUrl;
    }

    /**
     * Reload DataTable
     * @param {string} tableId
     */
    function reloadTable(tableId) {
        const dt = getDataTable(tableId);
        if (dt) {
            dt.ajax.reload();
        }
    }

    /**
     * Initialize search functionality
     * @param {string} tableId - DataTable ID
     * @param {string} searchInputId - Search input element ID
     */
    function initializeSearch(tableId, searchInputId) {
        const searchInput = document.getElementById(searchInputId);
        if (!searchInput) {
            console.warn('Search input not found:', searchInputId);
            return;
        }

        let searchTimer = null;
        searchInput.addEventListener('keyup', function() {
            const value = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                const dt = getDataTable(tableId);
                if (dt) {
                    dt.search(value).draw();
                }
            }, 350);
        });

        console.log('Search initialized for', tableId);
    }

    /**
     * Initialize filter functionality
     * @param {string} tableId - DataTable ID
     * @param {Object} options - Configuration options
     */
    function initializeFilter(tableId, options = {}) {
        const defaults = {
            filterButtonSelector: '[data-kt-table-filter="filter"]',
            resetButtonSelector: '[data-kt-table-filter="reset"]',
            statusSelector: '[data-kt-table-filter="status"]',
            paramName: 'status',
            onFilterApplied: null,
            onFilterReset: null
        };
        const config = { ...defaults, ...options };

        const filterButton = document.querySelector(config.filterButtonSelector);
        const resetButton = document.querySelector(config.resetButtonSelector);
        const statusSelect = document.querySelector(config.statusSelector);

        if (!filterButton || !resetButton || !statusSelect) {
            console.warn('Filter elements not found for', tableId);
            return;
        }

        // Apply filter
        filterButton.addEventListener('click', function(e) {
            e.preventDefault();

            const filterValue = statusSelect.value;
            const dt = getDataTable(tableId);

            if (!dt) {
                console.error('DataTable not available');
                return;
            }

            // Get current URL and build new one with filter
            const currentUrl = dt.ajax.url();
            const baseUrl = currentUrl.split('?')[0];

            const newUrl = filterValue
                ? `${baseUrl}?${config.paramName}=${encodeURIComponent(filterValue)}`
                : baseUrl;

            // Update URL and reload
            dt.ajax.url(newUrl).load();

            // Call custom callback if provided
            if (config.onFilterApplied) {
                config.onFilterApplied(filterValue);
            }
        });

        // Reset filter
        resetButton.addEventListener('click', function(e) {
            e.preventDefault();

            // Clear select value
            statusSelect.value = '';

            // Handle Select2 if initialized
            if (typeof $ !== 'undefined' && $(statusSelect).data('select2')) {
                $(statusSelect).val('').trigger('change');
            }

            const dt = getDataTable(tableId);
            if (!dt) {
                console.error('DataTable not available');
                return;
            }

            // Reset to base URL
            const currentUrl = dt.ajax.url();
            const baseUrl = currentUrl.split('?')[0];

            dt.ajax.url(baseUrl).load();

            // Call custom callback if provided
            if (config.onFilterReset) {
                config.onFilterReset();
            }
        });

        console.log('Filter initialized for', tableId);
    }

    /**
     * Generate filter HTML dynamically based on configuration
     * @param {Array} filterConfigs - Array of filter configuration objects
     * @returns {string} HTML string for filters
     *
     * Filter config structure:
     * {
     *   type: 'boolean'|'select'|'text',
     *   name: 'active',
     *   label: 'Actif',
     *   paramName: 'active',
     *   trueLabel: 'Oui',       // For boolean
     *   falseLabel: 'Non',      // For boolean
     *   options: [],            // For select: [{ value: '', text: '' }]
     *   placeholder: '',        // For text/select
     *   allowNull: true         // For select: show "Tous" option
     * }
     */
    function generateFilterHTML(filterConfigs) {
        let html = '';

        filterConfigs.forEach((config, index) => {
            const filterName = config.name || `filter_${index}`;
            const dataAttr = config.paramName || filterName;
            const labelId = `${filterName}_label`;

            html += `<!--begin::Input group - ${config.label}-->\n`;
            html += `<div class="mb-5">\n`;
            html += `    <label class="form-label fs-6 fw-semibold mb-3" id="${labelId}">${escapeHtml(config.label)}:</label>\n`;

            // Generate HTML based on filter type
            switch (config.type) {
                case 'boolean':
                    html += generateBooleanFilter(config, filterName, dataAttr, labelId);
                    break;

                case 'select':
                    html += generateSelectFilter(config, filterName, dataAttr, labelId);
                    break;

                case 'text':
                    html += generateTextFilter(config, filterName, dataAttr, labelId);
                    break;

                case 'date':
                    html += generateDateFilter(config, filterName, dataAttr);
                    break;

                case 'money':
                    html += generateMoneyFilter(config, filterName);
                    break;

                default:
                    console.warn('Unknown filter type:', config.type);
            }

            html += `</div>\n`;
            html += `<!--end::Input group-->\n`;
        });

        return html;
    }

    /**
     * Generate boolean toggle button group HTML
     */
    function generateBooleanFilter(config, filterName, dataAttr, labelId) {
        const trueLabel = config.trueLabel || 'Oui';
        const falseLabel = config.falseLabel || 'Non';
        const allLabel = config.allLabel || 'Tous';

        const idAll = `${filterName}_all`;
        const idYes = `${filterName}_yes`;
        const idNo = `${filterName}_no`;

        let html = `    <div class="btn-group w-100" role="group" aria-labelledby="${labelId}" data-kt-table-filter="${escapeAttribute(dataAttr)}" data-kt-buttons="true">\n`;

        // All button
        html += `        <input type="radio" class="btn-check" name="${escapeAttribute(filterName)}" value="" id="${idAll}" checked>\n`;
        html += `        <label class="btn btn-sm btn-outline btn-outline-dashed btn-active-light-primary" for="${idAll}">\n`;
        html += `            ${escapeHtml(allLabel)}\n`;
        html += `        </label>\n\n`;

        // Yes/True button
        html += `        <input type="radio" class="btn-check" name="${escapeAttribute(filterName)}" value="1" id="${idYes}">\n`;
        html += `        <label class="btn btn-sm btn-outline btn-outline-dashed btn-active-light-success" for="${idYes}">\n`;
        html += `            ${escapeHtml(trueLabel)}\n`;
        html += `        </label>\n\n`;

        // No/False button
        html += `        <input type="radio" class="btn-check" name="${escapeAttribute(filterName)}" value="0" id="${idNo}">\n`;
        html += `        <label class="btn btn-sm btn-outline btn-outline-dashed btn-active-light-danger" for="${idNo}">\n`;
        html += `            ${escapeHtml(falseLabel)}\n`;
        html += `        </label>\n`;

        html += `    </div>\n`;

        return html;
    }

    /**
     * Generate select dropdown HTML
     */
    function generateSelectFilter(config, filterName, dataAttr, labelId) {
        const placeholder = config.placeholder || `Sélectionner ${config.label.toLowerCase()}`;
        const allowNull = config.allowNull !== false; // Default to true
        const id = `${filterName}_select`;

        let html = `    <select id="${id}" class="form-select form-select-solid" data-kt-table-filter="${escapeAttribute(dataAttr)}" `;
        html += `aria-labelledby="${labelId}" data-placeholder="${escapeAttribute(placeholder)}" data-allow-clear="true" data-hide-search="${config.hideSearch ? 'true' : 'false'}">\n`;

        // Add "Tous" option if allowed
        if (allowNull) {
            html += `        <option value="">Tous</option>\n`;
        }

        // Add options
        if (config.options && Array.isArray(config.options)) {
            config.options.forEach(option => {
                const value = typeof option === 'object' ? option.value : option;
                const text = typeof option === 'object' ? option.text : option;
                html += `        <option value="${escapeAttribute(value)}">${escapeHtml(text)}</option>\n`;
            });
        }

        html += `    </select>\n`;

        return html;
    }

    /**
     * Generate text input HTML
     */
    function generateTextFilter(config, filterName, dataAttr, labelId) {
        const placeholder = config.placeholder || config.label;
        const id = `${filterName}_input`;

        let html = `    <input type="text" id="${id}" class="form-control form-control-solid" `;
        html += `aria-labelledby="${labelId}" data-kt-table-filter="${escapeAttribute(dataAttr)}" placeholder="${escapeAttribute(placeholder)}" />\n`;

        return html;
    }

    /**
     * Generate date range filter HTML (Du / Au)
     */
    function generateDateFilter(config, filterName, dataAttr) {
        const fromId = `${filterName}_from`;
        const toId = `${filterName}_to`;
        let html = `    <div class="d-flex gap-3">\n`;
        html += `        <div class="flex-grow-1">\n`;
        html += `            <label class="form-label fs-7 text-muted mb-1" for="${fromId}">Du</label>\n`;
        html += `            <input type="date" id="${fromId}" class="form-control form-control-solid form-control-sm" data-kt-table-filter="${escapeAttribute(dataAttr)}_from" />\n`;
        html += `        </div>\n`;
        html += `        <div class="flex-grow-1">\n`;
        html += `            <label class="form-label fs-7 text-muted mb-1" for="${toId}">Au</label>\n`;
        html += `            <input type="date" id="${toId}" class="form-control form-control-solid form-control-sm" data-kt-table-filter="${escapeAttribute(dataAttr)}_to" />\n`;
        html += `        </div>\n`;
        html += `    </div>\n`;

        return html;
    }

    /**
     * Generate money range filter HTML
     */
    function generateMoneyFilter(config, filterName) {
        const dataAttr = config.key || config.paramName || config.name;
        const minId = `${filterName}_min`;
        const maxId = `${filterName}_max`;

        let html = `    <div class="d-flex gap-3">\n`;
        html += `        <div class="flex-grow-1">\n`;
        html += `            <label class="form-label fs-7 text-muted mb-1" for="${minId}">Min</label>\n`;
        html += `            <input type="number" id="${minId}" class="form-control form-control-solid form-control-sm" data-kt-table-filter="${escapeAttribute(dataAttr)}_from" placeholder="Min €" />\n`;
        html += `        </div>\n`;
        html += `        <div class="flex-grow-1">\n`;
        html += `            <label class="form-label fs-7 text-muted mb-1" for="${maxId}">Max</label>\n`;
        html += `            <input type="number" id="${maxId}" class="form-control form-control-solid form-control-sm" data-kt-table-filter="${escapeAttribute(dataAttr)}_to" placeholder="Max €" />\n`;
        html += `        </div>\n`;
        html += `    </div>\n`;

        return html;
    }

    /**
     * Render filters into a container element
     * @param {string} containerId - ID of container element
     * @param {Array} filterConfigs - Array of filter configuration objects
     */
    function renderFilters(containerId, filterConfigs) {
        const container = document.getElementById(containerId);
        if (!container) {
            console.error('Filter container not found:', containerId);
            return;
        }

        const html = generateFilterHTML(filterConfigs);
        container.innerHTML = html;

        // Initialize Select2 for select elements
        if (typeof $ !== 'undefined') {
            container.querySelectorAll('select[data-kt-table-filter]').forEach(select => {
                $(select).select2({
                    minimumResultsForSearch: select.dataset.hideSearch === 'true' ? -1 : 0
                });
            });
        }

        console.log('Filters rendered:', filterConfigs.length);
    }

    /**
     * Initialize multiple filters functionality
     * @param {string} tableId - DataTable ID
     * @param {Object} options - Configuration options
     */
    function initializeMultipleFilters(tableId, options = {}) {
        const defaults = {
            filterButtonSelector: '[data-kt-table-filter="filter"]',
            resetButtonSelector: '[data-kt-table-filter="reset"]',
            filters: [],
            onFilterApplied: null,
            onFilterReset: null
        };
        const config = { ...defaults, ...options };

        const filterButton = document.querySelector(config.filterButtonSelector);
        const resetButton = document.querySelector(config.resetButtonSelector);

        if (!filterButton || !resetButton) {
            console.warn('Filter buttons not found for', tableId);
            return;
        }

        // Get all filter elements
        const filterElements = config.filters.map(filter => {
            const element = document.querySelector(filter.selector);
            // Check for date range filters (_from/_to pair)
            const isDateRange = !element &&
                document.querySelector('[data-kt-table-filter="' + filter.paramName + '_from"]') &&
                document.querySelector('[data-kt-table-filter="' + filter.paramName + '_to"]');
            return {
                element: element,
                paramName: filter.paramName,
                selector: filter.selector,
                isButtonGroup: element && element.hasAttribute('data-kt-buttons'),
                isDateRange: isDateRange
            };
        }).filter(f => f.element !== null || f.isDateRange);

        if (filterElements.length === 0) {
            console.warn('No filter elements found for', tableId);
            return;
        }

        // Helper function to get value from filter element
        const getFilterValue = function(filter) {
            if (filter.isButtonGroup) {
                // Get value from checked radio button in button group
                const checkedRadio = filter.element.querySelector('input[type="radio"]:checked');
                return checkedRadio ? checkedRadio.value : '';
            } else {
                // Regular select/input
                return filter.element.value;
            }
        };

        const setFilterValue = function(filter, value) {
            if (filter.isButtonGroup) {
                const radio = filter.element.querySelector('input[type="radio"][value="' + CSS.escape(value) + '"]');
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }

            filter.element.value = value;
            if (typeof $ !== 'undefined' && $(filter.element).data('select2')) {
                $(filter.element).val(value).trigger('change');
            }
        };

        const setResetState = function() {
            const active = filterElements.some(filter => {
                const fromEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_from"]');
                const toEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_to"]');
                if (fromEl && toEl) {
                    return !!(fromEl.value || toEl.value);
                }

                return getFilterValue(filter) !== '';
            });

            resetButton.classList.toggle('d-none', !active);
        };

        const currentParams = new URLSearchParams(window.location.search);
        filterElements.forEach(filter => {
            const fromEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_from"]');
            const toEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_to"]');
            if (fromEl && toEl) {
                fromEl.value = currentParams.get(filter.paramName + '_from') || '';
                toEl.value = currentParams.get(filter.paramName + '_to') || '';
                return;
            }

            if (currentParams.has(filter.paramName)) {
                setFilterValue(filter, currentParams.get(filter.paramName) || '');
            }
        });
        setResetState();

        // Apply filters
        filterButton.addEventListener('click', function(e) {
            e.preventDefault();

            const dt = getDataTable(tableId);
            if (!dt) {
                console.error('DataTable not available');
                return;
            }

            // Build query parameters from all filters
            const params = new URLSearchParams(window.location.search);
            filterElements.forEach(filter => {
                params.delete(filter.paramName);
                params.delete(filter.paramName + '_from');
                params.delete(filter.paramName + '_to');
            });

            filterElements.forEach(filter => {
                // Handle date range filters (two inputs: _from and _to)
                const fromEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_from"]');
                const toEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_to"]');
                if (fromEl && toEl) {
                    if (fromEl.value) params.set(filter.paramName + '_from', fromEl.value);
                    if (toEl.value) params.set(filter.paramName + '_to', toEl.value);
                    return;
                }

                const value = getFilterValue(filter);
                if (value) {
                    params.set(filter.paramName, value);
                }
            });

            // Build new URL
            const query = params.toString();
            const newUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;

            // Update URL and reload
            window.history.replaceState(null, '', newUrl);
            dt.ajax.url(newUrl).load();
            setResetState();

            // Call custom callback if provided
            if (config.onFilterApplied) {
                const filterValues = {};
                filterElements.forEach(filter => {
                    filterValues[filter.paramName] = getFilterValue(filter);
                });
                config.onFilterApplied(filterValues);
            }
        });

        // Reset filters
        resetButton.addEventListener('click', function(e) {
            e.preventDefault();

            // Clear all filter values
            filterElements.forEach(filter => {
                // Handle date range filters
                const fromEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_from"]');
                const toEl = document.querySelector('[data-kt-table-filter="' + filter.paramName + '_to"]');
                if (fromEl && toEl) {
                    fromEl.value = '';
                    toEl.value = '';
                    return;
                }

                if (filter.isButtonGroup) {
                    // Check the "Tous" (first/empty value) radio button
                    const allButton = filter.element.querySelector('input[type="radio"][value=""]');
                    if (allButton) {
                        // Trigger click on the associated label for full Bootstrap interaction
                        const label = document.querySelector(`label[for="${allButton.id}"]`);
                        if (label) {
                            label.click();
                        } else {
                            // Fallback: set checked and trigger change event
                            allButton.checked = true;
                            allButton.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                } else {
                    // Regular select/input
                    filter.element.value = '';

                    // Handle Select2 if initialized
                    if (typeof $ !== 'undefined' && $(filter.element).data('select2')) {
                        $(filter.element).val('').trigger('change');
                    }
                }
            });

            const dt = getDataTable(tableId);
            if (!dt) {
                console.error('DataTable not available');
                return;
            }

            const params = new URLSearchParams(window.location.search);
            filterElements.forEach(filter => {
                params.delete(filter.paramName);
                params.delete(filter.paramName + '_from');
                params.delete(filter.paramName + '_to');
            });

            const query = params.toString();
            const newUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;

            window.history.replaceState(null, '', newUrl);
            dt.ajax.url(newUrl).load();
            setResetState();

            // Call custom callback if provided
            if (config.onFilterReset) {
                config.onFilterReset();
            }
        });

        console.log('Multiple filters initialized for', tableId, '(' + filterElements.length + ' filters)');
    }

    /**
     * Initialize toggle switch functionality
     * @param {Object} options - Configuration options
     */
    function initializeToggleSwitch(options = {}) {
        const defaults = {
            toggleClass: 'status-toggle',
            successMessage: 'Statut mis à jour avec succès',
            errorMessage: 'Échec de la mise à jour du statut',
            onSuccess: null,
            onError: null
        };
        const config = { ...defaults, ...options };

        // Use event delegation for dynamically loaded content
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains(config.toggleClass)) {
                const toggle = e.target;
                const route = toggle.dataset.route;
                const field = toggle.dataset.field;
                const state = toggle.checked ? 1 : 0;

                if (!route) {
                    console.error('Toggle route not defined');
                    return;
                }

                fetch(route, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        field: field,
                        state: state
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccessToast(config.successMessage);
                        if (config.onSuccess) {
                            config.onSuccess(data, toggle);
                        }
                    } else {
                        toggle.checked = !toggle.checked;
                        showErrorToast(extractErrorMessage(data, config.errorMessage));
                        if (config.onError) {
                            config.onError(data, toggle);
                        }
                    }
                })
                .catch(error => {
                    toggle.checked = !toggle.checked;
                    console.error('Toggle error:', error);
                    showErrorToast('Une erreur est survenue');
                    if (config.onError) {
                        config.onError(error, toggle);
                    }
                });
            }
        });

        console.log('Toggle switch initialized');
    }

    /**
     * Initialize delete button functionality
     * @param {Object} options - Configuration options
     */
    function initializeDeleteButton(options = {}) {
        const defaults = {
            buttonSelector: '.delete-btn',
            confirmMessage: 'Êtes-vous sûr de vouloir supprimer cet enregistrement ?',
            successMessage: 'Enregistrement supprimé avec succès',
            errorMessage: 'Échec de la suppression',
            tableId: null, // Will auto-reload all tables if not specified
            onSuccess: null,
            onError: null
        };
        const config = { ...defaults, ...options };

        // Use event delegation for dynamically loaded content
        document.addEventListener('click', function(e) {
            const btn = e.target.closest(config.buttonSelector);
            if (!btn) return;

            e.preventDefault();
            const url = btn.dataset.url;

            if (!url) {
                console.error('Delete URL not defined');
                return;
            }

            confirmDelete(config.confirmMessage, function() {
                fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const message = data.message || config.successMessage;

                        Swal.fire({
                            text: message,
                            icon: 'success',
                            buttonsStyling: false,
                            confirmButtonText: 'Ok, compris !',
                            customClass: {
                                confirmButton: 'btn fw-bold btn-primary'
                            }
                        }).then(function() {
                            // Reload specific table or all tables
                            if (config.tableId) {
                                reloadTable(config.tableId);
                            } else if (window.LaravelDataTables) {
                                Object.values(window.LaravelDataTables).forEach(dt => dt.ajax.reload());
                            } else {
                                location.reload();
                            }

                            if (config.onSuccess) {
                                config.onSuccess(data);
                            }
                        });
                    } else {
                        const message = data.message || config.errorMessage;
                        Swal.fire({
                            text: message,
                            icon: 'error',
                            buttonsStyling: false,
                            confirmButtonText: 'Ok, compris !',
                            customClass: {
                                confirmButton: 'btn fw-bold btn-primary'
                            }
                        });

                        if (config.onError) {
                            config.onError(data);
                        }
                    }
                })
                .catch(error => {
                    console.error('Delete error:', error);
                    Swal.fire({
                        text: 'Une erreur est survenue lors de la suppression.',
                        icon: 'error',
                        buttonsStyling: false,
                        confirmButtonText: 'Ok, compris !',
                        customClass: {
                            confirmButton: 'btn fw-bold btn-primary'
                        }
                    });

                    if (config.onError) {
                        config.onError(error);
                    }
                });
            });
        });

        console.log('Delete button initialized');
    }

    /**
     * Initialize all common DataTable utilities at once
     * @param {string} tableId - DataTable ID
     * @param {Object} options - Configuration options
     */
    function initializeAll(tableId, options = {}) {
        const defaults = {
            search: true,
            searchInputId: 'mySearchInput',
            filter: false,
            filterOptions: {},
            toggleSwitch: true,
            toggleOptions: {},
            deleteButton: true,
            deleteOptions: {}
        };
        const config = { ...defaults, ...options };

        console.log('Initializing DataTable utilities for:', tableId);

        // Initialize search
        if (config.search) {
            initializeSearch(tableId, config.searchInputId);
        }

        // Initialize filter
        if (config.filter) {
            initializeFilter(tableId, config.filterOptions);
        }

        // Initialize toggle switch
        if (config.toggleSwitch) {
            initializeToggleSwitch(config.toggleOptions);
        }

        // Initialize delete button
        if (config.deleteButton) {
            const deleteConfig = {
                ...config.deleteOptions,
                tableId: tableId
            };
            initializeDeleteButton(deleteConfig);
        }

        console.log('All utilities initialized for', tableId);
    }

    /**
     * Initialize a complete DataTable index page with all features
     * This is the recommended method for index pages - it handles everything from PHP config
     *
     * @param {Object} config - Configuration object from PHP getIndexConfig()
     * @param {string} config.tableId - Table identifier (e.g., 'company', 'contact')
     * @param {Array} config.filterConfigs - Filter configurations from PHP
     * @param {Object} config.messages - Localized messages for UI feedback
     * @param {string} [config.searchInputId='mySearchInput'] - Search input element ID
     * @param {string} [config.filtersContainerId='filters-container'] - Filters container element ID
     */
    function initializeIndex(config) {
        const defaults = {
            searchInputId: 'mySearchInput',
            filtersContainerId: 'filters-container',
            filterConfigs: [],
            messages: {
                toggleSuccess: 'Statut mis à jour avec succès',
                deleteConfirm: 'Êtes-vous sûr de vouloir supprimer cet élément ?',
                deleteSuccess: 'Élément supprimé avec succès'
            }
        };

        const settings = { ...defaults, ...config };
        const { tableId, filterConfigs, messages, searchInputId, filtersContainerId } = settings;

        console.log('Initializing DataTable index for:', tableId);

        // Render filters if provided
        if (filterConfigs && filterConfigs.length > 0) {
            renderFilters(filtersContainerId, filterConfigs);

            // Store filter configs on active filters bar for reference
            var activeFiltersBar = document.getElementById('active-filters-bar');
            if (activeFiltersBar) {
                activeFiltersBar._filterConfigs = filterConfigs;
            }

            // Initialize filter functionality with badge bar callbacks
            initializeMultipleFilters(tableId, {
                filters: filterConfigs.map(f => ({
                    selector: '[data-kt-table-filter="' + f.paramName + '"]',
                    paramName: f.paramName
                })),
                onFilterApplied: function() {
                    updateActiveFiltersBadges(tableId, filterConfigs);
                },
                onFilterReset: function() {
                    updateActiveFiltersBadges(tableId, filterConfigs);
                }
            });

            // Initialize "Clear all" button
            var clearAllBtn = document.getElementById('clear-all-filters');
            if (clearAllBtn) {
                clearAllBtn.addEventListener('click', function() {
                    var resetBtn = document.querySelector('[data-kt-table-filter="reset"]');
                    if (resetBtn) resetBtn.click();
                });
            }

        }

        // Guard: stop polling after 25 iterations (~5 s) to prevent infinite loop
        // if the table id is misconfigured or the table fails to initialise.
        var waitForDtCount = 0;
        var waitForDt = setInterval(function() {
            var dt = getDataTable(tableId);
            if (dt) {
                clearInterval(waitForDt);
                var tableContainer = dt.table && dt.table().container ? dt.table().container() : document;
                var refreshAccessibility = function() {
                    fixAccessibility(tableContainer);
                    fixAccessibility(document);
                };

                refreshAccessibility();
                dt.on('init draw', function() {
                    refreshAccessibility();
                });

                if (filterConfigs && filterConfigs.length > 0) {
                    dt.on('draw', function() {
                        updateActiveFiltersBadges(tableId, filterConfigs);
                    });
                }
            } else if (++waitForDtCount >= 25) {
                clearInterval(waitForDt);
                console.warn('DataTableUtils: gave up waiting for table "' + tableId + '" after 5 s');
            }
        }, 200);

        // Initialize search
        initializeSearch(tableId, searchInputId);

        // Initialize toggle switches
        initializeToggleSwitch({
            successMessage: messages.toggleSuccess
        });

        // Initialize delete button
        initializeDeleteButton({
            confirmMessage: messages.deleteConfirm,
            successMessage: messages.deleteSuccess,
            tableId: tableId
        });

        console.log('DataTable index initialized for', tableId);
    }


    /**
     * Update active filters badge bar
     * Shows dismissible badges for each active filter
     * @param {string} tableId - DataTable ID
     * @param {Array} filterConfigs - Filter configurations
     */
    function updateActiveFiltersBadges(tableId, filterConfigs) {
        const bar = document.getElementById('active-filters-bar');
        const container = document.getElementById('active-filters-badges');
        const clearAllBtn = document.getElementById('clear-all-filters');

        if (!bar || !container) return;

        container.innerHTML = '';
        let activeCount = 0;

        filterConfigs.forEach(function(config) {
            const paramName = config.paramName;

            // Handle date range filters
            var dateFromEl = document.querySelector('[data-kt-table-filter="' + paramName + '_from"]');
            var dateToEl = document.querySelector('[data-kt-table-filter="' + paramName + '_to"]');
            if (dateFromEl && dateToEl) {
                var fromVal = dateFromEl.value;
                var toVal = dateToEl.value;
                if (fromVal || toVal) {
                    activeCount++;
                    if (config.type === 'money') {
                        var moneyDisplayValue = [];
                        if (fromVal) moneyDisplayValue.push(fromVal + ' €');
                        if (toVal) moneyDisplayValue.push(toVal + ' €');
                        moneyDisplayValue = moneyDisplayValue.join(' – ');

                        var moneyBadge = document.createElement('span');
                        moneyBadge.className = 'badge badge-light-primary fs-7 d-flex align-items-center gap-1 py-2 px-3';
                        moneyBadge.innerHTML = '<span class="fw-semibold">' + escapeHtml(config.label) + ':</span> ' +
                            escapeHtml(moneyDisplayValue) +
                            ' <button type="button" class="btn btn-icon btn-sm h-20px w-20px ms-1" data-filter-remove="' + escapeAttribute(paramName) + '" aria-label="Retirer le filtre ' + escapeAttribute(config.label) + '"><i class="ki-outline ki-cross fs-8" aria-hidden="true"></i></button>';

                        moneyBadge.querySelector('[data-filter-remove]').addEventListener('click', function() {
                            dateFromEl.value = '';
                            dateToEl.value = '';
                            var applyBtn = document.querySelector('[data-kt-table-filter="filter"]');
                            if (applyBtn) applyBtn.click();
                        });

                        container.appendChild(moneyBadge);
                        return;
                    }

                    var fromDisplay = fromVal ? new Date(fromVal + 'T00:00:00').toLocaleDateString('fr-FR') : '...';
                    var toDisplay = toVal ? new Date(toVal + 'T00:00:00').toLocaleDateString('fr-FR') : '...';
                    var dateDisplayValue = fromDisplay + ' → ' + toDisplay;

                    var dateBadge = document.createElement('span');
                    dateBadge.className = 'badge badge-light-primary fs-7 d-flex align-items-center gap-1 py-2 px-3';
                    dateBadge.innerHTML = '<span class="fw-semibold">' + escapeHtml(config.label) + ':</span> ' +
                        escapeHtml(dateDisplayValue) +
                        ' <button type="button" class="btn btn-icon btn-sm h-20px w-20px ms-1" data-filter-remove="' + escapeAttribute(paramName) + '" aria-label="Retirer le filtre ' + escapeAttribute(config.label) + '"><i class="ki-outline ki-cross fs-8" aria-hidden="true"></i></button>';

                    dateBadge.querySelector('[data-filter-remove]').addEventListener('click', function() {
                        dateFromEl.value = '';
                        dateToEl.value = '';
                        var applyBtn = document.querySelector('[data-kt-table-filter="filter"]');
                        if (applyBtn) applyBtn.click();
                    });

                    container.appendChild(dateBadge);
                }
                return;
            }

            const filterEl = document.querySelector('[data-kt-table-filter="' + paramName + '"]');
            if (!filterEl) return;

            let value = '';
            let displayValue = '';

            // Check if it's a button group (boolean filter)
            if (filterEl.hasAttribute('data-kt-buttons')) {
                const checkedRadio = filterEl.querySelector('input[type="radio"]:checked');
                value = checkedRadio ? checkedRadio.value : '';
                if (value === '1') {
                    displayValue = 'Oui';
                } else if (value === '0') {
                    displayValue = 'Non';
                }
            } else if (filterEl.tagName === 'SELECT') {
                value = filterEl.value;
                if (value) {
                    // Get the text of the selected option
                    const selectedOption = filterEl.querySelector('option[value="' + CSS.escape(value) + '"]');
                    displayValue = selectedOption ? selectedOption.textContent.trim() : value;
                }
            } else {
                // Text input
                value = filterEl.value.trim();
                displayValue = value;
            }

            if (value === '' || value === undefined || value === null) return;

            activeCount++;

            var badge = document.createElement('span');
            badge.className = 'badge badge-light-primary fs-7 d-flex align-items-center gap-1 py-2 px-3';
            badge.innerHTML = '<span class="fw-semibold">' + escapeHtml(config.label) + ':</span> ' +
                escapeHtml(displayValue) +
                ' <button type="button" class="btn btn-icon btn-sm h-20px w-20px ms-1" data-filter-remove="' + escapeAttribute(paramName) + '" aria-label="Retirer le filtre ' + escapeAttribute(config.label) + '"><i class="ki-outline ki-cross fs-8" aria-hidden="true"></i></button>';

            // Click handler for dismiss
            badge.querySelector('[data-filter-remove]').addEventListener('click', function() {
                // Clear this specific filter
                if (filterEl.hasAttribute('data-kt-buttons')) {
                    var allBtn = filterEl.querySelector('input[type="radio"][value=""]');
                    if (allBtn) {
                        var label = document.querySelector('label[for="' + allBtn.id + '"]');
                        if (label) {
                            label.click();
                        } else {
                            allBtn.checked = true;
                        }
                    }
                } else if (filterEl.tagName === 'SELECT') {
                    filterEl.value = '';
                    if (typeof $ !== 'undefined' && $(filterEl).data('select2')) {
                        $(filterEl).val('').trigger('change');
                    }
                } else {
                    filterEl.value = '';
                }

                // Re-apply remaining filters
                var applyBtn = document.querySelector('[data-kt-table-filter="filter"]');
                if (applyBtn) applyBtn.click();
            });

            container.appendChild(badge);
        });

        // Show/hide bar
        if (activeCount > 0) {
            bar.classList.remove('d-none');
            if (clearAllBtn) clearAllBtn.classList.remove('d-none');
        } else {
            bar.classList.add('d-none');
            if (clearAllBtn) clearAllBtn.classList.add('d-none');
        }
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function escapeAttribute(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function fixAccessibility(container) {
        var root = container || document;

        root.querySelectorAll('.dataTables_scrollHead, .dt-scroll-head, .fixedHeader-floating').forEach(function(header) {
            header.setAttribute('aria-hidden', 'true');
            header.querySelectorAll('a, button, input, select, textarea, [tabindex]').forEach(function(element) {
                element.setAttribute('tabindex', '-1');
            });
        });

        root.querySelectorAll('table[id$="-table"] i:not([aria-hidden])').forEach(function(icon) {
            icon.setAttribute('aria-hidden', 'true');
        });
    }

    // Public API
    return {
        getDataTable,
        showSuccessToast,
        showErrorToast,
        confirmDelete,
        updateUrl,
        reloadTable,
        initializeSearch,
        initializeFilter,
        initializeMultipleFilters,
        initializeToggleSwitch,
        initializeDeleteButton,
        initializeAll,
        initializeIndex,
        // Filter generation
        generateFilterHTML,
        renderFilters,
        updateActiveFiltersBadges,
        escapeHtml,
        fixAccessibility
    };
})();

// Make it available globally
window.DataTableUtils = DataTableUtils;

console.log('DataTableUtils loaded');
