{{-- Action buttons for DataTable rows --}}
@php
    $actionPermission = $actionPermission ?? null;
    $editPerm   = $actionPermission ?? ('edit '   . $modelName);
    $viewPerm   = $actionPermission ?? ('view '   . $modelName);
    $deletePerm = $actionPermission ?? ('delete ' . $modelName);
@endphp
<div class="d-flex justify-content-end flex-shrink-0">
    @can($editPerm)
    {{-- Edit Button --}}
    <a href="{{ route('admin.' . $modelName . '.edit', $model->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
       data-bs-toggle="tooltip"
       title="Modifier">
        <i class="bi bi-pencil fs-4"></i>
    </a>
    @endcan

    @can($viewPerm)
    {{-- View Button --}}
    <a href="{{ route('admin.' . $modelName . '.view', $model->id) }}"
       class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"
       data-bs-toggle="tooltip"
       title="Voir">
        <i class="bi bi-eye fs-4"></i>
    </a>
    @endcan

    @can($deletePerm)
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
