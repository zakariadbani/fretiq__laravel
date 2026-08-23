<x-default-layout>

@section('title')
    Importer des segments CSV
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Segments', 'route' => 'admin.segments.index'], ['label' => 'Importer CSV']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des segments</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.segments.index') }}" class="btn btn-light">Retour aux segments</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-shield-check fs-2x text-warning me-4" aria-hidden="true"></i>
            <div>Prévisualisation locale uniquement : aucune écriture n’a lieu tant que vous n’avez pas confirmé. Un segment existant portant le même nom (insensible à la casse) sera mis à jour, pas dupliqué.</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($rows === [])
            <form method="POST" action="{{ route('admin.segments.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-6">
                    <label for="csv" class="form-label required">Fichier CSV</label>
                    <input id="csv" name="csv" type="file" accept=".csv,.txt,text/csv" class="form-control" required aria-describedby="csv-help">
                    <div id="csv-help" class="form-text">Colonnes : name;scope;filter_json;notes. scope ∈ prospect, client, mixed. filter_json est optionnel (JSON valide ou vide). Maximum 100 lignes et 512 Ko.</div>
                </div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                    <a href="{{ route('admin.segments.import_template') }}" class="btn btn-light-primary">Télécharger le modèle CSV</a>
                </div>
            </form>
        @else
            <h3 class="fs-4 mb-4">Prévisualisation : {{ count($rows) }} segment(s)</h3>
            <div class="row g-5 mb-6" data-testid="segments-csv-preview">
                @foreach ($rows as $row)
                    <div class="col-12 col-xl-6" data-testid="segments-csv-preview-row">
                        <details class="card card-bordered h-100" open>
                            <summary class="card-header border-0 cursor-pointer">
                                <div class="card-title"><span class="fw-bold">{{ $row['name'] }}</span></div>
                                <div class="card-toolbar">
                                    <span class="badge {{ $row['will_update'] ? 'badge-light-warning' : 'badge-light-success' }}">
                                        {{ $row['will_update'] ? 'Sera mis à jour' : 'Sera créé' }}
                                    </span>
                                </div>
                            </summary>
                            <div class="card-body pt-4">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4 text-muted">Scope</dt><dd class="col-sm-8" data-field="scope">{{ config('global.data.segment_scopes.'.$row['scope'].'.label', $row['scope']) }}</dd>
                                    <dt class="col-sm-4 text-muted">Filtre</dt><dd class="col-sm-8" data-field="filter">{{ $row['filter'] ? json_encode($row['filter'], JSON_UNESCAPED_UNICODE) : '— (toute l’audience du scope)' }}</dd>
                                    <dt class="col-sm-4 text-muted">Notes</dt><dd class="col-sm-8" data-field="notes">{{ $row['notes'] ?? '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Mode</dt><dd class="col-sm-8" data-field="is_manual">Dynamique (is_manual = 0)</dd>
                                </dl>
                                @if ($row['warnings'])
                                    <div class="alert alert-warning mt-4 mb-0 py-3" role="status" data-field="warnings">
                                        <ul class="mb-0">@foreach ($row['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                                    </div>
                                @endif
                            </div>
                        </details>
                    </div>
                @endforeach
            </div>
            <div class="alert alert-info" role="status">Confirmation : les segments listés seront créés ou mis à jour, tous en mode dynamique (is_manual = 0).</div>
            <div class="d-flex flex-wrap gap-3">
                @if ($importToken)
                    <form method="POST" action="{{ route('admin.segments.import_store') }}">
                        @csrf
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import de {{ count($rows) }} segment(s)</button>
                    </form>
                @endif
                <a href="{{ route('admin.segments.import_form') }}" class="btn btn-light">Choisir un autre fichier</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
