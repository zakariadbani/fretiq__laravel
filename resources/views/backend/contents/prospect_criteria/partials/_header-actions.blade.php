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

    @can('run discovery')
        @php
            // Start disabled when a run is currently in flight (pending or running).
            // The real guard is server-side (discover() returns 409 if in-flight);
            // this is a cosmetic / UX convenience only.
            $discoveryInFlight = \Illuminate\Support\Facades\Schema::hasTable('discovery_runs')
                && in_array(optional($model->latestDiscoveryRun)->status, ['pending', 'running'], true);
        @endphp
        <button type="button"
                id="launch-discovery-btn"
                class="btn btn-sm btn-light-success"
                onclick="launchDiscovery({{ (int) $model->id }}, '{{ csrf_token() }}')"
                {{ $discoveryInFlight ? 'disabled' : '' }}>
            <i class="bi bi-play-fill me-1"></i>
            Lancer la découverte
        </button>
    @endcan
@endif
