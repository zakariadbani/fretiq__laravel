@php
    $allPermissions = \Spatie\Permission\Models\Permission::all()->sortBy('name');

    /*
     * Category grouping mirrors the sidebar order in config/global/menu.php.
     * Patterns are first-match-wins; entities appear in the order listed here,
     * and actions are ranked view / create / edit / delete inside each pattern.
     */
    $categoryPatterns = [
        // Prospection & campagnes
        'Accès général'             => ['backend.access'],
        'Découverte'                => ['*prospect_criteria*', '*prospect_batches*',
                                        'run discovery', 'run prospect resolution',
                                        'review prospect matches'],
        'Répertoire'                => ['*companies*', '*contacts*'],
        'Préparation des campagnes' => ['*segments*', '*campaign_templates*',
                                        '*sequences*', '*sender_identities*'],
        'Campagnes & planning'      => ['*campaigns*'],
        'Réponses & demandes'       => ['*inbox*', '*demandes*'],
        'Conformité & consommation' => ['*suppressions*', '*consumption*'],
        // Zoho CRM
        'Zoho CRM'                  => ['*marketing dashboard*', '*zoho*'],
        // Administration
        'Utilisateurs & accès'      => ['*users*', 'manage roles', 'manage permissions'],
        'Configuration'             => ['*settings*', '*packages*'],
        'Supervision'               => ['*provider quota*', '*provider activity*'],
    ];

    $actionRank = ['view' => 0, 'create' => 1, 'edit' => 2, 'delete' => 3];

    $permissionsByGroup = [];
    $assigned = [];

    foreach ($categoryPatterns as $category => $patterns) {
        foreach ($patterns as $pattern) {
            $matched = $allPermissions
                ->filter(fn ($p) => !isset($assigned[$p->id])
                    && \Illuminate\Support\Str::is($pattern, $p->name))
                ->sortBy(fn ($p) => $actionRank[explode(' ', $p->name, 2)[0]] ?? 9);

            foreach ($matched as $p) {
                $permissionsByGroup[$category][] = $p;
                $assigned[$p->id] = true;
            }
        }
    }

    // Safety net: any permission seeded later that matches no pattern still shows up.
    foreach ($allPermissions as $permission) {
        if (!isset($assigned[$permission->id])) {
            $permissionsByGroup['Autres'][] = $permission;
        }
    }
@endphp

