@php
    $engagementSeries = $engagementOverTime['series'] ?? ['opens' => [], 'clicks' => [], 'replies' => []];
    $hasEngagement = array_sum($engagementSeries['opens'] ?? []) + array_sum($engagementSeries['clicks'] ?? []) + array_sum($engagementSeries['replies'] ?? []) > 0;
    $hasFunnel = ! empty($funnel) && array_sum($funnel) > 0;
    $contactCoverage = $enterprises['total'] > 0 ? round(($enterprises['with_contacts'] / $enterprises['total']) * 100, 1) : 0.0;
@endphp

<section data-testid="dashboard-operational-priorities" class="mb-6">
    <div class="mb-4"><h2 class="fs-3 fw-bold mb-1">Priorités opérationnelles</h2><p class="text-muted mb-0">Ce qui demande une action aujourd’hui.</p></div>
    <div class="row g-4">
        @foreach ([
            [$operations['replies_awaiting_triage'], 'Réponses à traiter', 'reply', 'primary'],
            [$operations['failed_sends'] + $operations['stale_sends'], 'Incidents d’envoi', 'exclamation-triangle', 'danger'],
            [$planning['overdue_count'], 'Campagnes en retard', 'clock-history', 'warning'],
            [$planning['upcoming_count'], 'Campagnes à venir', 'calendar2-week', 'info'],
        ] as [$value, $label, $icon, $color])
            <div class="col-6 col-xl-3"><div class="card dashboard-kpi h-100"><div class="card-body p-5 d-flex align-items-center gap-4"><span class="dashboard-icon bg-light-{{ $color }}"><i class="bi bi-{{ $icon }} fs-2 text-{{ $color }}"></i></span><div><div class="fs-2 fw-bolder">{{ number_format($value) }}</div><div class="text-muted fw-semibold fs-7">{{ $label }}</div></div></div></div></div>
        @endforeach
    </div>
</section>

<section data-testid="dashboard-executive-kpis" class="card mb-6">
    <div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Vue exécutive</span><span class="text-muted fs-7">Acquisition, engagement et conversion sur 30 jours</span></div></div>
    <div class="card-body pt-0"><div class="row g-4">
        @foreach ([
            [number_format($kpis['companies']), 'Entreprises', 'ki-bank'],
            [number_format($kpis['emails_sent_30d']), 'Emails envoyés', 'ki-paper-plane'],
            [number_format($kpis['open_rate'], 1).' %', 'Taux d’ouverture', 'ki-eye'],
            [number_format($kpis['contacts']), 'Contacts', 'ki-people'],
            [number_format($kpis['active_campaigns']), 'Campagnes actives', 'ki-rocket'],
            [number_format($campaigns['total']), 'Campagnes totales', 'ki-chart-simple'],
        ] as [$value, $label, $icon])
            <div class="col-6 col-lg-3"><div class="border rounded p-4 h-100 d-flex align-items-center"><i class="ki-outline {{ $icon }} fs-2x text-primary me-3"></i><div><div class="fs-2 fw-bolder lh-1">{{ $value }}</div><div class="text-muted fs-7 fw-semibold">{{ $label }}</div></div></div></div>
        @endforeach
    </div></div>
</section>

