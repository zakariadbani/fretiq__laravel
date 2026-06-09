<x-default-layout>

@section('title')
    Identité — {{ e($model->name) }}
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
            <a href="{{ route('admin.sender_identities.index') }}" class="text-muted text-hover-primary">Identités d'expéditeur</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view sender_identities')
        <a href="{{ route('admin.sender_identities.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit sender_identities')
        <a href="{{ route('admin.sender_identities.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">

    {{-- Identity details --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-person-badge text-primary fs-3 me-2"></i>
                    Informations de l'identité
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Nom</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ e($model->name) }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Email</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ e($model->email) }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Répondre à</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->reply_to ? e($model->reply_to) : '—' }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Par défaut</label>
                    <div class="col-lg-8">
                        @if($model->is_default)
                            <span class="badge badge-light-success">Oui</span>
                        @else
                            <span class="badge badge-light-secondary">Non</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Statut</label>
                    <div class="col-lg-8">
                        @if($model->is_active)
                            <span class="badge badge-light-success">Actif</span>
                        @else
                            <span class="badge badge-light-danger">Inactif</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Créé le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Signature HTML --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-code-slash text-info fs-3 me-2"></i>
                    Signature HTML
                </h3>
            </div>
            <div class="card-body border-top">
                @if($model->signature_html)
                    <div class="mb-4">
                        <div class="fs-7 text-muted fw-semibold mb-2">Aperçu</div>
                        <div class="border rounded p-4 bg-light" style="min-height: 80px;">
                            {!! $model->signature_html !!}
                        </div>
                    </div>
                    <div>
                        <div class="fs-7 text-muted fw-semibold mb-2">Code HTML</div>
                        <pre class="bg-white border rounded p-4 mb-0 fs-8 text-gray-700" style="white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto;">{{ e($model->signature_html) }}</pre>
                    </div>
                @else
                    <div class="text-center py-8 text-muted">
                        <i class="bi bi-code-slash fs-2x mb-3 d-block"></i>
                        Aucune signature définie pour cette identité.
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>

</x-default-layout>
