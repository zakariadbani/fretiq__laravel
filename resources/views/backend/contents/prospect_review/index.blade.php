<x-default-layout>

@section('title', 'À revoir')

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Prospection'], ['label' => 'À revoir']]" />
@endsection

<div class="alert alert-light-primary d-flex align-items-center mb-6">
    <i class="bi bi-shield-check fs-2 me-3"></i>
    <div><strong>Seulement les exceptions.</strong> Les correspondances fiables continuent automatiquement.</div>
</div>

<div class="card mb-7">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h2 class="fw-bold mb-0">Correspondances à revoir</h2>
        </div>
        <div class="card-toolbar">
            <div class="d-flex align-items-center position-relative">
                {!! getIcon('magnifier', 'fs-3 position-absolute ms-5') !!}
                <input type="text" data-kt-table-filter="search" id="mySearchInput" class="form-control form-control-solid w-250px ps-13" placeholder="Rechercher">
            </div>
        </div>
    </div>
    <div class="card-body py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header border-0"><h2 class="card-title fw-bold">Contacts à décider</h2></div>
    <div class="card-body pt-0">
        @forelse($candidates as $candidate)
            <article class="border rounded p-4 mb-4">
                <div class="row align-items-center g-4">
                    <div class="col-12 col-lg-5">
                        <div class="fw-bold">{{ $candidate->email }}</div>
                        <div class="text-muted fs-7">{{ $candidate->item?->company_name ?: 'Entreprise non résolue' }}</div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <span class="badge badge-light-{{ in_array($candidate->verification_status, ['invalid', 'disposable']) ? 'danger' : 'warning' }}">
                            {{ $candidate->verification_status ?: 'non vérifié' }}
                        </span>
                    </div>
                    <div class="col-6 col-lg-2 text-muted">{{ $candidate->email_kind ?: 'type inconnu' }}</div>
                    <div class="col-12 col-lg-3">
                        <div class="d-flex flex-wrap justify-content-lg-end gap-2">
                            <form method="POST" action="{{ route('admin.prospect_review.candidates.decide', $candidate) }}">
                                @csrf<input type="hidden" name="action" value="approve">
                                <button class="btn btn-sm btn-primary">Approuver</button>
                            </form>
                            <form method="POST" action="{{ route('admin.prospect_review.candidates.decide', $candidate) }}">
                                @csrf<input type="hidden" name="action" value="reject">
                                <button class="btn btn-sm btn-light-danger">Ignorer</button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        @empty
            <div class="text-center py-10">
                <i class="bi bi-check-circle fs-3x text-success"></i>
                <p class="text-muted mt-4">Aucun contact en attente de décision.</p>
            </div>
        @endforelse
        {{ $candidates->links() }}
    </div>
</div>

<x-crud.datatable-init :data-table="$dataTable" :data-table-config="$dataTableConfig" />

</x-default-layout>
