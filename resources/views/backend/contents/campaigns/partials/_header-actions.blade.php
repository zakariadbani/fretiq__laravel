{{-- Shared campaign actions: rendered inside the generic hero on view and edit. --}}
@php
    $isNonSequence = $model->schedule_type !== 'sequence';
    $hasManagedRun = $isNonSequence && ! empty($managedTimelineRun);
    $ready = (bool) ($campaignReadiness['ok'] ?? false);
    $automationReady = ($schedulerHealth['status'] ?? 'missing') === 'healthy';
    $canSchedule = $ready && $automationReady;
    $canSend = $ready && ($isNonSequence || $automationReady);
@endphp

@if($isView)
    <span id="campaign-readiness-state" class="badge badge-light-{{ $ready ? 'success' : 'warning' }}"
          data-campaign-readiness="{{ $ready ? 'ready' : 'blocked' }}"
          data-campaign-actions-enabled="{{ $canSchedule ? 'true' : 'false' }}">
        {{ $ready ? 'Prête à envoyer' : ($model->is_active ? 'Active ne signifie pas prête à envoyer' : 'Vérification d’envoi requise') }}
    </span>
    @can('edit campaigns')
        <a href="{{ route('admin.campaigns.edit', $model) }}" class="btn btn-sm btn-light-primary" data-view-edit-link>
            <i class="bi bi-pencil me-1"></i>Modifier
        </a>
    @endcan
@endif

@if($isNonSequence)
    @if($model->is_active)
        @can('edit campaigns')
            <button type="button" class="btn btn-sm btn-light-warning campaign-switch" data-guard-unsaved="true"
                    data-state="0" data-url="{{ route('admin.campaigns.executeSwitch', $model) }}">
                <i class="bi bi-pause-circle me-1"></i>Mettre en pause
            </button>
        @endcan
    @else
        @can('send campaigns')
            <button type="button" class="btn btn-sm btn-light-success campaign-switch" data-guard-unsaved="true"
                    data-state="1" data-url="{{ route('admin.campaigns.executeSwitch', $model) }}">
                <i class="bi bi-play-circle me-1"></i>Reprendre la campagne
            </button>
        @endcan
    @endif
@endif

@can('send campaigns')
    <div class="dropdown">
        <button class="btn btn-sm btn-light-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-three-dots me-1"></i>Outils d’envoi
        </button>
        <div class="dropdown-menu dropdown-menu-end p-2 min-w-250px">
            <button type="button" id="btn-test-send" class="dropdown-item rounded py-2 campaign-post" data-guard-unsaved="true"
                    data-url="{{ route('admin.campaigns.testSend', $model) }}">
                <i class="bi bi-envelope-check me-2"></i>M’envoyer un test
            </button>
            @include('backend.contents.campaigns.partials._stats-sync-button', ['model' => $model, 'dropdown' => true])
            <button type="button" id="btn-sync-zoho-list" class="dropdown-item rounded py-2 campaign-zoho-list" data-guard-unsaved="true"
                    data-url="{{ route('admin.campaigns.syncZohoList', $model) }}">
                <i class="bi bi-people me-2"></i>Préparer la liste d’envoi
            </button>
        </div>
    </div>
@endcan

@canany(['send campaigns', 'edit campaigns'])
    @if($model->is_active && $hasManagedRun)
        <a class="btn btn-sm fw-bold btn-light-primary" href="{{ route('admin.campaigns.view', $model) }}#campaign_historique" data-guard-unsaved="true">
            <i class="bi bi-clock-history me-1"></i>Gérer le lot programmé
        </a>
    @endif
@endcanany

