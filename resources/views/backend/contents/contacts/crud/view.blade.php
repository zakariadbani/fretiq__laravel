<x-default-layout>

@section('title')
    Contact — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Contacts', 'route' => 'admin.contacts.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.contacts.index'])
@endsection

{{--
    Contact view — unified 2-tab UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: contact_apercu (view) / contact_general (cross-link to edit).
    Aperçu is native on the view page; Général deep-links to edit.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.contacts.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="contact_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\ContactViewConfig::make($model, $stats ?? null),
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
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
