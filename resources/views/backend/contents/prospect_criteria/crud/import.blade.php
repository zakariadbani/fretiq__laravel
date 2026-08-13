<x-default-layout>

@section('title')
    Importer des critères CSV
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Critères de découverte', 'route' => 'admin.prospect_criteria.index'], ['label' => 'Importer CSV']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des critères</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-light">Retour aux critères</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-shield-check fs-2x text-warning me-4" aria-hidden="true"></i>
            <div>Prévisualisation locale uniquement : aucun fournisseur, aucune file d’attente et aucune automatisation ne sont déclenchés. Les critères importés resteront inactifs, sans exécution ni enrichissement automatique.</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($rows === [])
            <form method="POST" action="{{ route('admin.prospect_criteria.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-6">
                    <label for="csv" class="form-label required">Fichier CSV</label>
                    <input id="csv" name="csv" type="file" accept=".csv,.txt,text/csv" class="form-control" required aria-describedby="csv-help">
                    <div id="csv-help" class="form-text">Maximum 100 lignes et 512 Ko. Les colonnes peuvent être réordonnées ; utilisez « | » pour plusieurs valeurs.</div>
                </div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                    <a href="{{ route('admin.prospect_criteria.import_template') }}" class="btn btn-light-primary">Télécharger le modèle CSV</a>
                </div>
            </form>
        @else
            <h3 class="fs-4 mb-4">Prévisualisation : {{ count($rows) }} critère(s)</h3>
            @if ($existingNames !== [])
                <div class="alert alert-danger" role="alert">Import bloqué : ces noms existent déjà : {{ implode(', ', $existingNames) }}.</div>
            @endif
            <div class="row g-5 mb-6" data-testid="criteria-csv-preview">
                @foreach ($rows as $row)
                    <div class="col-12 col-xl-6" data-testid="criteria-csv-preview-row">
                        <details class="card card-bordered h-100" open>
                            <summary class="card-header border-0 cursor-pointer">
                                <div class="card-title"><span class="fw-bold">{{ $row['name'] }}</span></div>
                                <div class="card-toolbar"><span class="badge badge-light-secondary">Inactif · Automatisations désactivées</span></div>
                            </summary>
                            <div class="card-body pt-4">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4 text-muted">Cible IA</dt><dd class="col-sm-8" data-field="ai_target">{{ $row['ai_target'] ?? '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Exclusions IA</dt><dd class="col-sm-8" data-field="ai_exclude">{{ $row['ai_exclude'] ?? '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Secteurs</dt><dd class="col-sm-8" data-field="sectors">{{ implode(' | ', $row['sectors']) ?: '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Pays</dt><dd class="col-sm-8" data-field="countries">{{ implode(' | ', $row['countries']) ?: '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Tailles</dt><dd class="col-sm-8" data-field="company_sizes">{{ implode(' | ', $row['company_sizes']) ?: '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Postes ciblés</dt><dd class="col-sm-8" data-field="target_positions">{{ implode(' | ', $row['target_positions']) ?: '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Limite quotidienne</dt><dd class="col-sm-8" data-field="daily_limit">{{ $row['daily_limit'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Limite contacts</dt><dd class="col-sm-8" data-field="contact_limit">{{ $row['contact_limit'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Score minimum</dt><dd class="col-sm-8" data-field="min_score_enrich">{{ $row['min_score_enrich'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Automatisation</dt><dd class="col-sm-8" data-field="safe_flags">Inactif · auto_run désactivé · auto_enrich désactivé</dd>
                                </dl>
                            </div>
                        </details>
                    </div>
                @endforeach
            </div>
            <div class="alert alert-info" role="status">Confirmation : tous les critères seront créés inactifs, avec auto_run et auto_enrich désactivés.</div>
            <div class="d-flex flex-wrap gap-3">
                @if ($importToken)
                    <form method="POST" action="{{ route('admin.prospect_criteria.import_store') }}">
                        @csrf
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import de {{ count($rows) }} critère(s)</button>
                    </form>
                @endif
                <a href="{{ route('admin.prospect_criteria.import_form') }}" class="btn btn-light">Choisir un autre fichier</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
