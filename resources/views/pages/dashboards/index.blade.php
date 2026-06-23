<x-default-layout>

@section('title')
    Tableau de bord
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Tableau de bord</li>
    </ul>
@endsection

{{-- ═══════════════════════════════════════════════════════════════════════════
     KPI CARDS — 8 cards, 2 rows × 4 cols
═══════════════════════════════════════════════════════════════════════════════ --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Entreprises --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.companies.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('office-bag', 'fs-2x text-primary') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($companiesCount) }}</div>
                <div class="fw-semibold text-gray-600">Entreprises</div>
            </div>
        </a>
    </div>

    {{-- Contacts --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.contacts.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('profile-user', 'fs-2x text-info') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($contactsCount) }}</div>
                <div class="fw-semibold text-gray-600">Contacts</div>
            </div>
        </a>
    </div>

    {{-- Campagnes actives --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.campaigns.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('rocket', 'fs-2x text-success') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $activeCampaigns }}</div>
                <div class="fw-semibold text-gray-600">Campagnes actives</div>
                @if($pendingCampaigns > 0)
                    <div class="mt-2"><span class="badge badge-light-warning fs-8">{{ $pendingCampaigns }} en pause</span></div>
                @endif
            </div>
        </a>
    </div>

    {{-- Emails envoyés --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('sms', 'fs-2x text-warning') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($totalSent) }}</div>
                <div class="fw-semibold text-gray-600">Emails envoyés</div>
            </div>
        </div>
    </div>

    {{-- Taux d'ouverture --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('eye', 'fs-2x text-primary') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $openRate }} %</div>
                <div class="fw-semibold text-gray-600">Taux d'ouverture</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-primary">
                        <div class="progress-bar bg-primary" style="width: {{ min($openRate, 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Taux de clic --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('mouse-square', 'fs-2x text-info') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $clickRate }} %</div>
                <div class="fw-semibold text-gray-600">Taux de clic</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-info">
                        <div class="progress-bar bg-info" style="width: {{ min($clickRate, 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Demandes générées --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.demandes.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('note-2', 'fs-2x text-success') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $demandesCount }}</div>
                <div class="fw-semibold text-gray-600">Demandes générées</div>
            </div>
        </a>
    </div>

    {{-- Taux de conversion --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                {!! getIcon('chart-simple', 'fs-2x text-danger') !!}
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ $conversionRate }} %</div>
                <div class="fw-semibold text-gray-600">Taux de conversion</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-danger">
                        <div class="progress-bar bg-danger" style="width: {{ min($conversionRate * 10, 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
{{-- end::KPI cards --}}

{{-- ═══════════════════════════════════════════════════════════════════════════
     CHARTS ROW — Funnel + Engagement area chart
═══════════════════════════════════════════════════════════════════════════════ --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Funnel de prospection --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Funnel de prospection</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Contacts → Demandes</span>
                </h3>
            </div>
            <div class="card-body py-3">
                <div id="kt_fretiq_funnel_chart" style="min-height:280px;"></div>
            </div>
        </div>
    </div>

    {{-- Engagement area chart --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Engagement (4 dernières semaines)</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Ouvertures, clics et réponses</span>
                </h3>
            </div>
            <div class="card-body py-3">
                <div id="kt_fretiq_engagement_chart" style="min-height:280px;"></div>
            </div>
        </div>
    </div>

</div>
{{-- end::charts row --}}

{{-- ═══════════════════════════════════════════════════════════════════════════
     BOTTOM ROW — Radial conversion + Top campaigns table
═══════════════════════════════════════════════════════════════════════════════ --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Radial conversion --}}
    <div class="col-xl-3">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Taux de conversion</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Envoyés → Demandes</span>
                </h3>
            </div>
            <div class="card-body py-3 d-flex justify-content-center align-items-center">
                <div id="kt_fretiq_conversion_chart" style="min-height:220px;width:100%;"></div>
            </div>
        </div>
    </div>

    {{-- Top campaigns table --}}
    <div class="col-xl-9">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Top campagnes</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Classées par demandes générées</span>
                </h3>
                <div class="card-toolbar">
                    <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-light-primary">Voir tout</a>
                </div>
            </div>
            <div class="card-body py-3">
                @if($topCampaigns->isEmpty())
                    <div class="text-center text-muted py-8">
                        {!! getIcon('information-5', 'fs-2x text-muted mb-3') !!}
                        <div class="fs-6">Aucune campagne avec des envois pour l'instant.</div>
                    </div>
                @else
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                        <thead>
                            <tr class="fw-bold text-muted">
                                <th class="min-w-200px">Campagne</th>
                                <th class="min-w-80px text-center">Envoyés</th>
                                <th class="min-w-80px text-center">Ouverture</th>
                                <th class="min-w-80px text-center">Demandes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($topCampaigns as $i => $campaign)
                            @php
                                $colors = ['success','primary','warning','info','danger'];
                                $color  = $colors[$i % count($colors)];
                            @endphp
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="symbol symbol-40px me-3">
                                            <div class="symbol-label bg-light-{{ $color }}">
                                                {!! getIcon('rocket', 'fs-3 text-' . $color) !!}
                                            </div>
                                        </div>
                                        <div>
                                            <a href="{{ route('admin.campaigns.view', $campaign['id']) }}"
                                               class="text-gray-900 fw-bold text-hover-primary fs-6">
                                                {{ $campaign['name'] }}
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-gray-900">{{ number_format($campaign['sent']) }}</span>
                                </td>
                                <td class="text-center">
                                    @if($campaign['open_rate'] >= 35)
                                        <span class="badge badge-light-success">{{ $campaign['open_rate'] }} %</span>
                                    @elseif($campaign['open_rate'] >= 20)
                                        <span class="badge badge-light-warning">{{ $campaign['open_rate'] }} %</span>
                                    @else
                                        <span class="badge badge-light-danger">{{ $campaign['open_rate'] }} %</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="fw-bold text-{{ $campaign['demandes'] > 0 ? 'success' : 'muted' }}">
                                        {{ $campaign['demandes'] }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>
{{-- end::bottom row --}}

{{-- ═══════════════════════════════════════════════════════════════════════════
     APEXCHARTS — inline init (no separate JS file needed)
═══════════════════════════════════════════════════════════════════════════════ --}}
@push('scripts')
<script>
(function () {
    'use strict';

    // Metronic CSS-var palette (safe fallbacks)
    var primary = KTUtil.getCssVariableValue('--bs-primary')   || '#009ef7';
    var success = KTUtil.getCssVariableValue('--bs-success')   || '#50cd89';
    var warning = KTUtil.getCssVariableValue('--bs-warning')   || '#ffc700';
    var info    = KTUtil.getCssVariableValue('--bs-info')      || '#7239ea';
    var gray300 = KTUtil.getCssVariableValue('--bs-gray-300')  || '#e4e6ef';
    var gray600 = KTUtil.getCssVariableValue('--bs-gray-600')  || '#7e8299';

    // ── 1. Funnel de prospection (horizontal bar) ─────────────────────────────
    var funnelEl = document.getElementById('kt_fretiq_funnel_chart');
    if (funnelEl) {
        var funnelData  = @json(array_values($funnelStages));
        var funnelLabels = @json(array_keys($funnelStages));

        new ApexCharts(funnelEl, {
            series: [{ name: 'Contacts', data: funnelData }],
            chart: {
                type: 'bar',
                height: 280,
                toolbar: { show: false },
                fontFamily: 'Inter, sans-serif'
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 4,
                    distributed: true,
                    dataLabels: { position: 'right' }
                }
            },
            dataLabels: {
                enabled: true,
                formatter: function (val) { return val.toLocaleString('fr-FR'); },
                offsetX: 6,
                style: { fontSize: '12px', colors: [gray600] }
            },
            colors: [primary, '#0095e8', success, warning, '#f1416c', info],
            xaxis: {
                categories: funnelLabels,
                labels: { style: { colors: gray600, fontSize: '12px' } }
            },
            yaxis: { labels: { style: { colors: gray600, fontSize: '12px' } } },
            grid: { borderColor: gray300, strokeDashArray: 4 },
            legend: { show: false },
            tooltip: {
                y: { formatter: function (val) { return val.toLocaleString('fr-FR'); } }
            }
        }).render();
    }

    // ── 2. Engagement — area chart (weeks) ────────────────────────────────────
    var engEl = document.getElementById('kt_fretiq_engagement_chart');
    if (engEl) {
        new ApexCharts(engEl, {
            series: [
                { name: 'Ouvertures', data: @json($engagementOpens)   },
                { name: 'Clics',      data: @json($engagementClicks)  },
                { name: 'Réponses',   data: @json($engagementReplies) }
            ],
            chart: {
                type: 'area',
                height: 280,
                toolbar: { show: false },
                fontFamily: 'Inter, sans-serif'
            },
            stroke: { curve: 'smooth', width: 2 },
            fill: {
                type: 'gradient',
                gradient: { opacityFrom: 0.35, opacityTo: 0.05 }
            },
            colors: [primary, warning, success],
            xaxis: {
                categories: @json($engagementWeeks),
                labels: { style: { colors: gray600, fontSize: '12px' } }
            },
            yaxis: { labels: { style: { colors: gray600, fontSize: '12px' } } },
            grid: { borderColor: gray300, strokeDashArray: 4 },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                labels: { colors: gray600 }
            },
            dataLabels: { enabled: false },
            tooltip: { x: { show: true } }
        }).render();
    }

    // ── 3. Taux de conversion — radialBar ─────────────────────────────────────
    var convEl = document.getElementById('kt_fretiq_conversion_chart');
    if (convEl) {
        var convRate = {{ $conversionRate }};
        new ApexCharts(convEl, {
            series: [convRate],
            chart: {
                type: 'radialBar',
                height: 220,
                fontFamily: 'Inter, sans-serif'
            },
            plotOptions: {
                radialBar: {
                    hollow: { size: '60%' },
                    dataLabels: {
                        name: {
                            show: true,
                            fontSize: '13px',
                            color: gray600,
                            offsetY: 20
                        },
                        value: {
                            show: true,
                            fontSize: '24px',
                            fontWeight: 700,
                            color: '#181c32',
                            offsetY: -15,
                            formatter: function (val) { return val + ' %'; }
                        }
                    }
                }
            },
            colors: [info],
            labels: ['Conversion'],
            legend: { show: false }
        }).render();
    }
})();
</script>
@endpush

</x-default-layout>
