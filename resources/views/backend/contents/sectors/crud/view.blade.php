<x-default-layout>

@section('title')
    Secteur — {{ $model->label }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Secteurs', 'route' => 'admin.sectors.index'], ['label' => $model->label]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sectors.index'])
@endsection

{{-- Lean lookup-table view page (contract §10) — plain read-only card, no hero/tabs. --}}

<div class="row g-5">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-tags text-primary fs-3 me-2"></i>
                    Détails du secteur
                </h3>
                <div class="card-toolbar">
                    @can('edit sectors')
                        <a href="{{ route('admin.sectors.edit', $model->id) }}" class="btn btn-sm btn-light-primary">
                            <i class="bi bi-pencil me-1"></i>
                            Modifier
                        </a>
                    @endcan
                </div>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Libellé</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ $model->label }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Statut</label>
                    <div class="col-lg-8">
                        @if($model->is_active)
                            <span class="badge badge-light-success">Actif</span>
                        @else
                            <span class="badge badge-light-secondary">Inactif</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Découverte</label>
                    <div class="col-lg-8">
                        @if($model->use_in_discovery)
                            <span class="badge badge-light-success">Utilisé en découverte</span>
                        @else
                            <span class="badge badge-light-secondary">Non utilisé</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Ordre d'affichage</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->sort_order }}</span>
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
</div>

</x-default-layout>
