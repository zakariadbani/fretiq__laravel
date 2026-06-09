<x-default-layout>

@section('title')
    Utilisateurs
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
        <li class="breadcrumb-item text-muted">Utilisateurs</li>
    </ul>
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
                       placeholder="Rechercher un utilisateur"
                       id="mySearchInput" />
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="card-toolbar">
            <div class="d-flex justify-content-end" data-kt-table-toolbar="base">
                @can('create users')
                <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Ajouter un utilisateur
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

@push('scripts')
    {{ $dataTable->scripts() }}
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Search binding
        const searchInput = document.getElementById('mySearchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function () {
                const tableId = Object.keys(window.LaravelDataTables || {})[0];
                if (tableId && window.LaravelDataTables[tableId]) {
                    window.LaravelDataTables[tableId].search(this.value).draw();
                }
            });
        }

        // Delete user via AJAX
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-delete-user');
            if (!btn) return;

            const userId = btn.dataset.userId;
            const userName = btn.dataset.userName;

            Swal.fire({
                text: 'Êtes-vous sûr de vouloir supprimer ' + userName + ' ?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler',
                customClass: {
                    confirmButton: 'btn btn-danger',
                    cancelButton: 'btn btn-secondary',
                }
            }).then(function (result) {
                if (!result.isConfirmed) return;

                fetch('/admin/users/' + userId, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        Swal.fire({
                            text: 'Utilisateur supprimé avec succès.',
                            icon: 'success',
                            confirmButtonText: 'Ok',
                            customClass: { confirmButton: 'btn btn-primary' }
                        }).then(function () {
                            const tableId = Object.keys(window.LaravelDataTables || {})[0];
                            if (tableId && window.LaravelDataTables[tableId]) {
                                window.LaravelDataTables[tableId].ajax.reload();
                            }
                        });
                    } else {
                        Swal.fire({
                            text: data.msg || 'Une erreur est survenue.',
                            icon: 'error',
                            confirmButtonText: 'Ok',
                            customClass: { confirmButton: 'btn btn-primary' }
                        });
                    }
                })
                .catch(function () {
                    Swal.fire({
                        text: 'Erreur de connexion.',
                        icon: 'error',
                        confirmButtonText: 'Ok',
                        customClass: { confirmButton: 'btn btn-primary' }
                    });
                });
            });
        });
    });
    </script>
@endpush

</x-default-layout>
