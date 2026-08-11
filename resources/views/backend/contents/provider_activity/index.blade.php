<x-default-layout>

@section('title', 'Activité fournisseurs')

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Prospection'], ['label' => 'Activité fournisseurs']]" />
@endsection

<div class="row g-5 mb-7">
    @foreach([
        ['Appels (24 h)', (int) ($stats->calls ?? 0), 'primary'],
        ['Réussis', (int) ($stats->succeeded ?? 0), 'success'],
        ['En attente', (int) ($stats->waiting ?? 0), 'warning'],
        ['Échecs', (int) ($stats->failed ?? 0), 'danger'],
    ] as [$label, $value, $color])
        <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="text-muted fs-7">{{ $label }}</div><div class="fs-2 fw-bold text-{{ $color }}">{{ $value }}</div></div></div></div>
    @endforeach
</div>

<div class="alert alert-light-info">Cette page utilise uniquement le journal local sécurisé. Elle ne déclenche aucun appel fournisseur.</div>

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Activité fournisseurs</h2></div>
        <div class="card-toolbar">
            <div class="d-flex align-items-center position-relative">
                {!! getIcon('magnifier', 'fs-3 position-absolute ms-5') !!}
                <input type="text" data-kt-table-filter="search" id="mySearchInput" class="form-control form-control-solid w-250px ps-13" placeholder="Rechercher">
            </div>
        </div>
    </div>
    <div class="card-body py-4"><div class="table-responsive">{{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}</div></div>
</div>

<x-crud.datatable-init :data-table="$dataTable" :data-table-config="$dataTableConfig" />

</x-default-layout>
