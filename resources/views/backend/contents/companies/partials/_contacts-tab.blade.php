{{--
    Contacts tab pane — inline contact management for a company.
    Shared between view.blade.php and form.blade.php (on edit, placed after </form> and
    moved into #company_tab_content by company-tabs.js).

    Actions gated by @can('create contacts') / @can('edit contacts') / @can('delete contacts').
    Delete uses a fetch() POST with _method=DELETE to admin.contacts.delete.
    Edit opens #company_contact_modal prefilled via data-* attributes.
--}}

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">
            <i class="bi bi-people text-info fs-3 me-2"></i>
            Contacts ({{ $model->contacts->count() }})
        </h3>
        @can('create contacts')
            <div class="card-toolbar">
                <button type="button"
                        class="btn btn-sm btn-light-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#company_contact_modal"
                        id="btn_add_contact">
                    <i class="bi bi-plus fs-4 me-1"></i>
                    Ajouter
                </button>
            </div>
        @endcan
    </div>
    <div class="card-body border-top" id="contacts_table_wrapper">
        @if($model->contacts->isEmpty())
            <div class="text-center py-10 text-muted" id="contacts_empty_state">
                <i class="bi bi-people fs-2x mb-3 d-block"></i>
                Aucun contact pour cette entreprise.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3" id="contacts_table">
                    <thead>
                        <tr class="fw-bold text-muted">
                            <th class="min-w-140px">Nom</th>
                            <th class="min-w-120px">Email</th>
                            <th class="min-w-80px">Statut</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="contacts_tbody">
                        @foreach($model->contacts as $contact)
                            @php $cStatus = config('global.data.contact_statuses.' . $contact->status); @endphp
                            <tr id="contact_row_{{ $contact->id }}">
                                <td>
                                    <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                       class="text-gray-900 fw-bold text-hover-primary fs-6">
                                        {{ e($contact->name) }}
                                    </a>
                                    @if($contact->position)
                                        <span class="text-muted fw-semibold d-block fs-7">{{ e($contact->position) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="mailto:{{ $contact->email }}"
                                       class="text-gray-700 text-hover-primary fs-7">
                                        {{ e($contact->email) }}
                                    </a>
                                </td>
                                <td>
                                    @if($cStatus)
                                        <span class="badge badge-light-{{ $cStatus['color'] }}">{{ $cStatus['label'] }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-2">
                                        @can('view contacts')
                                            <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                               class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
                                               title="Voir">
                                                <i class="bi bi-eye fs-4"></i>
                                            </a>
                                        @endcan

                                        @can('edit contacts')
                                            <button type="button"
                                                    class="btn btn-icon btn-bg-light btn-active-color-warning btn-sm btn-edit-contact"
                                                    title="Modifier"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#company_contact_modal"
                                                    data-contact-id="{{ $contact->id }}"
                                                    data-contact-name="{{ e($contact->name) }}"
                                                    data-contact-email="{{ e($contact->email) }}"
                                                    data-contact-position="{{ e($contact->position) }}"
                                                    data-contact-phone="{{ e($contact->phone) }}"
                                                    data-contact-status="{{ $contact->status }}"
                                                    data-contact-source="{{ $contact->source }}"
                                                    data-contact-legal-basis="{{ $contact->legal_basis }}"
                                                    data-contact-email-kind="{{ $contact->email_kind }}"
                                                    data-update-url="{{ route('admin.contacts.update', $contact->id) }}">
                                                <i class="bi bi-pencil fs-4"></i>
                                            </button>
                                        @endcan

                                        @can('delete contacts')
                                            <button type="button"
                                                    class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm btn-delete-contact"
                                                    title="Supprimer"
                                                    data-contact-id="{{ $contact->id }}"
                                                    data-contact-name="{{ e($contact->name) }}"
                                                    data-delete-url="{{ route('admin.contacts.delete', $contact->id) }}">
                                                <i class="bi bi-trash fs-4"></i>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
