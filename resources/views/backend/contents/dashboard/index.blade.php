<x-default-layout>

@section('title')
    Tableau de bord
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Tableau de bord']]" />
@endsection

<div data-testid="dashboard-shell">
    <section class="dashboard-hero card border-0 mb-6 overflow-hidden">
        <div class="card-body p-6 p-lg-8 position-relative">
            <div class="dashboard-orb dashboard-orb-one"></div>
            <div class="dashboard-orb dashboard-orb-two"></div>
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-5 position-relative">
                <div>
                    <div class="text-white-50 fw-semibold fs-7 text-uppercase ls-1 mb-2">Bonjour, {{ auth()->user()->name }}</div>
                    <h2 class="text-white fw-bolder fs-2x mb-2">Centre de commandement</h2>
                    <p class="text-white-75 fs-6 mb-0">Les priorités opérationnelles, échéances et résultats de la prospection.</p>
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

    @include('backend.contents.dashboard.operational')
</div>

@push('styles')
<style>
    .dashboard-hero { background: linear-gradient(125deg, #071b33 0%, #123d67 58%, #1769aa 100%); }
    .dashboard-hero .text-white-75 { color: rgba(255,255,255,.76); }
    .dashboard-orb { position: absolute; border-radius: 50%; background: rgba(255,255,255,.07); pointer-events: none; }
    .dashboard-orb-one { width: 260px; height: 260px; right: 7%; top: -160px; }
    .dashboard-orb-two { width: 150px; height: 150px; right: 28%; bottom: -110px; }
    .dashboard-kpi { border: 1px solid var(--bs-gray-200); transition: transform .16s ease, box-shadow .16s ease; }
    .dashboard-kpi:hover { transform: translateY(-2px); box-shadow: var(--bs-box-shadow-sm); }
    .dashboard-icon { width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; }
    .dashboard-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dashboard-list-row + .dashboard-list-row { border-top: 1px dashed var(--bs-gray-300); }
    .dashboard-accent { border-left: 4px solid var(--bs-primary); }
    .dashboard-accent-danger { border-left-color: var(--bs-danger); }
    .dashboard-accent-success { border-left-color: var(--bs-success); }
    .dashboard-chart { min-height: 320px; }
    .dashboard-empty { background: var(--bs-gray-100); border-radius: .75rem; }
    @media (max-width: 767.98px) {
        .dashboard-hero .card-body { min-height: 250px; }
    }
</style>
@endpush

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof ApexCharts === 'undefined') {
            return;
        }

        const engagementElement = document.getElementById('dashboard-engagement-chart-canvas');
        if (engagementElement) {
            const engagementFallback = document.getElementById('dashboard-engagement-chart-fallback');
            const engagementChart = new ApexCharts(engagementElement, {
                chart: { type: 'line', height: 320, toolbar: { show: false } },
                series: [
                    { name: 'Ouvertures', data: @json($engagementOverTime['series']['opens'] ?? []) },
                    { name: 'Clics', data: @json($engagementOverTime['series']['clicks'] ?? []) },
                    { name: 'Réponses', data: @json($engagementOverTime['series']['replies'] ?? []) },
                ],
                xaxis: { categories: @json($engagementOverTime['labels'] ?? []) },
                colors: ['#009ef7', '#50cd89', '#7239ea'],
                stroke: { curve: 'smooth', width: 3 },
                dataLabels: { enabled: false },
                legend: { position: 'top', horizontalAlign: 'left' },
                grid: { borderColor: '#eff2f5', strokeDashArray: 4 },
                noData: { text: 'Aucun engagement mesuré' },
            });
            engagementChart.render().then(function () {
                if (engagementFallback) {
                    engagementFallback.classList.add('d-none');
                }
            });
        }

        const funnelElement = document.getElementById('dashboard-funnel-chart-canvas');
        if (funnelElement) {
            const funnelFallback = document.getElementById('dashboard-funnel-chart-fallback');
            const funnelChart = new ApexCharts(funnelElement, {
                chart: { type: 'bar', height: 320, toolbar: { show: false } },
                series: [{ name: 'Prospects', data: @json(array_values($funnel)) }],
                xaxis: {
                    categories: @json(array_keys($funnel)),
                    labels: { formatter: function (value) { return Math.round(value); } },
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 4,
                        distributed: true,
                    },
                },
                colors: ['#009ef7', '#3e97ff', '#50cd89', '#ffc700', '#7239ea', '#f1416c'],
                dataLabels: { enabled: true },
                legend: { show: false },
                grid: { borderColor: '#eff2f5', strokeDashArray: 4 },
                noData: { text: 'Aucune donnée de parcours' },
            });
            funnelChart.render().then(function () {
                if (funnelFallback) {
                    funnelFallback.classList.add('d-none');
                }
            });
        }
    });
</script>
@endpush

</x-default-layout>
