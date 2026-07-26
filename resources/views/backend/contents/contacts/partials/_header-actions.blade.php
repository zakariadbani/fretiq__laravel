{{--
    Contact hero action buttons shared by view and edit.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('edit contacts')
        <a href="{{ route('admin.contacts.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif

@can('create demandes')
    @if(Route::has('admin.demandes.create'))
        <a href="{{ route('admin.demandes.create', ['contact_id' => $model->id]) }}" class="btn btn-sm btn-light btn-active-light-success">
            <i class="bi bi-plus-circle me-1"></i>
            Creer une demande
        </a>
    @endif
@endcan
