<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la campagne — ' . e($model->name) : 'Créer une campagne' }}
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
            <a href="{{ route('admin.campaigns.index') }}" class="text-muted text-hover-primary">Campagnes</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            {{ isset($model) && $model->id ? 'Modifier' : 'Nouvelle campagne' }}
        </li>
    </ul>
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.campaigns.index'])
@endsection

{{--
    Campaign Builder — multi-section flat form (MVP choice).
    ui-ux-spec describes a future multi-step KT Stepper; the current implementation
    uses clear section cards with AJAX submit via crud-form-handler.js.

    Edit mode:
        - Shared _header-with-tabs partial (currentPage='edit') with 2-tab strip.
        - Général tab is the active edit pane (contains all sections, inside <form>).
        - Aperçu tab is a cross-route link to view page.

    Create mode:
        - Simple header card, no tabbar (no existing model yet).
        - Same sections, no tab pane wrapping.

    Both form-actions calls are present:
        - toolbar variant in @section('toolbar_actions') above.
        - sticky variant at the bottom of the form.

    Selects use select2 via data-control="select2" (Metronic KTApp auto-init). A
    select2→native-change bridge in the @push('scripts') block re-emits a native change
    on select2 selection, so FormValidation and the native change handlers (segment count,
    subject hint, schedule switcher) all fire.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
    @endif

    {{-- ── Edit mode: shared hero + 2-tab nav ──────────────────────────── --}}
    @if(isset($model) && $model->id)
        @include('backend.contents.campaigns.partials._header-with-tabs', [
            'model'       => $model,
            'currentPage' => 'edit',
        ])
    @else
        {{-- ── Create mode: simple header card ──────────────────────────── --}}
        <div class="card mb-6">
            <div class="card-body py-4 d-flex align-items-center gap-4">
                <div class="symbol symbol-40px symbol-circle me-2">
                    <span class="symbol-label bg-light-primary">
                        <i class="bi bi-megaphone text-primary fs-3"></i>
                    </span>
                </div>
                <div>
                    <h3 class="card-title fw-bolder m-0 fs-4">Nouvelle campagne</h3>
                    <span class="text-muted fs-7">Remplissez les champs ci-dessous pour créer la campagne.</span>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Tab pane wrapper (edit mode: Général tab; create mode: bare content) ── --}}
    @if(isset($model) && $model->id)
    <div class="tab-content">
    <div class="tab-pane fade show active" id="campaign_general" role="tabpanel">
    @endif

    <div class="row g-5">

        {{-- ── Section 1: Identité & Nom ────────────────────────────── --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <span class="badge badge-circle badge-primary me-3 fs-6">1</span>
                        Informations de base
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Nom de la campagne --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Nom de la campagne</label>
                                <input type="text"
                                       name="name"
                                       class="form-control form-control-solid"
                                       placeholder="Ex : Prospection Transport FR — Juin 2026"
                                       value="{{ old('name', $model->name ?? '') }}"
                                       required />
                            </div>
                        </div>

                        {{-- Identité d'expéditeur --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Identité d'expéditeur</label>
                                <select name="sender_identity_id" class="form-select form-select-solid" data-control="select2" data-placeholder="Sélectionner une identité..." required>
                                    <option value="">Sélectionner une identité...</option>
                                    @foreach($senderIdentities as $identity)
                                        <option value="{{ $identity->id }}"
                                            {{ old('sender_identity_id', $model->sender_identity_id ?? '') == $identity->id ? 'selected' : '' }}>
                                            {{ e($identity->name) }} &lt;{{ e($identity->email) }}&gt;
                                            @if($identity->is_default) (défaut) @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 2: Audience & Modèle ────────────────────────── --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <span class="badge badge-circle badge-primary me-3 fs-6">2</span>
                        Audience et contenu
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Segment (audience) --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Segment cible</label>
                                <select name="segment_id"
                                        class="form-select form-select-solid"
                                        id="segment_select"
                                        data-control="select2"
                                        data-placeholder="Sélectionner un segment..."
                                        required>
                                    <option value="">Sélectionner un segment...</option>
                                    @foreach($segments as $segment)
                                        <option value="{{ $segment->id }}"
                                            {{ old('segment_id', $model->segment_id ?? '') == $segment->id ? 'selected' : '' }}>
                                            {{ e($segment->name) }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="mt-2">
                                    <span id="segment-count-label" class="text-muted fs-7">
                                        @if(isset($model) && $model->segment_id)
                                            {{-- Server-rendered count for edit page --}}
                                        @else
                                            Sélectionnez un segment pour voir le nombre de contacts.
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- Modèle d'email (W1: hidden in sequence mode) --}}
                        <div class="col-lg-6" id="field-template-wrapper">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2" id="label-template">Modèle d'email</label>
                                <select name="template_id" class="form-select form-select-solid" id="template_select" data-control="select2" data-placeholder="Sélectionner un modèle...">
                                    <option value="">Sélectionner un modèle...</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}"
                                                data-subject="{{ e($template->subject) }}"
                                            {{ old('template_id', $model->template_id ?? '') == $template->id ? 'selected' : '' }}>
                                            {{ e($template->name) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Sujet (override optionnel — W1: hidden in sequence mode) --}}
                        <div class="col-lg-12" id="field-subject-wrapper">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">
                                    Sujet de l'email <span class="text-muted fs-7">(optionnel — remplace le sujet du modèle)</span>
                                </label>
                                <input type="text"
                                       name="subject"
                                       id="subject_override"
                                       class="form-control form-control-solid"
                                       placeholder="Laissez vide pour utiliser le sujet du modèle"
                                       value="{{ old('subject', $model->subject ?? '') }}" />
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ── Section 3: Planification ─────────────────────────────── --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header border-0 pt-5">
                    <h3 class="card-title fw-bolder m-0">
                        <span class="badge badge-circle badge-primary me-3 fs-6">3</span>
                        Planification
                    </h3>
                </div>
                <div class="card-body border-top p-9">
                    <div class="row">

                        {{-- Type de planification --}}
                        <div class="col-lg-4">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Type de planification</label>
                                <select name="schedule_type" class="form-select form-select-solid" id="schedule_type_select" data-control="select2" data-hide-search="true">
                                    @foreach($scheduleTypes as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('schedule_type', $model->schedule_type ?? 'one_shot') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Fuseau horaire (hidden in sequence mode — drip ignores timezone) --}}
                        <div class="col-lg-4" id="field-timezone-wrapper">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Fuseau horaire</label>
                                <input type="text"
                                       name="timezone"
                                       class="form-control form-control-solid"
                                       placeholder="Europe/Paris"
                                       value="{{ old('timezone', $model->timezone ?? 'Europe/Paris') }}" />
                            </div>
                        </div>

                    </div>

                    {{-- one_shot fields --}}
                    <div id="fields-one-shot" class="row">
                        <div class="col-lg-4">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Date et heure d'envoi</label>
                                <input type="text"
                                       name="scheduled_at"
                                       id="scheduled_at"
                                       class="form-control form-control-solid"
                                       placeholder="Sélectionner une date..."
                                       value="{{ old('scheduled_at', isset($model) && $model->scheduled_at ? $model->scheduled_at->format('Y-m-d H:i') : '') }}"
                                       autocomplete="off" />
                            </div>
                        </div>
                    </div>

                    {{-- recurring fields --}}
                    <div id="fields-recurring" class="row" style="display:none;">
                        @php
                            $recurrence = isset($model) && is_array($model->recurrence) ? $model->recurrence : [];
                        @endphp
                        <div class="col-lg-3">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Fréquence</label>
                                <select name="recurrence_frequency" class="form-select form-select-solid" data-control="select2" data-hide-search="true">
                                    @foreach($recurrenceFrequencies as $key => $label)
                                        <option value="{{ $key }}"
                                            {{ old('recurrence_frequency', $recurrence['frequency'] ?? 'weekly') === $key ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-2">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Intervalle</label>
                                <input type="number"
                                       name="recurrence_interval"
                                       class="form-control form-control-solid"
                                       min="1"
                                       value="{{ old('recurrence_interval', $recurrence['interval'] ?? 1) }}" />
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Premier envoi (next_run_at)</label>
                                <input type="text"
                                       name="next_run_at"
                                       id="next_run_at"
                                       class="form-control form-control-solid"
                                       placeholder="Date du premier envoi..."
                                       value="{{ old('next_run_at', isset($model) && $model->next_run_at ? $model->next_run_at->format('Y-m-d H:i') : '') }}"
                                       autocomplete="off" />
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Fin de récurrence <span class="text-muted fs-8">(optionnel)</span></label>
                                <input type="text"
                                       name="recurrence_until"
                                       id="recurrence_until"
                                       class="form-control form-control-solid"
                                       placeholder="Aucune date de fin"
                                       value="{{ old('recurrence_until', $recurrence['until'] ?? '') }}"
                                       autocomplete="off" />
                            </div>
                        </div>
                    </div>

                    {{-- sequence fields --}}
                    <div id="fields-sequence" class="row" style="display:none;">
                        <div class="col-lg-5">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Séquence active</label>

                                @php
                                    $activeSequences = $sequences->where('is_active', true);
                                    $currentSequenceId = old('sequence_id', $model->sequence_id ?? '');
                                @endphp

                                @if($activeSequences->isEmpty())
                                    {{-- #6 empty-state CTA --}}
                                    <div class="alert alert-info d-flex align-items-center py-3">
                                        <i class="bi bi-info-circle-fill fs-4 me-3 text-info"></i>
                                        <div>
                                            Aucune séquence active disponible.
                                            @can('create sequences')
                                                <a href="{{ route('admin.sequences.create') }}" class="fw-bold ms-1">Créer une séquence</a>
                                            @endcan
                                        </div>
                                    </div>
                                @else
                                    <select name="sequence_id"
                                            id="sequence_select"
                                            class="form-select form-select-solid"
                                            data-control="select2"
                                            data-placeholder="Sélectionner une séquence...">
                                        <option value="">Sélectionner une séquence...</option>
                                        @foreach($activeSequences as $seq)
                                            <option value="{{ $seq->id }}"
                                                    data-steps="{{ json_encode($seq->steps->map(fn($s) => ['step_no' => $s->step_no, 'delay_days' => $s->delay_days, 'subject' => $s->subject, 'template_name' => $s->template?->name ?? '—'])) }}"
                                                {{ $currentSequenceId == $seq->id ? 'selected' : '' }}>
                                                {{ e($seq->name) }}
                                            </option>
                                        @endforeach
                                    </select>
                                @endif

                                <div class="form-text text-muted mt-1 fs-7">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Au lancement, les contacts éligibles du segment seront inscrits dans la séquence ; l'envoi des étapes est ensuite automatique (cadence = délais des étapes).
                                </div>

                                {{-- Edit mode: enrolled count hint (consultant F5) --}}
                                @if(isset($model) && $model->id && $model->sequence_id)
                                    @php
                                        $enrolledHint = \App\Models\SequenceEnrollment::where('campaign_id', $model->id)->count();
                                    @endphp
                                    @if($enrolledHint > 0)
                                        <div class="mt-2">
                                            <span class="badge badge-light-info">
                                                <i class="bi bi-people me-1"></i>
                                                {{ $enrolledHint }} contact(s) déjà inscrits — modifier la séquence n'affecte que les prochains lancements.
                                            </span>
                                        </div>
                                    @endif
                                @endif

                                {{-- W2 steps preview (populated by JS on sequence select) --}}
                                <div id="sequence-steps-preview" class="mt-3" style="display:none;">
                                    <div class="fw-semibold fs-7 text-muted mb-2">Aperçu des étapes :</div>
                                    <div id="sequence-steps-list"></div>
                                    <div class="mt-1">
                                        <a id="sequence-edit-link" href="#" class="fs-7 text-primary" target="_blank">
                                            <i class="bi bi-pencil me-1"></i>Modifier la séquence
                                        </a>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    {{-- ── Section 4: Activation (hidden for sequence type) ──────────── --}}
    {{--
        is_active pause switch — shown only for non-sequence campaigns.
        Sequence campaigns are paused via their sequence.is_active (drip engine).
        Required hidden sibling input BEFORE the checkbox so unchecking posts 0
        through Crudable::update() ($request->all()). Proven pattern (cf. ProspectCriteria).
    --}}
    @if(!(isset($model) && $model->schedule_type === 'sequence'))
    <div class="col-12" id="field-is-active-wrapper">
        <div class="card">
            <div class="card-body py-5 px-9">
                <div class="d-flex align-items-center gap-4">
                    <div class="flex-grow-1">
                        <label class="fw-semibold fs-6 mb-1">Active</label>
                        <div class="text-muted fs-7">
                            Décochez pour mettre en pause (le planificateur ignore la campagne).
                        </div>
                    </div>
                    <div>
                        {{-- Hidden sibling MUST come BEFORE the checkbox --}}
                        <input type="hidden" name="is_active" value="0">
                        <div class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input"
                                   type="checkbox"
                                   name="is_active"
                                   value="1"
                                   id="is_active_toggle"
                                   {{ old('is_active', $model->is_active ?? true) ? 'checked' : '' }} />
                            <label class="form-check-label fw-semibold ms-3" for="is_active_toggle">
                                Active
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Sticky action bar (standard contract) ──────────────────────── --}}
    @include('backend.elements.form-actions', ['variant' => 'sticky', 'backRoute' => 'admin.campaigns.index'])

    @if(isset($model) && $model->id)
    </div>{{-- end campaign_general tab-pane --}}
    </div>{{-- end tab-content --}}
    @endif

</form>

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-form-handler.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>

    @if(class_exists(\App\Services\Campaign\SegmentService::class))
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── select2 → native change bridge ──────────────────────
            // select2 fires `change` via jQuery .trigger() only, which does NOT reach
            // native addEventListener('change') handlers. Re-emit a native bubbling change
            // on select2 selection so FormValidation (native Trigger plugin) and the native
            // change handlers below (segment count, subject hint, schedule switcher) all fire.
            $('#form_crud').on('select2:select select2:unselect select2:clear', '[data-control="select2"]', function () {
                this.dispatchEvent(new Event('change', { bubbles: true }));
            });

            // ── Segment live count via AJAX ─────────────────────────
            const segmentSelect = document.getElementById('segment_select');
            const countLabel    = document.getElementById('segment-count-label');

            if (segmentSelect && countLabel) {
                const fetchCount = function (segmentId) {
                    if (!segmentId) {
                        countLabel.innerHTML = 'Sélectionnez un segment pour voir le nombre de contacts.';
                        return;
                    }
                    countLabel.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calcul en cours...';

                    axios.get('{{ route("admin.campaigns.segmentCount", "") }}/' + segmentId)
                        .then(function (r) {
                            countLabel.innerHTML = '<span class="badge badge-light-primary">' + r.data.count + ' contact(s)</span> dans ce segment.';
                        })
                        .catch(function () {
                            countLabel.innerHTML = '<span class="text-muted">Impossible de récupérer le compte.</span>';
                        });
                };

                segmentSelect.addEventListener('change', function () {
                    fetchCount(this.value);
                });

                // Trigger on page load if a segment is already selected (edit page)
                if (segmentSelect.value) {
                    fetchCount(segmentSelect.value);
                }
            }

            // ── Auto-fill subject hint from template ────────────────
            const templateSelect  = document.getElementById('template_select');
            const subjectOverride = document.getElementById('subject_override');

            if (templateSelect && subjectOverride) {
                templateSelect.addEventListener('change', function () {
                    const selected = this.options[this.selectedIndex];
                    const templateSubject = selected ? selected.dataset.subject : '';
                    if (!subjectOverride.value && templateSubject) {
                        subjectOverride.placeholder = 'Sujet du modèle : ' + templateSubject;
                    }
                });
                // Trigger on load
                if (templateSelect.value) {
                    templateSelect.dispatchEvent(new Event('change'));
                }
            }

            // ── Flatpickr date/time picker — one_shot scheduled_at ──
            const scheduledAtInput = document.getElementById('scheduled_at');
            if (scheduledAtInput && typeof flatpickr !== 'undefined') {
                flatpickr(scheduledAtInput, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    time_24hr: true,
                    locale: 'fr',
                    minDate: 'today',
                });
            }

            // ── Flatpickr — recurring next_run_at ────────────────────
            const nextRunAtInput = document.getElementById('next_run_at');
            if (nextRunAtInput && typeof flatpickr !== 'undefined') {
                flatpickr(nextRunAtInput, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    time_24hr: true,
                    locale: 'fr',
                    minDate: 'today',
                });
            }

            // ── Flatpickr — recurring until (date only) ──────────────
            const recurrenceUntilInput = document.getElementById('recurrence_until');
            if (recurrenceUntilInput && typeof flatpickr !== 'undefined') {
                flatpickr(recurrenceUntilInput, {
                    enableTime: false,
                    dateFormat: 'Y-m-d',
                    locale: 'fr',
                    minDate: 'today',
                });
            }

            // ── Schedule type switcher ───────────────────────────────
            const scheduleTypeSelect   = document.getElementById('schedule_type_select');
            const fieldsOneShotEl      = document.getElementById('fields-one-shot');
            const fieldsRecurringEl    = document.getElementById('fields-recurring');
            const fieldsSequenceEl     = document.getElementById('fields-sequence');
            const fieldTemplateWrapper = document.getElementById('field-template-wrapper');
            const fieldSubjectWrapper  = document.getElementById('field-subject-wrapper');
            const fieldTimezoneWrapper = document.getElementById('field-timezone-wrapper');

            function applyScheduleMode(value) {
                if (!fieldsOneShotEl || !fieldsRecurringEl || !fieldsSequenceEl) return;
                const isSequence = value === 'sequence';
                fieldsOneShotEl.style.display  = value === 'one_shot'  ? '' : 'none';
                fieldsRecurringEl.style.display = value === 'recurring' ? '' : 'none';
                fieldsSequenceEl.style.display  = isSequence  ? '' : 'none';

                // W1: hide template + subject + timezone in sequence mode
                if (fieldTemplateWrapper) fieldTemplateWrapper.style.display = isSequence ? 'none' : '';
                if (fieldSubjectWrapper)  fieldSubjectWrapper.style.display  = isSequence ? 'none' : '';
                if (fieldTimezoneWrapper) fieldTimezoneWrapper.style.display = isSequence ? 'none' : '';
            }

            if (scheduleTypeSelect) {
                scheduleTypeSelect.addEventListener('change', function () {
                    applyScheduleMode(this.value);
                });
                // Apply on page load
                applyScheduleMode(scheduleTypeSelect.value);
            }

            // ── #5 Auto-preselect single active sequence ─────────────────────
            const sequenceSelect = document.getElementById('sequence_select');
            if (sequenceSelect) {
                const nonEmptyOptions = Array.from(sequenceSelect.options).filter(o => o.value !== '');
                if (nonEmptyOptions.length === 1 && !sequenceSelect.value) {
                    sequenceSelect.value = nonEmptyOptions[0].value;
                    // Trigger select2 update
                    if (window.jQuery) {
                        $(sequenceSelect).trigger('change');
                    } else {
                        sequenceSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }

            // ── W2 Sequence steps preview ─────────────────────────────────────
            const stepsPreview  = document.getElementById('sequence-steps-preview');
            const stepsList     = document.getElementById('sequence-steps-list');
            const seqEditLink   = document.getElementById('sequence-edit-link');
            const seqEditBaseUrl = '{{ url("admin/sequences") }}/';

            function renderStepsPreview(selectEl) {
                if (!stepsPreview || !stepsList) return;
                const selected = selectEl ? selectEl.options[selectEl.selectedIndex] : null;
                if (!selected || !selected.value) {
                    stepsPreview.style.display = 'none';
                    return;
                }

                const stepsData = selected.dataset.steps ? JSON.parse(selected.dataset.steps) : [];
                if (!stepsData.length) {
                    stepsPreview.style.display = 'none';
                    return;
                }

                // Compute cumulative days for timeline labels.
                // Build DOM nodes via createElement+textContent to prevent XSS —
                // step.subject / step.template_name are user-authored strings.
                let cumulativeDays = 0;
                const ul = document.createElement('ul');
                ul.className = 'list-unstyled mb-0';
                stepsData.forEach(function (step, idx) {
                    if (idx === 0) {
                        cumulativeDays = 0;
                    } else {
                        cumulativeDays += parseInt(step.delay_days || 0, 10);
                    }
                    const dayLabel = cumulativeDays === 0 ? 'Jour 0' : 'J+' + cumulativeDays;

                    const li = document.createElement('li');
                    li.className = 'd-flex align-items-center gap-2 mb-1 fs-7 text-muted';

                    const badge = document.createElement('span');
                    badge.className = 'badge badge-light-primary me-1';
                    badge.textContent = dayLabel;

                    const label = document.createElement('span');
                    label.textContent = step.subject || step.template_name || ('Étape ' + step.step_no);

                    li.appendChild(badge);
                    li.appendChild(label);
                    ul.appendChild(li);
                });

                stepsList.innerHTML = '';
                stepsList.appendChild(ul);
                stepsPreview.style.display = '';

                if (seqEditLink) {
                    seqEditLink.href = seqEditBaseUrl + selected.value + '/edit';
                }
            }

            if (sequenceSelect) {
                sequenceSelect.addEventListener('change', function () {
                    renderStepsPreview(this);
                });
                // Render on page load if a sequence is already selected
                if (sequenceSelect.value) {
                    renderStepsPreview(sequenceSelect);
                }
            }
        });
    </script>
    @endif
@endpush

</x-default-layout>