@can('send campaigns')
    @if($model->is_active && ! $hasManagedRun && $isNonSequence)
        <button type="button" id="btn-schedule" class="btn btn-sm fw-bold btn-light-primary campaign-schedule" data-guard-unsaved="true"
                data-url="{{ route('admin.campaigns.schedule', $model) }}"
                data-preview-url="{{ route('admin.campaigns.dispatchPreview', ['id' => $model->id, 'action' => 'schedule']) }}"
                data-schedule-type="{{ $model->schedule_type }}" @disabled(! $canSchedule)>
            <i class="bi bi-calendar-check me-1"></i>Planifier
        </button>
        <button type="button" id="btn-send-now" class="btn btn-sm fw-bold btn-success campaign-send" data-guard-unsaved="true"
                data-url="{{ route('admin.campaigns.sendNow', $model) }}" data-preview-url="{{ route('admin.campaigns.dispatchPreview', $model) }}"
                data-schedule-type="{{ $model->schedule_type }}" data-daily-company-limit="{{ $model->pacedDailyCompanyLimit() }}"
                data-sequence-enrollment-mode="{{ $model->sequence_enrollment_mode }}" @disabled(! $canSend)>
            <i class="bi bi-send me-1"></i>{{ $model->schedule_type === 'paced' ? 'Envoyer le lot du jour' : 'Envoyer maintenant' }}
        </button>
    @elseif($model->is_active && ! $isNonSequence)
        <button type="button" id="btn-send-now" class="btn btn-sm fw-bold btn-success campaign-send" data-guard-unsaved="true"
                data-url="{{ route('admin.campaigns.sendNow', $model) }}" data-preview-url="{{ route('admin.campaigns.dispatchPreview', $model) }}"
                data-schedule-type="sequence" data-sequence-enrollment-mode="{{ $model->sequence_enrollment_mode }}" @disabled(! $canSend)>
            <i class="bi bi-play-circle me-1"></i>Démarrer la séquence
        </button>
    @endif
@endcan

