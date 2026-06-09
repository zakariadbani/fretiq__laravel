<x-default-layout>

@section('title')
    Contact — {{ e($model->name) }}
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
            <a href="{{ route('admin.contacts.index') }}" class="text-muted text-hover-primary">Contacts</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Action buttons --}}
<div class="d-flex align-items-center gap-2 mb-6">
    @can('view contacts')
        <a href="{{ route('admin.contacts.index') }}" class="btn btn-sm fw-bold btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit contacts')
        <a href="{{ route('admin.contacts.edit', $model->id) }}" class="btn btn-sm fw-bold btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
</div>

<div class="row g-5">

    {{-- Contact details --}}
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-person text-primary fs-3 me-2"></i>
                    Informations du contact
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Nom</label>
                    <div class="col-lg-8">
                        <span class="fw-bolder fs-6 text-gray-900">{{ $model->name }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Email</label>
                    <div class="col-lg-8">
                        <a href="mailto:{{ $model->email }}" class="fw-semibold text-primary">{{ $model->email }}</a>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Poste</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->position ?: '—' }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Téléphone</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->phone ?: '—' }}</span>
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Entreprise</label>
                    <div class="col-lg-8">
                        @if($model->company)
                            <a href="{{ route('admin.companies.view', $model->company_id) }}"
                               class="fw-semibold text-primary">
                                {{ $model->company->name }}
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Statut</label>
                    <div class="col-lg-8">
                        @php
                            $status = config('global.data.contact_statuses.' . $model->status);
                        @endphp
                        @if($status)
                            <span class="badge badge-light-{{ $status['color'] }}">{{ $status['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-4 fw-bold text-muted">Source</label>
                    <div class="col-lg-8">
                        @php
                            $src = config('global.data.contact_sources.' . $model->source);
                        @endphp
                        @if($src)
                            <span class="badge badge-light-{{ $src['color'] }}">{{ $src['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                @if($model->source_url)
                    <div class="row mb-7">
                        <label class="col-lg-4 fw-bold text-muted">URL source</label>
                        <div class="col-lg-8">
                            <a href="{{ $model->source_url }}" target="_blank" rel="noopener noreferrer"
                               class="fw-semibold text-primary text-break">{{ $model->source_url }}</a>
                        </div>
                    </div>
                @endif

                <div class="row mb-0">
                    <label class="col-lg-4 fw-bold text-muted">Créé le</label>
                    <div class="col-lg-8">
                        <span class="fw-semibold">{{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- Compliance / RGPD block --}}
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-shield-check text-success fs-3 me-2"></i>
                    Conformité RGPD
                </h3>
            </div>
            <div class="card-body border-top">

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Base légale</label>
                    <div class="col-lg-7">
                        @php
                            $lb = config('global.data.contact_legal_bases.' . $model->legal_basis);
                        @endphp
                        @if($lb)
                            <span class="badge badge-light-{{ $lb['color'] }}">{{ $lb['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Type d'email</label>
                    <div class="col-lg-7">
                        @php
                            $ek = config('global.data.contact_email_kinds.' . $model->email_kind);
                        @endphp
                        @if($ek)
                            <span class="badge badge-light-{{ $ek['color'] }}">{{ $ek['label'] }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                </div>

                <div class="row mb-7">
                    <label class="col-lg-5 fw-bold text-muted">Date consentement</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold">
                            {{ $model->consent_at ? $model->consent_at->format('d/m/Y H:i') : '—' }}
                        </span>
                    </div>
                </div>

                <div class="row mb-0">
                    <label class="col-lg-5 fw-bold text-muted">Vérification email</label>
                    <div class="col-lg-7">
                        <span class="fw-semibold">{{ $model->email_verification_status ?: '—' }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

</div>

</x-default-layout>
