@php
    $ceo = $ceo ?? [];
    $metrics = $ceo['metrics'] ?? [];
    $cards = $ceo['decision_cards'] ?? [];
    $queueData = $ceo['queue'] ?? [];
    $queue = $queueData['items'] ?? [];
    $owners = $ceo['owners'] ?? [];
    $freshness = $ceo['freshness'] ?? [];
    $meta = $ceo['meta'] ?? [];
    $confidence = $ceo['confidence'] ?? [];
    $filterScope = is_array($meta['filter_scope'] ?? null) ? $meta['filter_scope'] : [];
    $filterScopeStatus = static function (string $surface, ?string $key = null) use ($filterScope): string {
        $surfaceValue = $filterScope[$surface] ?? null;
        $value = $key === null
            ? ($surfaceValue ?? 'Sans filtre')
            : (is_array($surfaceValue) ? ($surfaceValue[$key] ?? 'Sans filtre') : 'Sans filtre');

        return in_array($value, ['Sans filtre', 'Filtré', 'Partiel', 'Non filtré'], true)
            ? $value
            : 'Sans filtre';
    };
    $filterScopeNeedsNotice = static fn (string $status): bool => in_array($status, ['Partiel', 'Non filtré'], true);
    $filterScopeClass = static fn (string $status): string => $status === 'Non filtré' ? 'danger' : 'warning';
    $queueAvailability = collect(['P1', 'P2', 'P3', 'enrichment'])->mapWithKeys(
        static fn (string $priority): array => [$priority => (bool) ($queueData['availability_by_priority'][$priority] ?? false)],
    )->all();
    $queueCompleteness = collect(['P1', 'P2', 'P3', 'enrichment'])->mapWithKeys(static function (string $priority) use ($queueData, $queueAvailability): array {
        $fallback = $queueAvailability[$priority] ? 'complete' : 'unavailable';
        $value = $queueData['completeness_by_priority'][$priority] ?? $fallback;

        return [$priority => in_array($value, ['complete', 'partial', 'unavailable'], true) ? $value : $fallback];
    })->all();
    $overallQueueCompleteness = collect($queueCompleteness)->every(static fn (string $value): bool => $value === 'complete')
        ? 'complete'
        : (collect($queueCompleteness)->contains(static fn (string $value): bool => $value !== 'unavailable') ? 'partial' : 'unavailable');
    $completenessLabel = static fn (string $value): string => match ($value) {
        'complete' => 'Complet', 'partial' => 'Sous-ensemble connu · partiel', default => 'Indisponible',
    };
    $briefingCompleteness = is_array($ceo['briefing']['completeness'] ?? null)
        ? $ceo['briefing']['completeness']
        : [
            'reachable_accounts' => $overallQueueCompleteness,
            'highest_deadline' => $overallQueueCompleteness,
            'owner_escalations' => ($ceo['briefing']['owner_escalations'] ?? 'Indisponible') === 'Indisponible' ? 'unavailable' : 'complete',
        ];
    $format = static fn (mixed $value): string => is_numeric($value)
        ? number_format((int) $value, 0, ',', ' ')
        : (is_string($value) && $value !== '' ? $value : 'Indisponible');
    $confidenceClass = static fn (mixed $value): string => match ($value) { 'Fiable' => 'success', 'Partiel' => 'warning', default => 'secondary' };
    $priorityLabel = static fn (string $value): string => $value === 'enrichment' ? 'À enrichir' : $value;
    $canExplore = $drilldowns !== [];
    $businessTimezone = $ceo['period']['timezone'] ?? 'Europe/Paris';
    $lastSynced = ! empty($freshness['last_synced_at'])
        ? \Illuminate\Support\Carbon::parse($freshness['last_synced_at'])->setTimezone($businessTimezone)->format('d/m/Y H:i')
        : 'Indisponible';
    $totalByPriority = $queueData['total_by_priority'] ?? [];
    $numericQueueTotal = collect($totalByPriority)->filter(static fn (mixed $value): bool => is_numeric($value))->sum();
    $displayedOwners = collect($queue)->pluck('owner')->filter()->unique()->sort()->values();
    $applicability = $meta['filter_applicability'] ?? [];
    $filterLabel = static fn (string $filter): string => match ($filter) {
        'campaign' => 'campagne (P3 uniquement)', 'country' => 'pays (panneaux CRM)',
        'transport' => 'transport (panneaux CRM)', 'currency' => 'devise (panneaux CRM)',
        default => str_replace('_', ' ', $filter),
    };
    $moduleLabel = static fn (string $module): string => match ($module) {
        'quotes' => 'devis', 'deals' => 'opportunités', 'activities' => 'activités',
        'accounts' => 'comptes', 'contacts' => 'contacts', 'tasks' => 'tâches',
        'events' => 'réunions', 'calls' => 'appels', 'notes' => 'notes', default => $module,
    };
    $freshnessStatus = $freshness['status'] ?? match ($freshness['state'] ?? null) {
        'Fiable' => 'À jour', 'Partiel' => 'Incomplet', default => 'Indisponible',
    };
    $freshnessModules = collect($freshness['modules'] ?? []);
    $affectedFreshnessModules = collect($freshness['affected_modules'] ?? [])
        ->merge($freshnessModules->filter(static fn (mixed $evidence): bool => is_array($evidence)
            && (($evidence['available'] ?? true) === false || ($evidence['status'] ?? 'À jour') !== 'À jour'))->keys())
        ->filter()->unique()->values();
    $freshnessDetails = $affectedFreshnessModules->map(function (string $module) use ($freshnessModules, $moduleLabel): string {
        $evidence = $freshnessModules->get($module, []);
        $status = is_array($evidence) ? ($evidence['status'] ?? 'Indisponible') : 'Indisponible';
        $failed = is_array($evidence) ? collect($evidence['failed_submodules'] ?? [])->map($moduleLabel)->implode(', ') : '';
        $partial = is_array($evidence) ? collect($evidence['partial_submodules'] ?? [])->map($moduleLabel)->implode(', ') : '';
        $missing = is_array($evidence) ? collect($evidence['missing_submodules'] ?? [])->map($moduleLabel)->implode(', ') : '';
        $details = [];
        if ($failed !== '') {
            $details[] = 'sous-modules en échec : '.$failed;
        }
        if ($partial !== '') {
            $details[] = 'sous-modules partiels : '.$partial;
        }
        if ($missing !== '') {
            $details[] = 'sous-modules manquants : '.$missing;
        }

        return $moduleLabel($module).' — '.$status.($details === [] ? '' : ' ('.implode(' ; ', $details).')');
    });
    $briefingConfidence = $confidence['briefing'] ?? 'Indisponible';
    $metricConfidences = $confidence['metrics'] ?? [];
    $decisionConfidences = $confidence['decision_cards'] ?? [];
    $panelConfidences = $confidence['panels'] ?? [];
    $panelConfidence = static fn (string $panel): string => $panelConfidences[$panel] ?? 'Indisponible';
    $briefingFilterScope = $filterScopeStatus('briefing');
    $queueFilterScope = $filterScopeStatus('queue');
    $panelFilterScope = static fn (string $panel): string => $filterScopeStatus('panels', $panel);
    $emptyState = static fn (string $panel): string => $panelConfidence($panel) === 'Indisponible'
        ? 'Indisponible'
        : 'Aucun résultat sur la période';
    $monthlyMeta = $ceo['monthly_meta'] ?? [];
    $currentMonth = $monthlyMeta['current_month'] ?? null;
    $monthlyAsOf = ! empty($monthlyMeta['as_of'])
        ? \Illuminate\Support\Carbon::parse($monthlyMeta['as_of'], $businessTimezone)->format('d/m/Y')
        : null;
    $priorityCards = [
        'P1' => ['fallback' => 'expired_missing_decision', 'label' => 'P1 · décision urgente', 'note' => 'Devis expiré ou échéance très proche sans décision.'],
        'P2' => ['fallback' => 'open_deals_stale', 'label' => 'P2 · progression à obtenir', 'note' => 'Relancer un dossier sans progrès humain prouvé.'],
        'P3' => ['fallback' => 'soft_campaign_signal', 'label' => 'P3 · signal doux', 'note' => 'Signal doux, pas un lead chaud.'],
        'enrichment' => ['fallback' => null, 'label' => 'À enrichir', 'note' => 'Compléter un canal autorisé avant toute relance.'],
    ];
