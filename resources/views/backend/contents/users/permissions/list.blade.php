<x-default-layout>

@section('title')
    Gestion des permissions
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Permissions']]" />
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
