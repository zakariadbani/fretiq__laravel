<x-default-layout>

@section('title')
    Critère — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte', 'route' => 'admin.prospect_criteria.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.prospect_criteria.index'])
@endsection

{{--
    ProspectCriteria view — hero + tabbar + aperçu contract.
    Native tab pane IDs: criteria_apercu / criteria_resultats.
    Aperçu is active by default and includes the discovery history;
    Général and Automatisation deep-link to edit.
    "Lancer la découverte" preserved in hero actions slot AND as a quick_action.
--}}

{{-- Permanent progress banner stays visible regardless of the selected tab. --}}
@include('backend.contents.prospect_criteria.partials._discovery-status', [
    'model' => $model,
    'discoveryContext' => 'view',
])

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
            'config' => $viewConfig ?? \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make($model),
        ])

        <div class="mt-10">
            @include('backend.contents.prospect_criteria.partials._discovery-history', ['model' => $model])
        </div>
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 2: Résultats (inline discovered companies) ────────────────── --}}
    <div class="tab-pane fade" id="criteria_resultats" role="tabpanel">
        <div class="alert alert-info d-flex align-items-start mb-6" role="status">
            <i class="bi bi-info-circle fs-2 me-3" aria-hidden="true"></i>
            <div><strong>Contacts importés automatiquement.</strong> Cet import ne déclenche aucun email ; l’éligibilité est contrôlée séparément avant chaque campagne.</div>
        </div>
        @include('backend.contents.prospect_criteria.partials._query-results', [
            'model'       => $model,
            'queryGroups' => $queryGroups ?? [],
            'auditMode'   => $auditMode ?? false,
        ])
        @include('backend.contents.prospect_criteria.partials._results-tab', [
            'model'            => $model,
            'resultCompanies'  => $resultCompanies,
            'resultsSort'      => $resultsSort ?? 'score',
            'resultsDir'       => $resultsDir ?? 'desc',
            'outcomeBreakdown' => $outcomeBreakdown ?? collect(),
        ])
    </div>
    {{-- end Résultats --}}

    @can('run discovery')
        <div class="tab-pane fade" id="criteria_hunter_discover" role="tabpanel">
            @include('backend.contents.prospect_criteria.partials._hunter-discover', ['model' => $model])
            <div class="mt-10">
                @include('backend.contents.prospect_criteria.partials._discover-history', ['model' => $model])
            </div>
        </div>
    @endcan

    {{--
        Général and Automatisation are NOT native panes here — they
        deep-link to the edit page via the tab nav. No pane divs are needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    @include('backend.contents.prospect_criteria.partials._discovery-script')
    @can('run discovery')
        @include('backend.contents.prospect_criteria.partials._hunter-discover-script', ['model' => $model])
    @endcan
@endpush

</x-default-layout>
