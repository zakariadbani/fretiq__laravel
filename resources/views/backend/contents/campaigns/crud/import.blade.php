<x-default-layout>

@section('title')
    Importer des campagnes CSV
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Campagnes', 'route' => 'admin.campaigns.index'], ['label' => 'Importer CSV']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des campagnes</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.campaigns.index') }}" class="btn btn-light">Retour aux campagnes</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-shield-check fs-2x text-warning me-4" aria-hidden="true"></i>
            <div>Prévisualisation locale uniquement : aucun envoi, aucune file d’attente. Les campagnes importées resteront toujours inactives, même si le fichier indique le contraire.</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($rows === [])
            <form method="POST" action="{{ route('admin.campaigns.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-6">
                    <label for="csv" class="form-label required">Fichier CSV</label>
                    <input id="csv" name="csv" type="file" accept=".csv,.txt,text/csv" class="form-control" required aria-describedby="csv-help">
                    <div id="csv-help" class="form-text">
                        Colonnes : name;segment_name;sequence_name;template_name;schedule_type;delivery_channel;email_verification_policy;smtp_daily_email_limit;is_active;notes.
                        segment_name obligatoire ; sequence_name obligatoire si schedule_type=sequence ; template_name obligatoire sinon.
                        delivery_channel doit être explicite (smtp, zoho ou mailjet — mailjet ne prend pas en charge schedule_type=sequence). L’expéditeur est déduit automatiquement (une seule identité doit exister). Maximum 100 lignes et 512 Ko.
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                    <a href="{{ route('admin.campaigns.import_template') }}" class="btn btn-light-primary">Télécharger le modèle CSV</a>
                </div>
            </form>
        @else
            <h3 class="fs-4 mb-4">Prévisualisation : {{ count($rows) }} campagne(s)</h3>
            <div class="row g-5 mb-6" data-testid="campaigns-csv-preview">
                @foreach ($rows as $row)
                    <div class="col-12 col-xl-6" data-testid="campaigns-csv-preview-row">
                        <details class="card card-bordered h-100" open>
                            <summary class="card-header border-0 cursor-pointer">
                                <div class="card-title"><span class="fw-bold">{{ $row['name'] }}</span></div>
                                <div class="card-toolbar d-flex gap-2">
                                    <span class="badge {{ $row['will_update'] ? 'badge-light-warning' : 'badge-light-success' }}">
                                        {{ $row['will_update'] ? 'Sera mise à jour' : 'Sera créée' }}
                                    </span>
                                    <span class="badge badge-light-secondary">Inactive</span>
                                </div>
                            </summary>
                            <div class="card-body pt-4">
                                <dl class="row mb-0">
                                    <dt class="col-sm-4 text-muted">Segment</dt><dd class="col-sm-8" data-field="segment_name">{{ $row['segment_name'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Séquence</dt><dd class="col-sm-8" data-field="sequence_name">{{ $row['sequence_name'] ?? '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Modèle</dt><dd class="col-sm-8" data-field="template_name">{{ $row['template_name'] ?? '—' }}</dd>
                                    <dt class="col-sm-4 text-muted">Type de planification</dt><dd class="col-sm-8" data-field="schedule_type">{{ config('global.data.schedule_types.'.$row['schedule_type'].'.label', $row['schedule_type']) }}</dd>
                                    <dt class="col-sm-4 text-muted">Canal</dt><dd class="col-sm-8" data-field="delivery_channel">{{ strtoupper($row['delivery_channel']) }}</dd>
                                    <dt class="col-sm-4 text-muted">Vérification email</dt><dd class="col-sm-8" data-field="email_verification_policy">{{ $row['email_verification_policy'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Limite SMTP/jour</dt><dd class="col-sm-8" data-field="smtp_daily_email_limit">{{ $row['smtp_daily_email_limit'] ?? '— (défaut)' }}</dd>
                                    <dt class="col-sm-4 text-muted">Notes</dt><dd class="col-sm-8" data-field="notes">{{ $row['notes'] ?? '—' }}</dd>
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
            <div class="alert alert-info" role="status">Confirmation : toutes les campagnes seront créées ou mises à jour inactives.</div>
            <div class="d-flex flex-wrap gap-3">
                @if ($importToken)
                    <form method="POST" action="{{ route('admin.campaigns.import_store') }}">
                        @csrf
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import de {{ count($rows) }} campagne(s)</button>
                    </form>
                @endif
                <a href="{{ route('admin.campaigns.import_form') }}" class="btn btn-light">Choisir un autre fichier</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
