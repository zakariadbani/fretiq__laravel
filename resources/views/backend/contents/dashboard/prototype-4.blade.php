<div data-testid="dashboard-kpis" class="row g-4 mb-6">
    @foreach ([
        ['label' => 'Envois 30j', 'value' => number_format($kpis['emails_sent_30d']), 'icon' => 'bi-send', 'color' => 'primary'],
        ['label' => 'Ouverture', 'value' => number_format($kpis['open_rate'], 1).' %', 'icon' => 'bi-eye', 'color' => 'info'],
        ['label' => 'Clic', 'value' => number_format($kpis['click_rate'], 1).' %', 'icon' => 'bi-cursor', 'color' => 'success'],
        ['label' => 'Conversion', 'value' => number_format($kpis['conversion_rate'], 1).' %', 'icon' => 'bi-graph-up-arrow', 'color' => 'warning'],
    ] as $metric)
        <div class="col-6 col-lg-3"><div class="card dashboard-kpi h-100"><div class="card-body py-4 px-5 d-flex align-items-center gap-3"><span class="dashboard-icon bg-light-{{ $metric['color'] }}"><i class="bi {{ $metric['icon'] }} text-{{ $metric['color'] }} fs-2"></i></span><div><div class="fs-3 fw-bolder">{{ $metric['value'] }}</div><div class="text-muted fs-8">{{ $metric['label'] }}</div></div></div></div></div>
    @endforeach
</div>

<div class="row g-5">
    <div class="col-xl-6" data-testid="dashboard-campaigns">
        <section class="card dashboard-quadrant h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold"><i class="bi bi-megaphone text-primary me-2"></i>Campagnes</span><span class="text-muted fs-7">Performance et activité</span></div>@can('view campaigns')<div class="card-toolbar"><a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-light-primary">Voir tout</a></div>@endcan</div>
            <div class="card-body pt-0">
                <div class="row text-center bg-light-primary rounded py-4 mb-4"><div class="col-4"><div class="fs-2 fw-bold">{{ $campaigns['total'] }}</div><div class="text-muted fs-8">total</div></div><div class="col-4"><div class="fs-2 fw-bold text-success">{{ $campaigns['active'] }}</div><div class="text-muted fs-8">actives</div></div><div class="col-4"><div class="fs-2 fw-bold text-primary">{{ $kpis['emails_sent_30d'] }}</div><div class="text-muted fs-8">envois 30j</div></div></div>
                @forelse ($campaigns['rows'] as $item)
                    <div class="dashboard-list-row d-flex align-items-center py-3 gap-3"><span class="w-8px h-8px rounded-circle bg-{{ $item['is_active'] ? 'success' : 'secondary' }} flex-shrink-0"></span><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['schedule_label'] }} · {{ number_format($item['sent']) }} envoyés</div></div><span class="fw-semibold fs-8">{{ $item['open_rate'] === null ? '—' : number_format($item['open_rate'], 1).' % ouv.' }}</span></div>
                @empty<div class="text-center text-muted py-8">Aucune campagne.</div>@endforelse
            </div>
        </section>
    </div>

    <div class="col-xl-6" data-testid="dashboard-criteria">
        <section class="card dashboard-quadrant h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold"><i class="bi bi-search text-info me-2"></i>Critères de découverte</span><span class="text-muted fs-7">Automatisation et rendement</span></div>@can('view prospect_criteria')<div class="card-toolbar"><a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-sm btn-light-info">Voir tout</a></div>@endcan</div>
            <div class="card-body pt-0">
                <div class="row text-center bg-light-info rounded py-4 mb-4"><div class="col-4"><div class="fs-2 fw-bold">{{ $criteria['total'] }}</div><div class="text-muted fs-8">total</div></div><div class="col-4"><div class="fs-2 fw-bold text-success">{{ $criteria['active'] }}</div><div class="text-muted fs-8">actifs</div></div><div class="col-4"><div class="fs-2 fw-bold text-info">{{ $criteria['auto_run'] }}</div><div class="text-muted fs-8">automatiques</div></div></div>
                @forelse ($criteria['rows'] as $item)
                    @php $runMeta = config('global.data.discovery_run_statuses.'.$item['latest_run_status'], ['label' => 'Jamais', 'color' => 'secondary']); @endphp
                    <div class="dashboard-list-row d-flex align-items-center py-3 gap-3"><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['companies_count'] }} entreprises · {{ $item['contacts_count'] }} contacts</div></div><span class="badge badge-light-{{ $runMeta['color'] }}">{{ $runMeta['label'] }}</span></div>
                @empty<div class="text-center text-muted py-8">Aucun critère configuré.</div>@endforelse
            </div>
        </section>
    </div>

    <div class="col-xl-6" data-testid="dashboard-planning">
        <section class="card dashboard-quadrant h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold"><i class="bi bi-calendar3 text-warning me-2"></i>Planning</span><span class="text-muted fs-7">Échéances et alertes</span></div>@can('view campaigns')<div class="card-toolbar"><a href="{{ route('admin.planner.index') }}" class="btn btn-sm btn-light-warning">Calendrier</a></div>@endcan</div>
            <div class="card-body pt-0">
                <div class="row text-center bg-light-warning rounded py-4 mb-4"><div class="col-6"><div class="fs-2 fw-bold text-danger">{{ $planning['overdue_count'] }}</div><div class="text-muted fs-8">en retard</div></div><div class="col-6"><div class="fs-2 fw-bold text-info">{{ $planning['upcoming_count'] }}</div><div class="text-muted fs-8">à venir</div></div></div>
                @forelse ($planning['upcoming'] as $item)
                    <div class="dashboard-list-row d-flex align-items-center py-3 gap-3"><span class="dashboard-icon bg-light-warning flex-shrink-0"><i class="bi bi-clock text-warning"></i></span><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d/m/Y H:i') }} · {{ $item['schedule_label'] }}</div></div></div>
                @empty<div class="text-center text-muted py-8">Aucune échéance à venir.</div>@endforelse
            </div>
        </section>
    </div>

    <div class="col-xl-6" data-testid="dashboard-enterprises">
        <section class="card dashboard-quadrant h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold"><i class="bi bi-building text-success me-2"></i>Entreprises</span><span class="text-muted fs-7">Couverture et qualification</span></div>@can('view companies')<div class="card-toolbar"><a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light-success">Voir tout</a></div>@endcan</div>
            <div class="card-body pt-0">
                <div class="row text-center bg-light-success rounded py-4 mb-4"><div class="col-4"><div class="fs-2 fw-bold">{{ $enterprises['total'] }}</div><div class="text-muted fs-8">visibles</div></div><div class="col-4"><div class="fs-2 fw-bold text-success">{{ $enterprises['with_contacts'] }}</div><div class="text-muted fs-8">avec contact</div></div><div class="col-4"><div class="fs-2 fw-bold text-primary">{{ $enterprises['qualification']['qualified'] ?? 0 }}</div><div class="text-muted fs-8">qualifiées</div></div></div>
                @forelse ($enterprises['recent'] as $company)
                    @php $qualificationMeta = config('global.data.company_qualification_statuses.'.$company['qualification_status'], ['label' => $company['qualification_status'] ?: 'À qualifier', 'color' => 'secondary']); @endphp
                    <div class="dashboard-list-row d-flex align-items-center py-3 gap-3"><span class="dashboard-icon bg-light-success flex-shrink-0"><i class="bi bi-building text-success"></i></span><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $company['name'] }}</div><div class="text-muted fs-8">{{ $company['contacts_count'] }} contact(s)</div></div><span class="badge badge-light-{{ $qualificationMeta['color'] }}">{{ $qualificationMeta['label'] }}</span></div>
                @empty<div class="text-center text-muted py-8">Aucune entreprise.</div>@endforelse
            </div>
        </section>
    </div>
</div>
