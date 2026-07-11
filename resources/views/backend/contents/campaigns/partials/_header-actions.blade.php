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
                data-url="{{ route('admin.campaigns.schedule', $model->id) }}">
            <i class="bi bi-calendar-check me-1"></i>
            Planifier
        </button>
        @endif

        <button type="button"
                class="btn btn-sm fw-bold btn-success"
                id="btn-send-now"
                data-campaign-id="{{ $model->id }}"
                data-schedule-type="{{ $model->schedule_type }}"
                data-url="{{ route('admin.campaigns.sendNow', $model->id) }}">
            @if($model->schedule_type === 'sequence')
                <i class="bi bi-play-circle me-1"></i>
                Démarrer la séquence
            @else
                <i class="bi bi-send me-1"></i>
                Envoyer maintenant
            @endif
        </button>
    @endcan

@endif
