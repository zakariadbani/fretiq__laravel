<x-default-layout>

@section('title')
    Modèle — {{ e($model->name) }}
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.campaign_templates.index') }}" class="text-muted text-hover-primary">Modèles d'email</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view campaign_templates')
        <a href="{{ route('admin.campaign_templates.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit campaign_templates')
        <a href="{{ route('admin.campaign_templates.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">

    {{-- Template details --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-envelope text-primary fs-3 me-2"></i>
                    Informations
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Nom</label>
                    <div class="col-lg-7">
                        <span class="fw-bolder fs-6 text-gray-900">{{ e($model->name) }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Sujet</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold">{{ e($model->subject) }}</span>
                    </div>
                </div>

                @if($model->preview_text)
                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Prévisualisation</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold text-muted fs-7">{{ e($model->preview_text) }}</span>
                    </div>
                </div>
                @endif

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Campagnes</label>
                    <div class="col-lg-7">
                        <span class="badge badge-light-primary">{{ $model->campaigns->count() }}</span>
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-5 fw-bold text-muted">Créé le</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>

        {{-- Variables hint card --}}
        <div class="card mt-5">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-braces text-warning fs-3 me-2"></i>
                    Variables disponibles
                </h3>
            </div>
            <div class="card-body border-top">
                <div class="d-flex flex-column gap-3">
                    <div>
                        <code class="text-primary">{{"{{"}}contact.name{{"}}"}}</code>
                        <div class="text-muted fs-8 mt-1">Prénom du contact destinataire</div>
                    </div>
                    <div>
                        <code class="text-primary">{{"{{"}}company.name{{"}}"}}</code>
                        <div class="text-muted fs-8 mt-1">Nom de la société du contact</div>
                    </div>
                    <div>
                        <code class="text-warning">{{"{{"}}unsubscribe_url{{"}}"}}</code>
                        <div class="text-muted fs-8 mt-1">URL de désabonnement (obligatoire)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- HTML preview --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-eye text-success fs-3 me-2"></i>
                    Aperçu du contenu
                </h3>
            </div>
            <div class="card-body border-top p-0">
                @if($model->html_content)
                    <iframe
                        srcdoc="{{ e($model->html_content) }}"
                        class="w-100 border-0 rounded-bottom"
                        style="min-height: 600px;"
                        sandbox="allow-same-origin"
                        title="Aperçu du modèle"></iframe>
                @else
                    <div class="text-center py-8 text-muted">
                        <i class="bi bi-envelope-x fs-2x mb-3 d-block"></i>
                        Aucun contenu HTML défini pour ce modèle.
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>

@push('scripts')
    <script>
        // Auto-resize iframe to content height once loaded
        document.addEventListener('DOMContentLoaded', function () {
            const iframe = document.querySelector('iframe[srcdoc]');
            if (iframe) {
                iframe.addEventListener('load', function () {
                    try {
                        const height = iframe.contentDocument.body.scrollHeight;
                        if (height > 0) {
                            iframe.style.height = (height + 40) + 'px';
                        }
                    } catch(e) {}
                });
            }
        });
    </script>
@endpush

</x-default-layout>
