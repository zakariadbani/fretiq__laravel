{{--
    ProspectCriteria hero action buttons — ACTIONS ONLY, rendered identically on the
    view page and the edit form (parity is the point; do not re-introduce a mode branch).

    "Modifier" was removed: the tab bar already cross-links view ↔ edit.
    "Retour à la liste" was removed: the breadcrumb on both pages already links to the index.
    Quota badges were removed: quota is not an action — it lives on the index page strip
    (_quota-strip.blade.php).

    _discovery-script.blade.php installs one delegated handler for every
    data-discovery-launch control on both pages.

    Variables: $model, and the quota guards $quotaRemaining / $monthlyRemaining /
    $contactRemaining (null = unlimited/unknown). The edit page gets these via
    ProspectCriteriaController::getViewVars(); the view page via resolveQuotaVars().

    $isView is still passed by the _header-with-tabs shim but is no longer consumed.
--}}

@can('run discovery')
    @php
        // Start disabled when a run is currently in flight (pending or running).
        // The real guard is server-side (discover() returns 409 if in-flight);
        // this is a cosmetic / UX convenience only.
        $discoveryInFlight = \Illuminate\Support\Facades\Schema::hasTable('discovery_runs')
            && in_array(optional($model->latestDiscoveryRun)->status, ['pending', 'running'], true);

        // Quota guard: $quotaRemaining / $monthlyRemaining injected by the controller (null = unlimited).
        // === 0 means solde épuisé; disable when EITHER the daily OR monthly company meter is at 0.
        $quotaExhausted = (isset($quotaRemaining) && $quotaRemaining === 0)
            || (isset($monthlyRemaining) && $monthlyRemaining === 0);

        $btnDisabled = $discoveryInFlight || $quotaExhausted;
        $btnTooltip  = $quotaExhausted
            ? 'Solde épuisé — recharge demain à minuit'
            : ($discoveryInFlight ? 'Découverte en cours' : '');
    @endphp
    <button type="button"
            class="btn btn-sm btn-light-success"
            data-discovery-launch
            data-criteria-id="{{ (int) $model->id }}"
            data-launch-url="{{ route('admin.prospect_criteria.discover', $model->id) }}"
            data-status-url="{{ route('admin.prospect_criteria.discovery_status', $model->id) }}"
            data-csrf-token="{{ csrf_token() }}"
            data-discovery-static-disabled="{{ $quotaExhausted ? 'true' : 'false' }}"
            @if($btnDisabled) disabled @endif
            @if($btnTooltip) data-bs-toggle="tooltip" title="{{ $btnTooltip }}" @endif>
        <i class="bi bi-play-fill me-1"></i>
        Lancer la découverte
    </button>
    @if(isset($contactRemaining) && $contactRemaining === 0)
        <span class="text-muted fs-8 ms-2">
            <i class="bi bi-person-x me-1"></i>
            Enrichissement épuisé — découverte sans contacts
        </span>
    @endif
@endcan

@can('enrich companies')
    <button type="button"
            id="criteria-contact-enrichment-btn"
            class="btn btn-sm btn-light-primary"
            data-preview-url="{{ route('admin.prospect_criteria.contact_enrichment_preview', $model->id) }}"
            data-dispatch-url="{{ route('admin.prospect_criteria.contact_enrichment_dispatch', $model->id) }}"
            data-csrf-token="{{ csrf_token() }}"
            data-saved-min-score="{{ $model->min_score_enrich === null ? '' : (int) $model->min_score_enrich }}"
            onclick="launchMissingContactEnrichment(this)">
        <i class="bi bi-person-plus-fill me-1"></i>
        Chercher les contacts manquants
    </button>
@endcan
