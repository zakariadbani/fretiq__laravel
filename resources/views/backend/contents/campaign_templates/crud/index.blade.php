<x-default-layout>

@section('title')
    Modèles d'email
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Modèles d\'email']]" />
@endsection

<div class="card">
    {{-- Card header --}}
    <div class="card-header border-0 pt-6">
        {{-- Search --}}
        <div class="card-title">
            <div class="d-flex align-items-center position-relative my-1">
                {!! getIcon('magnifier', 'fs-3 position-absolute ms-5') !!}
                <input type="text"
                       data-kt-table-filter="search"
                       class="form-control form-control-solid w-250px ps-13"
                       placeholder="Rechercher un modèle"
                       id="mySearchInput" />
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="card-toolbar">
            <div class="d-flex justify-content-end gap-3" data-kt-table-toolbar="base">

                @can('create campaign_templates')
                <form method="POST" action="{{ route('admin.campaign_templates.import_zoho') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-light-info fw-bold">
                        <i class="bi bi-cloud-download fs-2"></i>
                        Importer depuis Zoho
                    </button>
                </form>
                @endcan

                @can('create campaign_templates')
                <a href="{{ route('admin.campaign_templates.import_form') }}" class="btn btn-light-primary">
                    <i class="bi bi-upload fs-2"></i>
                    Importer HTML
                </a>
                @endcan

                @can('create campaign_templates')
                <a href="{{ route('admin.campaign_templates.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Ajouter un modèle
                </a>
                @endcan
            </div>
        </div>
    </div>

    {{-- Card body — datatable --}}
    <div class="card-body py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

<x-crud.datatable-init :data-table="$dataTable" :data-table-config="$dataTableConfig" />

</x-default-layout>
