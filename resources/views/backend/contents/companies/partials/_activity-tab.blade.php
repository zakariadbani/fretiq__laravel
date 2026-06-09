{{--
    Activité tab pane — acquisition area chart (Phase 3).
    Chart is deferred until tab-show to avoid rendering at 0-width.
--}}

<div class="card">
    <div class="card-header border-0 pt-5">
        <h3 class="card-title fs-5 fw-bold">Acquisition de contacts</h3>
        <div class="card-toolbar">
            <span class="text-muted fw-semibold fs-7">12 derniers mois</span>
        </div>
    </div>
    <div class="card-body py-3">
        @if ($stats['contacts_total'] === 0)
            <div class="text-center py-12 text-muted">
                <i class="bi bi-people fs-2x mb-4 d-block"></i>
                <p class="fw-semibold fs-5 mb-2">Aucun contact enregistré.</p>
                <p class="fs-6">Le graphique d'acquisition apparaîtra dès l'ajout du premier contact.</p>
            </div>
        @else
            <div id="company_acquisition_chart" style="min-height: 300px;"></div>
        @endif
    </div>
</div>

@push('scripts')
<script>
"use strict";
(function () {
    var trigger = document.querySelector('[data-bs-toggle="tab"][href="#company_activity"]');

    function renderAcq() {
        var el = document.getElementById('company_acquisition_chart');
        if (!el || typeof ApexCharts === 'undefined' || el.dataset.rendered) return;
        el.dataset.rendered = '1';
        var data = @json($stats['contacts_over_time']);
        var opts = {
            series: [{ name: 'Contacts', data: data.series }],
            chart: {
                type: 'area',
                height: 300,
                toolbar: { show: false }
            },
            colors: ['#009EF7'],
            stroke: { curve: 'smooth', width: 2 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05 }
            },
            dataLabels: { enabled: false },
            markers: { size: 3 },
            xaxis: {
                categories: data.labels,
                labels: { rotate: -30, style: { fontSize: '11px' } }
            },
            yaxis: {
                labels: {
                    formatter: function (v) { return Math.round(v); }
                }
            },
            grid: { borderColor: '#F4F4F4' }
        };
        var maxV = Math.max.apply(null, data.series.concat([0]));
        opts.yaxis.tickAmount = Math.max(1, Math.min(maxV, 5));
        opts.yaxis.min = 0;
        opts.yaxis.max = Math.max(maxV, 1);
        new ApexCharts(el, opts).render();
    }

    if (trigger) {
        trigger.addEventListener('shown.bs.tab', renderAcq);
    }

    // Render immediately if this pane is already active (e.g. deep-link)
    var pane = document.getElementById('company_activity');
    if (pane && pane.classList.contains('active')) {
        renderAcq();
    }
}());
</script>
@endpush
