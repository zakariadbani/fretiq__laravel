@can('send campaigns')
@php($canSyncCampaignStats = ($syncableZohoRunCount ?? 0) > 0)
@php($dropdown = (bool) ($dropdown ?? false))

@unless($canSyncCampaignStats)
    <span class="d-inline-block"
          tabindex="0"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-title="Disponible après une exécution Zoho terminée au cours des 30 derniers jours."
          aria-label="Disponible après une exécution Zoho terminée au cours des 30 derniers jours.">
@endunless
    <button type="button"
            class="{{ $dropdown ? 'dropdown-item rounded py-2' : 'btn btn-sm fw-bold btn-light-primary' }}"
            id="btn-sync-campaign-stats"
            data-campaign-stats-sync
            data-url="{{ route('admin.campaigns.syncStats', $model->id) }}"
            aria-disabled="{{ $canSyncCampaignStats ? 'false' : 'true' }}"
            @disabled(! $canSyncCampaignStats)>
        <i class="bi bi-arrow-repeat me-1"></i>
        Synchroniser les statistiques
    </button>
@unless($canSyncCampaignStats)
    </span>
@endunless
@endcan
