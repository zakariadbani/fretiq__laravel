{{--
    ProspectCriteria hero action buttons — only rendered on the view page.
    Used by _header-with-tabs.blade.php shim.

    Variables: $model, $isView (bool)
--}}

@if($isView)
    @can('view prospect_criteria')
        <a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-sm btn-light">
            <i class="bi bi-arrow-left me-1"></i>
            Retour à la liste
        </a>
    @endcan

    @can('edit prospect_criteria')
        <a href="{{ route('admin.prospect_criteria.edit', $model->id) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-pencil me-1"></i>
            Modifier
        </a>
    @endcan

    {{-- Quota solde badge --}}
    @include('backend.contents.prospect_criteria.partials._quota-badge', [
        'quotaRemaining' => $quotaRemaining ?? null,
        'quotaPackage'   => $quotaPackage   ?? null,
    ])

    @can('run discovery')
        @php
            // Start disabled when a run is currently in flight (pending or running).
            // The real guard is server-side (discover() returns 409 if in-flight);
            // this is a cosmetic / UX convenience only.
            $discoveryInFlight = \Illuminate\Support\Facades\Schema::hasTable('discovery_runs')
                && in_array(optional($model->latestDiscoveryRun)->status, ['pending', 'running'], true);

            // Quota guard: $quotaRemaining is injected by the controller (null = unlimited).
            // === 0 means solde épuisé; non-zero and null (unlimited) both allow launch.
            $quotaExhausted = isset($quotaRemaining) && $quotaRemaining === 0;

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
    @endcan
@endif
