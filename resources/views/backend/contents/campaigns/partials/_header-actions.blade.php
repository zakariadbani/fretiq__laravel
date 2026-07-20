{{--
    Campaign hero action buttons.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model (Campaign), $isView (bool)

    Preserves verbatim: Retour, Modifier, Planifier (btn-schedule), Envoyer maintenant (btn-send-now).
    All @can gates are kept exactly as in the original view.blade.php.
    The JS handlers for btn-schedule and btn-send-now live in view.blade.php @push('scripts').
--}}

@can('view campaigns')
    <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-left me-1"></i>
        Retour à la liste
    </a>
@endcan

@if($isView)

    @can('edit campaigns')
        <a href="{{ route('admin.campaigns.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan

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

@endif
