"use strict";

/**
 * CRUD Form AJAX Handler — fretiq
 *
 * Handles form submissions with AJAX for Create/Update operations.
 * On 2xx success  : follows response.data.redirect (or reloads page).
 * On 422 error    : shows a SweetAlert2 dialog listing validation errors.
 */
var KTCrudFormHandler = function () {

    const handleSubmit = () => {
        if (!$("#form_crud").length)
            return false;

        const form = document.getElementById('form_crud');

        // KTUtil.onDOMContentLoaded fires this callback on BOTH 'DOMContentLoaded' and
        // 'livewire:navigated' (Livewire 3 dispatches the latter on the initial page load too),
        // so init() runs twice per load. Without this guard the click listeners below bind
        // twice on every .submit button → one click = two axios.post = two inserts. The flag
        // lives on the form node, so a genuine wire:navigate swap (fresh #form_crud) re-wires.
        if (form.dataset.crudHandlerBound === '1') {
            return false;
        }
        form.dataset.crudHandlerBound = '1';

        const submitButtons = Array.from(new Set([
            ...form.querySelectorAll('.submit'),
            ...document.querySelectorAll('[data-form-id="' + form.id + '"].submit')
        ]));

        var requiredElements = form.querySelectorAll('[required]:not(.dropzone):not(.dropzone *)');
        var emailElements    = form.querySelectorAll('input[type="email"]:not(.dropzone):not(.dropzone *)');
        var numberElements   = form.querySelectorAll('input[type="number"]:not(.dropzone):not(.dropzone *)');

        let fields = {};

        if (requiredElements)
            requiredElements.forEach(input => {
                const fieldName = input.getAttribute('name');
                if (!fieldName) return;
                fields[fieldName] = {
                    validators: {
                        notEmpty: { message: 'Ce champ est requis' }
                    }
                };
            });

        if (emailElements)
            emailElements.forEach(input => {
                const fieldName = input.getAttribute('name');
                if (!fieldName) return;
                const validators = {};
                if (input.hasAttribute('required')) {
                    validators.notEmpty = { message: 'Ce champ est requis' };
                }
                validators.regexp = {
                    regexp: '^[^@\\s]+@([^@\\s]+\\.)+[^@\\s]+$',
                    message: "L'adresse email n'est pas valide"
                };
                fields[fieldName] = { validators };
            });

        if (numberElements)
            numberElements.forEach(input => {
                const fieldName = input.getAttribute('name');
                if (!fieldName) return;
                const validators = {};
                if (input.hasAttribute('required')) {
                    validators.notEmpty = { message: 'Ce champ est requis' };
                }
                validators.numeric = { message: 'Ce champ doit contenir uniquement des chiffres' };
                fields[fieldName] = { validators };
            });

        let validator;
        if (typeof FormValidation !== 'undefined' && FormValidation.plugins && FormValidation.plugins.Bootstrap5) {
            validator = FormValidation.formValidation(form, {
                fields: fields,
                plugins: {
                    trigger: new FormValidation.plugins.Trigger(),
                    bootstrap: new FormValidation.plugins.Bootstrap5({
                        rowSelector: '.fv-row',
                        eleInvalidClass: '',
                        eleValidClass: ''
                    })
                }
            });
        }

        submitButtons.forEach(submitButton => {
            submitButton.addEventListener('click', e => {
                e.preventDefault();

                const doSubmit = () => {
                    // Re-entrancy guard — one in-flight submit per form. Even if more than one
                    // listener/button fires for a single user action, only the first request
                    // goes out; the rest no-op until it settles.
                    if (form.dataset.crudSubmitting === '1') {
                        return;
                    }
                    form.dataset.crudSubmitting = '1';

                    submitButton.setAttribute('data-kt-indicator', 'on');
                    submitButton.disabled = true;

                    let formData = new FormData(form);
                    if (submitButton.hasAttribute('name')) {
                        formData.append(submitButton.getAttribute('name'), '');
                    }

                    axios.post(form.getAttribute('action'), formData)
                        .then(function (response) {
                            form.dispatchEvent(new CustomEvent('crud:form-saved', { bubbles: true }));

                            if (response.data.redirect) {
                                const redirectUrl = new URL(response.data.redirect, window.location.origin);
                                const currentUrl  = new URL(window.location.href);

                                if (redirectUrl.pathname === currentUrl.pathname &&
                                    redirectUrl.origin === currentUrl.origin) {
                                    if (!redirectUrl.hash && currentUrl.hash) {
                                        window.location.hash = currentUrl.hash;
                                        window.location.reload();
                                    } else if (redirectUrl.hash !== currentUrl.hash) {
                                        window.location.hash = redirectUrl.hash;
                                        window.location.reload();
                                    } else {
                                        window.location.reload();
                                    }
                                } else {
                                    window.location.replace(response.data.redirect);
                                }
                            } else {
                                window.location.reload();
                            }
                        })
                        .catch(function (error) {
                            let dataMessage = error.response?.data?.message || 'Une erreur est survenue lors de l’enregistrement.';
                            let dataErrors  = error.response?.data?.errors;

                            if (dataErrors) {
                                for (const errorsKey in dataErrors) {
                                    if (!dataErrors.hasOwnProperty(errorsKey)) continue;
                                    dataMessage += "<br>\r\n" + dataErrors[errorsKey];
                                }
                            }

                            if (!error.response) {
                                dataMessage = 'Impossible de joindre le serveur. Vérifiez votre connexion puis réessayez.';
                            }

                            Swal.fire({
                                html: dataMessage,
                                icon: 'error',
                                buttonsStyling: false,
                                confirmButtonText: 'Ok, compris !',
                                customClass: { confirmButton: 'btn btn-primary' }
                            });
                        })
                        .finally(function () {
                            submitButton.removeAttribute('data-kt-indicator');
                            submitButton.disabled = false;
                            form.dataset.crudSubmitting = '0';
                        });
                };

                if (validator) {
                    validator.validate().then(function (status) {
                        if (status === 'Valid') {
                            doSubmit();
                        }
                    });
                } else {
                    doSubmit();
                }
            });
        });
    };

    return {
        init: function () {
            handleSubmit();
        }
    };
}();

KTUtil.onDOMContentLoaded(function () {
    KTCrudFormHandler.init();
});
