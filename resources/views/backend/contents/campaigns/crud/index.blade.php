<x-default-layout>

@section('title')
    Campagnes
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Campagnes']]" />
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
                       placeholder="Rechercher une campagne"
                       id="mySearchInput" />
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="card-toolbar">
            <div class="d-flex justify-content-end" data-kt-table-toolbar="base">

                {{-- Filter button --}}
                <button type="button"
                        class="btn btn-light-primary me-3"
                        data-kt-menu-trigger="click"
                        data-kt-menu-placement="bottom-end">
                    {!! getIcon('filter', 'fs-2', '', 'i') !!}
                    Filtrer
                </button>

                {{-- Filter dropdown menu --}}
                <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true">
                    <div class="px-7 py-5">
                        <div class="fs-5 text-gray-900 fw-bold">Options de filtrage</div>
                    </div>
                    <div class="separator border-gray-200"></div>
                    <div class="px-7 py-5" data-kt-table-filter="form">
                        <div id="filters-container"></div>
                        <div class="d-flex justify-content-end">
                            <button type="reset"
                                    class="btn btn-light btn-active-light-primary fw-semibold me-2 px-6"
                                    data-kt-menu-dismiss="true"
                                    data-kt-table-filter="reset">
                                Réinitialiser
                            </button>
                            <button type="submit"
                                    class="btn btn-primary fw-semibold px-6"
                                    data-kt-menu-dismiss="true"
                                    data-kt-table-filter="filter">
                                Appliquer
                            </button>
                        </div>
                    </div>
                </div>

                @can('create campaigns')
                <a href="{{ route('admin.campaigns.import_form') }}" class="btn btn-light-primary me-3">
                    <i class="bi bi-upload fs-2"></i>
                    Importer CSV
                </a>
                @endcan

                @can('create campaigns')
                <a href="{{ route('admin.campaigns.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Créer une campagne
                </a>
                @endcan
            </div>
        </div>
    </div>

    <div id="active-filters-bar" class="d-none px-9 pt-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold text-gray-700">Filtres actifs :</span>
            <div id="active-filters-badges" class="d-flex flex-wrap gap-2" aria-live="polite"></div>
            <button type="button" id="clear-all-filters" class="btn btn-sm btn-light-primary d-none">Tout effacer</button>
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
