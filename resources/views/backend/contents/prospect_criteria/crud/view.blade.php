<x-default-layout>

@section('title')
    Critère — {{ e($model->name) }}
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.prospect_criteria.index') }}" class="text-muted text-hover-primary">Critères de découverte</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{--
    ProspectCriteria view — hero + tabbar + aperçu contract.
    Tab pane IDs: criteria_apercu / criteria_general.
    Aperçu is native (default active); Général deep-links to edit.
    "Lancer la découverte" preserved in hero actions slot AND as a quick_action.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.prospect_criteria.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="criteria_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make($model),
        ])
    </div>
    {{-- end Aperçu --}}

    {{--
        Tab 2 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script>
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
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.message === 'success') {
                        toastr.success(data.text || 'Découverte lancée en arrière-plan', 'Succès');
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
