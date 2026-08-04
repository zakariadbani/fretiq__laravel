<x-default-layout>

@section('title')
    Séquence — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Séquences', 'route' => 'admin.sequences.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.sequences.index'])
@endsection

{{--
    Sequence view — hero + tabbar + aperçu + steps contract.
    Tab pane IDs: sequence_apercu / sequence_general / sequence_steps.
    Aperçu is native (default active); Général deep-links to edit;
    Étapes is a native pane on both pages (steps timeline preserved verbatim).
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.sequences.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="sequence_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\SequenceViewConfig::make($model),
        ])
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 3: Étapes (native on both pages) ──────────────────────────── --}}
    {{--
        Steps timeline — preserved verbatim from the original view.
        This pane is native on the view page and out-of-form on the edit page.
        Location: sequence_steps pane.
    --}}
    <div class="tab-pane fade" id="sequence_steps" role="tabpanel">

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
                                        <form method="POST"
                                              action="{{ route('admin.sequences.deleteStep', [$model->id, $step->id]) }}"
                                              onsubmit="return confirm('Supprimer cette étape ?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="btn btn-sm btn-icon btn-light-danger"
                                                    title="Supprimer l'étape"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-title="Supprimer l'étape"
                                                    aria-label="Supprimer l'étape">
                                                <i class="bi bi-trash fs-5"></i>
                                            </button>
                                        </form>
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
    {{-- end Étapes --}}

    {{--
        Tab 2 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@include('backend.contents.sequences.partials._edit-step-modal')

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
