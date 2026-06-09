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

{{-- ====================================================================== --}}
{{-- KPI Cards Row (8 cartes)                                                --}}
{{-- ====================================================================== --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Entreprises --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.companies.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-briefcase fs-2x text-primary"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['companies']) }}</div>
                <div class="fw-semibold text-gray-600">Entreprises</div>
            </div>
        </a>
    </div>

    {{-- Contacts --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.contacts.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-person fs-2x text-info"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['contacts']) }}</div>
                <div class="fw-semibold text-gray-600">Contacts</div>
            </div>
        </a>
    </div>

    {{-- Campagnes actives --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.campaigns.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-rocket fs-2x text-success"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['active_campaigns']) }}</div>
                <div class="fw-semibold text-gray-600">Campagnes actives</div>
            </div>
        </a>
    </div>

    {{-- Emails envoyés (30j) --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-envelope fs-2x text-warning"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['emails_sent_30d']) }}</div>
                <div class="fw-semibold text-gray-600">Emails envoyés (30j)</div>
            </div>
        </div>
    </div>

    {{-- Taux d'ouverture --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-eye fs-2x text-primary"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['open_rate'], 1) }} %</div>
                <div class="fw-semibold text-gray-600">Taux d'ouverture</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-primary">
                        <div class="progress-bar bg-primary" style="width: {{ min((float) $kpis['open_rate'], 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Taux de clic --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-mouse fs-2x text-info"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['click_rate'], 1) }} %</div>
                <div class="fw-semibold text-gray-600">Taux de clic</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-info">
                        <div class="progress-bar bg-info" style="width: {{ min((float) $kpis['click_rate'], 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Demandes générées --}}
    <div class="col-xl-3 col-md-6">
        <a href="{{ route('admin.demandes.index') }}" class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-journal-text fs-2x text-success"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['demandes']) }}</div>
                <div class="fw-semibold text-gray-600">Demandes générées</div>
                @if ($kpis['demandes_30d'] > 0)
                    <div class="mt-2">
                        <span class="badge badge-light-success fs-8">+{{ number_format($kpis['demandes_30d']) }} ce mois</span>
                    </div>
                @endif
            </div>
        </a>
    </div>

    {{-- Taux de conversion --}}
    <div class="col-xl-3 col-md-6">
        <div class="card bg-body hoverable card-xl-stretch mb-xl-8">
            <div class="card-body">
                <i class="bi bi-bar-chart fs-2x text-danger"></i>
                <div class="text-gray-900 fw-bold fs-2 mb-2 mt-5">{{ number_format($kpis['conversion_rate'], 2) }} %</div>
                <div class="fw-semibold text-gray-600">Taux de conversion</div>
                <div class="mt-2">
                    <div class="progress h-6px bg-light-danger">
                        <div class="progress-bar bg-danger" style="width: {{ min((float) $kpis['conversion_rate'] * 10, 100) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
{{-- end::KPI Cards --}}

{{-- ====================================================================== --}}
{{-- Empty-state (affiché quand aucune donnée campagne)                      --}}
{{-- ====================================================================== --}}
@if ($kpis['emails_sent_30d'] === 0 && $kpis['active_campaigns'] === 0)
<div class="card mb-8">
    <div class="card-body text-center py-10">
        <i class="bi bi-rocket fs-3x text-muted mb-5 d-block"></i>
        <div class="text-gray-700 fw-semibold fs-5 mb-2">Aucune donnée — lancez votre première campagne</div>
        <div class="text-muted fs-7">
            Les indicateurs de campagnes (emails, taux d'ouverture, demandes) seront affichés ici dès qu'une campagne est active.
        </div>
        <a href="{{ route('admin.campaigns.create') }}" class="btn btn-primary mt-5">
            <i class="bi bi-plus-lg fs-4"></i>
            Créer une campagne
        </a>
    </div>
</div>
@endif

{{-- ====================================================================== --}}
{{-- Charts Row : Funnel (BAR) + Engagement (LINE)                          --}}
{{-- ====================================================================== --}}
<div class="row g-5 g-xl-8 mb-8">

    {{-- Funnel de prospection --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Funnel de prospection</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Du premier contact à la demande</span>
                </h3>
            </div>
            <div class="card-body py-3">
                @if (array_sum(array_values($funnel)) > 0)
                    <div id="kt_funnel_chart" style="min-height: 280px;"></div>
                @else
                    <div class="text-center text-muted py-10">
                        <i class="bi bi-bar-chart fs-3x text-muted mb-3 d-block"></i>
                        Aucune donnée de funnel disponible.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Engagement par semaine --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Engagement (8 dernières semaines)</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Ouvertures, clics et réponses</span>
                </h3>
            </div>
            <div class="card-body py-3">
                @php
                    $totalEngagement = array_sum($engagementOverTime['series']['opens'])
                        + array_sum($engagementOverTime['series']['clicks'])
                        + array_sum($engagementOverTime['series']['replies']);
                @endphp
                @if ($totalEngagement > 0)
                    <div id="kt_engagement_chart" style="min-height: 280px;"></div>
                @else
                    <div class="text-center text-muted py-10">
                        <i class="bi bi-graph-up fs-3x text-muted mb-3 d-block"></i>
                        Aucune donnée d'engagement disponible.
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
{{-- end::Charts Row --}}

{{-- ====================================================================== --}}
{{-- Top Campagnes (table)                                                   --}}
{{-- ====================================================================== --}}
<div class="row g-5 g-xl-8">
    <div class="col-xl-12">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title align-items-start flex-column">
                    <span class="card-label fw-bold text-gray-900">Top campagnes</span>
                    <span class="text-muted mt-1 fw-semibold fs-7">Classées par taux de conversion</span>
                </h3>
                <div class="card-toolbar">
                    <a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-light-primary">Voir tout</a>
                </div>
            </div>
            <div class="card-body py-3">
                @if (count($topCampaigns) > 0)
                    <div class="table-responsive">
                        <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4">
                            <thead>
                                <tr class="fw-bold text-muted">
                                    <th class="min-w-200px">Campagne</th>
                                    <th class="min-w-80px text-center">Envoyés</th>
                                    <th class="min-w-80px text-center">Demandes</th>
                                    <th class="min-w-100px text-center">Taux de conv.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topCampaigns as $campaign)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="symbol symbol-40px me-3">
                                                    <div class="symbol-label bg-light-primary">
                                                        <i class="bi bi-rocket fs-3 text-primary"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <a href="{{ route('admin.campaigns.view', $campaign['id']) }}"
                                                       class="text-gray-900 fw-bold text-hover-primary fs-6">
                                                        {{ e($campaign['name']) }}
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-gray-900">{{ number_format($campaign['sent']) }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="fw-bold text-success">{{ number_format($campaign['demandes']) }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-light-{{ $campaign['conversion_rate'] >= 2 ? 'success' : ($campaign['conversion_rate'] >= 1 ? 'warning' : 'danger') }}">
                                                {{ number_format($campaign['conversion_rate'], 2) }} %
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center text-muted py-10">
                        <i class="bi bi-rocket fs-3x text-muted mb-3 d-block"></i>
                        Aucune campagne avec des données de performance.
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
{{-- end::Top Campagnes --}}

@push('scripts')
<script>
"use strict";

(function () {

    // ── Funnel chart (ApexCharts BAR) ──────────────────────────────────────
    var funnelEl = document.getElementById('kt_funnel_chart');
    if (funnelEl && typeof ApexCharts !== 'undefined') {
        var funnelData = @json($funnel);
        var funnelLabels = Object.keys(funnelData);
        var funnelValues = Object.values(funnelData);

        var funnelOptions = {
            series: [{ name: 'Contacts', data: funnelValues }],
            chart: {
                type: 'bar',
                height: 280,
                toolbar: { show: false }
            },
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 4,
                    dataLabels: { position: 'right' }
                }
            },
            dataLabels: {
                enabled: true,
                formatter: function (val) { return val.toLocaleString('fr-FR'); },
                style: { fontSize: '12px', colors: ['#3F4254'] },
                offsetX: 8
            },
            colors: ['#009EF7'],
            xaxis: {
                categories: funnelLabels,
                labels: {
                    formatter: function (val) { return val.toLocaleString('fr-FR'); }
                }
            },
            yaxis: { labels: { style: { fontSize: '13px' } } },
            grid: { borderColor: '#F4F4F4' },
            tooltip: {
                y: { formatter: function (val) { return val.toLocaleString('fr-FR') + ' contacts'; } }
            }
        };

        var funnelChart = new ApexCharts(funnelEl, funnelOptions);
        funnelChart.render();
    }

    // ── Engagement chart (ApexCharts LINE) ────────────────────────────────
    var engagementEl = document.getElementById('kt_engagement_chart');
    if (engagementEl && typeof ApexCharts !== 'undefined') {
        var engagementData = @json($engagementOverTime);

        var engagementOptions = {
            series: [
                { name: 'Ouvertures', data: engagementData.series.opens },
                { name: 'Clics',      data: engagementData.series.clicks },
                { name: 'Réponses',   data: engagementData.series.replies }
            ],
            chart: {
                type: 'line',
                height: 280,
                toolbar: { show: false },
                zoom: { enabled: false }
            },
            colors: ['#009EF7', '#50CD89', '#FFC700'],
            stroke: { curve: 'smooth', width: 2 },
            markers: { size: 4 },
            xaxis: {
                categories: engagementData.labels,
                labels: { rotate: -30, style: { fontSize: '11px' } }
            },
            yaxis: {
                labels: { formatter: function (val) { return Math.round(val); } }
            },
            legend: { position: 'top' },
            grid: { borderColor: '#F4F4F4' },
            tooltip: {
                y: { formatter: function (val) { return val + ' contacts'; } }
            }
        };

        var engagementChart = new ApexCharts(engagementEl, engagementOptions);
        engagementChart.render();
    }

}());
</script>
@endpush

</x-default-layout>
