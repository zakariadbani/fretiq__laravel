<div class="modal fade" id="kt_modal_update_permission" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-650px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="fw-bold" id="permission-modal-title">Ajouter une permission</h2>
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal" aria-label="Close">
                    {!! getIcon('cross','fs-1') !!}
                </div>
            </div>
            <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                <!--begin::Notice-->
                <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed mb-9 p-6">
                    {!! getIcon('information','fs-2tx text-warning me-4') !!}
                    <div class="d-flex flex-stack flex-grow-1">
                        <div class="fw-semibold">
                            <div class="fs-6 text-gray-700">
                                <strong class="me-1">Attention !</strong>
                                Modifier le nom d'une permission peut casser les contrôles d'accès existants.
                                Agissez avec précaution.
                            </div>
                        </div>
                    </div>
                </div>
                <!--end::Notice-->

                <form id="kt_modal_update_permission_form" class="form" method="POST">
                    @csrf
                    <input type="hidden" name="_method" id="permission-form-method" value="POST">
                    <input type="hidden" name="permission_id" id="permission-form-id" value="">

                    <div class="fv-row mb-7">
                        <label class="fs-6 fw-semibold form-label mb-2">
                            <span class="required">Nom de la permission</span>
                            <span class="ms-2"
                                  data-bs-toggle="popover"
                                  data-bs-trigger="hover"
                                  data-bs-html="true"
                                  data-bs-content="Le nom doit être unique. Convention : '{action} {entité}' (ex : view companies).">
                                {!! getIcon('information','fs-7') !!}
                            </span>
                        </label>
                        <input class="form-control form-control-solid"
                               placeholder="Ex : view companies"
                               name="name"
                               id="permission-name-input" />
                        <span class="text-danger d-none" id="permission-name-error"></span>
                    </div>

                    <div class="text-center pt-15">
                        <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary" id="permission-submit-btn">
                            <span class="indicator-label">Enregistrer</span>
                            <span class="indicator-progress d-none">
                                Veuillez patienter...
                                <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                            </span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function() {
    const modal = document.getElementById('kt_modal_update_permission');
    const form = document.getElementById('kt_modal_update_permission_form');
    const submitBtn = document.getElementById('permission-submit-btn');
    const nameInput = document.getElementById('permission-name-input');
    const nameError = document.getElementById('permission-name-error');
    const modalTitle = document.getElementById('permission-modal-title');
    const methodInput = document.getElementById('permission-form-method');
    const idInput = document.getElementById('permission-form-id');

    modal.addEventListener('show.bs.modal', function (e) {
        const button = e.relatedTarget;
        const permissionId   = button?.getAttribute('data-permission-id');
        const permissionName = button?.getAttribute('data-permission-name');

        form.reset();
        nameError.classList.add('d-none');
        nameError.textContent = '';

        if (permissionId && permissionName) {
            modalTitle.textContent = 'Modifier la permission';
            methodInput.value = 'PUT';
            idInput.value = permissionId;
            nameInput.value = permissionName;
        } else {
            modalTitle.textContent = 'Ajouter une permission';
            methodInput.value = 'POST';
            idInput.value = '';
            nameInput.value = '';
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        submitBtn.querySelector('.indicator-label').classList.add('d-none');
        submitBtn.querySelector('.indicator-progress').classList.remove('d-none');
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const permissionId = idInput.value;
        const isEdit = methodInput.value === 'PUT';
        const url = isEdit
            ? '{{ url('/admin/user-management/permissions') }}/' + permissionId
            : '{{ route('user-management.permissions.store') }}';

        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(modal).hide();
                Swal.fire({
                    text: data.message || (isEdit ? 'Permission mise à jour.' : 'Permission créée.'),
                    icon: 'success',
                    confirmButtonText: 'Ok',
                    customClass: { confirmButton: 'btn btn-primary' }
                }).then(() => {
                    const tableKey = 'permissions-table';
                    if (window.LaravelDataTables?.[tableKey]) {
                        window.LaravelDataTables[tableKey].ajax.reload();
                    }
                });
            } else {
                if (data.errors?.name) {
                    nameError.textContent = data.errors.name[0];
                    nameError.classList.remove('d-none');
                } else {
                    Swal.fire({
                        text: data.message || 'Une erreur est survenue.',
                        icon: 'error',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                }
            }
        })
        .catch(() => {
            Swal.fire({
                text: 'Erreur de connexion.',
                icon: 'error',
                confirmButtonText: 'Ok',
                customClass: { confirmButton: 'btn btn-primary' }
            });
        })
        .finally(() => {
            submitBtn.querySelector('.indicator-label').classList.remove('d-none');
            submitBtn.querySelector('.indicator-progress').classList.add('d-none');
            submitBtn.disabled = false;
        });
    });

    window.deletePermission = function (permissionId, permissionName) {
        Swal.fire({
            text: 'Êtes-vous sûr de vouloir supprimer la permission "' + permissionName + '" ?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Oui, supprimer',
            cancelButtonText: 'Annuler',
            customClass: {
                confirmButton: 'btn btn-danger',
                cancelButton: 'btn btn-secondary',
            }
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch('{{ url('/admin/user-management/permissions') }}/' + permissionId, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        text: 'Permission supprimée avec succès.',
                        icon: 'success',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    }).then(() => {
                        const tableKey = 'permissions-table';
                        if (window.LaravelDataTables?.[tableKey]) {
                            window.LaravelDataTables[tableKey].ajax.reload();
                        }
                    });
                } else {
                    Swal.fire({
                        text: data.message || 'Une erreur est survenue.',
                        icon: 'error',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                }
            })
            .catch(() => {
                Swal.fire({
                    text: 'Erreur de connexion.',
                    icon: 'error',
                    confirmButtonText: 'Ok',
                    customClass: { confirmButton: 'btn btn-primary' }
                });
            });
        });
    };
})();
</script>
@endpush
