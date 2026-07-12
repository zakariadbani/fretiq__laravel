<x-default-layout>

@section('title')
    Utilisateur — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Utilisateurs', 'route' => 'admin.users.index'], ['label' => $model->name]]" />
@endsection

{{--
    User view — hero + tabbar + aperçu contract.
    Tab pane IDs: user_apercu / user_general.
    Aperçu is native (default active on view); Général deep-links to edit.
    Existing role/permission display blocks are preserved inside the aperçu pane.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.users.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="user_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\UserViewConfig::make($model),
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
@endpush

</x-default-layout>
