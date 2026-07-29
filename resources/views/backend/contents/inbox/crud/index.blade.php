<x-default-layout>

@section('title')
    Boîte de réception
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Boîte de réception']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <div class="d-flex align-items-center position-relative my-1">
                {!! getIcon('magnifier', 'fs-3 position-absolute ms-5') !!}
                <input type="text" data-kt-table-filter="search"
                       class="form-control form-control-solid w-250px ps-13"
                       placeholder="Rechercher un email ou un objet" id="mySearchInput" />
            </div>
        </div>

        <div class="card-toolbar">
            <div class="d-flex justify-content-end gap-2" data-kt-table-toolbar="base">
                <button type="button" class="btn btn-light-primary"
                        data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                    {!! getIcon('filter', 'fs-2', '', 'i') !!} Filtrer
                </button>
                <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true">
                    <div class="px-7 py-5"><div class="fs-5 text-gray-900 fw-bold">Options de filtrage</div></div>
                    <div class="separator border-gray-200"></div>
                    <div class="px-7 py-5" data-kt-table-filter="form">
                        <div id="filters-container"></div>
                        <div class="d-flex justify-content-end">
                            <button type="reset" class="btn btn-light btn-active-light-primary fw-semibold me-2 px-6"
                                    data-kt-menu-dismiss="true" data-kt-table-filter="reset">Réinitialiser</button>
                            <button type="submit" class="btn btn-primary fw-semibold px-6"
                                    data-kt-menu-dismiss="true" data-kt-table-filter="filter">Appliquer</button>
                        </div>
                    </div>
                </div>

                @can('edit inbox')
                    <button type="button" class="btn btn-primary" id="inbox_resync"
                            data-url="{{ route('admin.inbox.resync') }}">
                        <i class="bi bi-arrow-repeat fs-2"></i> Relever maintenant
                    </button>
                @endcan
            </div>
        </div>
    </div>

    <div class="card-body py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

<x-crud.datatable-init :data-table="$dataTable" :data-table-config="$dataTableConfig" />

@push('scripts')
<script>
(function () {
    var button = document.getElementById('inbox_resync');
    if (!button) return;
    button.addEventListener('click', async function () {
        button.disabled = true;
        try {
            var response = await fetch(button.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            });
            var data = await response.json();
            await Swal.fire({ text: data.message, icon: response.ok ? 'success' : 'error', confirmButtonText: 'Fermer' });
        } catch (error) {
            await Swal.fire({ text: 'Impossible de lancer la relève IMAP.', icon: 'error', confirmButtonText: 'Fermer' });
        } finally {
            button.disabled = false;
        }
    });
}());
</script>
@endpush

</x-default-layout>
