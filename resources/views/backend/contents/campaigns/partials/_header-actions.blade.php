{{--
    Campaign hero action buttons.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model (Campaign), $isView (bool)

    "Retour à la liste" lives in the top toolbar on both pages.
    "Modifier" is view-only; operational actions are shared by both pages.
    All @can gates are kept exactly as in the original view.blade.php.
    The shared JS handlers are pushed once from this partial.
--}}

@if($isView)

    @can('edit campaigns')
        <a href="{{ route('admin.campaigns.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan
@endif

    @can('send campaigns')
        <button type="button"
                class="btn btn-sm fw-bold btn-light-primary"
                id="btn-sync-zoho-list"
                data-url="{{ route('admin.campaigns.syncZohoList', $model->id) }}">
            <i class="bi bi-people me-1"></i>
            Ajouter et vérifier la liste Zoho
        </button>
    @endcan

    @can('send campaigns')
        {{-- Planifier: hidden for sequence campaigns (drip cadence ignores scheduling) --}}
        @if($model->schedule_type !== 'sequence')
        <button type="button"
                class="btn btn-sm fw-bold btn-info"
                id="btn-schedule"
                data-campaign-id="{{ $model->id }}"
                data-schedule-type="{{ $model->schedule_type }}"
                data-daily-company-limit="{{ $model->pacedDailyCompanyLimit() }}"
                data-url="{{ route('admin.campaigns.schedule', $model->id) }}"
                data-preview-url="{{ route('admin.campaigns.dispatchPreview', ['id' => $model->id, 'action' => 'schedule']) }}">
            <i class="bi bi-calendar-check me-1"></i>
            Planifier
        </button>
        @endif

        <button type="button"
                class="btn btn-sm fw-bold btn-success"
                id="btn-send-now"
                data-campaign-id="{{ $model->id }}"
                data-schedule-type="{{ $model->schedule_type }}"
                data-sequence-enrollment-mode="{{ $model->sequence_enrollment_mode }}"
                data-daily-company-limit="{{ $model->pacedDailyCompanyLimit() }}"
                data-next-batch-at="{{ $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') : '' }}"
                data-url="{{ route('admin.campaigns.sendNow', $model->id) }}"
                data-preview-url="{{ route('admin.campaigns.dispatchPreview', $model->id) }}">
            @if($model->schedule_type === 'paced')
                <i class="bi bi-send me-1"></i>
                Envoyer le lot du jour
            @elseif($model->schedule_type === 'sequence')
                @if($model->sequence_enrollment_mode === 'paced' && $model->sequence_auto_enroll_enabled)
                    <i class="bi bi-calendar2-check me-1"></i>
                    Vérifier le lot · {{ $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('d/m H:i') : 'date à définir' }}
                @elseif($model->sequence_enrollment_mode === 'paced')
                    <i class="bi bi-play-circle me-1"></i>
                    Activer l’inscription progressive
                @elseif($model->sequence_auto_enroll_enabled)
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Synchroniser maintenant
                @else
                    <i class="bi bi-play-circle me-1"></i>
                    Démarrer la séquence
                @endif
            @else
                <i class="bi bi-send me-1"></i>
                Envoyer maintenant
            @endif
        </button>
    @endcan