@endphp

<x-default-layout>
    @section('title', 'Tour de contrôle commerciale')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'Zoho'], ['label' => 'Tour de contrôle commerciale']]" />
    @endsection

    <div data-ceo-root class="ceo-control-tower">
        <header class="d-flex flex-wrap align-items-start justify-content-between gap-4 mb-6">
            <div>
                <div class="text-muted text-uppercase fw-bold fs-8 mb-1">Fretiq · CEO</div>
                <h1 class="fs-2hx fw-bolder mb-2">Tour de contrôle commerciale</h1>
                <p class="text-muted mb-0">Lecture seule du miroir Zoho · priorités de suivi, aucun forecast ni taux de gain.</p>
            </div>
            <div class="text-xl-end">
                <span class="badge badge-light-{{ ($freshness['state'] ?? 'Indisponible') === 'Fiable' ? 'success' : (($freshness['state'] ?? '') === 'Partiel' ? 'warning' : 'danger') }}">
                    Synchronisation {{ $freshness['state'] ?? 'Indisponible' }} · statut {{ $freshnessStatus }} · {{ $lastSynced }} ({{ $businessTimezone }})
                </span>
                <div class="small text-muted mt-2">Lecture seule · simulation uniquement, aucune action Zoho n’est effectuée.</div>
            </div>
        </header>

        @if(($dashboard['scope']['mapping_required'] ?? false) === true)
            <div class="alert alert-warning" role="alert">Votre portefeuille Zoho doit être confirmé par un administrateur avant de pouvoir afficher vos données CRM.</div>
        @endif
        @if(($dashboard['scope']['selection_invalid'] ?? false) === true)
            <div class="alert alert-info" role="status">Le commercial demandé n’est pas disponible. Les indicateurs sont volontairement vides.</div>
        @endif
        @if(($freshness['state'] ?? null) === 'Périmètre indisponible')
            <div class="alert alert-info" role="status">
                <strong>Fraîcheur non évaluée.</strong> Le périmètre CRM doit être confirmé avant d’inspecter les synchronisations de ce portefeuille.
            </div>
        @elseif(($dashboard['meta']['stale_or_unavailable'] ?? false) === true || $freshnessDetails->isNotEmpty() || $freshnessStatus !== 'À jour')
            <div class="alert alert-warning" role="alert">
                <strong>Fraîcheur du miroir : {{ $freshnessStatus }}.</strong> Les états indisponibles ne sont pas des zéros.
                @if(isset($freshness['age_minutes'])) Preuve exploitable la plus ancienne parmi les modules disponibles : il y a {{ $freshness['age_minutes'] }} min. @endif
                @if($freshnessDetails->isNotEmpty())
                    <span class="d-block mt-1">Modules concernés : {{ $freshnessDetails->implode(' · ') }}.</span>
                @endif
            </div>
        @endif
        @if(($applicability['ignored'] ?? []) !== [] || ($applicability['partial'] ?? []) !== [] || ($applicability['applied'] ?? []) !== [])
            <div class="alert alert-light-info" role="status"><strong>Filtres :</strong>
                @if(($applicability['applied'] ?? []) !== []) Appliqués : {{ collect($applicability['applied'])->map($filterLabel)->implode(', ') }}. @endif
                @if(($applicability['partial'] ?? []) !== []) Partiels : {{ collect($applicability['partial'])->map($filterLabel)->implode(', ') }}. @endif
                @if(($applicability['ignored'] ?? []) !== []) Ignorés : {{ collect($applicability['ignored'])->map($filterLabel)->implode(', ') }}. @endif
            </div>
        @endif

        <div class="card mb-6">
            <div class="card-body py-4 d-flex flex-wrap align-items-center gap-3">
                <div class="btn-group" role="tablist" aria-label="Vue du tableau de bord">
                    <button class="btn btn-sm btn-primary" id="tab-today" type="button" role="tab" aria-controls="ceo-today" aria-selected="true" tabindex="0" data-ceo-tab="today">Aujourd’hui</button>
                    <button class="btn btn-sm btn-light" id="tab-pilotage" type="button" role="tab" aria-controls="ceo-pilotage" aria-selected="false" tabindex="-1" data-ceo-tab="pilotage">Pilotage et trajectoire</button>
                </div>
                <div class="ms-auto d-flex gap-2" aria-label="Période">
                    @foreach(['30d' => '30 jours', '90d' => '90 jours', '365d' => '365 jours'] as $period => $label)
                        <a class="btn btn-sm {{ ($controls['period'] ?? '90d') === $period ? 'btn-primary' : 'btn-light' }}" href="{{ request()->fullUrlWithQuery(['period' => $period]) }}" data-testid="period-{{ str_replace('d', '', $period) }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>

        <section id="ceo-today" role="tabpanel" aria-labelledby="tab-today" tabindex="0">
            <div class="alert alert-light-primary d-flex flex-wrap gap-3 align-items-center mb-6" role="status">
                <i class="bi bi-sunrise fs-2" aria-hidden="true"></i>
                <div><strong>Briefing du matin.</strong> {{ $format($ceo['briefing']['reachable_accounts'] ?? null) }} comptes joignables · échéance la plus haute : {{ $ceo['briefing']['highest_deadline'] ?? 'Indisponible' }} · {{ $format($ceo['briefing']['owner_escalations'] ?? null) }} escalade(s) propriétaire.</div>
                <span class="ceo-source-completeness small text-muted">Sources : comptes/échéance — {{ $completenessLabel($briefingCompleteness['reachable_accounts'] ?? 'unavailable') }} · escalades — {{ $completenessLabel($briefingCompleteness['owner_escalations'] ?? 'unavailable') }}.</span>
                @if($filterScopeNeedsNotice($briefingFilterScope))
                    <span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($briefingFilterScope) }}" role="status">Filtre du briefing : {{ $briefingFilterScope }}</span>
                @endif
                <span class="badge badge-light-{{ $confidenceClass($briefingConfidence) }} ms-xl-auto">{{ $briefingConfidence }}</span>
            </div>

            <div class="row g-5 mb-6" aria-label="Décisions à prendre">
                @foreach($priorityCards as $priority => $definition)
                    @php
                        $card = $cards[$priority] ?? [];
                        $value = is_array($card) ? ($card['value'] ?? null) : ($definition['fallback'] ? ($cards[$definition['fallback']] ?? null) : ($totalByPriority[$priority] ?? null));
                        $cardConfidence = $decisionConfidences[$priority] ?? (is_array($card) ? ($card['confidence'] ?? 'Indisponible') : 'Indisponible');
                        $note = is_array($card) ? ($card['note'] ?? $definition['note']) : $definition['note'];
                        $priorityAvailable = $queueAvailability[$priority] ?? false;
                    @endphp
                    <div class="col-sm-6 col-xl-3">
                        <button type="button" class="card h-100 w-100 text-start border border-gray-200 ceo-decision-card" data-ceo-decision="{{ $priority }}" aria-pressed="false" aria-disabled="{{ $priorityAvailable ? 'false' : 'true' }}" @disabled(! $priorityAvailable)>
                            <span class="card-body d-block">
                                <span class="d-flex justify-content-between gap-2"><span class="text-muted fw-bold fs-8">{{ $definition['label'] }}</span><span class="badge badge-light-{{ $confidenceClass($cardConfidence) }}">{{ $cardConfidence }}</span></span>
                                <span class="d-block fs-2qx fw-bolder my-2">{{ $format($value) }}</span>
                                <span class="small text-muted">{{ $note }}</span>
                                @if(($queueCompleteness[$priority] ?? 'unavailable') === 'partial')<span class="d-block small text-warning fw-bold mt-2">Source : sous-ensemble connu · partiel.</span>@endif
                            </span>
                        </button>
                    </div>
                @endforeach
            </div>

            <div class="row g-5 mb-6">
                @foreach([
                    ['Devis émis · date métier proxy', 'quotes', 'quote_date ; timestamp Zoho indisponible.'],
                    ['Sans statut', 'missing_status', 'État actuel, pas un taux de conversion.'],
                    ['Tâches créées', 'tasks_created', $format($metrics['not_started_tasks'] ?? null).' non commencées.'],
                    ['Suivi humain prouvé*', 'proven_human_follow_up', $format($metrics['completed_tasks'] ?? null).' tâches créées sur la période et actuellement terminées (date de fin indisponible) + '.$format($metrics['meetings'] ?? null).' réunions + '.$format($metrics['calls'] ?? null).' appels + '.$format($metrics['notes'] ?? null).' notes.'],
                ] as [$label, $key, $note])
                    @php
                        $metricValue = $metrics[$key] ?? null;
                        $metricConfidence = $metricConfidences[$key] ?? 'Indisponible';
                        $metricFilterScope = $filterScopeStatus('metrics', $key);
                    @endphp
                    <div class="col-sm-6 col-xl-3"><article class="card h-100 ceo-kpi"><div class="card-body"><div class="text-muted fw-bold fs-8">{{ $label }} <span class="badge badge-light-{{ $confidenceClass($metricConfidence) }}">{{ $metricConfidence }}</span></div>@if($filterScopeNeedsNotice($metricFilterScope))<div class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($metricFilterScope) }} mt-2" role="status">Filtre de cet indicateur : {{ $metricFilterScope }}</div>@endif<div class="fs-2qx fw-bolder my-2" data-testid="ceo-{{ $key }}">{{ $format($metricValue) }}</div><div class="small text-muted">{{ $note }}</div></div></article></div>
                @endforeach
            </div>

            <div class="row g-5">
                <div class="col-xl-9">
                    <section class="card">
                        <div class="card-header flex-wrap gap-3">
                            <div><h2 class="card-title fw-bolder">Comptes à contacter ou relancer</h2><span class="text-muted small">Dédupliqués par compte · canaux masqués · {{ count($queue) }} affiché(s) · sources {{ mb_strtolower($completenessLabel($overallQueueCompleteness)) }}</span>@if($filterScopeNeedsNotice($queueFilterScope))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($queueFilterScope) }} d-table mt-2" role="status">Filtre de la file : {{ $queueFilterScope }}</span>@endif</div>
                            <div class="d-flex flex-wrap gap-2 ms-xl-auto" data-testid="ceo-queue-filters">
                                <div class="d-flex flex-wrap gap-1" role="group" aria-label="Filtrer par priorité">
                                    <button type="button" class="btn btn-sm btn-primary" aria-pressed="true" data-ceo-priority="all">Toutes</button>
                                    @foreach(['P1', 'P2', 'P3', 'enrichment'] as $priority)
                                        <button type="button" class="btn btn-sm btn-light" aria-pressed="false" aria-disabled="{{ $queueAvailability[$priority] ? 'false' : 'true' }}" data-ceo-priority="{{ $priority }}" @disabled(! $queueAvailability[$priority])>{{ $priorityLabel($priority) }} <span class="badge badge-light">{{ $queueAvailability[$priority] ? $format($totalByPriority[$priority] ?? 0) : 'Indisponible' }}</span>@if(($queueCompleteness[$priority] ?? 'unavailable') === 'partial') <span class="badge badge-light-warning">partiel</span>@endif</button>
                                    @endforeach
                                </div>
                                <select class="form-select form-select-sm w-auto" aria-label="Filtrer par propriétaire parmi les lignes affichées" data-ceo-owner><option value="all">Propriétaires des lignes affichées</option>@foreach($displayedOwners as $owner)<option value="{{ $owner }}">{{ $owner }}</option>@endforeach</select>
                            </div>
                        </div>
                        <div class="card-body pt-0">
                            @if(($queueData['truncated'] ?? false) === true)<p class="alert alert-light-info small mt-4 mb-3">File tronquée : {{ $format($numericQueueTotal) }} comptes identifiés dans les priorités disponibles, {{ count($queue) }} affichés. {{ $overallQueueCompleteness === 'complete' ? 'Les compteurs par priorité sont complets.' : 'Les compteurs marqués partiels couvrent uniquement le sous-ensemble connu.' }}</p>@endif
                            <div class="table-responsive ceo-table ceo-action-table-wrap">
                                <table class="table align-middle table-row-dashed ceo-action-table">
                                    <thead><tr><th scope="col">Priorité</th><th scope="col">Compte / contact</th><th scope="col">Pourquoi maintenant</th><th scope="col">Dernière action humaine prouvée</th><th scope="col">Canal</th><th scope="col">Propriétaire</th><th scope="col">Échéance</th><th scope="col">Action recommandée</th></tr></thead>
                                    <tbody>
                                        @forelse($queue as $index => $item)
                                            @php($priority = $item['priority'] ?? 'enrichment')
                                            <tr data-ceo-row data-priority="{{ $priority }}" data-signals="{{ collect($item['signals'] ?? [$priority])->implode(',') }}" data-owner="{{ $item['owner'] ?? 'Propriétaire Zoho indisponible' }}">
                                                <td data-label="Priorité"><span class="badge badge-light-{{ $priority === 'P1' ? 'danger' : ($priority === 'P2' ? 'warning' : 'info') }}">{{ $priorityLabel($priority) }}</span></td>
                                                <td data-label="Compte / contact"><button class="btn btn-link p-0 text-start fw-bold" type="button" data-ceo-open="{{ $index }}">{{ $item['account'] ?? 'Compte non nommé' }}<span class="d-block small text-muted">{{ $item['contact'] ?? 'Contact non nommé' }}</span></button></td>
                                                <td data-label="Pourquoi maintenant">{{ implode(' · ', $item['reasons'] ?? []) }}<span class="d-block small text-muted">{{ $item['quote_context'] ?? 'Contexte indisponible' }}</span></td>
                                                <td data-label="Dernière action humaine prouvée">{{ $item['last_proven_action'] ?? 'Indisponible' }}</td>
                                                <td data-label="Canal">{{ $item['channel'] ?? 'Indisponible' }}</td>
                                                <td data-label="Propriétaire">{{ $item['owner'] ?? 'Propriétaire Zoho indisponible' }}</td>
                                                <td data-label="Échéance">{{ $item['deadline'] ?? 'Indisponible' }}</td>
                                                <td data-label="Action recommandée"><button class="btn btn-sm btn-light-primary" type="button" data-ceo-open="{{ $index }}">{{ $item['recommended_action'] ?? 'Préparer le briefing' }}</button></td>
                                            </tr>
                                        @empty
                                            <tr class="ceo-action-empty"><td colspan="8" class="text-muted">{{ $overallQueueCompleteness === 'complete' ? 'Aucun résultat sur la période' : ($overallQueueCompleteness === 'partial' ? 'Aucune action prouvée dans le sous-ensemble connu · sources partielles' : 'Indisponible · aucune priorité calculable') }}</td></tr>
                                        @endforelse
                                        <tr class="ceo-action-empty" data-ceo-empty-filter hidden><td colspan="8" class="text-muted text-center">Aucun compte ne correspond aux filtres affichés.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
                <div class="col-xl-3"><section class="card h-100"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Escalade propriétaire</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('owners')) }}">{{ $panelConfidence('owners') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('owners')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('owners')) }}" role="status">Filtre : {{ $panelFilterScope('owners') }}</span>@endif</div><div class="card-body">@forelse($owners as $owner)<div class="border-bottom py-3"><strong>{{ $owner['owner'] ?? 'Propriétaire Zoho indisponible' }}</strong><div class="small text-muted">{{ $format($owner['overdue_tasks'] ?? null) }} tâches en retard · {{ $format($owner['missing_status'] ?? null) }} devis sans statut</div></div>@empty<div class="text-muted">{{ $emptyState('owners') }}</div>@endforelse</div></section></div>
            </div>
        </section>

        <section id="ceo-pilotage" role="tabpanel" aria-labelledby="tab-pilotage" tabindex="0" hidden>
            <div class="row g-5">
                <div class="col-xl-7">
                    <section class="card h-100">
                        <div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Trajectoire mensuelle</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('monthly')) }}">{{ $panelConfidence('monthly') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('monthly')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('monthly')) }}" role="status">Filtre : {{ $panelFilterScope('monthly') }}</span>@endif</div>
                        <div class="card-body">
                            <div class="table-responsive ceo-table">
                                <table class="table">
                                    <thead><tr><th>Mois</th><th>Devis</th><th>Décisions actuelles*</th><th>Tâches créées</th><th>Tâches créées ce mois, actuellement terminées*</th><th>Réunions</th><th>Appels</th><th>Notes</th><th>Deals</th></tr></thead>
                                    <tbody>
                                        @forelse($ceo['monthly'] ?? [] as $month)
                                            @php($monthLabel = $month['month'] ?? 'Indisponible')
                                            <tr>
                                                <td>{{ $monthLabel }}@if($monthLabel === $currentMonth && $monthlyAsOf) · en cours au {{ $monthlyAsOf }}@endif</td>
                                                <td>{{ $format($month['quotes'] ?? null) }}</td><td>{{ $format($month['current_decisions'] ?? null) }}</td><td>{{ $format($month['tasks_created'] ?? null) }}</td><td>{{ $format($month['completed_tasks'] ?? null) }}</td><td>{{ $format($month['meetings'] ?? null) }}</td><td>{{ $format($month['calls'] ?? null) }}</td><td>{{ $format($month['notes'] ?? null) }}</td><td>{{ $format($month['deals'] ?? null) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="9" class="text-muted">{{ $emptyState('monthly') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-muted mb-0">* État gagné/perdu actuel attaché à la cohorte <code>quote_date</code>, pas une décision historique. Les tâches dites terminées sont la cohorte des tâches créées ce mois et dont le statut actuel est terminé ; la date de fin est indisponible.</p>
                        </div>
                    </section>
                </div>
                <div class="col-xl-5">
                    <section class="card h-100"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Entonnoir de traçabilité</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('funnel')) }}">{{ $panelConfidence('funnel') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('funnel')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('funnel')) }}" role="status">Filtre : {{ $panelFilterScope('funnel') }}</span>@endif</div><div class="card-body">@foreach(['quotes' => 'Devis émis', 'named_contacts' => 'Contact nommé lié', 'linked_deals' => 'Deal lié', 'current_decisions' => 'Décision enregistrée', 'completed_quote_tasks' => 'Tâches devis terminées (statut actuel)'] as $key => $label)<div class="d-flex justify-content-between border-bottom py-3"><span>{{ $label }}</span><strong>{{ $format($ceo['funnel'][$key] ?? null) }}</strong></div>@endforeach</div></section>
                </div>
            </div>
            <div class="row g-5 mt-1">
                <div class="col-xl-4"><section class="card h-100"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Risque devis</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('quote_risks')) }}">{{ $panelConfidence('quote_risks') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('quote_risks')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('quote_risks')) }}" role="status">Filtre : {{ $panelFilterScope('quote_risks') }}</span>@endif</div><div class="card-body">@foreach(['expired_missing_decision' => 'Expirés sans décision', 'due_7_days' => 'Sous 7 jours', 'due_30_days' => 'Sous 30 jours', 'unreachable' => 'Sans canal joignable', 'future_date_anomalies' => 'Dates futures'] as $key => $label)<div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $label }}</span><strong>{{ $format($ceo['quote_risks'][$key] ?? null) }}</strong></div>@endforeach</div></section></div>
                <div class="col-xl-4"><section class="card h-100"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Mix de volume, pas de revenu</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('mix')) }}">{{ $panelConfidence('mix') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('mix')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('mix')) }}" role="status">Filtre : {{ $panelFilterScope('mix') }}</span>@endif</div><div class="card-body"><h3 class="fs-6">Transport</h3>@forelse($ceo['transport_mix'] ?? [] as $transport => $count)<div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $transport }}</span><strong>{{ $format($count) }}</strong></div>@empty<div class="text-muted">{{ $emptyState('mix') }}</div>@endforelse<h3 class="fs-6 mt-4">Mix des axes</h3>@forelse($ceo['lane_mix'] ?? [] as $lane => $count)<div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $lane }}</span><strong>{{ $format($count) }}</strong></div>@empty<div class="text-muted small">{{ $emptyState('mix') }}</div>@endforelse</div></section></div>
                <div class="col-xl-4"><section class="card h-100"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Préparation des données</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('readiness')) }}">{{ $panelConfidence('readiness') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('readiness')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('readiness')) }}" role="status">Filtre : {{ $panelFilterScope('readiness') }}</span>@endif</div><div class="card-body">@forelse($ceo['readiness'] ?? [] as $key => $note)<p class="small border-bottom pb-2"><strong>{{ is_string($key) ? str_replace('_', ' ', $key) : 'État' }} :</strong> {{ $note }}</p>@empty<p class="text-muted">{{ $emptyState('readiness') }}</p>@endforelse</div></section></div>
            </div>
            <section class="card mt-6"><div class="card-header flex-wrap gap-2"><h2 class="card-title fw-bolder">Preuves par propriétaire</h2><span class="badge badge-light-{{ $confidenceClass($panelConfidence('owners')) }}">{{ $panelConfidence('owners') }}</span>@if($filterScopeNeedsNotice($panelFilterScope('owners')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('owners')) }}" role="status">Filtre : {{ $panelFilterScope('owners') }}</span>@endif<span class="text-muted small">Éléments observés, pas un score de performance.</span></div><div class="card-body"><div class="table-responsive ceo-table"><table class="table align-middle"><thead><tr><th>Propriétaire</th><th>Devis</th><th>Sans statut</th><th>En retard</th><th>Terminées (statut actuel)</th><th>Réunions</th><th>Appels</th><th>Notes</th><th>Contacts liés</th><th>Deals liés</th></tr></thead><tbody>@forelse($owners as $owner)<tr><td>{{ $owner['owner'] ?? 'Propriétaire Zoho indisponible' }}</td><td>{{ $format($owner['quotes'] ?? null) }}</td><td>{{ $format($owner['missing_status'] ?? null) }}</td><td>{{ $format($owner['overdue_tasks'] ?? null) }}</td><td>{{ $format($owner['completed_tasks'] ?? null) }}</td><td>{{ $format($owner['meetings'] ?? null) }}</td><td>{{ $format($owner['calls'] ?? null) }}</td><td>{{ $format($owner['notes'] ?? null) }}</td><td>{{ $format($owner['linked_contacts'] ?? null) }}</td><td>{{ $format($owner['linked_deals'] ?? null) }}</td></tr>@empty<tr><td colspan="10" class="text-muted">{{ $emptyState('owners') }}</td></tr>@endforelse</tbody></table></div><p class="small text-muted mb-0">Les tâches terminées sont celles créées sur la période et dont le statut actuel est terminé ; la date de fin est indisponible.</p></div></section>
            @if($canExplore)
                <nav class="mt-6" aria-label="Explorer le CRM">
                    <span class="fw-bold me-3">Explorer le CRM :</span>
                    @foreach(['leads' => 'Leads', 'accounts' => 'Comptes', 'quotes' => 'Devis', 'deals' => 'Opportunités', 'contacts' => 'Contacts'] as $module => $label)
                        @if(isset($drilldowns[$module]))
                            <a class="btn btn-sm btn-light-primary me-2" href="{{ $drilldowns[$module] }}">{{ $label }}</a>
                        @endif
                    @endforeach
                </nav>
            @endif
        </section>

        <div class="modal fade" id="ceo-drawer" tabindex="-1" aria-hidden="true" aria-labelledby="ceo-drawer-title"><div class="modal-dialog modal-dialog-end modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-3" id="ceo-drawer-title">Briefing du compte</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div><div class="modal-body" id="ceo-drawer-body"></div><div class="modal-footer"><button type="button" class="btn btn-primary" id="ceo-simulate">Préparer l’action</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button></div></div></div></div>
    </div>

    @push('styles')
        <style>
            .ceo-kpi,.ceo-decision-card{border:1px solid var(--bs-gray-200)}
            .ceo-decision-card{background:var(--bs-body-bg)}
            .ceo-decision-card:focus-visible{outline:3px solid var(--bs-primary);outline-offset:2px}
            .ceo-decision-card[aria-pressed="true"]{border-color:var(--bs-primary)!important;background:var(--bs-primary-light,#eef6ff);box-shadow:0 0 0 .2rem rgba(var(--bs-primary-rgb),.15)}
            .ceo-decision-card:disabled{cursor:not-allowed;opacity:.68}
            .ceo-filter-scope{display:inline-flex;align-items:center;padding:.35rem .55rem;border:1px solid currentColor;border-radius:.475rem;font-size:.75rem;font-weight:700;line-height:1.2}
            .ceo-filter-scope-danger{color:var(--bs-danger);background:var(--bs-danger-light,#fff5f8)}
            .ceo-filter-scope-warning{color:var(--bs-warning-text-emphasis,#8a5d00);background:var(--bs-warning-light,#fff8dd)}
            .ceo-table{overflow-x:auto}
            .modal-dialog-end{margin-left:auto;margin-right:1rem;max-width:460px}
            @media(max-width:767.98px){
                .ceo-control-tower>header .text-xl-end{width:100%;min-width:0}
                .ceo-control-tower>header .badge{max-width:100%;white-space:normal;overflow-wrap:anywhere;text-align:start}
                .ceo-action-table-wrap{overflow:visible!important}
                .ceo-action-table{display:block;width:100%;min-width:0!important}
                .ceo-action-table thead{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
                .ceo-action-table thead tr,.ceo-action-table thead th{display:block!important;width:1px!important;min-width:0!important;max-width:1px!important;height:1px!important;padding:0!important;margin:0!important;overflow:hidden!important;border:0!important}
                .ceo-action-table tbody{display:grid;gap:1rem;width:100%}
                .ceo-action-table tr[data-ceo-row]{display:grid;width:100%;padding:.35rem .8rem;border:1px solid var(--bs-gray-300);border-radius:.65rem;background:var(--bs-body-bg);box-shadow:0 .15rem .5rem rgba(0,0,0,.04)}
                .ceo-action-table tr[data-ceo-row][hidden],.ceo-action-table tr.ceo-action-empty[hidden]{display:none!important}
                .ceo-action-table tr[data-ceo-row]>td{display:grid!important;grid-template-columns:minmax(7.5rem,38%) minmax(0,1fr);gap:.75rem;padding:.7rem 0!important;border-bottom:1px dashed var(--bs-gray-300);overflow-wrap:anywhere;white-space:normal}
                .ceo-action-table tr[data-ceo-row]>td::before{content:attr(data-label);color:var(--bs-gray-700);font-size:.75rem;font-weight:700;line-height:1.35;text-transform:uppercase}
                .ceo-action-table tr[data-ceo-row]>td:last-child{border-bottom:0}
                .ceo-action-table tr[data-ceo-row] button{max-width:100%;white-space:normal;overflow-wrap:anywhere}
                .ceo-action-table tr.ceo-action-empty{display:block;width:100%;border:1px dashed var(--bs-gray-300);border-radius:.65rem}
                .ceo-action-table tr.ceo-action-empty>td{display:block!important;width:100%;padding:1rem!important;border:0;text-align:start!important;white-space:normal}
                .modal-dialog-end{margin:0;max-width:none;height:100%}
                .modal-dialog-end .modal-content{min-height:100%;border-radius:0}
            }
        </style>
    @endpush
    @push('scripts')<script>
        (() => {
            const initialize = () => {
                const root = document.querySelector('[data-ceo-root]');
                if (!root || root.dataset.ceoTowerInit) return;
                root.dataset.ceoTowerInit = '1';
                const queue = {{ \Illuminate\Support\Js::from($queue) }};
                const queueCompleteness = {{ \Illuminate\Support\Js::from($queueCompleteness) }};
                const overallQueueCompleteness = {{ \Illuminate\Support\Js::from($overallQueueCompleteness) }};
                let selection = 'all'; let filterKind = 'priority'; let opener = null;
                const owner = root.querySelector('[data-ceo-owner]'); const emptyRow = root.querySelector('[data-ceo-empty-filter]');
                const rows = Array.from(root.querySelectorAll('[data-ceo-row]'));
                const filter = () => {
                    let visible = 0;
                    rows.forEach((row) => {
                        const priorityMatch = selection === 'all' || (filterKind === 'signal'
                            ? (row.dataset.signals || '').split(',').includes(selection)
                            : row.dataset.priority === selection);
                        const show = priorityMatch && (!owner || owner.value === 'all' || row.dataset.owner === owner.value);
                        row.hidden = !show;
                        if (show) visible++;
                    });
                    if (!emptyRow) return;
                    const completeness = selection === 'all' ? overallQueueCompleteness : (queueCompleteness[selection] || 'unavailable');
                    const message = completeness === 'complete'
                        ? 'Aucun compte ne correspond aux filtres affichés.'
                        : (completeness === 'partial'
                            ? 'Aucune action prouvée dans le sous-ensemble connu · sources partielles.'
                            : 'Indisponible · cette priorité ne peut pas être calculée.');
                    const cell = emptyRow.querySelector('td');
                    if (cell) cell.textContent = message;
                    emptyRow.hidden = rows.length === 0 || visible !== 0;
                };
                const tabs = Array.from(root.querySelectorAll('[data-ceo-tab]'));
                const activateTab = (button, focus = false) => { const today = button.dataset.ceoTab === 'today'; root.querySelector('#ceo-today').hidden = !today; root.querySelector('#ceo-pilotage').hidden = today; tabs.forEach((item) => { const active = item === button; item.setAttribute('aria-selected', active ? 'true' : 'false'); item.setAttribute('tabindex', active ? '0' : '-1'); item.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-light'); }); if (focus) button.focus(); };
                tabs.forEach((button, index) => { button.addEventListener('click', () => activateTab(button)); button.addEventListener('keydown', (event) => { let next = null; if (event.key === 'ArrowRight') next = (index + 1) % tabs.length; if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length; if (event.key === 'Home') next = 0; if (event.key === 'End') next = tabs.length - 1; if (next === null) return; event.preventDefault(); activateTab(tabs[next], true); }); });
                root.querySelectorAll('[data-ceo-priority], [data-ceo-decision]').forEach((button) => button.addEventListener('click', () => { if (button.disabled || button.getAttribute('aria-disabled') === 'true') return; filterKind = button.dataset.ceoDecision ? 'signal' : 'priority'; selection = button.dataset.ceoDecision || button.dataset.ceoPriority || 'all'; root.querySelectorAll('[data-ceo-priority], [data-ceo-decision]').forEach((item) => { const active = item === button; item.setAttribute('aria-pressed', active ? 'true' : 'false'); if (item.dataset.ceoPriority) item.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-light'); }); filter(); root.querySelector('#ceo-today').scrollIntoView({ block: 'start', behavior: 'smooth' }); }));
                owner?.addEventListener('change', filter);
                const modal = document.getElementById('ceo-drawer'); const simulate = document.getElementById('ceo-simulate');
                root.addEventListener('click', (event) => { const button = event.target.closest('[data-ceo-open]'); if (!button) return; const item = queue[Number(button.dataset.ceoOpen)]; if (!item) return; opener = button; document.getElementById('ceo-drawer-title').textContent = item.account || 'Compte non nommé'; const body = document.getElementById('ceo-drawer-body'); body.replaceChildren(); const signals = Array.isArray(item.signals) && item.signals.length > 0 ? item.signals.join(' · ') : 'Indisponible'; [['Contact masqué', item.contact || 'Indisponible'], ['Pourquoi maintenant', (item.reasons || []).join(' · ') || 'Indisponible'], ['Contexte', item.quote_context || 'Indisponible'], ['Dernière action humaine prouvée', item.last_proven_action || 'Indisponible'], ['Canal', item.channel || 'Indisponible'], ['Propriétaire', item.owner || 'Propriétaire Zoho indisponible'], ['Échéance', item.deadline || 'Indisponible'], ['Confiance', item.confidence || 'Indisponible'], ['Signaux', signals], ['Recommandation', item.recommended_action || 'Indisponible']].forEach(([heading, value]) => { const p = document.createElement('p'); const strong = document.createElement('strong'); strong.textContent = heading; p.append(strong, document.createElement('br'), document.createTextNode(String(value))); body.append(p); }); const note = document.createElement('p'); note.className = 'text-muted small'; note.textContent = 'Simulation uniquement : aucune action Zoho ne sera effectuée.'; body.append(note); simulate.textContent = 'Préparer l’action'; window.bootstrap?.Modal.getOrCreateInstance(modal).show(); });
                modal?.addEventListener('hidden.bs.modal', () => opener?.focus()); simulate?.addEventListener('click', () => { simulate.textContent = 'Simulation uniquement'; });
            };
            if (!window.__ceoTowerNavigationBound) { window.__ceoTowerNavigationBound = true; document.addEventListener('livewire:navigated', initialize); document.addEventListener('DOMContentLoaded', initialize, { once: true }); }
            initialize();
        })();
    </script>@endpush
</x-default-layout>
