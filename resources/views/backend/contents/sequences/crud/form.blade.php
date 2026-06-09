<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la séquence — ' . e($model->name) : 'Créer une séquence' }}
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
            <a href="{{ route('admin.sequences.index') }}" class="text-muted text-hover-primary">Séquences</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            {{ isset($model) && $model->id ? 'Modifier' : 'Nouvelle séquence' }}
        </li>
    </ul>
@endsection

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    <div class="row g-5">
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-layers text-primary fs-3 me-2"></i>
                        Informations de la séquence
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Nom --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom de la séquence</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Relance prospects transport"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>
                        </div>

                        {{-- Switches --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-4 d-block">Options</label>

                                {{-- is_active --}}
                                <div class="d-flex align-items-center mb-4">
                                    <div class="form-check form-switch form-check-custom form-check-solid me-4">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               name="is_active"
                                               id="is_active"
                                               value="1"
                                               {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                                        <label class="form-check-label fw-semibold text-gray-700" for="is_active">
                                            Séquence active
                                        </label>
                                    </div>
                                </div>

                                {{-- stop_on_reply --}}
                                <div class="d-flex align-items-center">
                                    <div class="form-check form-switch form-check-custom form-check-solid me-4">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               name="stop_on_reply"
                                               id="stop_on_reply"
                                               value="1"
                                               {{ old('stop_on_reply', $model->stop_on_reply ?? true) ? 'checked' : '' }} />
                                        <label class="form-check-label fw-semibold text-gray-700" for="stop_on_reply">
                                            Arrêter sur réponse du contact
                                        </label>
                                    </div>
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
                @can('view sequences')
                    <a href="{{ route('admin.sequences.index') }}" class="btn btn-secondary">
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
