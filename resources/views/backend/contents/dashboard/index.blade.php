<x-default-layout>

@section('title')
    Tableau de bord
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Tableau de bord']]" />
@endsection

@php
    $concepts = [
        1 => ['title' => 'Cockpit exécutif', 'subtitle' => 'La performance et les décisions importantes en un regard.', 'icon' => 'bi-speedometer2'],
        2 => ['title' => 'Centre de commandement', 'subtitle' => 'Les priorités opérationnelles, échéances et points d’attention.', 'icon' => 'bi-command'],
        3 => ['title' => 'Pipeline de prospection', 'subtitle' => 'De la découverte des entreprises jusqu’aux résultats des campagnes.', 'icon' => 'bi-funnel'],
        4 => ['title' => 'Vue portefeuille', 'subtitle' => 'Une lecture équilibrée de chaque pilier de la prospection.', 'icon' => 'bi-grid-1x2'],
    ];
    $concept = $concepts[$prototype];
@endphp

<div data-testid="dashboard-shell" data-prototype="{{ $prototype }}">
    <section class="dashboard-hero card border-0 mb-6 overflow-hidden">
        <div class="card-body p-6 p-lg-8 position-relative">
            <div class="dashboard-orb dashboard-orb-one"></div>
            <div class="dashboard-orb dashboard-orb-two"></div>
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-5 position-relative">
                <div>
                    <div class="text-white-50 fw-semibold fs-7 text-uppercase ls-1 mb-2">Bonjour, {{ auth()->user()->name }}</div>
                    <h1 class="text-white fw-bolder fs-2x mb-2">{{ $concept['title'] }}</h1>
                    <p class="text-white-75 fs-6 mb-0">{{ $concept['subtitle'] }}</p>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    @can('create campaigns')
                        <a href="{{ route('admin.campaigns.create') }}" class="btn btn-sm btn-light-primary">
                            <i class="bi bi-plus-lg"></i> Nouvelle campagne
                        </a>
                    @endcan
                    @can('view campaigns')
                        <a href="{{ route('admin.planner.index') }}" class="btn btn-sm btn-light">
                            <i class="bi bi-calendar3"></i> Ouvrir le planning
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    </section>

    <nav class="card mb-6" aria-label="Choisir un prototype" data-testid="dashboard-prototype-switcher">
        <div class="card-body py-3 px-4">
            <div class="d-flex flex-nowrap overflow-auto gap-2 dashboard-switcher">
                @foreach ($concepts as $id => $item)
                    <a href="{{ route('admin.dashboard', ['prototype' => $id]) }}"
                       class="btn btn-sm flex-shrink-0 {{ $prototype === $id ? 'btn-primary' : 'btn-light' }}"
                       @if ($prototype === $id) aria-current="page" @endif>
                        <i class="bi {{ $item['icon'] }}"></i>
                        {{ $id }}. {{ $item['title'] }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    @include("backend.contents.dashboard.prototype-{$prototype}")
</div>

@push('styles')
<style>
    .dashboard-hero { background: linear-gradient(125deg, #071b33 0%, #123d67 58%, #1769aa 100%); }
    .dashboard-hero .text-white-75 { color: rgba(255,255,255,.76); }
    .dashboard-orb { position: absolute; border-radius: 50%; background: rgba(255,255,255,.07); pointer-events: none; }
    .dashboard-orb-one { width: 260px; height: 260px; right: 7%; top: -160px; }
    .dashboard-orb-two { width: 150px; height: 150px; right: 28%; bottom: -110px; }
    .dashboard-switcher { scrollbar-width: thin; }
    .dashboard-kpi { border: 1px solid var(--bs-gray-200); transition: transform .16s ease, box-shadow .16s ease; }
    .dashboard-kpi:hover { transform: translateY(-2px); box-shadow: var(--bs-box-shadow-sm); }
    .dashboard-icon { width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; }
    .dashboard-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashboard-list-row + .dashboard-list-row { border-top: 1px dashed var(--bs-gray-300); }
    .dashboard-accent { border-left: 4px solid var(--bs-primary); }
    .dashboard-accent-danger { border-left-color: var(--bs-danger); }
    .dashboard-accent-success { border-left-color: var(--bs-success); }
    .dashboard-quadrant { min-height: 420px; }
    @media (max-width: 767.98px) {
        .dashboard-hero .card-body { min-height: 250px; }
        .dashboard-quadrant { min-height: auto; }
    }
</style>
@endpush

@push('scripts')
<script>
"use strict";
(function () {
    if (typeof ApexCharts === 'undefined') return;

    var engagementEl = document.getElementById('dashboard-engagement-chart');
    if (engagementEl) {
        var engagement = @json($engagementOverTime);
        new ApexCharts(engagementEl, {
            series: [
                { name: 'Ouvertures', data: engagement.series.opens },
                { name: 'Clics', data: engagement.series.clicks },
                { name: 'Réponses', data: engagement.series.replies }
            ],
            chart: { type: 'area', height: 300, toolbar: { show: false }, zoom: { enabled: false } },
            colors: ['#3e97ff', '#50cd89', '#f6c000'],
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { opacityFrom: .22, opacityTo: .03 } },
            dataLabels: { enabled: false },
            xaxis: { categories: engagement.labels, labels: { rotate: -25 } },
            yaxis: { min: 0, labels: { formatter: function (value) { return Math.round(value); } } },
            grid: { borderColor: '#eff2f5' },
            legend: { position: 'top', horizontalAlign: 'right' }
        }).render();
    }

    var funnelEl = document.getElementById('dashboard-funnel-chart');
    if (funnelEl) {
        var funnel = @json($funnel);
        new ApexCharts(funnelEl, {
            series: [{ name: 'Volume', data: Object.values(funnel) }],
            chart: { type: 'bar', height: 310, toolbar: { show: false } },
            colors: ['#3e97ff'],
            plotOptions: { bar: { horizontal: true, borderRadius: 5, distributed: true } },
            dataLabels: { enabled: true },
            xaxis: { categories: Object.keys(funnel), min: 0 },
            grid: { borderColor: '#eff2f5' },
            legend: { show: false }
        }).render();
    }
}());
</script>
@endpush

</x-default-layout>
