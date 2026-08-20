<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le secteur — ' . $model->label : 'Ajouter un secteur' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Secteurs', 'route' => 'admin.sectors.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sectors.index'])
@endsection

{{--
    Sector create/edit form — lean lookup-table layout (contract §10: simple
    lookup table, no relationships, few fields). No hero, no tab strip, no
    overview partial — a single plain card shared by create and edit.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    <div class="card mb-5">
        <div class="card-header border-0 pt-5">
            <h3 class="card-title fw-bolder m-0">
                <i class="bi bi-tags text-primary fs-3 me-2"></i>
                Informations du secteur
            </h3>
        </div>
        <div class="card-body border-top p-9">

            <div class="row">
                {{-- Left column --}}
                <div class="col-lg-6">

                    {{-- Libellé --}}
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Libellé</label>
                        <input type="text"
                               name="label"
                               class="form-control form-control-solid"
                               placeholder="Ex : Transport routier"
                               maxlength="100"
                               value="{{ old('label', $model->label ?? '') }}"
                               required />
                    </div>

                    {{-- Ordre --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Ordre d'affichage</label>
                        <input type="number"
                               name="sort_order"
                               min="0"
                               class="form-control form-control-solid"
                               value="{{ old('sort_order', $model->sort_order ?? 0) }}" />
                        <div class="form-text text-muted mt-1">
                            Détermine la position dans les listes triées (croissant, puis alphabétique).
                        </div>
                    </div>

                </div>

                {{-- Right column --}}
                <div class="col-lg-6">

                    {{-- Actif --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-3">Statut</label>
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input type="hidden" name="is_active" value="0" />
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="is_active"
                                   id="is_active"
                                   value="1"
                                   {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                            <label class="form-check-label fw-semibold text-gray-700 ms-3" for="is_active">
                                Secteur actif
                            </label>
                        </div>
                    </div>

                    {{-- Utilisé en découverte --}}
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-3">Découverte</label>
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input type="hidden" name="use_in_discovery" value="0" />
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="use_in_discovery"
                                   id="use_in_discovery"
                                   value="1"
                                   {{ old('use_in_discovery', $model->use_in_discovery ?? true) ? 'checked' : '' }} />
                            <label class="form-check-label fw-semibold text-gray-700 ms-3" for="use_in_discovery">
                                Utilisé en découverte
                            </label>
                        </div>
                        <div class="form-text text-muted mt-1">
                            Contrôle si ce secteur est proposé comme cible de découverte, indépendamment du statut actif.
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    {{-- Sticky save bar --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.sectors.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
@endpush

</x-default-layout>
