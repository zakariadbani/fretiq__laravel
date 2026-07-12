<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la demande — ' . ($model->contact ? e($model->contact->name) : '#' . $model->id) : 'Ajouter une demande' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Demandes', 'route' => 'admin.demandes.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Ajouter']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.demandes.index'])
@endsection

{{--
    Demande create/edit form — unified 2-tab UX (clic2loc parity).

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 2-tab strip.
        - Général (active) is the single form pane INSIDE <form>.
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card + minimal nav (Général only).
          Aperçu is irrelevant until the demande exists.

    select2 on the Général pane (default-active, safe width).
    No out-of-form panes — demandes module has no domain sub-tabs.

    Both form-actions includes are preserved:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of <form>.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + 2-tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.demandes.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-inbox text-primary fs-3 me-2"></i>
                    Ajouter une demande
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#demande_general">
                    <i class="bi bi-inbox me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    {{-- No out-of-form panes for demandes. --}}
    <div class="tab-content" id="demande_tab_content">

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        {{-- select2 selects live here — they render correctly in the default-active pane. --}}
        <div class="tab-pane fade show active" id="demande_general" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Informations de la demande</span>
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Contact (select2 — active pane, safe width) --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Contact</label>
                                <select name="contact_id"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-placeholder="Sélectionner un contact...">
                                    <option value="">Sélectionner un contact...</option>
                                    @foreach($contacts as $contact)
                                        <option value="{{ $contact->id }}"
                                            {{ old('contact_id', $model->contact_id ?? '') == $contact->id ? 'selected' : '' }}>
                                            {{ $contact->name }}
                                            @if($contact->email) &lt;{{ $contact->email }}&gt; @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Statut (select2 — active pane, safe width) --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Statut</label>
                                <select name="status"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true"
                                        data-placeholder="Sélectionner un statut...">
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

                        {{-- Captured at --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Capturée le</label>
                                <input type="datetime-local"
                                       name="captured_at"
                                       class="form-control form-control-solid"
                                       value="{{ old('captured_at', isset($model->captured_at) ? $model->captured_at?->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i')) }}"
                                       required />
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
        {{-- end Général --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.demandes.index'])

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
