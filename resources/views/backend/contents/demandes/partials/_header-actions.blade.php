{{--
    Demande hero action buttons — rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('view demandes')
        <a href="{{ route('admin.demandes.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit demandes')
        <a href="{{ route('admin.demandes.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif
