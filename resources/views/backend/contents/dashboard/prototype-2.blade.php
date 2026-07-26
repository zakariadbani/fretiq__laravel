<div data-testid="dashboard-kpis" class="row g-4 mb-6">
    @foreach ([
        ['label' => 'Retards', 'value' => $planning['overdue_count'], 'icon' => 'bi-exclamation-octagon', 'color' => $planning['overdue_count'] ? 'danger' : 'success'],
        ['label' => 'À venir', 'value' => $planning['upcoming_count'], 'icon' => 'bi-calendar2-week', 'color' => 'info'],
        ['label' => 'Campagnes actives', 'value' => $campaigns['active'], 'icon' => 'bi-broadcast', 'color' => 'primary'],
        ['label' => 'Critères automatiques', 'value' => $criteria['auto_run'], 'icon' => 'bi-stars', 'color' => 'warning'],
    ] as $metric)
        <div class="col-6 col-xl-3"><div class="card dashboard-kpi h-100"><div class="card-body p-5 d-flex align-items-center gap-4"><span class="dashboard-icon bg-light-{{ $metric['color'] }}"><i class="bi {{ $metric['icon'] }} fs-2 text-{{ $metric['color'] }}"></i></span><div><div class="fs-2 fw-bolder">{{ number_format($metric['value']) }}</div><div class="text-muted fw-semibold fs-7">{{ $metric['label'] }}</div></div></div></div></div>
    @endforeach
</div>

<div class="row g-5 mb-6" data-testid="dashboard-planning">
    <div class="col-xl-5">
        <div class="card h-100 dashboard-accent dashboard-accent-danger">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold text-danger">Échéances dépassées</span><span class="text-muted fs-7">À traiter avant les prochains envois</span></div></div>
            <div class="card-body pt-0">
                @forelse ($planning['overdue'] as $item)
                    <div class="dashboard-list-row d-flex align-items-center py-4 gap-3">
                        <span class="dashboard-icon bg-light-danger flex-shrink-0"><i class="bi bi-clock-history text-danger"></i></span>
                        <div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-danger fs-8">Prévue le {{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d/m/Y à H:i') }}</div></div>
                        @can('view campaigns')<a href="{{ route('admin.campaigns.view', $item['id']) }}" class="btn btn-sm btn-icon btn-light-danger" aria-label="Voir {{ $item['name'] }}"><i class="bi bi-arrow-right"></i></a>@endcan
                    </div>
                @empty
                    <div class="text-center py-12 text-muted"><i class="bi bi-check-circle text-success fs-3x d-block mb-3"></i>Aucun retard détecté.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card h-100 dashboard-accent">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Prochaines opérations</span><span class="text-muted fs-7">Campagnes actives ordonnées par date</span></div>@can('view campaigns')<div class="card-toolbar"><a href="{{ route('admin.planner.index') }}" class="btn btn-sm btn-light-primary">Planning complet</a></div>@endcan</div>
            <div class="card-body pt-0">
                @forelse ($planning['upcoming'] as $item)
                    <div class="dashboard-list-row d-flex align-items-center py-4 gap-4">
                        <div class="text-center flex-shrink-0"><div class="fw-bolder fs-3 text-primary">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d') }}</div><div class="text-muted fs-9 text-uppercase">{{ $item['scheduled_at']->setTimezone($item['timezone'])->translatedFormat('M') }}</div></div>
                        <div class="flex-grow-1 dashboard-name"><div class="fw-bold fs-6 dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('H:i') }} · {{ $item['schedule_label'] }}</div></div>
                        <span class="badge badge-light-info">Planifiée</span>
                    </div>
                @empty
                    <div class="text-center py-12 text-muted"><i class="bi bi-calendar2-check fs-3x d-block mb-3"></i>Aucune opération à venir.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-5">
    <div class="col-xl-7" data-testid="dashboard-campaigns">
        <div class="card h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Santé des campagnes</span><span class="text-muted fs-7">Statut, cadence et engagement</span></div></div>
            <div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Campagne</th><th>Statut</th><th>Type</th><th>Envoyés</th><th>Ouverture</th></tr></thead><tbody>
                @forelse ($campaigns['rows'] as $item)
                    <tr><td><div class="dashboard-name fw-bold">{{ $item['name'] }}</div></td><td><span class="badge badge-light-{{ $item['is_active'] ? 'success' : 'secondary' }}">{{ $item['is_active'] ? 'Active' : 'En pause' }}</span></td><td>{{ $item['schedule_label'] }}</td><td>{{ number_format($item['sent']) }}</td><td>{{ $item['open_rate'] === null ? '—' : number_format($item['open_rate'], 1).' %' }}</td></tr>
                @empty<tr><td colspan="5" class="text-center text-muted py-8">Aucune campagne configurée.</td></tr>@endforelse
            </tbody></table></div></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-5" data-testid="dashboard-criteria">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Automatisation de la découverte</span><span class="text-muted fs-7">Critères actifs et derniers résultats</span></div></div>
            <div class="card-body pt-0">
                @forelse (array_slice($criteria['rows'], 0, 3) as $item)
                    @php $runMeta = config('global.data.discovery_run_statuses.'.$item['latest_run_status'], ['label' => 'Jamais exécuté', 'color' => 'secondary']); @endphp
                    <div class="dashboard-list-row d-flex justify-content-between align-items-center py-3 gap-3"><div class="dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['auto_run'] ? 'Auto à '.str_pad((string) $item['run_at_hour'], 2, '0', STR_PAD_LEFT).'h' : 'Déclenchement manuel' }} · {{ $item['companies_count'] }} entreprises</div></div><span class="badge badge-light-{{ $runMeta['color'] }}">{{ $runMeta['label'] }}</span></div>
                @empty<div class="text-center text-muted py-8">Aucun critère de découverte.</div>@endforelse
            </div>
        </div>
        <div class="card" data-testid="dashboard-enterprises">
            <div class="card-body d-flex align-items-center justify-content-between gap-4"><div><div class="text-muted fs-7">Couverture entreprises</div><div class="fs-2 fw-bolder">{{ $enterprises['with_contacts'] }} / {{ $enterprises['total'] }}</div><div class="text-muted fs-8">ont au moins un contact</div></div>@can('view companies')<a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light-primary">Voir les entreprises</a>@endcan</div>
        </div>
    </div>
</div>
