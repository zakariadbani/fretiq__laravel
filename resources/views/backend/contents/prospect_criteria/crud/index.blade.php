<x-default-layout>

@section('title')
    Critères de découverte
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte']]" />
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
                       placeholder="Rechercher un critère"
                       id="mySearchInput" />
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="card-toolbar">
            <div class="d-flex justify-content-end align-items-center gap-3" data-kt-table-toolbar="base">

                {{-- Daily quota badge (solde) --}}
                @include('backend.contents.prospect_criteria.partials._quota-badge', [
                    'quotaRemaining'      => $quotaRemaining ?? null,
                    'quotaPackage'        => $quotaPackage ?? null,
                    'activeDailyLimitSum' => $activeDailyLimitSum ?? null,
                ])

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

                @can('create prospect_criteria')
                <a href="{{ route('admin.prospect_criteria.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Ajouter un critère
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

@push('scripts')
    <script>
        /**
         * Generic helper: submit a hidden POST form to the given URL with a CSRF token.
         * Used by the "Dupliquer" row action button.
         */
        window.submitPostForm = function (url, csrfToken) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            var csrf = document.createElement('input');
            csrf.type  = 'hidden';
            csrf.name  = '_token';
            csrf.value = csrfToken;
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        };

        /**
         * "Lancer la découverte" action — called from the row action button.
         * Sends a POST to the discover endpoint and shows a Swal/toastr notification.
         *
         * HTTP status mapping:
         *   200 success  → toastr success with server-provided text (partial or full batch)
         *   409          → already in-flight info toast
         *   422          → error toast with server-provided text verbatim (inactive / quota épuisé)
         *   other        → generic error toast
         */
        window.launchDiscovery = function (id, csrfToken) {
            Swal.fire({
                title: 'Lancer la découverte ?',
                text: 'La pipeline de découverte sera exécutée en arrière-plan pour ce critère.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Lancer',
                cancelButtonText: 'Annuler',
                confirmButtonColor: '#009ef7',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                fetch('/admin/prospect_criteria/' + id + '/discover', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({}),
                })
                .then(function (response) {
                    var httpStatus = response.status;
                    return response.json().then(function (data) {
                        return { status: httpStatus, data: data };
                    });
                })
                .then(function (res) {
                    var httpStatus = res.status;
                    var data       = res.data;

                    if (httpStatus === 200 && data.message === 'success') {
                        toastr.success(data.text || 'Découverte lancée en arrière-plan', 'Succès');
                    } else if (httpStatus === 409) {
                        toastr.info('Une découverte est déjà en cours.', 'En cours');
                    } else if (httpStatus === 422) {
                        toastr.error(data.text || 'Le critère est inactif ou invalide.', 'Erreur');
                    } else {
                        toastr.error('Une erreur est survenue.', 'Erreur');
                    }
                })
                .catch(function () {
                    toastr.error('Une erreur est survenue.', 'Erreur');
                });
            });
        };
    </script>
@endpush

</x-default-layout>