@once
@push('scripts')
<script data-campaign-action-handlers>
document.addEventListener('DOMContentLoaded', function () {
    const csrf = '{{ csrf_token() }}';
    const isDirty = () => {
        const form = document.getElementById('form_crud');
        return form && form.dataset.cleanSnapshot !== undefined
            && form.dataset.cleanSnapshot !== new URLSearchParams(new FormData(form)).toString();
    };
    const errorMessage = (error) => {
        const data = error.response?.data || {};
        if (data.msg) return data.msg;
        if (data.text) return data.text;
        if (data.message) return data.message;
        if (Array.isArray(data.messages)) return data.messages.join(' ');
        if (error.message) return error.message;
        return 'Cette action ne peut pas être effectuée.';
    };
    const request = (url, method = 'post', data = {}) => axios({ url, method, data: { ...data, _token: csrf } });
    const unsaved = () => Swal.fire({ icon: 'warning', title: 'Modifications non enregistrées', text: 'Enregistrez la campagne avant de lancer cette action.' });
    const reload = (response) => window.location.replace(response.data.redirect || response.data['redirect-to-view'] || window.location.href);
    const guarded = (callback) => function () { if (isDirty()) return unsaved(); callback.call(this); };

    document.querySelectorAll('a[data-guard-unsaved="true"]').forEach((link) => link.addEventListener('click', function (event) {
        if (!isDirty()) return;
        event.preventDefault();
        unsaved();
    }));

    document.querySelectorAll('.campaign-switch').forEach((button) => button.addEventListener('click', guarded(function () {
        const isPause = this.dataset.state === '0';
        Swal.fire({ icon: 'question', title: isPause ? 'Mettre la campagne en pause ?' : 'Reprendre la campagne ?',
            text: isPause ? 'Les prochains lots et réservations sûres sont arrêtés. Les destinataires en attente et l’historique sont conservés ; vous pourrez reprendre plus tard.' : 'Les lots sûrs devenus obsolètes seront replanifiés. Aucun envoi ne partira immédiatement.',
            showCancelButton: true, confirmButtonText: isPause ? 'Mettre en pause' : 'Reprendre', cancelButtonText: 'Annuler' }).then((result) => {
            if (!result.isConfirmed) return; this.disabled = true;
            request(this.dataset.url, 'put', { field: 'is_active', state: this.dataset.state }).then(reload).catch((e) => Swal.fire({ icon:'error', title:'Action impossible', text:errorMessage(e) })).finally(() => this.disabled = false);
        });
    })));

    const preview = (button) => axios.get(button.dataset.previewUrl).then((response) => response.data);
    const confirmDispatch = (button, mode) => guarded(function () {
        button.disabled = true;
        preview(button).then((preview) => {
            const isPaced = button.dataset.scheduleType === 'paced';
            const pacedContext = mode === 'schedule'
                ? 'La planification active les lots continus des jours ouvrés.'
                : 'Cet envoi concerne uniquement le lot du jour.';
            const audience = isPaced
                ? 'Audience actuelle : <strong>' + (preview.company_count || 0) + '</strong> société(s), <strong>' + (preview.contact_count || 0) + '</strong> contact(s).'
                : 'Audience vérifiée : <strong>' + (preview.count || 0) + '</strong> destinataire(s) éligible(s).';

            return Swal.fire({
                icon: mode === 'send' ? 'warning' : 'question',
                showCancelButton: true,
                title: mode === 'schedule' ? 'Planifier la campagne ?' : (isPaced ? 'Envoyer le lot du jour ?' : 'Envoyer maintenant ?'),
                html: (isPaced ? pacedContext + '<br><br>' : '') + audience,
                confirmButtonText: mode === 'schedule' ? 'Planifier' : 'Envoyer',
                cancelButtonText: 'Annuler',
            });
        }).then((result) => {
            if (result?.isConfirmed) return request(button.dataset.url);
            return null;
        }).then((response) => { if (response) reload(response); }).catch((e) => Swal.fire({ icon:'error', title:'Envoi bloqué', text:errorMessage(e) })).finally(() => button.disabled = false);
    });
    document.querySelectorAll('.campaign-schedule').forEach((button) => button.addEventListener('click', confirmDispatch(button, 'schedule')));
    document.querySelectorAll('.campaign-send').forEach((button) => button.addEventListener('click', confirmDispatch(button, 'send')));

    document.querySelectorAll('.campaign-post, [data-campaign-stats-sync]').forEach((button) => button.addEventListener('click', guarded(function () {
        this.disabled = true; request(this.dataset.url).then(reload).catch((e) => Swal.fire({ icon:'error', title:'Action impossible', text:errorMessage(e) })).finally(() => this.disabled = false);
    })));
    document.querySelectorAll('.campaign-zoho-list').forEach((button) => button.addEventListener('click', guarded(function () {
        Swal.fire({ icon:'question', title:'Préparer la liste d’envoi ?', text:'Les contacts conformes seront ajoutés et vérifiés. Aucune campagne Zoho ne sera créée ni envoyée.', showCancelButton:true, confirmButtonText:'Préparer et vérifier', cancelButtonText:'Annuler' }).then((result) => {
            if (!result.isConfirmed) return; this.disabled = true; request(this.dataset.url).then(reload).catch((e) => Swal.fire({ icon:'warning', title:'Préparation bloquée', text:errorMessage(e) })).finally(() => this.disabled = false);
        });
    })));
    document.querySelectorAll('.timeline-action').forEach((button) => button.addEventListener('click', function () {
        const copy = { start:['Démarrer ce lot maintenant ?', 'Le premier créneau sûr sera choisi. Les quotas et heures d’envoi restent appliqués.'], cancel:['Annuler ce lot ?', 'Cette annulation est définitive pour cette occurrence. Les destinataires non envoyés ne seront pas reprogrammés automatiquement ; utilisez Pause pour un arrêt réversible.'], resend:['Renvoyer ce lot ?', 'Un nouveau lot audité sera créé avec les destinataires encore éligibles. Le lot d’origine est conservé ; certains contacts peuvent recevoir un doublon.'] }[this.dataset.action];
        Swal.fire({ icon:'question', title:copy[0], text:copy[1], showCancelButton:true, confirmButtonText:'Confirmer', cancelButtonText:'Retour' }).then((result) => { if (!result.isConfirmed) return; this.disabled = true; request(this.dataset.url).then((response) => Swal.fire({ icon:'success', title:'Action enregistrée', text:response.data.message || 'La campagne a été mise à jour.' }).then(() => reload(response))).catch((e) => Swal.fire({ icon:'error', title:'Action impossible', text:errorMessage(e) })).finally(() => this.disabled = false); });
    }));
});
</script>
@endpush
@endonce