@once
@push('scripts')
<script data-campaign-action-handlers>
        document.addEventListener('DOMContentLoaded', function () {
            const hasUnsavedCampaignChanges = function () {
                const form = document.getElementById('form_crud');
                return form
                    && form.dataset.cleanSnapshot !== undefined
                    && form.dataset.cleanSnapshot !== new URLSearchParams(new FormData(form)).toString();
            };

            const warnUnsavedCampaignChanges = function () {
                return Swal.fire({
                    icon: 'warning',
                    title: 'Modifications non enregistrées',
                    text: 'Enregistrez la campagne avant de lancer cette action.',
                    buttonsStyling: false,
                    confirmButtonText: 'OK',
                    customClass: { confirmButton: 'btn btn-primary' },
                });
            };

            // ── Schedule button ──────────────────────────────────────────────
            const campaignActionErrorMessage = function (error) {
                const data = error.response?.data || {};
                if (typeof data.text === 'string' && data.text.trim() !== '') return data.text;
                if (typeof data.message === 'string' && data.message.trim() !== '') return data.message;
                if (Array.isArray(data.messages) && data.messages.length > 0) return data.messages.join(' ');
                return error.message || 'Impossible de vérifier l’audience finale.';
            };

            const previewDispatch = function (button) {
                return axios.get(button.dataset.previewUrl).then(function (response) {
                    return response.data;
                });
            };

            const postCampaignAction = function (button) {
                return axios.post(button.dataset.url, { _token: '{{ csrf_token() }}' })
                    .then(function (r) {
                        if (r.data.redirect) {
                            window.location.replace(r.data.redirect);
                        } else {
                            window.location.reload();
                        }
                    });
            };

            const showBlocked = function (message) {
                return Swal.fire({
                    icon: 'error',
                    title: 'Envoi bloqué',
                    text: message,
                    buttonsStyling: false,
                    confirmButtonText: 'OK',
                    customClass: { confirmButton: 'btn btn-primary' },
                });
            };

            const btnSchedule = document.getElementById('btn-schedule');
            if (btnSchedule) {
                btnSchedule.addEventListener('click', function () {
                    if (hasUnsavedCampaignChanges()) {
                        warnUnsavedCampaignChanges();
                        return;
                    }

                    const self = this;
                    const isPaced = (self.dataset.scheduleType || '') === 'paced';
                    self.disabled = true;

                    previewDispatch(self)
                        .then(function (preview) {
                            return Swal.fire({
                                title: isPaced ? 'Activer l’envoi progressif ?' : 'Planifier la campagne ?',
                                html: isPaced
                                    ? 'Audience actuelle : <strong>' + preview.company_count + '</strong> société(s), <strong>' + preview.contact_count + '</strong> contact(s).<br>Cette action active les lots continus des jours ouvrés ; elle ne met pas toute la campagne en file d’attente.'
                                    : 'Audience vérifiée : <strong>' + preview.count + '</strong> destinataire(s) éligible(s).<br>La campagne sera placée en file d’attente pour l’envoi à la date planifiée.',
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonText: 'Planifier',
                                cancelButtonText: 'Annuler',
                                buttonsStyling: false,
                                customClass: {
                                    confirmButton: 'btn btn-info me-2',
                                    cancelButton: 'btn btn-light',
                                },
                            });
                        })
                        .then(function (result) {
                            if (result && result.isConfirmed) {
                                return postCampaignAction(self);
                            }
                        })
                        .catch(function (error) { showBlocked(campaignActionErrorMessage(error)); })
                        .finally(function () { self.disabled = false; });
                });
            }
            const btnSyncZohoList = document.getElementById('btn-sync-zoho-list');
            if (btnSyncZohoList) {
                btnSyncZohoList.addEventListener('click', function () {
                    if (hasUnsavedCampaignChanges()) {
                        warnUnsavedCampaignChanges();
                        return;
                    }

                    const self = this;
                    self.disabled = true;

                    Swal.fire({
                        title: 'Ajouter et vérifier la liste Zoho ?',
                        html: 'Les contacts conformes de la campagne seront ajoutés à sa liste Zoho dédiée, puis leur présence sera vérifiée.<br>Les contacts déjà présents seront conservés.<br><strong>Aucune campagne Zoho ne sera créée ni envoyée.</strong>',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Ajouter et vérifier',
                        cancelButtonText: 'Annuler',
                        buttonsStyling: false,
                        customClass: {
                            confirmButton: 'btn btn-primary me-2',
                            cancelButton: 'btn btn-light',
                        },
                    }).then((result) => {
                        if (! result.isConfirmed) {
                            self.disabled = false;
                            return;
                        }

                        axios.post(self.dataset.url, { _token: '{{ csrf_token() }}' })
                            .then(function (response) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Ajouts Zoho vérifiés',
                                    text: response.data.message,
                                    buttonsStyling: false,
                                    confirmButtonText: 'OK',
                                    customClass: { confirmButton: 'btn btn-primary' },
                                }).then(() => window.location.reload());
                            })
                            .catch(function (error) {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Préparation bloquée',
                                    text: error.response?.data?.message || 'Impossible de vérifier les ajouts à la liste Zoho. Aucun envoi n’a été créé.',
                                    buttonsStyling: false,
                                    confirmButtonText: 'OK',
                                    customClass: { confirmButton: 'btn btn-primary' },
                                });
                            })
                            .finally(function () { self.disabled = false; });
                    });
                });
            }

            // ── Send now button ──────────────────────────────────────────────
            const btnSendNow = document.getElementById('btn-send-now');
            if (btnSendNow) {
                btnSendNow.addEventListener('click', function () {
                    if (hasUnsavedCampaignChanges()) {
                        warnUnsavedCampaignChanges();
                        return;
                    }

                    const self = this;
                    const scheduleType = self.dataset.scheduleType || '';
                    const isSequence = scheduleType === 'sequence';
                    const isPaced = scheduleType === 'paced';
                    const isPacedSequence = isSequence && self.dataset.sequenceEnrollmentMode === 'paced';
                    const dailyCompanyLimit = self.dataset.dailyCompanyLimit || '20';
                    const nextBatchAt = self.dataset.nextBatchAt || 'date à définir';
                    self.disabled = true;

                    previewDispatch(self)
                        .then(function (preview) {
                            return Swal.fire({
                                title: isPacedSequence ? 'Activer ou vérifier le lot progressif ?' : (isSequence ? 'Démarrer la séquence ?' : (isPaced ? 'Envoyer le lot du jour ?' : 'Envoyer maintenant ?')),
                                html: isPacedSequence
                                    ? 'Cette action traite uniquement le lot arrivé à échéance, limité à <strong>' + dailyCompanyLimit + '</strong> société(s). Tous leurs contacts éligibles commenceront à l’étape 1.<br>Prochain lot planifié : <strong>' + nextBatchAt + '</strong>.'
                                    : isPaced
                                    ? 'Audience actuelle : <strong>' + preview.company_count + '</strong> société(s), <strong>' + preview.contact_count + '</strong> contact(s).<br>Cette action prépare uniquement le lot du jour, limité à <strong>' + dailyCompanyLimit + '</strong> société(s).'
                                    : 'Audience vérifiée : <strong>' + preview.count + '</strong> destinataire(s) éligible(s).<br>'
                                        + (isSequence ? 'Les contacts déjà inscrits seront ignorés.' : 'La campagne sera envoyée immédiatement. Cette action ne peut pas être annulée.'),
                                icon: isSequence ? 'question' : 'warning',
                                showCancelButton: true,
                                confirmButtonText: isPacedSequence ? 'Vérifier le lot' : (isSequence ? 'Démarrer' : 'Envoyer'),
                                cancelButtonText: 'Annuler',
                                buttonsStyling: false,
                                customClass: {
                                    confirmButton: 'btn btn-success me-2',
                                    cancelButton: 'btn btn-light',
                                },
                            });
                        })
                        .then(function (result) {
                            if (result && result.isConfirmed) {
                                return postCampaignAction(self);
                            }
                        })
                        .catch(function (error) { showBlocked(campaignActionErrorMessage(error)); })
                        .finally(function () { self.disabled = false; });
                });
            }        });
    </script>
@endpush
@endonce
