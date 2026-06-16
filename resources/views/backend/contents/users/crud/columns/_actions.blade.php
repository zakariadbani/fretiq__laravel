{{--
    Action buttons for the users DataTable row.
    $row = App\Models\User instance.
--}}
<div class="d-flex justify-content-end gap-2">

    @can('view users')
    <a href="{{ route('admin.users.view', $row->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
       title="Voir">
        <i class="bi bi-eye fs-4"></i>
    </a>
    @endcan

    @can('edit users')
    <a href="{{ route('admin.users.edit', $row->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm"
       title="Modifier">
        <i class="bi bi-pencil fs-4"></i>
    </a>
    @endcan

    @can('delete users')
    @if($row->id !== auth()->id())
    <button type="button"
            class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm btn-delete-user"
            data-user-id="{{ $row->id }}"
            data-user-name="{{ $row->name }}"
            title="Supprimer">
        <i class="bi bi-trash fs-4"></i>
    </button>
    @endif
    @endcan

</div>
