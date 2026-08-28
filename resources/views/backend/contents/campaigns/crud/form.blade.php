<x-default-layout>

@section('title')
    {{ isset($model) && $model->id ? 'Modifier la campagne — ' . e($model->name) : 'Créer une campagne' }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Campagnes', 'route' => 'admin.campaigns.index'], ['label' => isset($model) && $model->id ? 'Modifier' : 'Nouvelle campagne']]" />
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

<style>
    .campaign-template-preview-popover {
        --bs-popover-max-width: min(560px, calc(100vw - 2rem));
        --bs-popover-border-color: var(--bs-primary-light, #e1e9ff);
        box-shadow: 0 1rem 3rem rgba(15, 23, 42, .16);
    }

    .campaign-template-preview-card {
        width: min(520px, calc(100vw - 3rem));
    }

    .campaign-template-preview-toolbar {
        background: linear-gradient(135deg, #eef6ff 0%, #f8f5ff 100%);
        border: 1px solid #edf2f7;
        border-radius: .85rem;
    }

    .campaign-template-preview-frame {
        height: 360px;
        border: 1px solid #e4e6ef;
        border-radius: .85rem;
        overflow: hidden;
        background: #f5f8fa;
    }

    .campaign-template-preview-frame iframe {
        width: 100%;
        height: 100%;
        border: 0;
        background: #fff;
    }
</style>

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
        @if($selectedCompany || $selectedSegment || $selectedTemplate)
            <div class="alert alert-primary d-flex align-items-center p-5 mb-6">
                <i class="bi bi-link-45deg fs-2hx text-primary me-4"></i>
                <div>
                    <div class="fw-bold">Contexte source conserve</div>
                    <div class="text-gray-700">
                        @if($selectedCompany)
                            Entreprise : {{ $selectedCompany->name }}
                        @elseif($selectedSegment)
                            Segment : {{ $selectedSegment->name }}
                        @elseif($selectedTemplate)
                            Modele : {{ $selectedTemplate->name }}
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ── Tab pane wrapper (edit mode: Général tab; create mode: bare content) ── --}}
    @if(isset($model) && $model->id)
    <div class="tab-content">
    <div class="tab-pane fade show active" id="campaign_general" role="tabpanel">
    @endif

    @php
        $deliveryLocked = isset($model) && $model->id && $model->deliverySettingsLocked();
        $selectedDeliveryChannel = old(
            'delivery_channel',
            isset($model) && $model->id ? ($model->delivery_channel ?? '') : 'zoho'
        );
        $selectedVerificationPolicy = old(
            'email_verification_policy',
            isset($model) && $model->id ? $model->emailVerificationPolicy() : $defaultEmailVerificationPolicy
        );
    @endphp

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
                                @if($deliveryLocked)
                                    <input type="hidden" name="sender_identity_id" value="{{ (int) $model->sender_identity_id }}">
                                @endif
                                <select name="sender_identity_id" class="form-select form-select-solid" data-control="select2" data-placeholder="Sélectionner une identité..." required {{ $deliveryLocked ? 'disabled' : '' }}>
                                    <option value="">Sélectionner une identité...</option>
                                    @foreach($senderIdentities as $identity)
                                        <option value="{{ $identity->id }}"
                                            {{ old('sender_identity_id', $model->sender_identity_id ?? $selectedSender?->id ?? '') == $identity->id ? 'selected' : '' }}>
                                            {{ $identity->name }} &lt;{{ $identity->email }}&gt;
                                            @if($identity->is_default) (défaut) @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2" for="delivery_channel">Canal d’envoi</label>
                                @if($deliveryLocked)
                                    <input type="hidden" name="delivery_channel" value="{{ (string) $model->delivery_channel }}">
                                @endif
                                <select name="delivery_channel" id="delivery_channel" class="form-select form-select-solid" data-control="select2" data-hide-search="true" {{ $deliveryLocked ? 'disabled' : '' }}>
                                    @if(isset($model) && $model->id && $model->delivery_channel === null)
                                        <option value="" selected>Mode historique ({{ $model->effectiveDeliveryChannel() === 'zoho' ? 'Zoho Campaigns' : 'mail local' }})</option>
                                    @endif
                                    <option value="zoho" {{ $selectedDeliveryChannel === 'zoho' ? 'selected' : '' }}>Zoho Campaigns (par défaut)</option>
                                    <option value="smtp" {{ $selectedDeliveryChannel === 'smtp' ? 'selected' : '' }}>SMTP direct progressif</option>
                                    <option value="mailjet" {{ $selectedDeliveryChannel === 'mailjet' ? 'selected' : '' }}>Mailjet</option>
                                </select>
                                <div class="form-text d-none" id="mailjet_sequence_hint">Mailjet : indisponible en mode séquence.</div>
                                @if($deliveryLocked)
                                    <div class="form-text text-warning">Le canal et l’expéditeur sont verrouillés car une livraison réelle a déjà commencé.</div>
                                @endif
                            </div>
                        </div>

                        <div class="col-lg-6" id="smtp_daily_limit_wrapper">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2" for="smtp_daily_email_limit">Objectif SMTP — emails par jour</label>
                                <input type="number" min="0" max="500" step="1"
                                       class="form-control form-control-solid"
                                       name="smtp_daily_email_limit"
                                       id="smtp_daily_email_limit"
                                       value="{{ old('smtp_daily_email_limit', $model->smtp_daily_email_limit ?? 20) }}">
                                <div class="form-text">20 par défaut. Utilisez 0 pour mettre l’envoi SMTP en pause sans changer de canal.</div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2" for="email_verification_policy">Politique de vérification email</label>
                                @if($deliveryLocked)
                                    <input type="hidden" name="email_verification_policy" value="{{ e($model->emailVerificationPolicy()) }}">
                                @endif
                                <select name="email_verification_policy" id="email_verification_policy" class="form-select form-select-solid" data-control="select2" data-hide-search="true" {{ $deliveryLocked ? 'disabled' : '' }}>
                                    @foreach($emailVerificationPolicies as $value => $definition)
                                        <option value="{{ $value }}" {{ $selectedVerificationPolicy === $value ? 'selected' : '' }}>{{ $definition['label'] }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">Le mode strict exige un résultat valide. Le mode étendu accepte les adresses non vérifiées, inconnues, accept-all, webmail ou personnelles, mais attend toujours la fin d'une vérification en cours.</div>
                                @if($deliveryLocked)
                                    <div class="form-text text-warning">Ce choix est verrouillé depuis la première livraison acceptée.</div>
                                @endif
                            </div>
                        </div>

                        <div class="col-12" id="smtp_guidance_card">
                            <div class="rounded border border-primary border-dashed p-6 bg-light-primary">
                                <div class="alert alert-primary mb-4">
                                    <strong>SMTP progressif :</strong> l’espacement réduit les rafales, mais ne garantit jamais l’onglet Principal ni la boîte de réception.
                                </div>
                                <div class="row g-4">
                                    <div class="col-lg-6">
                                        <div class="alert alert-success h-100 mb-0">
                                            <div class="fw-bold mb-2">Démarrage recommandé</div>
                                            <div>Jours 1–3 : <strong>15 emails/jour</strong>, maximum 3/heure.</div>
                                            <div>Jours 4–7 : <strong>20 emails/jour</strong>, maximum 4/heure.</div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="alert alert-warning h-100 mb-0">
                                            <div class="fw-bold mb-2">Montée progressive</div>
                                            <div>Semaine 2 : 30/jour, maximum 6/heure.</div>
                                            <div>Semaine 3+ : 40–50/jour, maximum 8–10/heure.</div>
                                            <div>Lundi–vendredi, heures ouvrées, répartition régulière sans rafale.</div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="alert alert-danger mb-0">
                                            <div class="fw-bold mb-2">À éviter absolument</div>
                                            <div>Ne jamais envoyer <strong>2 emails/minute</strong> (120/heure). Plafond initial : 50/jour par boîte.</div>
                                            <div>Mettez en pause vers plus de 2 % de hard bounces, ou dès la première plainte à ce volume.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 text-gray-800">
                                    <strong>Avant production :</strong> vérifier SPF, DKIM, DMARC, le désabonnement et un <code>APP_URL</code> public stable pour le suivi.
                                </div>
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
                                            {{ old('segment_id', $model->segment_id ?? $selectedSegment?->id ?? '') == $segment->id ? 'selected' : '' }}>
                                            {{ $segment->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="mt-2">
                                    <span id="segment-count-label" class="text-muted fs-7">
                                        @if(isset($model) && $model->segment_id)
                                            {{-- Server-rendered count for edit page --}}
                                        @else
                                            Sélectionnez un segment pour comparer les correspondants aux destinataires éligibles.
                                        @endif
                                    </span>
                                </div>
                                <div id="segment-readiness-warning"
                                     class="alert alert-warning align-items-start py-3 mt-3 mb-0 d-none"
                                     role="status">
                                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3 mt-1"></i>
                                    <span></span>
                                </div>
                                {{-- Audience language split (populated by JS when segment + template selected) --}}
                                <div id="audience-lang-split" class="mt-3 d-none">
                                    <div class="d-flex gap-2 flex-wrap mb-2" id="audience-lang-chips"></div>
                                    <div id="audience-lang-warning" class="alert alert-warning d-flex align-items-center py-3 d-none">
                                        <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-warning"></i>
                                        <div></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Modèle d'email (W1: hidden in sequence mode) --}}
                        <div class="col-lg-6" id="field-template-wrapper">
                            <div class="fv-row mb-7">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="required fw-semibold fs-6 mb-0" id="label-template">Modèle d'email</label>
                                    <button type="button"
                                            class="btn btn-icon btn-sm btn-light-primary rounded-circle"
                                            id="template-preview-button"
                                            aria-label="Aperçu du modèle d'email"
                                            data-bs-toggle="popover"
                                            disabled>
                                        <i class="bi bi-eye fs-5"></i>
                                    </button>
                                </div>
                                <select name="template_id" class="form-select form-select-solid" id="template_select" data-control="select2" data-placeholder="Sélectionner un modèle...">
                                    <option value="">Sélectionner un modèle...</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}"
                                                data-subject="{{ $template->subject }}"
                                            {{ old('template_id', $model->template_id ?? $selectedTemplate?->id ?? '') == $template->id ? 'selected' : '' }}>
                                            {{ $template->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text text-muted mt-2 fs-7" id="template-preview-hint">
                                    Sélectionnez un modèle puis cliquez sur l’icône œil pour prévisualiser l’email.
                                </div>
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

                        {{-- Fuseau horaire (used by scheduled sends and progressive sequences) --}}
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
                                        value="{{ old('scheduled_at', isset($model) && $model->scheduled_at ? $model->scheduled_at->copy()->setTimezone($model->scheduleTimezone())->format('Y-m-d H:i') : '') }}"
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
                                <label class="required fw-semibold fs-6 mb-2">Premier envoi</label>
                                <input type="text"
                                       name="next_run_at"
                                       id="next_run_at"
                                       class="form-control form-control-solid"
                                       placeholder="Date du premier envoi..."
                                        value="{{ old('next_run_at', isset($model) && $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('Y-m-d H:i') : '') }}"
                                       autocomplete="off" />
                                <div class="form-text text-muted mt-1 fs-7">
                                    Obligatoire pour activer une campagne récurrente.
                                </div>
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

                    {{-- paced fields --}}
                    <div id="fields-paced" class="row" style="display:none;">
                        <div class="col-lg-4">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Premier envoi</label>
                                <input type="text"
                                       name="paced_first_send_at"
                                       id="paced_first_send_at"
                                       class="form-control form-control-solid"
                                       placeholder="Date du premier lot..."
                                       value="{{ old('paced_first_send_at', isset($model) && $model->schedule_type === 'paced' && $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('Y-m-d H:i') : '') }}"
                                       autocomplete="off" />
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="fv-row mb-7">
                                <label class="required fw-semibold fs-6 mb-2">Sociétés par jour</label>
                                <input type="number"
                                       name="daily_company_limit"
                                       id="daily_company_limit"
                                       class="form-control form-control-solid"
                                       min="1"
                                       value="{{ old('daily_company_limit', isset($model) && $model->schedule_type === 'paced' ? ($model->daily_company_limit ?? 20) : 20) }}" />
                                <div class="form-text text-muted mt-1 fs-7">
                                    Tous les contacts éligibles des sociétés sélectionnées recevront l’e-mail. Le nombre d’e-mails peut dépasser le nombre de sociétés.
                                </div>
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
                                                {{ $seq->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                @endif

                                <div class="form-text text-muted mt-1 fs-7">
                                    <i class="bi bi-info-circle me-1"></i>
                                    La séquence détermine les étapes et leurs délais. Le mode d’inscription ci-contre détermine quand les contacts éligibles commencent l’étape 1.
                                </div>

                                {{-- Edit mode: enrolled count hint (consultant F5) --}}
                                @if(isset($model) && $model->id && $model->sequence_id)
                                    @php
                                        $enrolledHint = \App\Models\SequenceEnrollment::where('campaign_id', $model->id)->count();
                                    @endphp
                                    @if($enrolledHint > 0)
                                        <div class="mt-2">
                                            <span class="badge badge-light-info text-wrap text-start lh-base">
                                                <i class="bi bi-people me-1"></i>
                                                {{ $enrolledHint }} contact(s) déjà inscrits. Changer de séquence arrête le suivi automatique et exige un nouveau démarrage ; les parcours en cours restent inchangés.
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
                        <div class="col-lg-4">
                            <div class="fv-row mb-7">
                                <label class="fw-semibold fs-6 mb-2">Mode d’inscription</label>
                                <select name="sequence_enrollment_mode"
                                        id="sequence_enrollment_mode"
                                        class="form-select form-select-solid"
                                        data-control="select2"
                                        data-hide-search="true">
                                    @if(config('services.zoho.driver', 'local') === 'zoho')
                                        <option value="paced" selected>Progressif</option>
                                    @else
                                    <option value="immediate" {{ old('sequence_enrollment_mode', $model->sequence_enrollment_mode ?? 'immediate') === 'immediate' ? 'selected' : '' }}>Tous immédiatement</option>
                                    <option value="paced" {{ old('sequence_enrollment_mode', $model->sequence_enrollment_mode ?? 'immediate') === 'paced' ? 'selected' : '' }}>Progressif</option>
                                    @endif
                                </select>
                            </div>
                        </div>
                        <div class="col-12">
                            <div id="fields-sequence-paced" class="row" style="display:none;">
                                <div class="col-lg-4">
                                    <div class="fv-row mb-7">
                                        <label class="required fw-semibold fs-6 mb-2">Premier lot</label>
                                        <input type="text"
                                               name="sequence_first_batch_at"
                                               id="sequence_first_batch_at"
                                               class="form-control form-control-solid"
                                               placeholder="Date du premier lot..."
                                               value="{{ old('sequence_first_batch_at', isset($model) && $model->schedule_type === 'sequence' && $model->sequence_enrollment_mode === 'paced' && $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('Y-m-d H:i') : '') }}"
                                               autocomplete="off" />
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="fv-row mb-7">
                                        <label class="required fw-semibold fs-6 mb-2">Sociétés par jour</label>
                                        <input type="number"
                                               name="sequence_daily_company_limit"
                                               id="sequence_daily_company_limit"
                                               class="form-control form-control-solid"
                                               min="1"
                                               value="{{ old('sequence_daily_company_limit', isset($model) && $model->schedule_type === 'sequence' && $model->sequence_enrollment_mode === 'paced' ? ($model->daily_company_limit ?? 20) : 20) }}" />
                                        <div class="form-text text-muted mt-1 fs-7">
                                            Chaque jour ouvré, toutes les personnes éligibles des sociétés retenues commencent ensemble à l’étape 1.
                                        </div>
                                        @if(isset($model) && $model->id)
                                            <div id="next-wave-preview"
                                                 class="form-text text-muted mt-3 fs-7"
                                                 aria-live="polite"
                                                 data-url="{{ route('admin.campaigns.nextWavePreview', $model->id) }}">
                                                <span class="spinner-border spinner-border-sm me-1"></span>
                                                Calcul de la prochaine vague...
                                            </div>
                                        @endif
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
                        <label class="fw-semibold fs-6 mb-1">Participation à l’automatisation</label>
                        <div class="text-muted fs-7">
                            Une campagne active peut être examinée par l’automatisation. Ce statut ne garantit pas que l’envoi est prêt : la vérification de l’audience, du planning et de l’expéditeur reste obligatoire.
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
                                Campagne active
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
            const deliverySelect = document.getElementById('delivery_channel');
            const smtpLimitWrapper = document.getElementById('smtp_daily_limit_wrapper');
            const smtpGuidanceCard = document.getElementById('smtp_guidance_card');
            const mailjetSequenceHint = document.getElementById('mailjet_sequence_hint');
            const toggleSmtpDeliveryFields = () => {
                const visible = deliverySelect?.value === 'smtp';
                smtpLimitWrapper?.classList.toggle('d-none', !visible);
                smtpGuidanceCard?.classList.toggle('d-none', !visible);
                mailjetSequenceHint?.classList.toggle('d-none', deliverySelect?.value !== 'mailjet');
            };
            deliverySelect?.addEventListener('change', toggleSmtpDeliveryFields);
            toggleSmtpDeliveryFields();

            const campaignTemplatePreviews = {!! \Illuminate\Support\Js::from($templates->mapWithKeys(function ($template) {
                return [
                    (string) $template->id => [
                        'id'           => $template->id,
                        'name'         => $template->name,
                        'subject'      => $template->subject,
                        'preview_text' => $template->preview_text,
                        'html_content' => $template->html_content,
                        'edit_url'     => route('admin.campaign_templates.edit', $template->id),
                    ],
                ];
            })) !!};

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
            const verificationPolicySelect = document.getElementById('email_verification_policy');
            const countLabel    = document.getElementById('segment-count-label');
            const readinessWarning = document.getElementById('segment-readiness-warning');

            const hideReadinessWarning = function () {
                if (!readinessWarning) return;
                readinessWarning.classList.add('d-none');
                readinessWarning.classList.remove('d-flex');
                const text = readinessWarning.querySelector('span');
                if (text) text.textContent = '';
            };

            const showReadinessWarning = function (message) {
                if (!readinessWarning) return;
                const text = readinessWarning.querySelector('span');
                if (text) text.textContent = message;
                readinessWarning.classList.remove('d-none');
                readinessWarning.classList.add('d-flex');
            };

            if (segmentSelect && countLabel) {
                const fetchCount = function (segmentId) {
                    hideReadinessWarning();
                    if (!segmentId) {
                        countLabel.innerHTML = 'Sélectionnez un segment pour comparer les correspondants aux destinataires éligibles.';
                        return;
                    }
                    countLabel.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calcul en cours...';

                    axios.get('{{ route("admin.campaigns.segmentCount", "") }}/' + segmentId, {
                        params: {email_verification_policy: verificationPolicySelect?.value || 'verified_only'}
                    })
                        .then(function (r) {
                            const data = r.data || {};
                            const matched = Number(data.matched_count || 0);
                            const eligible = Number(data.contacts_count || 0);
                            const companies = Number(data.company_count || 0);

                            countLabel.innerHTML = '<span class="badge badge-light-secondary">' + matched + ' correspondant(s)</span> · '
                                + '<span class="badge badge-light-primary">' + eligible + ' destinataire(s) éligible(s)</span> dans '
                                + '<span class="badge badge-light-info">' + companies + ' société(s)</span>.';

                            if (eligible === 0) {
                                showReadinessWarning('Aucun destinataire n’est éligible actuellement. Vous pouvez enregistrer la campagne, mais la programmation et l’envoi resteront bloqués par la vérification.');
                            }
                        })
                        .catch(function (error) {
                            const message = error?.response?.data?.message || 'Le calcul de l’audience est momentanément indisponible. Réessayez.';
                            countLabel.textContent = message;
                            showReadinessWarning('Aucun nombre de destinataires n’est affiché tant que le calcul n’a pas abouti.');
                        });
                };

                segmentSelect.addEventListener('change', function () {
                    fetchCount(this.value);
                });
                verificationPolicySelect?.addEventListener('change', function () {
                    fetchCount(segmentSelect.value);
                });

                // Trigger on page load if a segment is already selected (edit page)
                if (segmentSelect.value) {
                    fetchCount(segmentSelect.value);
                }
            }

            // ── Next progressive-sequence wave preview ───────────────────────
            const nextWavePreview = document.getElementById('next-wave-preview');
            const dailyLimitInput = document.getElementById('sequence_daily_company_limit');
            let nextWaveRequest = 0;
            let nextWaveDebounce = null;

            function scheduleNextWavePreview() {
                if (!nextWavePreview) return;
                ++nextWaveRequest;
                window.clearTimeout(nextWaveDebounce);
                nextWaveDebounce = window.setTimeout(refreshNextWavePreview, 400);
            }

            function refreshNextWavePreview() {
                if (!nextWavePreview) return;

                const requestId = nextWaveRequest;
                const isPacedSequence = document.getElementById('schedule_type_select')?.value === 'sequence'
                    && document.getElementById('sequence_enrollment_mode')?.value === 'paced';

                if (!isPacedSequence) {
                    nextWavePreview.classList.add('d-none');
                    return;
                }

                nextWavePreview.classList.remove('d-none');

                const segmentId = segmentSelect?.value;
                const dailyLimit = Number.parseInt(dailyLimitInput?.value ?? '', 10);
                if (!segmentId || !Number.isInteger(dailyLimit) || dailyLimit < 1) {
                    nextWavePreview.textContent = 'Sélectionnez un segment et une limite quotidienne valide.';
                    return;
                }

                nextWavePreview.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Calcul de la prochaine vague...';

                axios.get(nextWavePreview.dataset.url, {
                    params: {
                        segment_id: segmentId,
                        daily_company_limit: dailyLimit,
                        email_verification_policy: verificationPolicySelect?.value || 'verified_only',
                    },
                }).then(function (response) {
                    if (requestId !== nextWaveRequest) return;

                    const data = response.data;
                    if (!data.is_sequence_paced) {
                        nextWavePreview.classList.add('d-none');
                        return;
                    }
                    if (data.eligible_remaining_companies === 0) {
                        nextWavePreview.textContent = 'Aucune entreprise éligible.';
                        return;
                    }

                    nextWavePreview.innerHTML =
                        'Prochaine vague : <strong>' + data.next_wave_companies + ' entreprise(s)</strong> (' +
                        data.next_wave_contacts + ' contact(s)) · Restant : ' +
                        data.eligible_remaining_companies + ' entreprise(s) (~' +
                        data.projected_remaining_waves + ' vague(s)) · Prochaine exécution : ' +
                        (data.next_run_at || 'non définie');
                }).catch(function () {
                    if (requestId !== nextWaveRequest) return;
                    nextWavePreview.textContent = 'Impossible de calculer la prochaine vague.';
                });
            }

            if (segmentSelect && nextWavePreview) {
                segmentSelect.addEventListener('change', scheduleNextWavePreview);
            }
            if (dailyLimitInput && nextWavePreview) {
                dailyLimitInput.addEventListener('input', scheduleNextWavePreview);
            }
            verificationPolicySelect?.addEventListener('change', scheduleNextWavePreview);
            scheduleNextWavePreview();

            // ── Auto-fill subject hint from template ────────────────
            const templateSelect  = document.getElementById('template_select');
            const subjectOverride = document.getElementById('subject_override');
            const templatePreviewButton = document.getElementById('template-preview-button');
            const templatePreviewHint = document.getElementById('template-preview-hint');
            let templatePreviewPopover = null;

            function selectedTemplatePreview() {
                if (!templateSelect || !templateSelect.value) return null;
                return campaignTemplatePreviews[String(templateSelect.value)] || null;
            }

            function emailPreviewDocument(template) {
                const body = template.html_content || '<p style="color:#7e8299;font-family:Arial,sans-serif;">Ce modèle ne contient pas encore de contenu HTML.</p>';

                return '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">' +
                    '<style>' +
                    'html,body{margin:0;padding:0;background:#f5f8fa;color:#181c32;font-family:Arial,Helvetica,sans-serif;}' +
                    '.email-shell{max-width:700px;margin:0 auto;padding:18px;}' +
                    '.email-card{background:#fff;border-radius:14px;box-shadow:0 8px 28px rgba(15,23,42,.08);padding:22px;}' +
                    'img{max-width:100%;height:auto;}a{color:#009ef7;}table{max-width:100%;}' +
                    '</style></head><body><div class="email-shell"><div class="email-card">' + body +
                    '</div></div></body></html>';
            }

            function buildTemplatePreviewContent(template) {
                const wrapper = document.createElement('div');
                wrapper.className = 'campaign-template-preview-card';

                const toolbar = document.createElement('div');
                toolbar.className = 'campaign-template-preview-toolbar p-4 mb-4';

                const titleRow = document.createElement('div');
                titleRow.className = 'd-flex align-items-start gap-3';

                const icon = document.createElement('span');
                icon.className = 'symbol symbol-40px symbol-circle flex-shrink-0';
                const iconLabel = document.createElement('span');
                iconLabel.className = 'symbol-label bg-primary text-white';
                const iconInner = document.createElement('i');
                iconInner.className = 'bi bi-envelope-paper-heart fs-3 text-white';
                iconLabel.appendChild(iconInner);
                icon.appendChild(iconLabel);

                const meta = document.createElement('div');
                meta.className = 'flex-grow-1 min-w-0';
                const name = document.createElement('div');
                name.className = 'fw-bold text-gray-900 fs-6 text-truncate';
                name.textContent = template.name || 'Modèle sans nom';
                const subject = document.createElement('div');
                subject.className = 'mt-1 fs-7 text-gray-700';
                subject.textContent = template.subject ? ('Sujet : ' + template.subject) : 'Aucun sujet renseigné';
                meta.appendChild(name);
                meta.appendChild(subject);

                titleRow.appendChild(icon);
                titleRow.appendChild(meta);
                toolbar.appendChild(titleRow);

                if (template.preview_text) {
                    const preheader = document.createElement('div');
                    preheader.className = 'badge badge-light-info text-wrap text-start mt-3 px-3 py-2';
                    preheader.textContent = 'Pré-en-tête : ' + template.preview_text;
                    toolbar.appendChild(preheader);
                }

                wrapper.appendChild(toolbar);

                const frameWrap = document.createElement('div');
                frameWrap.className = 'campaign-template-preview-frame';
                const iframe = document.createElement('iframe');
                iframe.setAttribute('title', 'Aperçu du modèle d\'email');
                iframe.setAttribute('sandbox', '');
                iframe.setAttribute('referrerpolicy', 'no-referrer');
                iframe.srcdoc = emailPreviewDocument(template);
                frameWrap.appendChild(iframe);
                wrapper.appendChild(frameWrap);

                const footer = document.createElement('div');
                footer.className = 'd-flex justify-content-between align-items-center mt-3';
                const note = document.createElement('span');
                note.className = 'text-muted fs-8';
                note.textContent = 'Aperçu indicatif avant personnalisation des variables.';
                footer.appendChild(note);
                if (template.edit_url) {
                    const link = document.createElement('a');
                    link.className = 'btn btn-sm btn-light-primary';
                    link.href = template.edit_url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = 'Ouvrir le modèle';
                    footer.appendChild(link);
                }
                wrapper.appendChild(footer);

                return wrapper;
            }

            function disposeTemplatePreviewPopover() {
                if (templatePreviewPopover) {
                    templatePreviewPopover.dispose();
                    templatePreviewPopover = null;
                }
            }

            function ensureTemplatePreviewPopover(template) {
                if (!templatePreviewButton || typeof bootstrap === 'undefined' || !bootstrap.Popover) return null;
                disposeTemplatePreviewPopover();
                templatePreviewPopover = new bootstrap.Popover(templatePreviewButton, {
                    container: 'body',
                    html: true,
                    sanitize: false,
                    trigger: 'manual',
                    placement: 'left',
                    customClass: 'campaign-template-preview-popover',
                    title: 'Aperçu du modèle',
                    content: function () {
                        return buildTemplatePreviewContent(template);
                    },
                });
                return templatePreviewPopover;
            }

            function updateTemplatePreviewState() {
                if (!templatePreviewButton) return;
                const template = selectedTemplatePreview();
                disposeTemplatePreviewPopover();

                if (!template) {
                    templatePreviewButton.disabled = true;
                    templatePreviewButton.classList.add('btn-light-primary');
                    templatePreviewButton.classList.remove('btn-primary');
                    if (templatePreviewHint) {
                        templatePreviewHint.textContent = 'Sélectionnez un modèle puis cliquez sur l’icône œil pour prévisualiser l’email.';
                    }
                    return;
                }

                templatePreviewButton.disabled = false;
                templatePreviewButton.classList.remove('btn-light-primary');
                templatePreviewButton.classList.add('btn-primary');
                if (templatePreviewHint) {
                    templatePreviewHint.textContent = 'Aperçu disponible : ' + (template.name || 'modèle sélectionné') + '.';
                }
            }

            if (templatePreviewButton) {
                templatePreviewButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    const template = selectedTemplatePreview();
                    if (!template) return;

                    const popover = templatePreviewPopover || ensureTemplatePreviewPopover(template);
                    if (popover) {
                        popover.toggle();
                    }
                });

                document.addEventListener('click', function (event) {
                    if (!templatePreviewPopover || !templatePreviewButton) return;
                    const popoverEl = document.querySelector('.campaign-template-preview-popover');
                    if (templatePreviewButton.contains(event.target) || (popoverEl && popoverEl.contains(event.target))) return;
                    templatePreviewPopover.hide();
                });
            }

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

            if (templateSelect) {
                templateSelect.addEventListener('change', updateTemplatePreviewState);
                updateTemplatePreviewState();
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

            const pacedFirstSendInput = document.getElementById('paced_first_send_at');
            if (pacedFirstSendInput && typeof flatpickr !== 'undefined') {
                flatpickr(pacedFirstSendInput, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    time_24hr: true,
                    locale: 'fr',
                    minDate: 'today',
                });
            }

            const sequenceFirstBatchInput = document.getElementById('sequence_first_batch_at');
            if (sequenceFirstBatchInput && typeof flatpickr !== 'undefined') {
                flatpickr(sequenceFirstBatchInput, {
                    enableTime: true,
                    dateFormat: 'Y-m-d H:i',
                    time_24hr: true,
                    locale: 'fr',
                    minDate: 'today',
                });
            }

            // ── Schedule type switcher ───────────────────────────────
            const scheduleTypeSelect   = document.getElementById('schedule_type_select');
            const fieldsOneShotEl      = document.getElementById('fields-one-shot');
            const fieldsRecurringEl    = document.getElementById('fields-recurring');
            const fieldsPacedEl         = document.getElementById('fields-paced');
            const fieldsSequenceEl     = document.getElementById('fields-sequence');
            const fieldTemplateWrapper = document.getElementById('field-template-wrapper');
            const fieldSubjectWrapper  = document.getElementById('field-subject-wrapper');
            const fieldTimezoneWrapper = document.getElementById('field-timezone-wrapper');
            const sequenceModeSelect = document.getElementById('sequence_enrollment_mode');
            const fieldsSequencePacedEl = document.getElementById('fields-sequence-paced');

            function applySequenceEnrollmentMode() {
                const isPacedSequence = scheduleTypeSelect?.value === 'sequence'
                    && sequenceModeSelect?.value === 'paced';
                if (fieldsSequencePacedEl) fieldsSequencePacedEl.style.display = isPacedSequence ? '' : 'none';
                if (fieldTimezoneWrapper && scheduleTypeSelect?.value === 'sequence') {
                    fieldTimezoneWrapper.style.display = isPacedSequence ? '' : 'none';
                }
                scheduleNextWavePreview();
            }

            function applyScheduleMode(value) {
                if (!fieldsOneShotEl || !fieldsRecurringEl || !fieldsPacedEl || !fieldsSequenceEl) return;
                const isSequence = value === 'sequence';
                fieldsOneShotEl.style.display  = value === 'one_shot'  ? '' : 'none';
                fieldsRecurringEl.style.display = value === 'recurring' ? '' : 'none';
                fieldsPacedEl.style.display = value === 'paced' ? '' : 'none';
                fieldsSequenceEl.style.display  = isSequence  ? '' : 'none';

                // W1: hide template + subject + timezone in sequence mode
                if (fieldTemplateWrapper) fieldTemplateWrapper.style.display = isSequence ? 'none' : '';
                if (fieldSubjectWrapper)  fieldSubjectWrapper.style.display  = isSequence ? 'none' : '';
                if (fieldTimezoneWrapper) fieldTimezoneWrapper.style.display = isSequence ? 'none' : '';
                applySequenceEnrollmentMode();
            }

            if (sequenceModeSelect) {
                sequenceModeSelect.addEventListener('change', applySequenceEnrollmentMode);
                applySequenceEnrollmentMode();
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
                    // Automatic single-sequence selection is part of the clean initial state.
                    document.getElementById('form_crud')?.dispatchEvent(new CustomEvent('crud:form-saved', { bubbles: true }));
                }
            }

            // ── Audience language split ───────────────────────────────────────
            const langSplitUrl     = '{{ route("admin.campaigns.audienceLanguageSplit") }}';
            const csrfToken        = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const audienceSplitEl  = document.getElementById('audience-lang-split');
            const audienceChipsEl  = document.getElementById('audience-lang-chips');
            const audienceWarnEl   = document.getElementById('audience-lang-warning');

            let langSplitSeq = 0;    // stale-response guard
            let langSplitXhr = null; // pending XHR abort guard

            function refreshAudienceLangSplit() {
                if (!audienceSplitEl || !audienceChipsEl || !audienceWarnEl) return;

                const segId  = segmentSelect ? segmentSelect.value : '';
                const tplId  = templateSelect ? templateSelect.value : '';

                if (!segId) {
                    audienceSplitEl.classList.add('d-none');
                    audienceChipsEl.innerHTML = '';
                    audienceWarnEl.classList.add('d-none');
                    return;
                }

                // Abort any in-flight request
                if (langSplitXhr) {
                    langSplitXhr.abort();
                    langSplitXhr = null;
                }

                const seq = ++langSplitSeq;

                const body = new URLSearchParams();
                body.append('segment_id', segId);
                if (tplId) body.append('template_id', tplId);
                body.append('email_verification_policy', verificationPolicySelect?.value || 'verified_only');

                const xhr = new XMLHttpRequest();
                langSplitXhr = xhr;
                xhr.open('POST', langSplitUrl, true);
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                xhr.onload = function () {
                    if (seq !== langSplitSeq) return; // stale
                    langSplitXhr = null;

                    if (xhr.status !== 200) return;

                    let resp;
                    try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }

                    if (resp.error) return;

                    // Render chips
                    let chipsHtml = '<span class="badge badge-light-primary">' + resp.fr + ' 🇫🇷 FR</span>';
                    if (resp.en > 0) {
                        chipsHtml += ' <span class="badge badge-light-info">' + resp.en + ' 🇬🇧 EN</span>';
                    }
                    if (resp.unknown > 0) {
                        chipsHtml += ' <span class="badge badge-light-secondary">' + resp.unknown + ' inconnu → FR</span>';
                    }
                    audienceChipsEl.innerHTML = chipsHtml;
                    audienceSplitEl.classList.remove('d-none');

                    // Render warning
                    if (resp.warning) {
                        const detail = resp.has_en ? 'obsolète' : 'absente';
                        let msg = "L’audience contient " + resp.en + " destinataire(s) anglophone(s) mais la traduction EN du modèle est " + detail + ".";
                        if (resp.template_edit_url) {
                            msg += ' <a href="' + resp.template_edit_url + '" class="fw-bold ms-1">Mettre à jour la traduction</a>';
                        }
                        const warnBody = audienceWarnEl.querySelector('div');
                        if (warnBody) warnBody.innerHTML = msg;
                        audienceWarnEl.classList.remove('d-none');
                    } else {
                        audienceWarnEl.classList.add('d-none');
                    }
                };

                xhr.onerror = function () {
                    if (seq !== langSplitSeq) return;
                    langSplitXhr = null;
                };

                xhr.send(body.toString());
            }

            // Bind to segment + template change
            if (segmentSelect) {
                segmentSelect.addEventListener('change', refreshAudienceLangSplit);
            }
            if (templateSelect) {
                templateSelect.addEventListener('change', refreshAudienceLangSplit);
            }
            verificationPolicySelect?.addEventListener('change', refreshAudienceLangSplit);

            // Trigger on load if both already selected (edit mode)
            if (segmentSelect && segmentSelect.value && templateSelect && templateSelect.value) {
                refreshAudienceLangSplit();
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
