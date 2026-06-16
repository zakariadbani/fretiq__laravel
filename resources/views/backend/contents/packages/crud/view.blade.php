<x-default-layout>

@section('title')
    Pack — {{ $model->name }}
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
            <a href="{{ route('admin.packages.index') }}" class="text-muted text-hover-primary">Packs</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ $model->name }}</li>
    </ul>
@endsection

{{-- Shared hero + tab nav --}}
@include('backend.contents.packages.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Aperçu (default active on view page) ──────────────────────────── --}}
    <div class="tab-pane fade show active" id="package_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\PackageViewConfig::make($model, $stats ?? null),
        ])

        {{-- Quick-action: assign this pack (inline POST form) --}}
        @can('manage packages')
        <div class="card mt-6">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold fs-3 mb-1">Assigner ce pack</span>
                    <span class="text-muted fw-semibold fs-7">Rend ce pack actif pour la découverte.</span>
                </h3>
            </div>
            <div class="card-body pt-3 pb-6">
                <form method="POST" action="{{ route('admin.packages.assign') }}" class="d-flex align-items-center gap-3">
                    @csrf
                    <input type="hidden" name="package_id" value="{{ $model->id }}" />
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-square me-1"></i>
                        Assigner ce pack
                    </button>
                    <span class="text-muted fs-7">
                        @if($model->daily_credits === null)
                            Pack illimité — aucune limite de découverte.
                        @else
                            {{ $model->daily_credits }} × 30 = {{ $model->daily_credits * 30 }} crédits de découverte max / mois
                        @endif
                    </span>
                </form>
            </div>
        </div>
        @endcan
    </div>
    {{-- end Aperçu --}}

    {{--
        General and Enrichissement tabs are NOT native panes here —
        they deep-link to the edit page via the tab nav.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