<div class="row g-5 mb-6">
    <section class="col-xl-7" data-testid="dashboard-engagement-chart" aria-labelledby="dashboard-engagement-title"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span id="dashboard-engagement-title" class="fw-bold">Engagement dans le temps</span><span class="text-muted fs-7">Ouvertures, clics et réponses sur huit semaines</span></div></div><div class="card-body pt-0">
        @if ($hasEngagement)
            <div id="dashboard-engagement-chart-canvas" class="dashboard-chart" role="img" aria-label="Ouvertures, clics et réponses par semaine"></div>
            <div id="dashboard-engagement-chart-fallback" data-testid="dashboard-engagement-fallback" class="dashboard-empty text-center text-muted py-12">
                <i class="bi bi-bar-chart fs-3x d-block mb-3"></i>Graphique indisponible. Les valeurs restent disponibles ci-dessous.
            </div>
            <ul data-testid="dashboard-engagement-summary" class="visually-hidden">
                @foreach (($engagementOverTime['labels'] ?? []) as $index => $label)
                    <li>{{ $label }} : {{ $engagementSeries['opens'][$index] ?? 0 }} ouvertures, {{ $engagementSeries['clicks'][$index] ?? 0 }} clics, {{ $engagementSeries['replies'][$index] ?? 0 }} réponses.</li>
                @endforeach
            </ul>
        @else<div data-testid="dashboard-engagement-empty" class="dashboard-empty text-center text-muted py-12"><i class="bi bi-activity fs-3x d-block mb-3"></i>Aucun engagement mesuré.</div>@endif
    </div></div></section>
    <section class="col-xl-5" data-testid="dashboard-funnel-chart" aria-labelledby="dashboard-funnel-title"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span id="dashboard-funnel-title" class="fw-bold">Parcours de prospection</span><span class="text-muted fs-7">Du prospect découvert à la demande reçue</span></div></div><div class="card-body pt-0">
        @if ($hasFunnel)
            <div id="dashboard-funnel-chart-canvas" class="dashboard-chart" role="img" aria-label="Volumes du parcours de prospection"></div>
            <div id="dashboard-funnel-chart-fallback" data-testid="dashboard-funnel-fallback" class="dashboard-empty text-center text-muted py-12">
                <i class="bi bi-bar-chart fs-3x d-block mb-3"></i>Graphique indisponible. Les valeurs restent disponibles ci-dessous.
            </div>
            <ul data-testid="dashboard-funnel-summary" class="visually-hidden">
                @foreach ($funnel as $stage => $value)
                    <li>{{ $stage }} : {{ number_format($value) }}</li>
                @endforeach
            </ul>
        @else<div data-testid="dashboard-funnel-empty" class="dashboard-empty text-center text-muted py-12"><i class="bi bi-funnel fs-3x d-block mb-3"></i>Aucune donnée de parcours.</div>@endif
    </div></div></section>
</div>

<div class="row g-5 mb-6">
    <section class="col-xl-4" data-testid="dashboard-planning-overdue"><div class="card h-100 dashboard-accent dashboard-accent-danger"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Planning en retard</span><span class="text-muted fs-7">{{ $planning['overdue_count'] }} opération(s) à rattraper</span></div></div><div class="card-body pt-0">
        @forelse ($planning['overdue'] as $item)<div class="dashboard-list-row d-flex align-items-center gap-3 py-4"><span class="dashboard-icon bg-light-danger"><i class="bi bi-clock-history text-danger"></i></span><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-danger fs-8">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d/m/Y à H:i') }}</div></div>@can('view campaigns')<a href="{{ route('admin.campaigns.view', $item['id']) }}" class="btn btn-sm btn-icon btn-light-danger" aria-label="Voir {{ $item['name'] }}"><i class="bi bi-arrow-right"></i></a>@endcan</div>@empty<div class="dashboard-empty text-center text-muted py-10"><i class="bi bi-check2-circle text-success fs-3x d-block mb-3"></i>Aucun retard.</div>@endforelse
    </div></div></section>
    <section class="col-xl-4" data-testid="dashboard-planning-upcoming"><div class="card h-100 dashboard-accent"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Prochaines campagnes</span><span class="text-muted fs-7">{{ $planning['upcoming_count'] }} opération(s) planifiée(s)</span></div>@can('view campaigns')<div class="card-toolbar"><a href="{{ route('admin.planner.index') }}" class="btn btn-sm btn-light-primary">Planning</a></div>@endcan</div><div class="card-body pt-0">
        @forelse ($planning['upcoming'] as $item)<div class="dashboard-list-row d-flex align-items-center gap-3 py-4"><span class="dashboard-icon bg-light-info"><i class="bi bi-calendar-event text-info"></i></span><div class="dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ $item['scheduled_at']->setTimezone($item['timezone'])->format('d/m/Y à H:i') }} · {{ $item['schedule_label'] }}</div></div></div>@empty<div class="dashboard-empty text-center text-muted py-10"><i class="bi bi-calendar2-check fs-3x d-block mb-3"></i>Aucune campagne planifiée.</div>@endforelse
    </div></div></section>
    <div class="col-xl-4">
        <section class="card mb-5 dashboard-accent dashboard-accent-danger" data-testid="dashboard-send-incidents"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Incidents d’envoi</span><span class="text-muted fs-7">Exécutions à vérifier</span></div></div><div class="card-body pt-0"><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">En échec</span><span class="badge badge-light-{{ $operations['failed_sends'] ? 'danger' : 'success' }}">{{ $operations['failed_sends'] }}</span></div><div class="d-flex justify-content-between py-3"><span class="text-muted">Bloqués depuis 30 min</span><span class="badge badge-light-{{ $operations['stale_sends'] ? 'warning' : 'success' }}">{{ $operations['stale_sends'] }}</span></div></div></section>
        <section class="card dashboard-accent dashboard-accent-success" data-testid="dashboard-inbox"><div class="card-body d-flex align-items-center gap-4"><span class="dashboard-icon bg-light-primary"><i class="bi bi-inbox text-primary fs-2"></i></span><div class="flex-grow-1"><div class="fw-bold">Réponses à traiter</div><div class="text-muted fs-8">{{ $operations['replies_awaiting_triage'] }} nouveau(x) message(s)</div></div>@can('view inbox')<a href="{{ route('admin.inbox.index') }}" class="btn btn-sm btn-icon btn-light-primary" aria-label="Ouvrir la boîte de réception"><i class="bi bi-arrow-right"></i></a>@endcan</div></section>
    </div>
