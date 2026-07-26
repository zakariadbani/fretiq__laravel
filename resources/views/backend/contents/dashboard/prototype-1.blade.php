<div data-testid="dashboard-kpis" class="row g-4 mb-6">
    @foreach ([
        ['label' => 'Entreprises visibles', 'value' => $enterprises['total'], 'icon' => 'bi-building', 'color' => 'primary', 'note' => $enterprises['with_contacts'].' avec contact'],
        ['label' => 'Campagnes actives', 'value' => $campaigns['active'], 'icon' => 'bi-megaphone', 'color' => 'success', 'note' => $campaigns['total'].' au total'],
        ['label' => 'Emails envoyés', 'value' => $kpis['emails_sent_30d'], 'icon' => 'bi-send', 'color' => 'info', 'note' => '30 derniers jours'],
        ['label' => 'Demandes', 'value' => $kpis['demandes_30d'], 'icon' => 'bi-inbox', 'color' => 'warning', 'note' => number_format($kpis['conversion_rate'], 1).' % de conversion'],
    ] as $metric)
        <div class="col-6 col-xl-3">
            <div class="card dashboard-kpi h-100">
                <div class="card-body p-5">
                    <div class="dashboard-icon bg-light-{{ $metric['color'] }} mb-4"><i class="bi {{ $metric['icon'] }} fs-2 text-{{ $metric['color'] }}"></i></div>
                    <div class="fs-2x fw-bolder text-gray-900">{{ number_format($metric['value']) }}</div>
                    <div class="fw-bold text-gray-700">{{ $metric['label'] }}</div>
                    <div class="text-muted fs-8 mt-1">{{ $metric['note'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-5 mb-6">
    <div class="col-xl-8" data-testid="dashboard-campaigns">
        <div class="card h-100">
            <div class="card-header border-0 pt-4">
                <div class="card-title d-flex flex-column">
                    <span class="fw-bold text-gray-900">Engagement des campagnes</span>
                    <span class="text-muted fs-7">Ouvertures, clics et réponses sur 8 semaines</span>
                </div>
                <div class="card-toolbar gap-4">
                    <div class="text-end"><span class="d-block fs-4 fw-bold">{{ number_format($kpis['open_rate'], 1) }} %</span><span class="text-muted fs-8">ouverture 30j</span></div>
                    <div class="text-end"><span class="d-block fs-4 fw-bold">{{ number_format($kpis['click_rate'], 1) }} %</span><span class="text-muted fs-8">clic 30j</span></div>
                </div>
            </div>
            <div class="card-body pt-0">
                @if (array_sum($engagementOverTime['series']['opens']) + array_sum($engagementOverTime['series']['clicks']) + array_sum($engagementOverTime['series']['replies']) > 0)
                    <div id="dashboard-engagement-chart"></div>
                @else
                    <div class="text-center py-15 text-muted"><i class="bi bi-graph-up fs-3x d-block mb-3"></i>Aucun engagement mesuré sur cette période.</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-xl-4" data-testid="dashboard-planning">
        <div class="card h-100 dashboard-accent {{ $planning['overdue_count'] ? 'dashboard-accent-danger' : 'dashboard-accent-success' }}">
            <div class="card-header border-0 pt-4">
                <div class="card-title d-flex flex-column"><span class="fw-bold">À surveiller</span><span class="text-muted fs-7">Planning et automatisation</span></div>
            </div>
            <div class="card-body pt-1">
                @if ($planning['overdue_count'])
                    <div class="notice d-flex bg-light-danger rounded border-danger border border-dashed p-4 mb-4">
                        <i class="bi bi-exclamation-triangle fs-2 text-danger me-3"></i>
                        <div><span class="fw-bold text-danger">{{ $planning['overdue_count'] }} campagne(s) en retard</span><div class="text-muted fs-8">Vérifiez le prochain déclenchement.</div></div>
                    </div>
                @endif
                <div class="fw-bold text-gray-800 mb-2">Prochaines échéances</div>
                @forelse ($planning['upcoming'] as $item)
                    <div class="dashboard-list-row d-flex align-items-center py-3 gap-3">
                        <span class="dashboard-icon bg-light-info flex-shrink-0"><i class="bi bi-calendar-event text-info"></i></span>
                        <div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d/m/Y H:i') }} · {{ $item['schedule_label'] }}</div></div>
                    </div>
                @empty
                    <div class="text-center text-muted py-8"><i class="bi bi-calendar-check fs-2x d-block mb-2"></i>Aucune échéance planifiée.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-5">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header border-0"><h2 class="card-title fs-4 fw-bold">Campagnes les plus performantes</h2></div>
            <div class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Campagne</th><th>Envoyés</th><th>Demandes</th><th>Conversion</th></tr></thead><tbody>
                    @forelse ($topCampaigns as $campaign)
                        <tr><td>@can('view campaigns')<a class="fw-bold text-gray-900 text-hover-primary" href="{{ route('admin.campaigns.view', $campaign['id']) }}">{{ $campaign['name'] }}</a>@else<span class="fw-bold">{{ $campaign['name'] }}</span>@endcan</td><td>{{ number_format($campaign['sent']) }}</td><td>{{ number_format($campaign['demandes']) }}</td><td><span class="badge badge-light-{{ $campaign['conversion_rate'] > 0 ? 'success' : 'secondary' }}">{{ number_format($campaign['conversion_rate'], 1) }} %</span></td></tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-8">Aucune campagne exécutée.</td></tr>
                    @endforelse
                    </tbody></table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4" data-testid="dashboard-criteria">
        <div class="card h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Découverte</span><span class="text-muted fs-7">Critères et collecte</span></div></div>
            <div class="card-body pt-1">
                <div class="row text-center mb-4"><div class="col-4"><div class="fs-2 fw-bold">{{ $criteria['total'] }}</div><div class="text-muted fs-8">critères</div></div><div class="col-4"><div class="fs-2 fw-bold text-success">{{ $criteria['active'] }}</div><div class="text-muted fs-8">actifs</div></div><div class="col-4"><div class="fs-2 fw-bold text-primary">{{ $criteria['auto_run'] }}</div><div class="text-muted fs-8">automatiques</div></div></div>
                @foreach (array_slice($criteria['rows'], 0, 3) as $item)
                    <div class="dashboard-list-row d-flex align-items-center justify-content-between py-3 gap-3"><div class="dashboard-name fw-semibold">{{ $item['name'] }}</div><span class="badge badge-light-{{ $item['is_active'] ? 'success' : 'secondary' }}">{{ $item['companies_count'] }} entreprises</span></div>
                @endforeach
            </div>
        </div>
    </div>
</div>
