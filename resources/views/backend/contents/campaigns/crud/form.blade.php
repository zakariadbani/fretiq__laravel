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

{{--
    Campaign Builder — single-page form (MVP choice).
    A multi-step stepper (KT Stepper) is marked as a future enhancement.
    Approach: clear section cards, AJAX submit via crud-form-handler.js.
--}}

<form method="POST" action="{{ $route }}" class="form" id="form_crud">
    @csrf
    @if(isset($model) && $model->id)
        @method('PUT')
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
                                <select name="sender_identity_id" class="form-select form-select-solid" required>
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

                        {{-- Modèle d'email --}}
                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Modèle d'email</label>
                                <select name="template_id" class="form-select form-select-solid" id="template_select" required>
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

                        {{-- Sujet (override optionnel) --}}
                        <div class="col-lg-12">
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
                                <select name="schedule_type" class="form-select form-select-solid" id="schedule_type_select">
                                    @foreach($scheduleTypes as $key => $data)
                                        <option value="{{ $key }}"
                                            {{ old('schedule_type', $model->schedule_type ?? 'one_shot') === $key ? 'selected' : '' }}>
                                            {{ $data['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Fuseau horaire (always shown) --}}
                        <div class="col-lg-4">
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
                                <select name="recurrence_frequency" class="form-select form-select-solid">
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
                                <select name="sequence_id" class="form-select form-select-solid">
                                    <option value="">Sélectionner une séquence...</option>
                                    @foreach($sequences as $seq)
                                        <option value="{{ $seq->id }}"
                                            {{ old('sequence_id') == $seq->id ? 'selected' : '' }}>
                                            {{ e($seq->name) }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text text-muted mt-1 fs-7">
                                    <i class="bi bi-info-circle me-1"></i>
                                    En mode séquence, l'inscription des contacts est gérée depuis le module Séquences.
                                    Le choix ici enregistre uniquement l'association — aucun envoi automatique n'est déclenché depuis ce formulaire.
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
                @can('view campaigns')
                    <a href="{{ route('admin.campaigns.index') }}" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-2"></i>
                        Annuler
                    </a>
                @endcan

                <button type="submit" class="btn btn-primary submit" id="submit_btn">
                    <span class="indicator-label">
                        <i class="bi bi-check-circle me-2"></i>
                        Enregistrer le brouillon
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

    @if(class_exists(\App\Services\Campaign\SegmentService::class))
    <script>
        document.addEventListener('DOMContentLoaded', function () {

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
            const scheduleTypeSelect = document.getElementById('schedule_type_select');
            const fieldsOneShotEl   = document.getElementById('fields-one-shot');
            const fieldsRecurringEl = document.getElementById('fields-recurring');
            const fieldsSequenceEl  = document.getElementById('fields-sequence');

            function applyScheduleMode(value) {
                if (!fieldsOneShotEl || !fieldsRecurringEl || !fieldsSequenceEl) return;
                fieldsOneShotEl.style.display  = value === 'one_shot'  ? '' : 'none';
                fieldsRecurringEl.style.display = value === 'recurring' ? '' : 'none';
                fieldsSequenceEl.style.display  = value === 'sequence'  ? '' : 'none';
            }

            if (scheduleTypeSelect) {
                scheduleTypeSelect.addEventListener('change', function () {
                    applyScheduleMode(this.value);
                });
                // Apply on page load
                applyScheduleMode(scheduleTypeSelect.value);
            }
        });
    </script>
    @endif
@endpush

</x-default-layout>
