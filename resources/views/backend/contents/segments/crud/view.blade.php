<x-default-layout>

@section('title')
    Segment — {{ e($model->name) }}
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
            <a href="{{ route('admin.segments.index') }}" class="text-muted text-hover-primary">Segments</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{--
    Segment view — shared hero + 2-tab UX.
    Tab pane IDs: segment_apercu / segment_general.
    Aperçu is native on view; Général deep-links to edit.
    The domain-specific filter JSON card is preserved below _apercu.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.segments.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="segment_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\SegmentViewConfig::make($model, $stats ?? null),
        ])

        {{-- Domain-specific: Filtre (JSON) — preserved below the generic apercu --}}
        <div class="row g-5 mt-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-braces text-info fs-3 me-2"></i>
                            Filtre (JSON)
                        </h3>
                    </div>
                    <div class="card-body border-top">
                        @if($model->filter)
                            <pre class="bg-light rounded p-4 mb-0 fs-7 text-gray-700" style="white-space: pre-wrap; word-break: break-all;">{{ json_encode($model->filter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @else
                            <div class="text-center py-8 text-muted">
                                <i class="bi bi-braces fs-2x mb-3 d-block"></i>
                                Aucun filtre défini pour ce segment.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        {{-- end Filter JSON card --}}
    </div>
    {{-- end Aperçu --}}

    {{--
        Général tab is NOT a native pane here — it deep-links to the edit page.
        No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
