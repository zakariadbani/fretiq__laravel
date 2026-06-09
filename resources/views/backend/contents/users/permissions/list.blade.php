<x-default-layout>

@section('title')
    Gestion des permissions
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Administration</li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Permissions</li>
    </ul>
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h2 class="fw-bold m-0">
                <i class="bi bi-lock text-primary fs-2 me-2"></i>
                Permissions
            </h2>
        </div>
        <div class="card-toolbar">
            <button type="button"
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#kt_modal_update_permission">
                <i class="bi bi-plus-lg fs-2"></i>
                Ajouter une permission
            </button>
        </div>
    </div>

    <div class="card-body py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

@include('backend.contents.users.partials._permission-modal')

@push('scripts')
    {{ $dataTable->scripts() }}
@endpush

</x-default-layout>
