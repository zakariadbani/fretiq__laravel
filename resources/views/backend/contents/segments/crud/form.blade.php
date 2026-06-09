<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier le segment — ' . e($model->name) : 'Ajouter un segment' }}
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
            <a href="{{ route('admin.segments.index') }}" class="text-muted text-hover-primary">Segments</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            {{ isset($model) && $model->id ? 'Modifier' : 'Ajouter' }}
        </li>
    </ul>
@endsection

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    <div class="row g-5">
        {{-- Main information card --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-funnel text-primary fs-3 me-2"></i>
                        Informations du segment
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Nom --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom du segment</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Prospects transport FR"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>

                            {{-- Portée (scope) --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Portée</label>
                                <select name="scope" class="form-select form-select-solid" required>
                                    <option value="">Sélectionner une portée...</option>
                                    @foreach($scopes as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('scope', $model->scope ?? '') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Filtre (JSON) --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Filtre (JSON)</label>
                                <textarea name="filter"
                                          class="form-control form-control-solid font-monospace"
                                          rows="6"
                                          placeholder='{"sector":"Transport"}'>{{ old('filter', isset($model) && $model->filter ? json_encode($model->filter, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                                <div class="form-text text-muted mt-1">
                                    JSON optionnel — laissez vide pour aucun filtre supplémentaire.
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    {{-- Action buttons --}}
    <div class="row g-5 mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-end gap-3">
                @can('view segments')
                    <a href="{{ route('admin.segments.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </a>
                @endcan

                <button type="submit" class="btn btn-primary submit" id="submit_btn">
                    <span class="indicator-label">
                        <i class="bi bi-check-circle me-2"></i>
                        Enregistrer
                    </span>
                    <span class="indicator-progress">
                        <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
                    </span>
                </button>
            </div>
        </div>
    </div>

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
@endpush

</x-default-layout>
