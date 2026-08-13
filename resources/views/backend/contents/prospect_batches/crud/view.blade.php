<x-default-layout>

@section('title', $model->name)

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[
        ['label' => 'Lots', 'route' => 'admin.prospect_batches.index'],
        ['label' => $model->name],
    ]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.prospect_batches.index'])
@endsection

@include('backend.partials.crud._tabbar', [
    'model' => $model,
    'currentPage' => 'view',
    'config' => $viewConfig,
    'actions' => '',
])

<div class="tab-content">
    <div class="tab-pane fade show active" id="prospect_batch_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', ['model' => $model, 'config' => $viewConfig])

        @if($model->status === 'review')
            <div class="alert alert-warning d-flex align-items-center mt-6">
                <i class="bi bi-exclamation-triangle fs-2 me-3"></i>
                <div class="flex-grow-1">Certaines entreprises ou certains domaines demandent votre choix.</div>
                @if(Route::has('admin.prospect_review.index'))
                    <a href="{{ route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $model->id]) }}" class="btn btn-sm btn-warning">Ouvrir À revoir</a>
                @endif
            </div>
        @elseif($model->status === 'failed')
            <div class="alert alert-danger mt-6">Le traitement n’a pas pu se terminer. Aucun détail fournisseur sensible n’est affiché ici.</div>
        @endif

        <div class="card mt-6">
            <div class="card-header border-0"><h3 class="card-title">Entreprises du lot</h3></div>
            <div class="card-body pt-0">
                @if($items->isEmpty())
                    <div class="text-center py-10">
                        <i class="bi bi-building-slash fs-3x text-muted"></i>
                        <p class="text-muted mt-4 mb-0">Aucune entreprise dans ce lot.</p>
                    </div>
                @else
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-row-dashed align-middle">
                            <thead><tr><th>Entreprise</th><th>Domaine</th><th>Statut</th><th>Contacts importés</th><th>Motif</th></tr></thead>
                            <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td><div class="fw-semibold">{{ $item->company_name }}</div><div class="text-muted fs-8">{{ collect([$item->city, $item->country])->filter()->join(', ') }}</div></td>
                                    <td>{{ $item->selected_domain ?: $item->provided_domain ?: '—' }}</td>
                                    <td><span class="badge badge-light-{{ in_array($item->status, ['ready', 'promoted']) ? 'success' : ($item->status === 'review' ? 'warning' : ($item->status === 'failed' ? 'danger' : 'primary')) }}">{{ ucfirst($item->status) }}</span></td>
                                    <td>{{ $item->imported_contacts_count }}</td>
                                    <td>{{ $item->domain_reason ?: $item->error_code ?: '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-md-none vstack gap-3">
                        @foreach($items as $item)
                            <article class="card border">
                                <div class="card-body py-4">
                                    <div class="d-flex justify-content-between gap-3"><strong>{{ $item->company_name }}</strong><span class="badge badge-light-primary">{{ ucfirst($item->status) }}</span></div>
                                    <div class="text-muted fs-7 mt-2">{{ $item->selected_domain ?: $item->provided_domain ?: 'Domaine à revoir' }}</div>
                                    <div class="fs-8 mt-2">{{ $item->imported_contacts_count }} contact(s) importé(s)</div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <p class="text-muted fs-8 mt-4 mb-3">Importer un contact ne déclenche aucun email. Les règles d’éligibilité restent appliquées au moment de préparer un envoi.</p>
                    <div class="mt-5">{{ $items->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if(in_array($model->status, ['queued', 'running'], true))
    <div id="prospect-batch-live-status" data-status-url="{{ route('admin.prospect_batches.status', $model) }}" aria-live="polite"></div>
@endif

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
