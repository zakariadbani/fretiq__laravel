<x-default-layout>

@section('title')
    Entreprise — {{ e($model->name) }}
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
            <a href="{{ route('admin.companies.index') }}" class="text-muted text-hover-primary">Entreprises</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{--
    Company view — prototype parity.
    Hero card + 4 tabs: Aperçu / Contacts / Activité / Enrichissement.
    KPI cards and activity timeline use honest empty states (Phase 3+).
    enrichment_data rendered with {{ }} only — never {!! !!}.
--}}

{{-- Hero card --}}
<x-companies.hero :model="$model">
    <x-slot:actions>
        @can('view companies')
            <a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light">
                <i class="bi bi-arrow-left me-1"></i>
                Retour à la liste
            </a>
        @endcan

        @can('edit companies')
            <a href="{{ route('admin.companies.edit', $model->id) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-pencil me-1"></i>
                Modifier
            </a>
        @endcan

        @can('create campaigns')
            @if(Route::has('admin.campaigns.create'))
                <a href="{{ route('admin.campaigns.create') }}" class="btn btn-sm btn-light btn-active-light-primary">
                    <i class="bi bi-rocket me-1"></i>
                    Lancer une campagne
                </a>
            @endif
        @endcan
    </x-slot:actions>

    {{-- Tab nav inside the hero card --}}
    <ul class="nav nav-stretch nav-line-tabs nav-line-tabs-2x border-transparent fs-5 fw-bold">
        <li class="nav-item mt-2">
            <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
               data-bs-toggle="tab" href="#cv_overview">Aperçu</a>
        </li>
        <li class="nav-item mt-2">
            <a class="nav-link text-active-primary ms-0 me-10 py-5"
               data-bs-toggle="tab" href="#cv_contacts">
                Contacts
                <span class="badge badge-light-primary ms-2">{{ $model->contacts->count() }}</span>
            </a>
        </li>
        <li class="nav-item mt-2">
            <a class="nav-link text-active-primary ms-0 me-10 py-5"
               data-bs-toggle="tab" href="#cv_activity">Activité</a>
        </li>
        <li class="nav-item mt-2">
            <a class="nav-link text-active-primary ms-0 me-10 py-5"
               data-bs-toggle="tab" href="#cv_enrichment">Enrichissement</a>
        </li>
    </ul>
