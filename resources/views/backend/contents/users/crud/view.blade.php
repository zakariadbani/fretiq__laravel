<x-default-layout>

@section('title')
    Utilisateur — {{ e($model->name) }}
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
            <a href="{{ route('admin.users.index') }}" class="text-muted text-hover-primary">Utilisateurs</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
@endsection

{{-- Hero card --}}
<div class="card mb-5 mb-xl-10">
    <div class="card-body pt-9 pb-0">
        <div class="d-flex flex-wrap flex-sm-nowrap mb-3">
            {{-- Avatar --}}
            <div class="me-7 mb-4">
                <div class="symbol symbol-100px symbol-lg-160px symbol-fixed position-relative">
                    <div class="symbol-label fs-1 bg-light-primary text-primary fw-bold">
                        {{ mb_strtoupper(mb_substr($model->name ?? '', 0, 1)) }}
                    </div>
                    <div class="position-absolute translate-middle bottom-0 start-100 mb-6 {{ $model->is_active !== false ? 'bg-success' : 'bg-danger' }} rounded-circle border border-4 border-white h-20px w-20px"></div>
                </div>
            </div>

            {{-- Info --}}
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-2">
                    <div class="d-flex flex-column">
                        <div class="d-flex align-items-center mb-2">
                            <span class="text-gray-900 fs-2 fw-bolder me-1">{{ e($model->name) }}</span>
                        </div>
                        <div class="d-flex flex-wrap fw-bold fs-6 mb-4 pe-2">
                            <span class="d-flex align-items-center text-gray-500 me-5 mb-2">
                                <i class="bi bi-envelope fs-4 me-1"></i>
                                {{ e($model->email) }}
                            </span>
                            <span class="d-flex align-items-center text-gray-500 me-5 mb-2">
                                <i class="bi bi-shield-check fs-4 me-1"></i>
                                {{ ucfirst($model->roles->first()?->name ?? '—') }}
                            </span>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="d-flex my-4 gap-2">
                        @can('view users')
                        <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-light">
                            <i class="bi bi-arrow-left me-1"></i>
                            Retour
                        </a>
                        @endcan
                        @can('edit users')
                        @if(!$model->hasRole('superadmin'))
                        <a href="{{ route('admin.users.edit', $model->id) }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil me-1"></i>
                            Modifier
                        </a>
                        @endif
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Details card --}}
<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">Détails du compte</h3>
    </div>
    <div class="card-body border-top p-9">
        <div class="table-responsive">
            <table class="table align-middle gy-4">
                <tbody>

                    <tr>
                        <td class="text-muted fw-semibold w-30">Nom</td>
                        <td class="text-gray-800 fw-bold">{{ e($model->name) }}</td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Email</td>
                        <td class="text-gray-800 fw-bold">{{ e($model->email) }}</td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Rôle</td>
                        <td>
                            <span class="badge badge-light-primary">
                                {{ ucfirst($model->roles->first()?->name ?? '—') }}
                            </span>
                        </td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Statut</td>
                        <td>
                            @if($model->is_active !== false)
                                <span class="badge badge-light-success">Actif</span>
                            @else
                                <span class="badge badge-light-danger">Inactif</span>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Dernière connexion</td>
                        <td class="text-gray-800 fw-bold">
                            {{ $model->last_login_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Email vérifié</td>
                        <td class="text-gray-800 fw-bold">
                            {{ $model->email_verified_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                    </tr>

                    <tr>
                        <td class="text-muted fw-semibold">Créé le</td>
                        <td class="text-gray-800 fw-bold">
                            {{ $model->created_at?->format('d/m/Y H:i') ?? '—' }}
                        </td>
                    </tr>

                </tbody>
            </table>
        </div>
    </div>
</div>

</x-default-layout>
