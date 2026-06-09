<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la demande #' . $model->id : 'Ajouter une demande' }}
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
            <a href="{{ route('admin.demandes.index') }}" class="text-muted text-hover-primary">Demandes</a>
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
                        <i class="bi bi-inbox text-primary fs-3 me-2"></i>
                        Informations de la demande
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Contact --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Contact</label>
                                <select name="contact_id" class="form-select form-select-solid">
                                    <option value="">Sélectionner un contact...</option>
                                    @foreach($contacts as $contact)
                                        <option value="{{ $contact->id }}"
                                            {{ old('contact_id', $model->contact_id ?? '') == $contact->id ? 'selected' : '' }}>
                                            {{ e($contact->name) }}
                                            @if($contact->email) &lt;{{ e($contact->email) }}&gt; @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Kind --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Type de demande</label>
                                <input type="text"
                                       name="kind"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : reply, form, call"
                                       value="{{ old('kind', $model->kind ?? '') }}" />
                            </div>
                        </div>

                        {{-- Status --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Statut</label>
                                <select name="status" class="form-select form-select-solid">
                                    <option value="">Sélectionner un statut...</option>
                                    @foreach($statuses as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('status', $model->status ?? 'pending') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div class="col-lg-12">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Notes</label>
                                <textarea name="notes"
                                          class="form-control form-control-solid"
                                          rows="4"
                                          placeholder="Commentaires sur cette demande...">{{ old('notes', $model->notes ?? '') }}</textarea>
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
                @can('view demandes')
                    <a href="{{ route('admin.demandes.index') }}" class="btn btn-secondary">
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
