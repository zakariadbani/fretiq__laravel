<x-default-layout>

@section('title')
    Importer des séquences CSV
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Séquences', 'route' => 'admin.sequences.index'], ['label' => 'Importer CSV']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des séquences</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.sequences.index') }}" class="btn btn-light">Retour aux séquences</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-shield-check fs-2x text-warning me-4" aria-hidden="true"></i>
            <div>Prévisualisation locale uniquement : aucune écriture n’a lieu tant que vous n’avez pas confirmé. Une séquence existante (même nom, insensible à la casse) conserve son état actif ; ses étapes sont intégralement remplacées par celles du fichier.</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($groups === [])
            <form method="POST" action="{{ route('admin.sequences.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-6">
                    <label for="csv" class="form-label required">Fichier CSV</label>
                    <input id="csv" name="csv" type="file" accept=".csv,.txt,text/csv" class="form-control" required aria-describedby="csv-help">
                    <div id="csv-help" class="form-text">Colonnes : name;step_no;delay_days;template_name;subject. Une ligne = une étape ; plusieurs lignes partageant le même name construisent les étapes de cette séquence. Maximum 100 lignes et 512 Ko.</div>
                </div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                    <a href="{{ route('admin.sequences.import_template') }}" class="btn btn-light-primary">Télécharger le modèle CSV</a>
                </div>
            </form>
        @else
            <h3 class="fs-4 mb-4">Prévisualisation : {{ count($groups) }} séquence(s)</h3>
            <div class="row g-5 mb-6" data-testid="sequences-csv-preview">
                @foreach ($groups as $group)
                    <div class="col-12 col-xl-6" data-testid="sequences-csv-preview-row">
                        <details class="card card-bordered h-100" open>
                            <summary class="card-header border-0 cursor-pointer">
                                <div class="card-title"><span class="fw-bold">{{ $group['name'] }}</span></div>
                                <div class="card-toolbar">
                                    <span class="badge {{ $group['will_update'] ? 'badge-light-warning' : 'badge-light-success' }}">
                                        {{ $group['will_update'] ? 'Sera mise à jour (état actif conservé)' : 'Sera créée inactive' }}
                                    </span>
                                </div>
                            </summary>
                            <div class="card-body pt-4">
                                <table class="table table-sm mb-0" data-field="steps">
                                    <thead>
                                        <tr><th>#</th><th>Délai (j)</th><th>Modèle</th><th>Sujet</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($group['steps'] as $step)
                                            <tr>
                                                <td>{{ $step['step_no'] }}</td>
                                                <td>{{ $step['delay_days'] }}</td>
                                                <td>{{ $step['template_name'] }}</td>
                                                <td>{{ $step['subject'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                @endforeach
            </div>
            <div class="alert alert-info" role="status">Confirmation : les étapes existantes de chaque séquence listée seront intégralement remplacées par celles du fichier.</div>
            <div class="d-flex flex-wrap gap-3">
                @if ($importToken)
                    <form method="POST" action="{{ route('admin.sequences.import_store') }}">
                        @csrf
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import de {{ count($groups) }} séquence(s)</button>
                    </form>
                @endif
                <a href="{{ route('admin.sequences.import_form') }}" class="btn btn-light">Choisir un autre fichier</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
