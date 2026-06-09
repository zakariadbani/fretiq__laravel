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

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view segments')
        <a href="{{ route('admin.segments.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit segments')
        <a href="{{ route('admin.segments.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">

    {{-- Segment details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-funnel text-primary fs-3 me-2"></i>
                    Informations du segment
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Nom</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ e($model->name) }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Portée</label>
                    <div class="col-lg-8">
                        @php
                            $scope = config('global.data.segment_scopes.' . $model->scope);
                        @endphp
                        @if($scope)
                            <span class="badge badge-light-{{ $scope['color'] }}">{{ $scope['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">≈ Contacts</label>
                    <div class="col-lg-8">
                        <span class="badge badge-light-primary">{{ $model->contactsCount() }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Dernière construction</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->last_built_at ? $model->last_built_at->format('d/m/Y H:i') : '—' }}</span>
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Créé le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Filter (JSON) --}}
    <div class="col-lg-6">
        <div class="card h-100">
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

</x-default-layout>
