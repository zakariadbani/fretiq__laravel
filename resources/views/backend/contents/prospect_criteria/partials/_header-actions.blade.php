{{--
    ProspectCriteria hero action buttons — ACTIONS ONLY, rendered identically on the
    view page and the edit form (parity is the point; do not re-introduce a mode branch).

    "Modifier" was removed: the tab bar already cross-links view ↔ edit.
    "Retour à la liste" was removed: the breadcrumb on both pages already links to the index.
    Quota badges were removed: quota is not an action — it lives on the index page strip
    (_quota-strip.blade.php).

    window.launchDiscovery is defined in _discovery-script.blade.php, which BOTH pages
    must include, otherwise the launch button throws ReferenceError.

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
            id="launch-discovery-btn"
            class="btn btn-sm btn-light-success"
            onclick="launchDiscovery({{ (int) $model->id }}, '{{ csrf_token() }}')"
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
