<x-default-layout>

@section('title')
    Segment — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Segments', 'route' => 'admin.segments.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.segments.index'])
@endsection

{{--
    Segment view — shared hero + 2-tab UX.
    Tab pane IDs: segment_apercu / segment_general.
    Aperçu is native on view; Général deep-links to edit.
    The domain-specific filter JSON card is preserved below _apercu.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.segments.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="segment_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\SegmentViewConfig::make($model, $stats ?? null),
        ])

        {{-- Domain-specific: Ciblage card — replaces the raw JSON card --}}
        @php
            $filter         = is_array($model->filter) ? $model->filter : [];
            $countries      = config('global.data.company_countries', []);
            $contactStatuses = config('global.data.contact_statuses', []);

            // sectors — scalar legacy support: cast to array
            $sectors = $filter['sector'] ?? [];
            if (!is_array($sectors)) { $sectors = (array) $sectors; }

            // countries — scalar legacy support
            $filterCountries = $filter['country'] ?? [];
            if (!is_array($filterCountries)) { $filterCountries = (array) $filterCountries; }

            // status — always single scalar or missing
            $filterStatus = $filter['status'] ?? null;

            // Funnel data (passed from controller as $stats['funnel'])
            $funnel = $stats['funnel'] ?? null;
            $funnelParts = [];
            if (is_array($funnel)) {
                if (!empty($funnel['matched']))             { $funnelParts[] = ['label' => (string)$funnel['matched'] . ' correspondants',   'class' => '']; }
                if (!empty($funnel['suppressed']))          { $funnelParts[] = ['label' => '− ' . $funnel['suppressed'] . ' suppression',     'class' => '']; }
                if (!empty($funnel['cold_excluded']))       { $funnelParts[] = ['label' => $funnel['cold_excluded'] . ' en attente (envoi à froid)', 'class' => 'text-warning']; }
                if (!empty($funnel['personal_excluded']))   { $funnelParts[] = ['label' => '− ' . $funnel['personal_excluded'] . ' personnels', 'class' => '']; }
                if (!empty($funnel['duplicates_excluded'])) { $funnelParts[] = ['label' => '− ' . $funnel['duplicates_excluded'] . ' doublons', 'class' => '']; }
                if (isset($funnel['final']))                { $funnelParts[] = ['label' => '= ' . $funnel['final'] . ' destinataires éligibles',          'class' => 'fw-semibold']; }
            }
        @endphp
        <div class="row g-5 mt-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-crosshair text-info fs-3 me-2"></i>
                            Ciblage
                        </h3>
                    </div>
                    <div class="card-body border-top pt-5">

                        {{-- Row: Secteurs d'activité --}}
                        <div class="d-flex align-items-start mb-4">
                            <span class="fw-semibold text-gray-700 w-200px flex-shrink-0">Secteurs d'activité</span>
                            <div>
                                @if(empty($sectors))
                                    <span class="text-muted">Tous</span>
                                @else
                                    @foreach($sectors as $s)
                                        <span class="badge badge-light me-1 mb-1">{{ $s }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </div>

                        {{-- Row: Pays --}}
                        <div class="d-flex align-items-start mb-4">
                            <span class="fw-semibold text-gray-700 w-200px flex-shrink-0">Pays</span>
                            <div>
                                @if(empty($filterCountries))
                                    <span class="text-muted">Tous</span>
                                @else
                                    @foreach($filterCountries as $iso)
                                        <span class="badge badge-light me-1 mb-1">{{ $countries[$iso] ?? e($iso) }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </div>

                        {{-- Row: Statut du contact --}}
                        <div class="d-flex align-items-start mb-4">
                            <span class="fw-semibold text-gray-700 w-200px flex-shrink-0">Statut du contact</span>
                            <div>
                                @if(!$filterStatus)
                                    <span class="text-muted">Tous</span>
                                @else
                                    @php
                                        $statusCfg   = $contactStatuses[$filterStatus] ?? [];
                                        $statusLabel = $statusCfg['label'] ?? $filterStatus;
                                        $statusColor = $statusCfg['color'] ?? 'secondary';
                                    @endphp
                                    <span class="badge badge-light-{{ $statusColor }}">{{ $statusLabel }}</span>
                                @endif
                            </div>
                        </div>

                        {{-- Funnel line (only when stats are available) --}}
                        @if(!empty($funnelParts))
                            <div class="border-top pt-4 mt-2">
                                <p class="fs-7 text-muted mb-0">
                                    @foreach($funnelParts as $i => $part)
                                        @if($i > 0)<span class="mx-1 text-gray-400">·</span>@endif
                                        <span class="{{ $part['class'] }}">{{ $part['label'] }}</span>
                                    @endforeach
                                    @php
                                        $pinnedIn  = $stats['pinned_in']  ?? 0;
                                        $pinnedOut = $stats['pinned_out'] ?? 0;
                                    @endphp
                                    @if($pinnedIn > 0)
                                        <span class="mx-1 text-gray-400">·</span>
                                        <span class="text-primary">+{{ $pinnedIn }} épinglé{{ $pinnedIn > 1 ? 's' : '' }}</span>
                                    @endif
                                    @if($pinnedOut > 0)
                                        <span class="mx-1 text-gray-400">·</span>
                                        <span class="text-secondary">−{{ $pinnedOut }} exclu{{ $pinnedOut > 1 ? 's' : '' }}</span>
                                    @endif
                                </p>
                            </div>
                        @endif

                        @if(!empty($stats['funnel']['cold_excluded']) && ! config('prospecting.cold_send_enabled', false))
                            <div class="alert alert-warning d-flex align-items-center py-3 fs-7 mt-3 mb-0" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <span>{{ $stats['funnel']['cold_excluded'] }} prospect(s) en attente — l'envoi à froid est désactivé. Les clients reçoivent l'email ; les prospects seront contactés dès son activation.</span>
                            </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
        {{-- end Ciblage card --}}
    </div>
    {{-- end Aperçu --}}

    {{--
        Général tab is NOT a native pane here — it deep-links to the edit page.
        No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
@endpush

</x-default-layout>
