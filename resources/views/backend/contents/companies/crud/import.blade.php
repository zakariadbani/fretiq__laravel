<x-default-layout>

@section('title')
    Importer des entreprises et contacts CSV
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Entreprises', 'route' => 'admin.companies.index'], ['label' => 'Importer CSV']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des entreprises et contacts</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.companies.index') }}" class="btn btn-light">Retour aux entreprises</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-info rounded border-info border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-info-circle fs-2x text-info me-4" aria-hidden="true"></i>
            <div>
                Import CSV des entreprises et/ou des contacts. Les entreprises déjà présentes (même domaine) sont
                complétées uniquement sur leurs champs vides — jamais écrasées. Les contacts sont rattachés à leur
                entreprise via le domaine du site. Ré-importer le même fichier ne crée pas de doublons.
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if (!empty($parseErrors))
            <div class="alert alert-danger" role="alert" data-field="parse-errors">
                <ul class="mb-0">
                    @foreach ($parseErrors as $error)
                        <li>
                            {{ $error['label'] }} ({{ $error['count'] }})
                            @if (!empty($error['rows']))
                                — ligne(s) {{ implode(', ', $error['rows']) }}@if ($error['more'] > 0), +{{ $error['more'] }} autre(s)@endif
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($summary === null)
            <form method="POST" action="{{ route('admin.companies.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-6 mb-6">
                    <div class="col-md-6">
                        <label for="companies_csv" class="form-label">Fichier sociétés</label>
                        <input id="companies_csv" name="companies_csv" type="file" accept=".csv,.txt,text/csv" class="form-control" aria-describedby="companies-csv-help">
                        <div id="companies-csv-help" class="form-text">Colonnes attendues : société, domaine, secteur, description, pays, effectif. Maximum 10 000 lignes et 10 Mo.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="contacts_csv" class="form-label">Fichier contacts</label>
                        <input id="contacts_csv" name="contacts_csv" type="file" accept=".csv,.txt,text/csv" class="form-control" aria-describedby="contacts-csv-help">
                        <div id="contacts-csv-help" class="form-text">Colonnes attendues : prénom, nom, email, poste, téléphone, société, domaine (site), secteur, effectif, pays. Maximum 10 000 lignes et 10 Mo.</div>
                    </div>
                </div>
                <div class="alert alert-light-warning" role="status">Au moins un des deux fichiers est requis.</div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                </div>
            </form>
        @else
            @php
                $c = $summary['companies'];
                $l = $summary['leads'];
            @endphp

            <h3 class="fs-4 mb-4">Prévisualisation</h3>

            <div class="row g-5 mb-6" data-testid="companies-csv-preview">
                <div class="col-12 col-lg-6">
                    <div class="card card-bordered h-100">
                        <div class="card-header border-0 pt-4"><div class="card-title fw-bold">Sociétés</div></div>
                        <div class="card-body pt-0">
                            <dl class="row mb-0">
                                <dt class="col-sm-8 text-muted">À créer</dt><dd class="col-sm-4 text-end fw-bold" data-field="companies-create">{{ $c['create'] }}</dd>
                                <dt class="col-sm-8 text-muted">À compléter (fusion)</dt><dd class="col-sm-4 text-end fw-bold" data-field="companies-merge">{{ $c['merge'] }}</dd>
                                <dt class="col-sm-8 text-muted">Ignorées (domaine ambigu)</dt><dd class="col-sm-4 text-end fw-bold" data-field="companies-conflict">{{ $c['registrable_domain_conflict'] }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card card-bordered h-100">
                        <div class="card-header border-0 pt-4"><div class="card-title fw-bold">Contacts</div></div>
                        <div class="card-body pt-0">
                            <dl class="row mb-0">
                                <dt class="col-sm-8 text-muted">Total lignes</dt><dd class="col-sm-4 text-end fw-bold" data-field="contacts-total">{{ $l['contacts_total'] }}</dd>
                                <dt class="col-sm-8 text-muted">Domaines détectés</dt><dd class="col-sm-4 text-end" data-field="contacts-domains">{{ $l['domains_total'] }}</dd>
                                <dt class="col-sm-8 text-muted">Sociétés créées automatiquement</dt><dd class="col-sm-4 text-end" data-field="contacts-stub">{{ $l['will_create_stub_companies'] }}</dd>
                                <dt class="col-sm-8 text-muted">Domaines rattachés à une société existante</dt><dd class="col-sm-4 text-end" data-field="contacts-existing">{{ $l['will_attach_existing'] }}</dd>
                                <dt class="col-sm-8 text-muted">Adresses vérifiées</dt><dd class="col-sm-4 text-end text-success" data-field="contacts-valid">{{ $l['contacts_valid'] }}</dd>
                                <dt class="col-sm-8 text-muted">Adresses accept-all</dt><dd class="col-sm-4 text-end text-warning" data-field="contacts-accept-all">{{ $l['contacts_accept_all'] }}</dd>
                                <dt class="col-sm-8 text-muted">En attente de vérification</dt><dd class="col-sm-4 text-end" data-field="contacts-pending">{{ $l['contacts_pending_verification'] }}</dd>
                                <dt class="col-sm-8 text-muted">Ignorés (adresse invalide)</dt><dd class="col-sm-4 text-end text-danger" data-field="contacts-invalid">{{ $l['contacts_skipped_invalid_verification'] }}</dd>
                                <dt class="col-sm-8 text-muted">Ignorés (domaine ambigu)</dt><dd class="col-sm-4 text-end text-danger" data-field="contacts-conflict">{{ $l['contacts_skipped_registrable_domain_conflict'] }}</dd>
                                <dt class="col-sm-8 text-muted">Autre statut de vérification</dt><dd class="col-sm-4 text-end" data-field="contacts-other-status">{{ $l['contacts_other_status'] }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            @if (!empty($summary['unmapped_industries']))
                <div class="alert alert-light-warning" role="status" data-field="unmapped-industries">
                    <div class="fw-bold mb-2">Secteurs non reconnus dans la taxonomie ({{ count($summary['unmapped_industries']) }})</div>
                    <div class="text-muted">{{ implode(', ', $summary['unmapped_industries']) }}</div>
                </div>
            @endif

            <div class="alert alert-info" role="status">
                Confirmation : les entreprises et contacts ci-dessus seront créés ou complétés (jamais écrasés ni supprimés).
            </div>
            <div class="d-flex flex-wrap gap-3">
                @if ($uuid && (($c['create'] ?? 0) + ($c['merge'] ?? 0) + ($l['contacts_total'] ?? 0)) > 0)
                    <form method="POST" action="{{ route('admin.companies.import_store') }}" onsubmit="this.querySelector('button').disabled = true;">
                        @csrf
                        <input type="hidden" name="import_uuid" value="{{ $uuid }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import</button>
                    </form>
                @endif
                <a href="{{ route('admin.companies.import_form') }}" class="btn btn-light">Choisir d’autres fichiers</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