<div class="modal fade" id="kt_modal_update_role" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mw-1000px">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="fw-bold" id="role-modal-title">Ajouter un rôle</h2>
                <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal" aria-label="Close">
                    {!! getIcon('cross','fs-1') !!}
                </div>
            </div>
            <div class="modal-body scroll-y mx-5 my-7">
                <form id="kt_modal_update_role_form"
                      class="form"
                      action="{{ route('user-management.roles.store') }}"
                      method="POST">
                    @csrf
                    <input type="hidden" name="_method" id="role-form-method" value="POST">
                    <input type="hidden" name="role_id" id="role-form-id" value="">

                    <div class="d-flex flex-column scroll-y me-n7 pe-7"
                         id="kt_modal_update_role_scroll"
                         data-kt-scroll="true"
                         data-kt-scroll-activate="{default: false, lg: true}"
                         data-kt-scroll-max-height="auto"
                         data-kt-scroll-dependencies="#kt_modal_update_role_header"
                         data-kt-scroll-wrappers="#kt_modal_update_role_scroll"
                         data-kt-scroll-offset="300px">

                        <!--begin::Input group-->
                        <div class="fv-row mb-10">
                            <label class="fs-5 fw-bold form-label mb-2">
                                <span class="required">Nom du rôle</span>
                            </label>
                            <input class="form-control form-control-solid"
                                   placeholder="Ex : manager"
                                   name="name"
                                   id="role-name-input" />
                            <span class="text-danger d-none" id="role-name-error"></span>
                        </div>
                        <!--end::Input group-->

                        <!--begin::Permissions-->
                        <div class="fv-row">
                            <label class="fs-5 fw-bold form-label mb-2">Permissions du rôle</label>
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed fs-6 gy-5">
                                    <tbody class="text-gray-600 fw-semibold">

                                    <tr>
                                        <td class="text-gray-800">
                                            Accès administrateur
                                            <span class="ms-1"
                                                  data-bs-toggle="tooltip"
                                                  title="Cochez pour accorder toutes les permissions.">
                                                {!! getIcon('information-5','text-gray-500 fs-6') !!}
                                            </span>
                                        </td>
                                        <td>
                                            <label class="form-check form-check-sm form-check-custom form-check-solid me-9">
                                                <input class="form-check-input" type="checkbox" id="kt_roles_select_all" />
                                                <span class="form-check-label" for="kt_roles_select_all">
                                                    Tout sélectionner
                                                </span>
                                            </label>
                                        </td>
                                    </tr>

                                    @foreach($permissionsByGroup as $group => $permissions)
                                        <tr>
                                            <td colspan="5" class="pt-5 pb-2">
                                                <span class="fw-bold text-primary fs-6">{{ $group }}</span>
                                                <span class="badge badge-light-primary ms-2">{{ count($permissions) }}</span>
                                            </td>
                                        </tr>
                                        @foreach(array_chunk($permissions, 3) as $row)
                                        <tr>
                                            @foreach($row as $permission)
                                                <td>
                                                    <label class="form-check form-check-sm form-check-custom form-check-solid">
                                                        <input class="form-check-input permission-checkbox"
                                                               type="checkbox"
                                                               name="permissions[]"
                                                               value="{{ $permission->name }}" />
                                                        <span class="form-check-label">{{ permission_label($permission->name) }}</span>
                                                    </label>
                                                </td>
                                            @endforeach
                                            @for($i = count($row); $i < 3; $i++)
                                                <td></td>
                                            @endfor
                                        </tr>
                                        @endforeach
                                    @endforeach

                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <!--end::Permissions-->

                    </div>

                    <div class="text-center pt-15">
                        <button type="reset" class="btn btn-light me-3" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary" id="role-submit-btn">
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
    const modal = document.getElementById('kt_modal_update_role');
    const form = document.getElementById('kt_modal_update_role_form');
    const selectAllCheckbox = document.getElementById('kt_roles_select_all');
    const permissionCheckboxes = document.querySelectorAll('.permission-checkbox');
    const submitBtn = document.getElementById('role-submit-btn');
    const nameInput = document.getElementById('role-name-input');
    const nameError = document.getElementById('role-name-error');
    const modalTitle = document.getElementById('role-modal-title');
    const methodInput = document.getElementById('role-form-method');
    const idInput = document.getElementById('role-form-id');

    // Select all / deselect all
    selectAllCheckbox.addEventListener('change', function () {
        permissionCheckboxes.forEach(cb => cb.checked = this.checked);
    });

    permissionCheckboxes.forEach(cb => {
        cb.addEventListener('change', function () {
            selectAllCheckbox.checked = Array.from(permissionCheckboxes).every(c => c.checked);
        });
    });

    // Modal show: populate for edit or reset for create
    modal.addEventListener('show.bs.modal', function (e) {
        const button = e.relatedTarget;
        const roleId   = button?.getAttribute('data-role-id');
        const roleName = button?.getAttribute('data-role-name');

        form.reset();
        permissionCheckboxes.forEach(cb => cb.checked = false);
        selectAllCheckbox.checked = false;
        nameError.classList.add('d-none');
        nameError.textContent = '';

        if (roleId && roleName) {
            modalTitle.textContent = 'Modifier le rôle';
            methodInput.value = 'PUT';
            idInput.value = roleId;
            nameInput.value = roleName;

            // Load permissions via AJAX
            fetch('{{ url('/admin/user-management/roles') }}/' + roleId + '/permissions', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.permissions) {
                    data.permissions.forEach(permName => {
                        const cb = document.querySelector(`.permission-checkbox[value="${permName}"]`);
                        if (cb) cb.checked = true;
                    });
                    selectAllCheckbox.checked = Array.from(permissionCheckboxes).every(c => c.checked);
                }
            })
            .catch(err => console.error('Error loading permissions:', err));
        } else {
            modalTitle.textContent = 'Ajouter un rôle';
            methodInput.value = 'POST';
            idInput.value = '';
        }
    });

    // AJAX form submit
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        submitBtn.querySelector('.indicator-label').classList.add('d-none');
        submitBtn.querySelector('.indicator-progress').classList.remove('d-none');
        submitBtn.disabled = true;

        const formData = new FormData(form);
        const roleId = idInput.value;
        const isEdit = methodInput.value === 'PUT';
        const url = isEdit
            ? '{{ route('user-management.roles.update', '') }}/' + roleId
            : '{{ route('user-management.roles.store') }}';

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
                    text: data.message || (isEdit ? 'Rôle mis à jour avec succès.' : 'Rôle créé avec succès.'),
                    icon: 'success',
                    confirmButtonText: 'Ok',
                    customClass: { confirmButton: 'btn btn-primary' }
                }).then(() => window.location.reload());
            } else {
                if (data.errors && data.errors.name) {
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

    // Delete role via AJAX (called from role card "Supprimer" button if added)
    window.deleteRole = function (roleId, roleName) {
        Swal.fire({
            text: 'Êtes-vous sûr de vouloir supprimer le rôle "' + roleName + '" ?',
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
            fetch('{{ route('user-management.roles.destroy', '') }}/' + roleId, {
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
                        text: 'Rôle supprimé avec succès.',
                        icon: 'success',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    }).then(() => window.location.reload());
                } else {
                    Swal.fire({
                        text: data.message || 'Une erreur est survenue.',
                        icon: 'error',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                }
            });
        });
    };
})();
</script>
@endpush
