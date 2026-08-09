<x-default-layout>
    @section('title', 'Explorateur CRM')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'CRM Zoho'], ['label' => ucfirst($module)]]" />
    @endsection

    @if($mappingRequired)
        <div class="alert alert-warning d-flex align-items-center mb-6" role="alert">
            <i class="bi bi-person-lock fs-2 me-3" aria-hidden="true"></i>
            <div>Votre portefeuille Zoho doit être confirmé par un administrateur avant de pouvoir afficher des données CRM.</div>
        </div>
    @endif

    @foreach($filterNotices as $notice)
        <div class="alert alert-info d-flex align-items-center mb-6" role="status">
            <i class="bi bi-info-circle fs-2 me-3" aria-hidden="true"></i>
            <div>{{ $notice }}</div>
        </div>
    @endforeach

    <div class="card">
        <div class="card-header border-0 pt-6">
            <div class="card-title">
                <div class="d-flex align-items-center position-relative my-1">
                    <i class="bi bi-search position-absolute ms-5" aria-hidden="true"></i>
                    <input id="zoho-records-search" type="search" class="form-control form-control-solid w-250px ps-13" placeholder="Rechercher" aria-label="Rechercher dans {{ ucfirst($module) }}">
                </div>
            </div>
            <div class="card-toolbar">
                @can('export zoho records')
                    <a class="btn btn-light-primary" href="{{ url('/admin/zoho/records/'.$module.'/export').(count($filters) ? '?'.http_build_query($filters) : '') }}">
                        <i class="bi bi-download" aria-hidden="true"></i> Exporter CSV
                    </a>
                @endcan
            </div>
        </div>
        <div class="card-body py-4">
            <div class="table-responsive">
                <table id="zoho-records-table" class="table align-middle table-row-dashed fs-6 gy-3" data-source="{{ url('/admin/zoho/records/'.$module.'/data').(count($filters) ? '?'.http_build_query($filters) : '') }}">
                    <thead><tr>
                        @foreach($fields as $field)<th>{{ str_replace('_', ' ', ucfirst($field)) }}</th>@endforeach
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="text-muted mt-4 mb-0">Les enregistrements supprimés dans Zoho sont exclus de cette vue. Les données affichées sont en lecture seule.</p>
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var table = $('#zoho-records-table');
                if (!table.length || !$.fn.DataTable) return;
                var columns = @json(array_map(fn ($field) => ['data' => $field, 'name' => $field], $fields));
                var instance = table.DataTable({
                    processing: true, serverSide: true, responsive: true,
                    ajax: table.data('source'), columns: columns,
                    language: @json(trans('datatables')),
                    order: [[Math.max(columns.length - 1, 0), 'desc']],
                    createdRow: function (row, data) {
                        $(row).css('cursor', 'pointer').on('click', function () { window.location = data.detail_url; });
                    }
                });
                $('#zoho-records-search').on('input', function () { instance.search(this.value).draw(); });
            });
        </script>
    @endpush
</x-default-layout>
