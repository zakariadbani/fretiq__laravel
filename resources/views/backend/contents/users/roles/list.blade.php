<x-default-layout>

@section('title')
    Gestion des rôles
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Administration</li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Rôles</li>
    </ul>
@endsection

<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h2 class="fw-bold m-0">
                <i class="bi bi-shield-check text-primary fs-2 me-2"></i>
                Rôles
            </h2>
        </div>
        <div class="card-toolbar">
        </div>
    </div>
    <div class="card-body pt-0">
        @include('backend.contents.users.partials._role-list')
    </div>
</div>

@include('backend.contents.users.partials._role-modal')

</x-default-layout>
