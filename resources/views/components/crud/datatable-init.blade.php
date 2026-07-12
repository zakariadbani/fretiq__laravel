@push('scripts')
    {{ $dataTable->scripts() }}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            DataTableUtils.initializeIndex(@json($dataTableConfig));
        });
    </script>
@endpush
