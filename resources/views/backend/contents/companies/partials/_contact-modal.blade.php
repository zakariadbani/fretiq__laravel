{{--
    Contact modal — create / edit a contact inline from the company page.

    Create mode (default):
        - Form POSTs to admin.contacts.store
        - Hidden return_url sends back to this company's Contacts tab

    Edit mode (JS-toggled):
        - JS sets action to data-update-url from the edit button
        - JS injects <input name="_method" value="PUT"> (or sets the hidden field)
        - JS fills all fields from data-* attributes on the edit button

    Select fields use plain <select class="form-select form-select-solid"> — NO data-control="select2".
    select2 misrenders at width:0 inside a hidden modal / hidden tab pane.
--}}

<div class="modal fade" id="company_contact_modal" tabindex="-1" aria-labelledby="company_contact_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="company_contact_modal_label">
                    <i class="bi bi-person-plus text-primary me-2"></i>
                    <span id="modal_title_text">Ajouter un contact</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <form method="POST"
                  action="{{ route('admin.contacts.store') }}"
                  id="company_contact_form"
                  novalidate>
                @csrf
                {{-- _method field — toggled between POST (empty) and PUT by JS on edit --}}
                <input type="hidden" name="_method" id="contact_form_method" value="">

                {{-- Context: link contact to this company, return here after save --}}
                <input type="hidden" name="company_id" value="{{ $model->id }}">
                <input type="hidden" name="return_url" value="{{ route('admin.companies.view', $model->id) }}#company_contacts">

                <div class="modal-body py-6 px-8">
                    <div class="row g-5">

                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-5">
                                <label class="required fw-semibold fs-6 mb-2">Nom complet</label>
                                <input type="text"
                                       name="name"
                                       id="contact_name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Jean Dupont"
                                       required />
                            </div>

                            {{-- Email --}}
                            <div class="fv-row mb-5">
                                <label class="required fw-semibold fs-6 mb-2">Email</label>
                                <input type="email"
                                       name="email"
                                       id="contact_email"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : j.dupont@entreprise.com"
                                       required />
                            </div>

                            {{-- Poste --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Poste</label>
                                <input type="text"
                                       name="position"
                                       id="contact_position"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Directeur achats" />
                            </div>

                            {{-- Téléphone --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Téléphone</label>
                                <input type="text"
                                       name="phone"
                                       id="contact_phone"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : +33 6 12 34 56 78" />
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Statut --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Statut</label>
                                <select name="status" id="contact_status" class="form-select form-select-solid">
                                    <option value="">Sélectionner un statut...</option>
                                    @foreach(config('global.data.contact_statuses', []) as $key => $data)
                                        <option value="{{ $key }}">{{ $data['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Source --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Source</label>
                                <select name="source" id="contact_source" class="form-select form-select-solid">
                                    <option value="">Sélectionner une source...</option>
                                    @foreach(config('global.data.contact_sources', []) as $key => $data)
                                        <option value="{{ $key }}">{{ $data['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Base légale RGPD --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Base légale (RGPD)</label>
                                <select name="legal_basis" id="contact_legal_basis" class="form-select form-select-solid">
                                    <option value="">Sélectionner une base légale...</option>
                                    @foreach(config('global.data.contact_legal_bases', []) as $key => $data)
                                        <option value="{{ $key }}">{{ $data['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Type d'email --}}
                            <div class="fv-row mb-5">
                                <label class="fw-semibold fs-6 mb-2">Type d'email</label>
                                <select name="email_kind" id="contact_email_kind" class="form-select form-select-solid">
                                    <option value="">Sélectionner un type...</option>
                                    @foreach(config('global.data.contact_email_kinds', []) as $key => $data)
                                        <option value="{{ $key }}">{{ $data['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                        </div>
                    </div>

                    {{-- Inline validation error container --}}
                    <div id="contact_form_errors" class="alert alert-danger d-none mt-3" role="alert"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-primary" id="contact_form_submit">
                        <span class="indicator-label">
                            <i class="bi bi-check-circle me-1"></i>
                            Enregistrer
                        </span>
                        <span class="indicator-progress d-none">
                            <span class="spinner-border spinner-border-sm align-middle me-2"></span>
                            Veuillez patienter...
                        </span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    var contactModal      = document.getElementById('company_contact_modal');
    var contactForm       = document.getElementById('company_contact_form');
    var modalTitleText    = document.getElementById('modal_title_text');
    var methodField       = document.getElementById('contact_form_method');
    var submitBtn         = document.getElementById('contact_form_submit');
    var errorsBox         = document.getElementById('contact_form_errors');
    var storeUrl          = contactForm ? contactForm.getAttribute('action') : '';

    if (!contactModal || !contactForm) return;

    // ── Reset form to "create" mode ──────────────────────────────────────────
    function resetToCreateMode() {
        contactForm.reset();
        contactForm.setAttribute('action', storeUrl);
        methodField.value = '';
        if (modalTitleText) modalTitleText.textContent = 'Ajouter un contact';
        hideErrors();
    }

    // ── Prefill form for "edit" mode ─────────────────────────────────────────
    function prefillForEdit(btn) {
        var updateUrl = btn.dataset.updateUrl;
        contactForm.setAttribute('action', updateUrl);
        methodField.value = 'PUT';

        document.getElementById('contact_name').value         = btn.dataset.contactName         || '';
        document.getElementById('contact_email').value        = btn.dataset.contactEmail        || '';
        document.getElementById('contact_position').value     = btn.dataset.contactPosition     || '';
        document.getElementById('contact_phone').value        = btn.dataset.contactPhone        || '';
        setSelectValue('contact_status',     btn.dataset.contactStatus);
        setSelectValue('contact_source',     btn.dataset.contactSource);
        setSelectValue('contact_legal_basis', btn.dataset.contactLegalBasis);
        setSelectValue('contact_email_kind', btn.dataset.contactEmailKind);

        if (modalTitleText) modalTitleText.textContent = 'Modifier le contact';
        hideErrors();
    }

    function setSelectValue(selectId, value) {
        var sel = document.getElementById(selectId);
        if (!sel || !value) return;
        for (var i = 0; i < sel.options.length; i++) {
            sel.options[i].selected = (sel.options[i].value === value);
        }
    }

    // ── Show/hide errors ─────────────────────────────────────────────────────
    function showErrors(message) {
        if (!errorsBox) return;
        errorsBox.innerHTML = message;
        errorsBox.classList.remove('d-none');
    }

    function hideErrors() {
        if (!errorsBox) return;
        errorsBox.innerHTML = '';
        errorsBox.classList.add('d-none');
    }

    // ── Modal events ─────────────────────────────────────────────────────────

    // Reset to create mode whenever modal opens without an edit trigger
    document.getElementById('btn_add_contact') && document.getElementById('btn_add_contact').addEventListener('click', function () {
        resetToCreateMode();
    });

    // Prefill when an edit button is clicked (delegated via modal show event)
    contactModal.addEventListener('show.bs.modal', function (e) {
        var trigger = e.relatedTarget;
        if (trigger && trigger.classList.contains('btn-edit-contact')) {
            prefillForEdit(trigger);
        }
    });

    // ── Form submit (AJAX) ───────────────────────────────────────────────────
    contactForm.addEventListener('submit', function (e) {
        e.preventDefault();
        hideErrors();

        // Basic client-side validation
        var name  = document.getElementById('contact_name').value.trim();
        var email = document.getElementById('contact_email').value.trim();
        if (!name || !email) {
            showErrors('Le nom et l\'email sont requis.');
            return;
        }

        // Show loading state
        var indicatorLabel    = submitBtn.querySelector('.indicator-label');
        var indicatorProgress = submitBtn.querySelector('.indicator-progress');
        submitBtn.disabled = true;
        if (indicatorLabel)    indicatorLabel.classList.add('d-none');
        if (indicatorProgress) indicatorProgress.classList.remove('d-none');

        var formData = new FormData(contactForm);

        fetch(contactForm.getAttribute('action'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: formData
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        })
        .then(function (result) {
            if (result.status >= 200 && result.status < 300) {
                // Success: follow redirect (goes back to company Contacts tab via return_url)
                var redirectUrl = result.data.redirect || (result.data.return_url);
                if (redirectUrl) {
                    window.location.replace(redirectUrl);
                } else {
                    window.location.reload();
                }
            } else {
                // Validation / server error
                var msg = result.data.message || 'Erreur lors de l\'enregistrement.';
                var errors = result.data.errors;
                if (errors) {
                    var lines = [msg];
                    Object.values(errors).forEach(function (errs) {
                        errs.forEach(function (e) { lines.push(e); });
                    });
                    showErrors(lines.join('<br>'));
                } else {
                    showErrors(msg);
                }
            }
        })
        .catch(function () {
            showErrors('Erreur de connexion. Veuillez réessayer.');
        })
        .finally(function () {
            submitBtn.disabled = false;
            if (indicatorLabel)    indicatorLabel.classList.remove('d-none');
            if (indicatorProgress) indicatorProgress.classList.add('d-none');
        });
    });

    // ── Delete contact buttons (delegated) ───────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-contact');
        if (!btn) return;

        var contactName = btn.dataset.contactName || 'ce contact';
        var deleteUrl   = btn.dataset.deleteUrl;
        var contactId   = btn.dataset.contactId;

        if (!deleteUrl) return;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Supprimer ' + contactName + ' ?',
                text: 'Cette action est irréversible.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler',
                confirmButtonColor: '#f1416c',
                buttonsStyling: false,
                customClass: {
                    confirmButton: 'btn btn-danger me-3',
                    cancelButton: 'btn btn-light'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    doDelete(deleteUrl, contactId, btn);
                }
            });
        } else {
            if (confirm('Supprimer ' + contactName + ' ?')) {
                doDelete(deleteUrl, contactId, btn);
            }
        }
    });

    function doDelete(deleteUrl, contactId, btn) {
        btn.disabled = true;

        var formData = new FormData();
        formData.append('_method', 'DELETE');
        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

        fetch(deleteUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData
        })
        .then(function (response) {
            if (response.ok || response.status === 302) {
                // Remove row from DOM
                var row = document.getElementById('contact_row_' + contactId);
                if (row) row.remove();

                // If no more rows, show empty state
                var tbody = document.getElementById('contacts_tbody');
                if (tbody && tbody.querySelectorAll('tr').length === 0) {
                    var wrapper = document.getElementById('contacts_table_wrapper');
                    if (wrapper) {
                        wrapper.innerHTML = '<div class="text-center py-10 text-muted"><i class="bi bi-people fs-2x mb-3 d-block"></i>Aucun contact pour cette entreprise.</div>';
                    }
                }

                // Update badge counts
                document.querySelectorAll('.contacts-count-badge').forEach(function (el) {
                    var n = parseInt(el.textContent, 10);
                    if (!isNaN(n) && n > 0) el.textContent = n - 1;
                });

                if (typeof toastr !== 'undefined') {
                    toastr.success('Contact supprimé.');
                }
            } else {
                if (typeof toastr !== 'undefined') {
                    toastr.error('Erreur lors de la suppression.');
                }
                btn.disabled = false;
            }
        })
        .catch(function () {
            if (typeof toastr !== 'undefined') {
                toastr.error('Erreur de connexion.');
            }
            btn.disabled = false;
        });
    }

})();
</script>
@endpush
