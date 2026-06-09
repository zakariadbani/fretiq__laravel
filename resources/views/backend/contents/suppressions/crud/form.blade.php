<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la suppression — ' . e($model->email) : 'Ajouter une suppression' }}
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
            <a href="{{ route('admin.suppressions.index') }}" class="text-muted text-hover-primary">Suppressions</a>
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
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <i class="bi bi-slash-circle text-danger fs-3 me-2"></i>
                        Informations de la suppression
                    </h3>
                </div>
                <div class="card-body border-top p-9">

                    <div class="row">
                        {{-- Left column --}}
                        <div class="col-lg-6">

                            {{-- Email --}}
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Adresse email</label>
                                <input type="email"
                                       name="email"
                                       class="form-control form-control-solid"
                                       placeholder="contact@exemple.fr"
                                       value="{{ old('email', $model->email ?? '') }}"
                                       required />
                            </div>

                        </div>

                        {{-- Right column --}}
                        <div class="col-lg-6">

                            {{-- Motif --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Motif</label>
                                <select name="reason" class="form-select form-select-solid">
                                    <option value="">Sélectionner un motif...</option>
                                    @foreach($suppressionReasons as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('reason', $model->reason ?? 'manual') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Source --}}
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Source</label>
                                <select name="source" class="form-select form-select-solid">
                                    <option value="">Sélectionner une source...</option>
                                    @foreach($suppressionSources as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('source', $model->source ?? 'manual') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                        </div>
                    </div>

                    <div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-6">
                        <i class="bi bi-exclamation-triangle fs-2tx text-warning me-4"></i>
                        <div>
                            <h4 class="text-gray-900 fw-bolder">Liste de suppression</h4>
                            <div class="fs-7 text-gray-700">
                                Les adresses sur cette liste ne recevront <strong>aucun email de campagne</strong>, quelle que soit la campagne.
                                Les suppressions ajoutées manuellement ont généralement le motif « Manuel » et la source « Manuel ».
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
                @can('view suppressions')
                    <a href="{{ route('admin.suppressions.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </a>
                @endcan

                <button type="submit" class="btn btn-danger submit" id="submit_btn">
                    <span class="indicator-label">
                        <i class="bi bi-slash-circle me-2"></i>
                        Enregistrer la suppression
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
