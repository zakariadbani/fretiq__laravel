{{-- Action buttons for DataTable rows --}}
<div class="d-flex justify-content-end flex-shrink-0">
    @can('edit ' . $modelName)
    {{-- Edit Button --}}
    <a href="{{ route('admin.' . $modelName . '.edit', $model->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
       data-bs-toggle="tooltip"
       title="Modifier">
        <i class="bi bi-pencil fs-4"></i>
    </a>
    @endcan

    @can('view ' . $modelName)
    {{-- View Button --}}
    <a href="{{ route('admin.' . $modelName . '.view', $model->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
       data-bs-toggle="tooltip"
       title="Voir">
        <i class="bi bi-eye fs-4"></i>
    </a>
    @endcan

    @can('delete ' . $modelName)
    {{-- Delete Button --}}
    <a href="javascript:void(0);"
       class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm delete-btn"
       data-id="{{ $model->id }}"
       data-url="{{ route('admin.' . $modelName . '.delete', $model->id) }}"
       data-bs-toggle="tooltip"
       title="Supprimer">
        <i class="bi bi-trash fs-4"></i>
    </a>
    @endcan
</div>
