@php
    $qualified = (int) ($enterprises['qualification']['qualified'] ?? 0);
    $pending = (int) ($enterprises['qualification']['pending'] ?? 0);
    $converted = (int) ($enterprises['qualification']['converted'] ?? 0);
    $coverage = $enterprises['total'] > 0 ? round($enterprises['with_contacts'] / $enterprises['total'] * 100) : 0;
@endphp

<div data-testid="dashboard-kpis" class="row g-4 mb-6">
    @foreach ([
        ['label' => 'Entreprises', 'value' => $enterprises['total'], 'note' => 'pipeline visible', 'color' => 'primary'],
        ['label' => 'À qualifier', 'value' => $pending, 'note' => 'prochaine priorité', 'color' => 'warning'],
        ['label' => 'Qualifiées', 'value' => $qualified, 'note' => 'prêtes à cibler', 'color' => 'success'],
        ['label' => 'Couverture contact', 'value' => $coverage.' %', 'note' => $enterprises['with_contacts'].' entreprises couvertes', 'color' => 'info'],
    ] as $metric)
        <div class="col-6 col-xl-3"><div class="card dashboard-kpi h-100"><div class="card-body p-5"><div class="text-uppercase text-muted fs-8 fw-bold mb-3">{{ $metric['label'] }}</div><div class="fs-2x fw-bolder text-{{ $metric['color'] }}">{{ is_numeric($metric['value']) ? number_format($metric['value']) : $metric['value'] }}</div><div class="text-muted fs-8">{{ $metric['note'] }}</div></div></div></div>
    @endforeach
</div>

<div class="row g-5 mb-6">
    <div class="col-xl-7" data-testid="dashboard-enterprises">
        <div class="card h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Entreprises récemment ajoutées</span><span class="text-muted fs-7">Qualification et richesse du contact</span></div>@can('view companies')<div class="card-toolbar"><a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light-primary">Explorer</a></div>@endcan</div>
            <div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Entreprise</th><th>Source</th><th>Qualification</th><th>Contacts</th></tr></thead><tbody>
                @forelse ($enterprises['recent'] as $company)
                    @php
                        $qualificationMeta = config('global.data.company_qualification_statuses.'.$company['qualification_status'], ['label' => $company['qualification_status'] ?: 'À qualifier', 'color' => 'secondary']);
                        $sourceMeta = config('global.data.company_sources.'.$company['source'], ['label' => $company['source'] ?: 'Inconnue', 'color' => 'secondary']);
                    @endphp
                    <tr><td>@can('view companies')<a href="{{ route('admin.companies.view', $company['id']) }}" class="fw-bold text-gray-900 text-hover-primary dashboard-name d-block">{{ $company['name'] }}</a>@else<span class="fw-bold">{{ $company['name'] }}</span>@endcan<div class="text-muted fs-8">Ajoutée {{ $company['created_at']->diffForHumans() }}</div></td><td><span class="badge badge-light-{{ $sourceMeta['color'] }}">{{ $sourceMeta['label'] }}</span></td><td><span class="badge badge-light-{{ $qualificationMeta['color'] }}">{{ $qualificationMeta['label'] }}</span></td><td><span class="fw-bold">{{ $company['contacts_count'] }}</span></td></tr>
                @empty<tr><td colspan="4" class="text-center text-muted py-10">Aucune entreprise dans le pipeline.</td></tr>@endforelse
            </tbody></table></div></div>
        </div>
    </div>
    <div class="col-xl-5" data-testid="dashboard-campaigns">
        <div class="card h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Progression de la prospection</span><span class="text-muted fs-7">Volumes globaux par étape, à lire comme indicateurs</span></div></div>
            <div class="card-body pt-0">
                @if (array_sum($funnel) > 0)<div id="dashboard-funnel-chart"></div>@else<div class="text-center py-12 text-muted"><i class="bi bi-funnel fs-3x d-block mb-3"></i>Aucune donnée de progression.</div>@endif
            </div>
        </div>
    </div>
</div>

<div class="row g-5">
    <div class="col-xl-7" data-testid="dashboard-criteria">
        <div class="card h-100">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Rendement des critères de découverte</span><span class="text-muted fs-7">Entreprises et contacts collectés par ciblage</span></div></div>
            <div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Critère</th><th>Mode</th><th>Entreprises</th><th>Contacts</th><th>Dernier run</th></tr></thead><tbody>
                @forelse ($criteria['rows'] as $item)
                    @php $runMeta = config('global.data.discovery_run_statuses.'.$item['latest_run_status'], ['label' => 'Jamais', 'color' => 'secondary']); @endphp
                    <tr><td>@can('view prospect_criteria')<a href="{{ route('admin.prospect_criteria.view', $item['id']) }}" class="fw-bold text-gray-900 text-hover-primary">{{ $item['name'] }}</a>@else<span class="fw-bold">{{ $item['name'] }}</span>@endcan</td><td><span class="badge badge-light-{{ $item['auto_run'] ? 'primary' : 'secondary' }}">{{ $item['auto_run'] ? 'Auto' : 'Manuel' }}</span></td><td>{{ number_format($item['companies_count']) }}</td><td>{{ number_format($item['contacts_count']) }}</td><td><span class="badge badge-light-{{ $runMeta['color'] }}">{{ $runMeta['label'] }}</span></td></tr>
                @empty<tr><td colspan="5" class="text-center text-muted py-8">Aucun critère configuré.</td></tr>@endforelse
            </tbody></table></div></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-5" data-testid="dashboard-planning">
            <div class="card-body d-flex align-items-center gap-4"><span class="dashboard-icon bg-light-info"><i class="bi bi-calendar3 text-info fs-2"></i></span><div class="flex-grow-1"><div class="fw-bold">Prochaine activation</div>@if ($planning['upcoming'])<div class="text-muted fs-8">{{ $planning['upcoming'][0]['name'] }} · {{ $planning['upcoming'][0]['scheduled_at']->setTimezone($planning['upcoming'][0]['timezone'])->format('d/m/Y H:i') }}</div>@else<div class="text-muted fs-8">Aucune campagne planifiée</div>@endif</div>@can('view campaigns')<a href="{{ route('admin.planner.index') }}" class="btn btn-sm btn-icon btn-light-info" aria-label="Voir le planning"><i class="bi bi-arrow-right"></i></a>@endcan</div>
        </div>
        <div class="card">
            <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Résultat aval</span><span class="text-muted fs-7">30 derniers jours</span></div></div>
            <div class="card-body pt-0"><div class="d-flex justify-content-between py-3 border-bottom border-gray-200"><span class="text-muted">Emails envoyés</span><span class="fw-bold">{{ number_format($kpis['emails_sent_30d']) }}</span></div><div class="d-flex justify-content-between py-3 border-bottom border-gray-200"><span class="text-muted">Taux d’ouverture</span><span class="fw-bold">{{ number_format($kpis['open_rate'], 1) }} %</span></div><div class="d-flex justify-content-between py-3"><span class="text-muted">Demandes générées</span><span class="fw-bold text-success">{{ number_format($kpis['demandes_30d']) }}</span></div></div>
        </div>
    </div>
</div>
