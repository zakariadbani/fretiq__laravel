@if($isView)
    @can('edit suppressions')
        <a href="{{ route('admin.suppressions.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif
