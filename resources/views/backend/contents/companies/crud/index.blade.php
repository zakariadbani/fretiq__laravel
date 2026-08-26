<x-default-layout>

@php
    $isArchive = $isArchive ?? false;
@endphp

@section('title')
    {{ $isArchive ? 'Rejetées / Archives' : 'Entreprises' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="$isArchive
        ? [['label' => 'Entreprises', 'route' => 'admin.companies.index'], ['label' => 'Rejetées / Archives']]
        : [['label' => 'Entreprises']]" />
@endsection

@if($isArchive)
    <div class="alert alert-warning d-flex align-items-center mb-6" role="alert">
        <i class="bi bi-archive-fill fs-2 me-3"></i>
        <div>Rejetées / Archives — ces entreprises restent exclues de la prospection active.</div>
    </div>
@endif

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
                       placeholder="Rechercher une entreprise"
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

                @can('view companies')
                    @if($isArchive)
                        <a href="{{ route('admin.companies.index') }}" class="btn btn-light-danger me-3">
                            <i class="bi bi-arrow-left fs-4"></i>
                            Entreprises actives
                        </a>
                    @else
                        <a href="{{ route('admin.companies.archive') }}" class="btn btn-light-danger me-3">
                            <i class="bi bi-archive fs-4"></i>
                            Rejetées / Archives
                            <span id="rejected-companies-count" class="badge badge-light-danger ms-1">{{ $rejectedCount ?? 0 }}</span>
                        </a>
                    @endif
                @endcan

                @can('create companies')
                <a href="{{ route('admin.companies.import_form') }}" class="btn btn-light-primary me-3">
                    <i class="bi bi-upload fs-2"></i>
                    Importer
                </a>
                @endcan

                @can('create companies')
                <a href="{{ route('admin.companies.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Ajouter une entreprise
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

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // ── Delegated enrich button handler ──────────────────────────────
            // Mirrors the delete-btn wiring pattern: click delegated on the table body.
            var tableId = @json($dataTableConfig['tableId'] ?? 'company');
            var $tableEl = $('#' + tableId + '-table');

            $tableEl.on('click', '.enrich-btn', function (e) {
                e.preventDefault();

                var id  = $(this).data('id');
                var url = $(this).data('url');
                var $btn = $(this);

                Swal.fire({
                    title: 'Récupérer les contacts ?',
                    text: "Cette action consommera 1 crédit de découverte.",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Récupérer',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#009ef7',
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    $btn.prop('disabled', true);

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({}),
                    })
                    .then(function (response) {
                        var httpStatus = response.status;
                        return response.text().then(function (text) {
                            var data = {};
                            try {
                                data = JSON.parse(text);
                            } catch (e) {
                                $btn.prop('disabled', false);
                                toastr.error("Une erreur est survenue — réessayez.", 'Erreur');
                                return;
                            }

                            $btn.prop('disabled', false);

                            if (httpStatus === 200) {
                                toastr.success(data.text || 'Enrichissement réussi.', 'Succès');
                                // Reload the DataTable without resetting pagination.
                                var dtApi = $.fn.dataTable.Api('#' + tableId + '-table');
                                if (dtApi) {
                                    dtApi.ajax.reload(null, false);
                                }
                            } else if (httpStatus === 409 || httpStatus === 422) {
                                toastr.error(data.text || 'Enrichissement impossible.', 'Erreur');
                            } else {
                                toastr.error(data.text || 'Une erreur est survenue.', 'Erreur');
                            }
                        });
                    })
                    .catch(function () {
                        $btn.prop('disabled', false);
                        toastr.error("Une erreur est survenue — réessayez.", 'Erreur');
                    });
                });
            });
        });
    </script>
@endpush

</x-default-layout>