</div>

<div class="row g-5 mb-6">
    <section class="col-xl-7" data-testid="dashboard-campaign-health"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Santé des campagnes</span><span class="text-muted fs-7">Cadence et engagement des campagnes récentes</span></div>@can('view campaigns')<div class="card-toolbar"><a href="{{ route('admin.campaigns.index') }}" class="btn btn-sm btn-light-primary">Voir tout</a></div>@endcan</div><div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Campagne</th><th>Type</th><th>Statut</th><th>Envoyés</th><th>Ouverture</th><th>Clic</th></tr></thead><tbody>
        @forelse ($campaigns['rows'] as $item)<tr><td>@can('view campaigns')<a href="{{ route('admin.campaigns.view', $item['id']) }}" class="fw-bold text-gray-900 text-hover-primary">{{ $item['name'] }}</a>@else<span class="fw-bold">{{ $item['name'] }}</span>@endcan</td><td>{{ $item['schedule_label'] }}</td><td><span class="badge badge-light-{{ $item['is_active'] ? 'success' : 'secondary' }}">{{ $item['is_active'] ? 'Active' : 'En pause' }}</span></td><td>{{ number_format($item['sent']) }}</td><td>{{ $item['open_rate'] === null ? '—' : number_format($item['open_rate'], 1).' %' }}</td><td>{{ $item['click_rate'] === null ? '—' : number_format($item['click_rate'], 1).' %' }}</td></tr>@empty<tr><td colspan="6" class="dashboard-empty text-center text-muted py-8">Aucune campagne configurée.</td></tr>@endforelse
    </tbody></table></div></div></div></section>
    <section class="col-xl-5" data-testid="dashboard-top-campaigns"><div class="card h-100 dashboard-accent dashboard-accent-success"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Meilleures campagnes</span><span class="text-muted fs-7">Classement par taux de conversion</span></div></div><div class="card-body pt-0">
        @forelse ($topCampaigns as $item)<div class="dashboard-list-row d-flex align-items-center py-4 gap-3"><div class="flex-grow-1 dashboard-name"><div class="fw-bold dashboard-name">{{ $item['name'] }}</div><div class="text-muted fs-8">{{ number_format($item['sent']) }} envoi(s) · {{ number_format($item['demandes']) }} demande(s)</div></div><span class="badge badge-light-success">{{ number_format($item['conversion_rate'], 1) }} %</span></div>@empty<div class="dashboard-empty text-center text-muted py-10"><i class="bi bi-trophy fs-3x d-block mb-3"></i>Aucune conversion attribuée.</div>@endforelse
    </div></div></section>
</div>

