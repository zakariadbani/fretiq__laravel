<x-default-layout>

@section('title')
    Suppression — {{ e($model->email) }}
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
            <a href="{{ route('admin.suppressions.index') }}" class="text-muted text-hover-primary">Suppressions</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->email) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view suppressions')
        <a href="{{ route('admin.suppressions.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit suppressions')
        <a href="{{ route('admin.suppressions.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-slash-circle text-danger fs-3 me-2"></i>
                    Détails de la suppression
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Email</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ e($model->email) }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Motif</label>
                    <div class="col-lg-8">
                        @php
                            $reason = config('global.data.suppression_reasons.' . $model->reason);
                        @endphp
                        @if($reason)
                            <span class="badge badge-light-{{ $reason['color'] }}">{{ $reason['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Source</label>
                    <div class="col-lg-8">
                        @php
                            $source = config('global.data.suppression_sources.' . $model->source);
                        @endphp
                        @if($source)
                            <span class="badge badge-light-{{ $source['color'] }}">{{ $source['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Contact lié</label>
                    <div class="col-lg-8">
                        @if($model->contact)
                            <a href="{{ route('admin.contacts.view', $model->contact->id) }}" class="fw-semibold text-primary">
                                {{ e($model->contact->email) }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Supprimé le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

</x-default-layout>