</x-companies.hero>

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu ──────────────────────────────────────────────────── --}}
    <div class="tab-pane fade show active" id="cv_overview" role="tabpanel">
        <div class="row g-6 g-xl-9">

            {{-- Left: Details table --}}
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title fs-5 fw-bold">Détails</div>
                    </div>
                    <div class="card-body p-9">
                        <div class="table-responsive">
                            <table class="table align-middle gy-4">
                                <tbody>

                                    <tr>
                                        <td class="text-muted fw-semibold w-50">Secteur</td>
                                        <td class="text-gray-800 fw-bold">{{ $model->sector ?: '—' }}</td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Pays</td>
                                        <td class="text-gray-800 fw-bold">
                                            {{ $model->country ? strtoupper($model->country) : '—' }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Taille estimée</td>
                                        <td class="text-gray-800 fw-bold">{{ $model->estimated_size ?: '—' }}</td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Téléphone</td>
                                        <td class="text-gray-800 fw-bold">{{ $model->phone ?: '—' }}</td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Relation</td>
                                        <td>
                                            @php $rel = config('global.data.company_relationships.' . $model->relationship); @endphp
                                            @if($rel)
                                                <span class="badge badge-light-{{ $rel['color'] }}">{{ $rel['label'] }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Source</td>
                                        <td>
                                            @php $src = config('global.data.company_sources.' . $model->source); @endphp
                                            @if($src)
                                                <span class="badge badge-light-{{ $src['color'] }}">{{ $src['label'] }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Statut qualif.</td>
                                        <td>
                                            @php $qs = config('global.data.company_qualification_statuses.' . $model->qualification_status); @endphp
                                            @if($qs)
                                                <span class="badge badge-light-{{ $qs['color'] }}">{{ $qs['label'] }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Score IA</td>
                                        <td>
                                            <x-companies.score :score="$model->ai_score" />
                                        </td>
                                    </tr>

                                    <tr>
                                        <td class="text-muted fw-semibold">Créé le</td>
                                        <td class="text-gray-800 fw-bold">
                                            {{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}
                                        </td>
                                    </tr>

                                    @if($model->ai_explanation)
                                        <tr>
                                            <td class="text-muted fw-semibold">Explication IA</td>
                                            <td class="text-gray-700">{{ $model->ai_explanation }}</td>
                                        </tr>
                                    @endif

                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: KPI cards (honest empty states — Phase 3 tracking) --}}
            <div class="col-lg-7">
                <div class="row g-5">

                    <div class="col-12">
                        <div class="card">
                            <div class="card-body py-5 px-7">
                                <div class="d-flex align-items-center gap-4">
                                    <div class="symbol symbol-50px">
                                        <div class="symbol-label bg-light-primary">
                                            <i class="bi bi-envelope fs-2 text-primary"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fs-2 fw-bold text-gray-400">—</div>
                                        <div class="fw-semibold text-muted fs-6">Emails envoyés</div>
                                        <div class="text-muted fs-7">Disponible après le lancement des campagnes</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card">
                            <div class="card-body py-5 px-7">
                                <div class="d-flex align-items-center gap-4">
                                    <div class="symbol symbol-50px">
                                        <div class="symbol-label bg-light-success">
                                            <i class="bi bi-eye fs-2 text-success"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fs-2 fw-bold text-gray-400">—</div>
                                        <div class="fw-semibold text-muted fs-6">Ouvertures</div>
                                        <div class="text-muted fs-7">Disponible après le lancement des campagnes</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card">
                            <div class="card-body py-5 px-7">
                                <div class="d-flex align-items-center gap-4">
                                    <div class="symbol symbol-50px">
                                        <div class="symbol-label bg-light-warning">
                                            <i class="bi bi-inbox fs-2 text-warning"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fs-2 fw-bold text-gray-400">—</div>
                                        <div class="fw-semibold text-muted fs-6">Demandes reçues</div>
                                        <div class="text-muted fs-7">Disponible après le lancement des campagnes</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 2: Contacts ────────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="cv_contacts" role="tabpanel">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-people text-info fs-3 me-2"></i>
                    Contacts ({{ $model->contacts->count() }})
                </h3>
                @can('create contacts')
                    <div class="card-toolbar">
                        <a href="{{ route('admin.contacts.create') }}" class="btn btn-sm btn-light-primary">
                            <i class="bi bi-plus fs-4 me-1"></i>
                            Ajouter
                        </a>
                    </div>
                @endcan
            </div>
            <div class="card-body border-top">
                @if($model->contacts->isEmpty())
                    <div class="text-center py-10 text-muted">
                        <i class="bi bi-people fs-2x mb-3 d-block"></i>
                        Aucun contact pour cette entreprise.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
                            <thead>
                                <tr class="fw-bold text-muted">
                                    <th class="min-w-140px">Nom</th>
                                    <th class="min-w-120px">Email</th>
                                    <th class="min-w-80px">Statut</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($model->contacts as $contact)
                                    @php $cStatus = config('global.data.contact_statuses.' . $contact->status); @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                               class="text-gray-900 fw-bold text-hover-primary fs-6">
                                                {{ $contact->name }}
                                            </a>
                                            @if($contact->position)
                                                <span class="text-muted fw-semibold d-block fs-7">{{ $contact->position }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="mailto:{{ $contact->email }}"
                                               class="text-gray-700 text-hover-primary fs-7">
                                                {{ $contact->email }}
                                            </a>
                                        </td>
                                        <td>
                                            @if($cStatus)
                                                <span class="badge badge-light-{{ $cStatus['color'] }}">{{ $cStatus['label'] }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @can('view contacts')
                                                <a href="{{ route('admin.contacts.view', $contact->id) }}"
                                                   class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm">
                                                    <i class="bi bi-eye fs-4"></i>
                                                </a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
    {{-- end Contacts --}}

    {{-- ── Tab 3: Activité ────────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="cv_activity" role="tabpanel">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-clock-history text-primary fs-3 me-2"></i>
                    Activité
                </h3>
            </div>
            <div class="card-body border-top">
                <div class="text-center py-12 text-muted">
                    <i class="bi bi-clock-history fs-2x mb-4 d-block"></i>
                    <p class="fw-semibold fs-5 mb-2">Aucune activité enregistrée.</p>
                    <p class="fs-6">Le suivi des interactions sera disponible après le lancement des campagnes (Phase 3).</p>
                </div>
            </div>
        </div>
    </div>
    {{-- end Activité --}}

    {{-- ── Tab 4: Enrichissement ──────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="cv_enrichment" role="tabpanel">
        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-database text-info fs-3 me-2"></i>
                    Données d'enrichissement
                </h3>
            </div>
            <div class="card-body border-top">
                @php $enrichData = $model->enrichment_data; @endphp
                @if(!empty($enrichData))
                    <div class="table-responsive">
                        <table class="table table-row-bordered table-row-gray-200 align-middle gs-0 gy-3">
                            <thead>
                                <tr class="fw-bold text-muted">
                                    <th class="min-w-120px">Clé</th>
                                    <th>Valeur</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($enrichData as $key => $value)
                                    <tr>
                                        <td class="fw-semibold text-gray-700">{{ $key }}</td>
                                        <td class="text-gray-800">
                                            @if(is_array($value) || is_object($value))
                                                <code class="fs-7">{{ json_encode($value) }}</code>
                                            @else
                                                {{ $value }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center py-10 text-muted">
                        <i class="bi bi-database fs-2x mb-3 d-block"></i>
                        Aucune donnée d'enrichissement disponible.
                    </div>
                @endif
            </div>
        </div>
    </div>
    {{-- end Enrichissement --}}

</div>
{{-- end tab-content --}}

</x-default-layout>
