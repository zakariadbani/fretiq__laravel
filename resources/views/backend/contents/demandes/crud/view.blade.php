<x-default-layout>

@section('title')
    Demande — {{ $model->contact ? e($model->contact->name) : '#' . $model->id }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Demandes', 'route' => 'admin.demandes.index'], ['label' => $model->contact ? e($model->contact->name) : '#' . $model->id]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.demandes.index'])
@endsection

{{--
    Demande view — unified 2-tab UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: demande_apercu (view) / demande_general (cross-link to edit).
    Aperçu is native on the view page; Général deep-links to edit.

    Source-chain attribution (campagne / séquence) is the key domain info —
    visible in tiles, subtitle, detail_rows, and quick_actions.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.demandes.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="demande_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\DemandeViewConfig::make($model, $stats ?? null),
        ])

        {{-- Domain block: Notes — preserved below the generic apercu, only when set --}}
        @if($model->notes)
        <div class="row g-6 g-xl-9 mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-chat-left-text text-warning fs-3 me-2"></i>
                            Notes
                        </h3>
                    </div>
                    <div class="card-body border-top">
                        <p class="text-gray-700 mb-0" style="white-space: pre-wrap;">{{ $model->notes }}</p>
                    </div>
                </div>
            </div>
        </div>
        @endif
        {{-- end notes block --}}

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
