{{--
    Action buttons for the PermissionsDataTable row.
    $permission = Spatie\Permission\Models\Permission instance.
--}}
<div class="d-flex justify-content-end gap-2">
    <button type="button"
            class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#kt_modal_update_permission"
            data-permission-id="{{ $permission->id }}"
            data-permission-name="{{ $permission->name }}"
            title="Modifier">
        <i class="bi bi-pencil fs-4"></i>
    </button>
    <button type="button"
            class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm"
            onclick="deletePermission({{ $permission->id }}, '{{ $permission->name }}')"
            title="Supprimer">
        <i class="bi bi-trash fs-4"></i>
    </button>
</div>