<div class="row g-5 mb-6">
    <section class="col-xl-4" data-testid="dashboard-discovery-summary"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Découverte</span><span class="text-muted fs-7">Couverture des critères actifs</span></div></div><div class="card-body pt-0"><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">Critères</span><span class="fw-bold">{{ number_format($criteria['total']) }}</span></div><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">Actifs</span><span class="fw-bold">{{ number_format($criteria['active']) }}</span></div><div class="d-flex justify-content-between py-3"><span class="text-muted">Automatisés</span><span class="fw-bold">{{ number_format($criteria['auto_run']) }}</span></div>@can('view prospect_criteria')<a href="{{ route('admin.prospect_criteria.index') }}" class="btn btn-sm btn-light-primary w-100 mt-4">Voir les critères</a>@endcan</div></div></section>
    <section class="col-xl-8" data-testid="dashboard-criteria-yield"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Rendement des critères</span><span class="text-muted fs-7">Entreprises et contacts découverts récemment</span></div></div><div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Critère</th><th>Mode</th><th>Statut</th><th>Entreprises</th><th>Contacts</th><th>Dernière exécution</th></tr></thead><tbody>
        @forelse ($criteria['rows'] as $item)
            @php $runMeta = config('global.data.discovery_run_statuses.'.$item['latest_run_status'], ['label' => ($item['is_active'] ? 'Prêt' : 'Inactif'), 'color' => 'secondary']); @endphp
            <tr><td class="fw-bold">@can('view prospect_criteria')<a href="{{ route('admin.prospect_criteria.view', $item['id']) }}" class="text-gray-900 text-hover-primary">{{ $item['name'] }}</a>@else{{ $item['name'] }}@endcan</td><td><span class="badge badge-light-{{ $item['auto_run'] ? 'primary' : 'secondary' }}">{{ $item['auto_run'] ? 'Automatique à '.str_pad((string) $item['run_at_hour'], 2, '0', STR_PAD_LEFT).'h' : 'Manuel' }}</span></td><td><span class="badge badge-light-{{ $runMeta['color'] }}">{{ $runMeta['label'] }}</span></td><td>{{ number_format($item['companies_count']) }}</td><td>{{ number_format($item['contacts_count']) }}</td><td>{{ $item['latest_run_at']?->format('d/m/Y H:i') ?? 'Jamais' }}</td></tr>
        @empty
            <tr><td colspan="6" class="dashboard-empty text-center text-muted py-8">Aucun critère de découverte.</td></tr>
        @endforelse
    </tbody></table></div></div></div></section>
</div>

<div class="row g-5">
    <section class="col-xl-4" data-testid="dashboard-enterprise-qualification-contacts"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Qualification et contacts</span><span class="text-muted fs-7">Qualité du portefeuille entreprises</span></div></div><div class="card-body pt-0"><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">Entreprises</span><span class="fw-bold">{{ number_format($enterprises['total']) }}</span></div><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">Avec contacts</span><span class="fw-bold">{{ number_format($enterprises['with_contacts']) }}</span></div><div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">Couverture contacts</span><span class="fw-bold">{{ number_format($contactCoverage, 1) }} %</span></div>@forelse ($enterprises['qualification'] as $status => $count)<div class="d-flex justify-content-between py-3 border-bottom"><span class="text-muted">{{ ucfirst(str_replace('_', ' ', $status ?: 'non qualifié')) }}</span><span class="badge badge-light-primary">{{ number_format($count) }}</span></div>@empty<div class="dashboard-empty text-muted text-center py-6">Aucune qualification disponible.</div>@endforelse</div></div></section>
    <section class="col-xl-8" data-testid="dashboard-recent-enterprises"><div class="card h-100"><div class="card-header border-0"><div class="card-title d-flex flex-column"><span class="fw-bold">Entreprises récentes</span><span class="text-muted fs-7">Derniers prospects ajoutés au portefeuille</span></div>@can('view companies')<div class="card-toolbar"><a href="{{ route('admin.companies.index') }}" class="btn btn-sm btn-light-primary">Voir tout</a></div>@endcan</div><div class="card-body pt-0"><div class="table-responsive"><table class="table align-middle table-row-dashed gy-4"><thead><tr class="text-muted fw-bold fs-7"><th>Entreprise</th><th>Source</th><th>Qualification</th><th>Contacts</th><th>Ajoutée</th></tr></thead><tbody>
        @forelse ($enterprises['recent'] as $item)<tr><td>@can('view companies')<a href="{{ route('admin.companies.view', $item['id']) }}" class="fw-bold text-gray-900 text-hover-primary">{{ $item['name'] }}</a>@else<span class="fw-bold">{{ $item['name'] }}</span>@endcan</td><td>{{ ucfirst($item['source'] ?: '—') }}</td><td>{{ ucfirst(str_replace('_', ' ', $item['qualification_status'] ?: '—')) }}</td><td>{{ number_format($item['contacts_count']) }}</td><td>{{ $item['created_at']->format('d/m/Y') }}</td></tr>@empty<tr><td colspan="5" class="dashboard-empty text-center text-muted py-8">Aucune entreprise récente.</td></tr>@endforelse
    </tbody></table></div></div></div></section>
</div>
