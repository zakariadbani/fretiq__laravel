<x-default-layout>

@section('title')
    Critère — {{ e($model->name) }}
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
            <a href="{{ route('admin.prospect_criteria.index') }}" class="text-muted text-hover-primary">Critères de découverte</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view prospect_criteria')
        <a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit prospect_criteria')
        <a href="{{ route('admin.prospect_criteria.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan

    @can('run discovery')
        <button type="button"
                class="btn btn-sm fw-bold btn-light-success"
                onclick="launchDiscovery({{ $model->id }}, '{{ csrf_token() }}')">
            <i class="bi bi-play-fill me-1"></i>
            Lancer la découverte
        </button>
    @endcan
</div>

<div class="row g-5">

    {{-- Criteria details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-gear text-primary fs-3 me-2"></i>
                    Informations générales
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
                    <label class="col-lg-4 fw-bold text-muted">Statut</label>
                    <div class="col-lg-8">
                        @if($model->is_active)
                            <span class="badge badge-light-success">Actif</span>
                        @else
                            <span class="badge badge-light-danger">Inactif</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Limite / jour</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ $model->daily_limit ?? '—' }}</span>
                        <span class="text-muted ms-1 fs-7">contacts / jour</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Entreprises découvertes</label>
                    <div class="col-lg-8">
                        <span class="badge badge-light-primary">{{ $model->companies()->count() }}</span>
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

    {{-- Targeting details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-crosshair text-info fs-3 me-2"></i>
                    Ciblage
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Secteurs</label>
                    <div class="col-lg-8">
                        @if(!empty($model->sectors))
                            @foreach($model->sectors as $sector)
                                <span class="badge badge-light-primary me-1 mb-1">{{ e($sector) }}</span>
                            @endforeach
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Pays</label>
                    <div class="col-lg-8">
                        @if(!empty($model->countries))
                            @foreach($model->countries as $country)
                                <span class="badge badge-light-info me-1 mb-1">{{ e($country) }}</span>
                            @endforeach
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Tailles</label>
                    <div class="col-lg-8">
                        @if(!empty($model->company_sizes))
                            @foreach($model->company_sizes as $size)
                                <span class="badge badge-light-warning me-1 mb-1">{{ e($size) }}</span>
                            @endforeach
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Postes cibles</label>
                    <div class="col-lg-8">
                        @if(!empty($model->target_positions))
                            @foreach($model->target_positions as $position)
                                <span class="badge badge-light-success me-1 mb-1">{{ e($position) }}</span>
                            @endforeach
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
    window.launchDiscovery = function (id, csrfToken) {
        Swal.fire({
            title: 'Lancer la découverte ?',
            text: 'La pipeline de découverte sera exécutée en arrière-plan pour ce critère.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Lancer',
            cancelButtonText: 'Annuler',
            confirmButtonColor: '#009ef7',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            fetch('/admin/prospect_criteria/' + id + '/discover', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.message === 'success') {
                    toastr.success(data.text || 'Découverte lancée en arrière-plan', 'Succès');
                } else {
                    toastr.error('Une erreur est survenue.', 'Erreur');
                }
            })
            .catch(function () {
                toastr.error('Une erreur est survenue.', 'Erreur');
            });
        });
    };
</script>
@endpush

</x-default-layout>
