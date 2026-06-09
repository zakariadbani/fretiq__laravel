{{--
    Action buttons for the UsersAssignedRoleDataTable row.
    $user = App\Models\User instance.
--}}
<div class="d-flex justify-content-end gap-2">
    @can('view users')
    <a href="{{ route('admin.users.view', $user->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
       title="Voir">
        <i class="bi bi-eye fs-4"></i>
    </a>
    @endcan
</div>
