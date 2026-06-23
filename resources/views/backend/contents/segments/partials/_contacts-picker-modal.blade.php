{{--
    Contacts picker modal — search existing contacts and pin them into this segment.
    NO name= on any input (B1: never submits with the segment form).
    NO select2 (modal starts at width:0 — misrenders).
    All inputs read by id, all actions via class/data-*.
--}}

<div class="modal fade" id="segment_contacts_modal" tabindex="-1"
     aria-labelledby="segment_contacts_modal_label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="segment_contacts_modal_label">
                    <i class="bi bi-person-plus text-primary me-2"></i>
                    Ajouter un contact au segment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body py-6 px-8">

                {{-- Search input — NO name= --}}
                <div class="fv-row mb-5">
                    <label class="fw-semibold fs-6 mb-2" for="segment_contact_search">
                        Rechercher un contact
                    </label>
                    <div class="position-relative">
                        <i class="bi bi-search position-absolute top-50 translate-middle-y ms-3 text-muted fs-6"></i>
                        <input type="text"
                               id="segment_contact_search"
                               class="form-control form-control-solid ps-10"
                               placeholder="Nom, email ou société…"
                               autocomplete="off"
                               data-search-url="{{ route('admin.segments.contacts.search', $model->id) }}"
                               aria-label="Rechercher un contact à ajouter au segment"
                               aria-autocomplete="list"
                               aria-controls="segment_contact_results" />
                        <div id="segment_contact_search_spinner" class="position-absolute top-50 translate-middle-y end-0 me-3 d-none">
                            <span class="spinner-border spinner-border-sm text-muted" role="status"></span>
                        </div>
                    </div>
                </div>

                {{-- Results list --}}
                <div id="segment_contact_results" role="listbox" aria-label="Résultats de recherche">
                    {{-- Populated by segment-contacts.js --}}
                </div>

                {{-- Empty state --}}
                <div id="segment_contact_search_empty" class="text-center py-8 text-muted d-none">
                    <i class="bi bi-person-x fs-2x mb-2 d-block text-gray-400"></i>
                    Aucun contact trouvé.
                </div>

                {{-- Error --}}
                <div id="segment_contact_search_error" class="alert alert-danger d-none py-3 fs-7" role="alert">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Erreur de recherche. Veuillez réessayer.
                </div>

                {{-- Hint (shown before first search) --}}
                <div id="segment_contact_search_hint" class="text-center py-8 text-muted fs-7">
                    <i class="bi bi-keyboard fs-2x mb-2 d-block text-gray-400"></i>
                    Tapez au moins 2 caractères pour rechercher.
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle me-1"></i>
                    Fermer
                </button>
            </div>

        </div>
    </div>
</div>
