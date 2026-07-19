<x-default-layout>

@section('title')
    Entreprise — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Entreprises', 'route' => 'admin.companies.index'], ['label' => $model->name]]" />
@endsection

{{--
    Company view — unified 5-tab UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: company_apercu / company_general / company_contacts / company_activity / company_enrichment.
    Aperçu is native; Général + Enrichissement deep-link to edit; Contacts + Activité are native toggles.
    enrichment_data rendered with {{ }} only — never {!! !!}.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.companies.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

@if($model->qualification_status === 'rejected')
    <div class="alert alert-warning d-flex align-items-center mb-6" role="alert">
        <i class="bi bi-archive-fill fs-2 me-3"></i>
        <div>Cette entreprise est archivée et exclue de la prospection active.</div>
    </div>
@endif

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="company_apercu" role="tabpanel">
        @include('backend.contents.companies.partials._score-recap', ['model' => $model])
        @include('backend.contents.companies.partials._enrichment-recap', ['model' => $model])
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\CompanyViewConfig::make($model, $stats ?? null),
        ])
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 3: Contacts (native toggle on both pages) ────────────────── --}}
    <div class="tab-pane fade" id="company_contacts" role="tabpanel">
        @include('backend.contents.companies.partials._contacts-tab', ['model' => $model])
    </div>
    {{-- end Contacts --}}

    {{-- ── Tab 4: Activité (native toggle on both pages) ────────────────── --}}
    <div class="tab-pane fade" id="company_activity" role="tabpanel">
        @include('backend.contents.companies.partials._activity-tab', ['model' => $model])
    </div>
    {{-- end Activité --}}

    {{--
        Tabs 2 (Général) and 5 (Enrichissement) are NOT native panes here —
        they deep-link to the edit page via the tab nav. No pane divs needed.
    --}}

</div>
{{-- end tab-content --}}

{{-- Contact modal (for inline create / edit from the Contacts tab) --}}
@include('backend.contents.companies.partials._contact-modal', ['model' => $model])

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
