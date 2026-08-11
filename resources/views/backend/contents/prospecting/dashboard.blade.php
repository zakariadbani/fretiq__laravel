<x-default-layout>

@section('title', 'Centre de prospection')

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Centre de prospection']]" />
@endsection

<div class="d-flex flex-wrap justify-content-between align-items-center gap-4 mb-7">
    <div>
        <h1 class="fs-2 fw-bold mb-1">Centre de prospection</h1>
        <p class="text-muted mb-0">Ajoutez une liste, confirmez le coût, puis ne traitez que les exceptions.</p>
    </div>
    @can('create prospect_batches')
        <a href="{{ route('admin.prospect_batches.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-2"></i> Importer des entreprises
        </a>
    @endcan
</div>

<div class="row g-5 mb-7">
    @foreach([
        ['Lots actifs', $stats['active'], 'primary', 'bi-hourglass-split'],
        ['À revoir', $stats['review'], 'warning', 'bi-exclamation-diamond'],
        ['Entreprises traitées', $stats['processed'], 'info', 'bi-building-check'],
        ['Entreprises créées', $stats['promoted'], 'success', 'bi-check-circle'],
    ] as [$label, $value, $color, $icon])
        <div class="col-6 col-xl-3">
            <div class="card h-100"><div class="card-body d-flex align-items-center gap-4"><i class="bi {{ $icon }} fs-2x text-{{ $color }}"></i><div><div class="text-muted fs-7">{{ $label }}</div><div class="fs-2 fw-bold">{{ $value }}</div></div></div></div>
        </div>
    @endforeach
</div>

<div class="row g-6">
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header border-0"><h2 class="card-title fw-bold">Lots récents</h2><div class="card-toolbar"><a href="{{ route('admin.prospect_batches.index') }}" class="btn btn-sm btn-light-primary">Voir tous</a></div></div>
            <div class="card-body pt-0">
                @forelse($recent as $batch)
                    <a href="{{ route('admin.prospect_batches.view', $batch) }}" class="d-flex align-items-center justify-content-between py-4 border-bottom text-gray-900 text-hover-primary">
                        <div><div class="fw-semibold">{{ $batch->name }}</div><div class="text-muted fs-8">{{ $batch->total_items }} entreprise(s)</div></div>
                        <span class="badge badge-light-{{ $batch->status === 'completed' ? 'success' : ($batch->status === 'review' ? 'warning' : 'primary') }}">{{ config('global.data.prospect_batch_statuses.'.$batch->status.'.label', ucfirst($batch->status)) }}</span>
                    </a>
                @empty
                    <div class="text-center py-10"><i class="bi bi-inbox fs-3x text-muted"></i><p class="text-muted mt-4">Aucun lot pour le moment.</p><a href="{{ route('admin.prospect_batches.create') }}" class="btn btn-primary">Créer le premier lot</a></div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header border-0"><h2 class="card-title fw-bold">Actions rapides</h2></div>
            <div class="card-body pt-0 d-grid gap-3">
                <a href="{{ route('admin.prospect_batches.create') }}" class="btn btn-light-primary text-start"><i class="bi bi-upload me-2"></i>Importer une liste</a>
                @can('review prospect matches')<a href="{{ route('admin.prospect_review.index') }}" class="btn btn-light-warning text-start"><i class="bi bi-eye me-2"></i>Ouvrir À revoir</a>@endcan
                @can('view prospect_criteria')<a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-light text-start"><i class="bi bi-sliders me-2"></i>Critères Discover IA</a>@endcan
                @can('view provider quota')<a href="{{ route('admin.provider_activity.index') }}" class="btn btn-light text-start"><i class="bi bi-activity me-2"></i>Activité fournisseurs</a>@endcan
            </div>
        </div>
    </div>
</div>

</x-default-layout>
