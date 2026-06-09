<x-default-layout>

@section('title')
    Rôle — {{ ucfirst($role->name) }}
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
            <a href="{{ route('user-management.roles.index') }}" class="text-muted text-hover-primary">Rôles</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ ucfirst($role->name) }}</li>
    </ul>
@endsection

<div class="card mb-5">
    <div class="card-body d-flex align-items-center justify-content-between py-6">
        <div>
            <h2 class="fw-bold m-0">
                <i class="bi bi-shield-check text-primary fs-2 me-2"></i>
                {{ ucfirst($role->name) }}
            </h2>
            <span class="text-muted fs-6">
                {{ $role->permissions->count() }} permission(s) assignée(s)
            </span>
        </div>
        <a href="{{ route('user-management.roles.index') }}" class="btn btn-sm btn-light btn-active-light-primary">
            <i class="bi bi-arrow-left fs-4"></i>
            Retour aux rôles
        </a>
    </div>
</div>

{{-- Permissions card --}}
<div class="card mb-5">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">Permissions assignées</h3>
    </div>
    <div class="card-body border-top pt-6">
        @if($role->permissions->isEmpty())
            <div class="text-center py-8 text-muted">
                <i class="bi bi-shield-x fs-2x mb-3 d-block"></i>
                Aucune permission assignée à ce rôle.
            </div>
        @else
            <div class="d-flex flex-wrap gap-2">
                @foreach($role->permissions->sortBy('name') as $permission)
                    <span class="badge badge-light-primary">{{ $permission->name }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- Users with this role --}}
<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fw-bolder m-0">Utilisateurs avec ce rôle</h3>
    </div>
    <div class="card-body border-top py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

@push('scripts')
    {{ $dataTable->scripts() }}
@endpush

</x-default-layout>
