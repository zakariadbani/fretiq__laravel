<x-default-layout>

@section('title')
    Importer des modèles HTML
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Modèles d\'email', 'route' => 'admin.campaign_templates.index'], ['label' => 'Importer HTML']]" />
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title"><h2 class="fw-bold">Importer des modèles HTML</h2></div>
        <div class="card-toolbar"><a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-light">Retour aux modèles</a></div>
    </div>
    <div class="card-body pt-0">
        <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6 mb-8" role="status">
            <i class="bi bi-shield-check fs-2x text-warning me-4" aria-hidden="true"></i>
            <div>Prévisualisation locale uniquement : aucune écriture n’a lieu tant que vous n’avez pas confirmé. Un modèle existant portant le même nom (insensible à la casse) sera mis à jour, pas dupliqué.</div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($rows === [])
            <form method="POST" action="{{ route('admin.campaign_templates.import_preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-6">
                    <label for="html_files" class="form-label required">Fichiers HTML</label>
                    <input id="html_files" name="html_files[]" type="file" accept=".html,.htm,text/html" class="form-control" required multiple aria-describedby="html-help">
                    <div id="html-help" class="form-text">
                        Chaque fichier doit porter un commentaire d’en-tête du type <code>&lt;!-- Campagne : … / Objet : … --&gt;</code> — Objet fournit le sujet (obligatoire), Campagne fournit le nom (sinon dérivé du nom de fichier).
                        Balises de fusion autorisées : @verbatim{{contact.first_name}}, {{contact.name}}, {{company.name}}, {{company.sector}}, {{unsubscribe_url}}@endverbatim.
                        Maximum {{ \App\Services\Campaign\CampaignTemplateHtmlImporter::MAX_FILES }} fichiers, 512 Ko chacun.
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Prévisualiser l’import</button>
                </div>
            </form>
        @else
            <h3 class="fs-4 mb-4">Prévisualisation : {{ count($rows) }} modèle(s)</h3>
            <div class="row g-5 mb-6" data-testid="campaign-templates-html-preview">
                @foreach ($rows as $row)
                    <div class="col-12 col-xl-6" data-testid="campaign-templates-html-preview-row">
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
                                    <dt class="col-sm-4 text-muted">Fichier</dt><dd class="col-sm-8" data-field="filename">{{ $row['filename'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Sujet</dt><dd class="col-sm-8" data-field="subject">{{ $row['subject'] }}</dd>
                                    <dt class="col-sm-4 text-muted">Taille</dt><dd class="col-sm-8" data-field="size">{{ number_format($row['size'] / 1024, 1) }} Ko</dd>
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
            <div class="alert alert-info" role="status">Confirmation : les modèles listés seront créés ou mis à jour.</div>
            <div class="d-flex flex-wrap gap-3">
                @if ($importToken)
                    <form method="POST" action="{{ route('admin.campaign_templates.import_store') }}">
                        @csrf
                        <input type="hidden" name="import_token" value="{{ $importToken }}">
                        <button type="submit" class="btn btn-primary">Confirmer l’import de {{ count($rows) }} modèle(s)</button>
                    </form>
                @endif
                <a href="{{ route('admin.campaign_templates.import_form') }}" class="btn btn-light">Choisir d’autres fichiers</a>
            </div>
        @endif
    </div>
</div>

</x-default-layout>
