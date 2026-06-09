<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier l\'utilisateur — ' . e($model->name) : 'Ajouter un utilisateur' }}
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
        <li class="breadcrumb-item text-muted">
            {{ isset($model) && $model->id ? 'Modifier' : 'Ajouter' }}
        </li>
    </ul>
@endsection

{{--
    User create/edit form.
    AJAX submit (crud-form-handler.js) — expects 200 JSON {message:'success', redirect:url}
    or 422 JSON {message:string, errors:{field:[...]}}.
    Password field: required on create, optional on edit.
    Role: single select, superadmin excluded.
--}}

<form method="POST"
      action="{{ $route }}"
      class="form"
      id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- Header --}}
    <div class="card mb-5">
        <div class="card-body py-6 d-flex align-items-center justify-content-between">
            <h2 class="fs-3 fw-bold m-0">
                <i class="bi bi-person-gear text-primary fs-3 me-2"></i>
                {{ isset($model) && $model->id ? 'Modifier ' . e($model->name) : 'Ajouter un utilisateur' }}
            </h2>
            <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-light btn-active-light-primary">
                <i class="bi bi-arrow-left fs-4"></i>
                Retour à la liste
            </a>
        </div>
    </div>

    <div class="card mb-5">
        <div class="card-header border-0 pt-5">
            <h3 class="card-title align-items-start flex-column">
                <span class="card-label fw-bold fs-3 mb-1">Informations de l'utilisateur</span>
            </h3>
        </div>
        <div class="card-body border-top p-9">
            <div class="row">
                <div class="col-lg-6">

                    {{-- Nom complet --}}
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nom complet</label>
                        <input type="text"
                               name="name"
                               class="form-control form-control-solid"
                               placeholder="Ex : Jean Dupont"
                               value="{{ old('name', $model->name ?? '') }}"
                               required />
                    </div>

                    {{-- Email --}}
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Email</label>
                        <input type="email"
                               name="email"
                               class="form-control form-control-solid"
                               placeholder="Ex : jean.dupont@example.com"
                               value="{{ old('email', $model->email ?? '') }}"
                               required />
                    </div>

                    {{-- Rôle --}}
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Rôle</label>
                        <select name="role" class="form-select form-select-solid" required>
                            <option value="">Sélectionner un rôle...</option>
                            @foreach($roles as $role)
                                <option value="{{ $role->name }}"
                                    {{ old('role', $model->roles->first()?->name ?? '') === $role->name ? 'selected' : '' }}>
                                    {{ ucfirst($role->name) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Statut actif --}}
                    {{-- Hidden companion ensures is_active=0 is always submitted when
                         the checkbox is unchecked (browsers omit unchecked checkboxes). --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2 d-block">Compte actif</label>
                        <input type="hidden" name="is_active" value="0" />
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="is_active"
                                   id="is_active"
                                   value="1"
                                   {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                            <label class="form-check-label fw-semibold text-gray-700" for="is_active">
                                Actif
                            </label>
                        </div>
                    </div>

                </div>

                <div class="col-lg-6">

                    {{-- Mot de passe --}}
                    <div class="fv-row mb-7">
                        <label class="{{ !isset($model) || !$model->id ? 'required' : '' }} fw-semibold fs-6 mb-2">
                            {{ isset($model) && $model->id ? 'Nouveau mot de passe' : 'Mot de passe' }}
                        </label>
                        <input type="password"
                               name="password"
                               class="form-control form-control-solid"
                               placeholder="{{ isset($model) && $model->id ? 'Laisser vide pour ne pas changer' : 'Mot de passe' }}"
                               {{ !isset($model) || !$model->id ? 'required' : '' }}
                               autocomplete="new-password" />
                        @if(isset($model) && $model->id)
                            <div class="text-muted fs-7 mt-1">Laisser vide pour conserver le mot de passe actuel.</div>
                        @endif
                    </div>

                    {{-- Confirmation --}}
                    <div class="fv-row mb-7">
                        <label class="{{ !isset($model) || !$model->id ? 'required' : '' }} fw-semibold fs-6 mb-2">
                            Confirmer le mot de passe
                        </label>
                        <input type="password"
                               name="password_confirmation"
                               class="form-control form-control-solid"
                               placeholder="Confirmer"
                               {{ !isset($model) || !$model->id ? 'required' : '' }}
                               autocomplete="new-password" />
                    </div>

                </div>
            </div>
        </div>
    </div>

    {{-- Sticky save bar — shared partial --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.users.index', 'saveAndContinue' => false])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
@endpush

</x-default-layout>
