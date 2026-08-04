<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la séquence — ' . e($model->name) : 'Créer une séquence' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Séquences', 'route' => 'admin.sequences.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Nouvelle séquence']]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sequences.index'])
@endsection

{{--
    Sequence create/edit form — hero + tabbar + sticky contract.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with tab strip.
        - Général (active) is the only native form pane INSIDE <form>.
        - Aperçu tab deep-links to view page.
        - Étapes pane is rendered AFTER </form> and moved into
          #sequence_tab_content by crud-tabs.js (avoids nested forms — the
          steps pane has its own inline POST forms for addStep/deleteStep).

    Create mode:
        - Simple header card + minimal nav (Général only).
          Steps are irrelevant until the sequence exists.

    No select2 used — all inputs are text, number, or checkbox switches.
    Both form-actions calls preserved: toolbar variant above + sticky variant at bottom.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)

        @include('backend.contents.sequences.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])

    @else
        {{-- ── Create mode: simple header + minimal nav (Général only) ── --}}
        <div class="card mb-5">
            <div class="card-body py-6">
                <h2 class="fs-3 fw-bold m-0">
                    <i class="bi bi-layers text-primary fs-3 me-2"></i>
                    Créer une séquence
                </h2>
            </div>
        </div>
        <ul class="nav nav-line-tabs nav-line-tabs-2x border-bottom mb-5 fs-5 fw-bold">
            <li class="nav-item mt-2">
                <a class="nav-link text-active-primary ms-0 me-10 py-5 active"
                   data-bs-toggle="tab" href="#sequence_general">
                    <i class="bi bi-layers me-1"></i>
                    Général
                </a>
            </li>
        </ul>
    @endif

    {{-- ── Tab content (form panes only) ────────────────────────────────── --}}
    {{-- data-out-of-form-panes: consumed by crud-tabs.js to move out-of-form panes into this container. --}}
    <div class="tab-content" id="sequence_tab_content"
         data-out-of-form-panes='["sequence_steps"]'>

        {{-- ── Général (default active) ──────────────────────────────────── --}}
        {{-- All required/validated fields are here — validation errors surface on the visible pane. --}}
        <div class="tab-pane fade show active" id="sequence_general" role="tabpanel">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title align-items-start flex-column">
                        <span class="card-label fw-bold fs-3 mb-1">Informations de la séquence</span>
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
                                        <input type="hidden" name="is_active" value="0" />
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
                                        <input type="hidden" name="stop_on_reply" value="0" />
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
        {{-- end Général --}}

        {{--
            Étapes pane is NOT inside the form.
            It is rendered AFTER </form> (below) and moved into this
            #sequence_tab_content div by crud-tabs.js on page load.
            This avoids nested-form issues (steps pane has its own POST forms).
        --}}

    </div>
    {{-- end tab-content --}}

    {{-- Sticky save bar — shared partial (mirrors top toolbar) --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.sequences.index'])

</form>

{{-- ── Out-of-form panes (edit mode only) ───────────────────────────────── --}}
{{-- crud-tabs.js moves these into #sequence_tab_content after DOMContentLoaded --}}
@if(isset($model) && $model->id)

    <div class="tab-pane fade" id="sequence_steps" role="tabpanel" data-crud-pane>

        {{-- ── Step Builder ─────────────────────────────────────────────────── --}}
        <div class="card mb-5">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-list-ol text-info fs-3 me-2"></i>
                    Étapes de la séquence
                </h3>
            </div>
            <div class="card-body border-top p-0">

                @if($model->steps->isNotEmpty())
                @php
                    $stepsOrdered = $model->steps->sortBy('step_no')->values();
                    $cumulativeDays = 0;
                    $stepTimeline = [];
                    foreach ($stepsOrdered as $s) {
                        $cumulativeDays += $s->delay_days;
                        $stepTimeline[$s->id] = $cumulativeDays === 0 ? 'Jour 0' : 'J+' . $cumulativeDays;
                    }
                    $firstStepId = $stepsOrdered->first()?->id;
                    $lastStepId  = $stepsOrdered->last()?->id;
                @endphp
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-3 mb-0">
                        <thead>
                            <tr class="fw-bold text-muted bg-light">
                                <th class="ps-7">N°</th>
                                <th>Calendrier</th>
                                <th>Modèle</th>
                                <th>Sujet</th>
                                @can('edit sequences')
                                <th class="text-end pe-7">Actions</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stepsOrdered as $step)
                            <tr>
                                <td class="ps-7">
                                    <span class="badge badge-circle badge-light-primary">{{ $step->step_no }}</span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-primary">{{ $stepTimeline[$step->id] }}</span>
                                    @if($step->delay_days > 0)
                                        <br><span class="text-muted fs-8">+{{ $step->delay_days }} j après l'étape précédente</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="fw-semibold">{{ $step->template?->name ?? '—' }}</span>
                                </td>
                                <td>
                                    <span class="text-muted">{{ $step->subject ?? $step->template?->subject ?? 'Sans sujet' }}</span>
                                </td>
                                @can('edit sequences')
                                <td class="text-end pe-7">
                                    <div class="d-flex gap-1 justify-content-end">
                                        <button type="button"
                                                class="btn btn-sm btn-icon btn-light-primary"
                                                title="Modifier l’étape"
                                                data-bs-toggle="modal"
                                                data-bs-target="#sequence_step_edit_modal"
                                                data-bs-title="Modifier l’étape"
                                                data-sequence-step-edit
                                                data-update-url="{{ route('admin.sequences.updateStep', [$model->id, $step->id]) }}"
                                                data-delay-days="{{ $step->delay_days }}"
                                                data-template-id="{{ $step->template_id }}"
                                                data-subject="{{ $step->subject }}"
                                                aria-label="Modifier l’étape">
                                            <i class="bi bi-pencil fs-5"></i>
                                        </button>
                                        @if($step->id !== $firstStepId)
                                            <button type="submit"
                                                    form="sequence_step_move_up_{{ $step->id }}"
                                                    class="btn btn-sm btn-light"
                                                    title="Monter l'étape"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Monter l'étape"
                                                    aria-label="Monter l'étape">
                                                <i class="bi bi-arrow-up fs-6"></i>
                                            </button>
                                        @endif
                                        @if($step->id !== $lastStepId)
                                            <button type="submit"
                                                    form="sequence_step_move_down_{{ $step->id }}"
                                                    class="btn btn-sm btn-light"
                                                    title="Descendre l'étape"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Descendre l'étape"
                                                    aria-label="Descendre l'étape">
                                                <i class="bi bi-arrow-down fs-6"></i>
                                            </button>
                                        @endif
                                        <button type="submit"
                                                form="sequence_step_delete_{{ $step->id }}"
                                                class="btn btn-sm btn-icon btn-light-danger"
                                                title="Supprimer l'étape"
                                                data-bs-toggle="tooltip"
                                                data-bs-title="Supprimer l'étape"
                                                aria-label="Supprimer l'étape">
                                            <i class="bi bi-trash fs-5"></i>
                                        </button>
                                    </div>
                                </td>
                                @endcan
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="text-center py-8 text-muted">
                        <i class="bi bi-list-ol fs-2x mb-3 d-block"></i>
                        Aucune étape définie. Ajoutez la première étape ci-dessous.
                    </div>
                @endif

            </div>

            {{-- Add step inline form --}}
            @can('edit sequences')
            <div class="card-footer border-top pt-5 pb-6 px-9">
                <h5 class="fw-bold mb-5 text-gray-700">
                    <i class="bi bi-plus-circle text-primary me-2"></i>
                    Ajouter une étape
                </h5>
                <form method="POST" action="{{ route('admin.sequences.addStep', $model->id) }}">
                    @csrf
                    <div class="row g-4 align-items-end">

                        <div class="col-md-2">
                            <label class="required fw-semibold fs-7 mb-2">Délai (jours)</label>
                            <input type="number"
                                   name="delay_days"
                                   class="form-control form-control-solid form-control-sm"
                                   placeholder="0"
                                   min="0"
                                   value="{{ old('delay_days', 1) }}"
                                   required />
                            <div class="form-text text-muted fs-8">0 = immédiat</div>
                        </div>

                        <div class="col-md-4">
                            <label class="required fw-semibold fs-7 mb-2">Modèle d'email</label>
                            @if($templates->isEmpty())
                                <div class="form-text text-muted fs-7 pt-2">
                                    Aucun modèle —
                                    @can('create campaign_templates')
                                        <a href="{{ route('admin.campaign_templates.create') }}" target="_blank">Créer un modèle</a>
                                    @else
                                        <span>Créer un modèle</span>
                                    @endcan
                                </div>
                                {{-- Hidden required field to prevent orphaned form submission --}}
                                <input type="hidden" name="template_id" value="" />
                            @else
                                <select name="template_id" class="form-select form-select-solid form-select-sm" required>
                                    <option value="">Sélectionner un modèle...</option>
                                    @foreach($templates as $tpl)
                                        <option value="{{ $tpl->id }}" {{ old('template_id') == $tpl->id ? 'selected' : '' }}>
                                            {{ $tpl->name }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </div>

                        <div class="col-md-4">
                            <label class="fw-semibold fs-7 mb-2">Sujet <span class="text-muted">(optionnel)</span></label>
                            <input type="text"
                                   name="subject"
                                   class="form-control form-control-solid form-control-sm"
                                   placeholder="Laissez vide pour utiliser le sujet du modèle"
                                   value="{{ old('subject') }}" />
                        </div>

                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100" {{ $templates->isEmpty() ? 'disabled' : '' }}>
                                <i class="bi bi-plus me-1"></i>
                                Ajouter
                            </button>
                        </div>

                    </div>
                </form>
            </div>
            @endcan
        </div>

        {{-- ── Suivi des contacts ───────────────────────────────────────────── --}}
        @php
            $enrollments = $model->enrollments()->with('contact')->orderByDesc('created_at')->get();
            $enrollmentStatuses = config('global.data.sequence_enrollment_statuses', []);
        @endphp

        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-person-check text-success fs-3 me-2"></i>
                    Suivi des contacts ({{ $enrollments->count() }})
                </h3>
            </div>
            <div class="card-body border-top p-0">

                @if($enrollments->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-3 mb-0">
                        <thead>
                            <tr class="fw-bold text-muted bg-light">
                                <th class="ps-7">Contact</th>
                                <th>Étape actuelle</th>
                                <th>Statut</th>
                                <th>Prochain envoi</th>
                                @can('edit sequences')
                                <th class="text-end pe-7">Actions</th>
                                @endcan
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($enrollments as $enrollment)
                            @php
                                $statusCfg = $enrollmentStatuses[$enrollment->status] ?? [];
                            @endphp
                            <tr>
                                <td class="ps-7 fw-semibold">
                                    {{ $enrollment->contact?->email ? e($enrollment->contact->email) : '—' }}
                                </td>
                                <td>
                                    <span class="badge badge-light-primary">
                                        Étape {{ $enrollment->current_step ?? 0 }}
                                    </span>
                                </td>
                                <td>
                                    @if($statusCfg)
                                        <span class="badge badge-light-{{ $statusCfg['color'] }}">{{ $statusCfg['label'] }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $enrollment->next_send_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                @can('edit sequences')
                                <td class="text-end pe-7">
                                    <div class="d-flex gap-1 justify-content-end">
                                        @if($enrollment->status === 'active')
                                            <form method="POST"
                                                  action="{{ route('admin.sequences.pauseEnrollment', [$model->id, $enrollment->id]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-light-warning" title="Mettre en pause">
                                                    <i class="bi bi-pause fs-6"></i> Pause
                                                </button>
                                            </form>
                                        @endif
                                        @if($enrollment->status === 'paused')
                                            <form method="POST"
                                                  action="{{ route('admin.sequences.resumeEnrollment', [$model->id, $enrollment->id]) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-light-success" title="Reprendre">
                                                    <i class="bi bi-play fs-6"></i> Reprendre
                                                </button>
                                            </form>
                                        @endif
                                        @if(in_array($enrollment->status, ['active', 'paused']))
                                            <form method="POST"
                                                  action="{{ route('admin.sequences.stopEnrollment', [$model->id, $enrollment->id]) }}"
                                                  onsubmit="return confirm('Stopper définitivement cette inscription ?');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-light-danger" title="Stopper">
                                                    <i class="bi bi-stop-circle fs-6"></i> Stopper
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                                @endcan
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="text-center py-8 text-muted">
                        <i class="bi bi-person-x fs-2x mb-3 d-block"></i>
                        Aucune inscription pour cette séquence.
                    </div>
                @endif

            </div>
        </div>

    </div>
    {{-- end out-of-form Étapes --}}

    @can('edit sequences')
        @foreach($stepsOrdered ?? collect() as $step)
            @if($step->id !== ($firstStepId ?? null))
                <form id="sequence_step_move_up_{{ $step->id }}"
                      method="POST"
                      action="{{ route('admin.sequences.moveStepUp', [$model->id, $step->id]) }}"
                      class="d-none">
                    @csrf
                </form>
            @endif
            @if($step->id !== ($lastStepId ?? null))
                <form id="sequence_step_move_down_{{ $step->id }}"
                      method="POST"
                      action="{{ route('admin.sequences.moveStepDown', [$model->id, $step->id]) }}"
                      class="d-none">
                    @csrf
                </form>
            @endif
            <form id="sequence_step_delete_{{ $step->id }}"
                  method="POST"
                  action="{{ route('admin.sequences.deleteStep', [$model->id, $step->id]) }}"
                  onsubmit="return confirm('Supprimer cette étape ?');"
                  class="d-none">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    @endcan

    @include('backend.contents.sequences.partials._edit-step-modal')

@endif

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
