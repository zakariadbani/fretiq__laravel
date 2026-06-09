{{--
    User hero action buttons — rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('view users')
        <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @if(!$model->hasRole('superadmin'))
        @can('edit users')
            <a href="{{ route('admin.users.edit', $model->id) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil me-1"></i>
                Modifier
            </a>
        @endcan
    @endif
@endif
