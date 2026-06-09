<x-default-layout>

@section('title')
    Demande #{{ $model->id }}
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
            <a href="{{ route('admin.demandes.index') }}" class="text-muted text-hover-primary">Demandes</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Demande #{{ $model->id }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6 flex-wrap">
    @can('view demandes')
        <a href="{{ route('admin.demandes.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit demandes')
        <a href="{{ route('admin.demandes.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">

    {{-- Contact & attribution --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-person text-primary fs-3 me-2"></i>
                    Contact &amp; Attribution
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Contact</label>
                    <div class="col-lg-8">
                        @if($model->contact)
                            <a href="{{ route('admin.contacts.view', $model->contact_id) }}" class="fw-bolder text-primary fs-6">
                                {{ e($model->contact->email) }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Entreprise</label>
                    <div class="col-lg-8">
                        @if($model->contact?->company)
                            <span class="fw-semibold">{{ e($model->contact->company->name) }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Campagne</label>
                    <div class="col-lg-8">
                        @if($model->campaign)
                            <a href="{{ route('admin.campaigns.view', $model->campaign_id) }}" class="fw-semibold text-info">
                                {{ e($model->campaign->name) }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Exécution</label>
                    <div class="col-lg-8">
                        @if($model->campaign_run_id)
                            <span class="badge badge-light-secondary">#{{ $model->campaign_run_id }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Séquence</label>
                    <div class="col-lg-8">
                        @if($model->sequence)
                            <a href="{{ route('admin.sequences.view', $model->sequence_id) }}" class="fw-semibold text-info">
                                {{ e($model->sequence->name) }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Demande details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-inbox text-success fs-3 me-2"></i>
                    Détails
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Statut</label>
                    <div class="col-lg-8">
                        @php
                            $statusCfg = config('global.data.demande_statuses.' . $model->status);
                        @endphp
                        @if($statusCfg)
                            <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Type</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->kind ? e($model->kind) : '—' }}</span>
                    </div>
                </div>

                <div class="row mb-5">
                    <label class="col-lg-4 fw-bold text-muted">Capturée le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->captured_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Créée le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Notes --}}
    @if($model->notes)
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-chat-left-text text-warning fs-3 me-2"></i>
                    Notes
                </h3>
            </div>
            <div class="card-body border-top">
                <p class="text-gray-700 mb-0" style="white-space: pre-wrap;">{{ e($model->notes) }}</p>
            </div>
        </div>
    </div>
    @endif

</div>

</x-default-layout>
