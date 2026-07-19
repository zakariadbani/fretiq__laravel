<x-default-layout>

@section('title')
    Critère — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte', 'route' => 'admin.prospect_criteria.index'], ['label' => $model->name]]" />
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
        {{-- Live discovery status panel — above the generic aperçu --}}
        @include('backend.contents.prospect_criteria.partials._discovery-status', [
            'model' => $model,
        ])

        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => $viewConfig ?? \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make($model),
        ])
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 2: Historique (native on view) ────────────────────────────── --}}
    <div class="tab-pane fade" id="criteria_historique" role="tabpanel">
        @include('backend.contents.prospect_criteria.partials._discovery-history', ['model' => $model])
    </div>
    {{-- end Historique --}}

    {{-- ── Tab 3: Résultats (inline discovered companies) ────────────────── --}}
    <div class="tab-pane fade" id="criteria_resultats" role="tabpanel">
        @include('backend.contents.prospect_criteria.partials._query-results', [
            'model'       => $model,
            'queryGroups' => $queryGroups ?? [],
            'auditMode'   => $auditMode ?? false,
        ])
        @include('backend.contents.prospect_criteria.partials._results-tab', [
            'model'           => $model,
            'resultCompanies' => $resultCompanies,
            'resultsSort'     => $resultsSort ?? 'score',
            'resultsDir'      => $resultsDir ?? 'desc',
        ])
    </div>
    {{-- end Résultats --}}

    {{--
        Tab 4 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    @include('backend.contents.prospect_criteria.partials._discovery-script')
@endpush

</x-default-layout>
