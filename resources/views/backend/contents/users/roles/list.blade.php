<x-default-layout>

@section('title')
    Gestion des rôles
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Rôles']]" />
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
