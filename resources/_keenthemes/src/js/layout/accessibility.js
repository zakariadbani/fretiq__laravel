(function () {
    'use strict';

    function hasName(control) {
        return control.getAttribute('aria-label') ||
            control.getAttribute('aria-labelledby') ||
            control.getAttribute('title') ||
            (control.id && document.querySelector('label[for="' + CSS.escape(control.id) + '"]')) ||
            control.closest('label');
    }

    function labelText(label) {
        return label ? label.textContent.replace(/\s+/g, ' ').replace(/[*:]+$/, '').trim() : '';
    }

    function ensureControlName(control, index) {
        if (control.type === 'hidden' || hasName(control)) {
            return;
        }

        var scope = control.closest('.fv-row, .mb-7, .mb-5, .form-group, .col-lg-6, .card-body') || control.parentElement;
        var label = scope ? scope.querySelector('label:not([for])') : null;
        var text = labelText(label);

        if (label && text) {
            if (!control.id) {
                control.id = 'a11y-control-' + index;
            }
            label.setAttribute('for', control.id);
            return;
        }

        if (control.name) {
            control.setAttribute('aria-label', control.name.replace(/\[[^\]]*]/g, '').replace(/[_-]+/g, ' '));
        } else if (control.getAttribute('placeholder')) {
            control.setAttribute('aria-label', control.getAttribute('placeholder'));
        }
    }

    function ensureIconActionName(action) {
        if (action.getAttribute('aria-label') || action.textContent.trim()) {
            return;
        }

        var label = action.getAttribute('title') || action.dataset.bsOriginalTitle;
        if (label) {
            action.setAttribute('aria-label', label);
        }
    }

    function bindMenuKeyboardTriggers() {
        document.querySelectorAll('button[data-kt-menu-trigger]').forEach(function (trigger) {
            if (trigger.dataset.a11yMenuKeyboardBound === 'true') {
                return;
            }

            trigger.dataset.a11yMenuKeyboardBound = 'true';
            trigger.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                var menu = window.KTMenu && window.KTMenu.getInstance(trigger);
                if (!menu) {
                    return;
                }

                event.preventDefault();
                menu.toggle(trigger);
            });
        });
    }

    function applyAccessibilityPass() {
        document.querySelectorAll('input, select, textarea').forEach(ensureControlName);
        document.querySelectorAll('a.btn-icon, button.btn-icon, .btn-icon[data-kt-menu-trigger]').forEach(ensureIconActionName);
        bindMenuKeyboardTriggers();
        document.querySelectorAll('i.bi, i.ki-outline, i.ki-duotone').forEach(function (icon) {
            if (!icon.closest('[role="img"]')) {
                icon.setAttribute('aria-hidden', 'true');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyAccessibilityPass);
    } else {
        applyAccessibilityPass();
    }
})();
