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
    $queueEmptyState = static function (array $availability, array $completeness): array {
        $allUnavailable = collect($availability)->every(static fn (bool $available): bool => ! $available)
            || collect($completeness)->every(static fn (string $value): bool => $value === 'unavailable');
        if ($allUnavailable) {
            return ['kind' => 'unavailable', 'message' => 'Indisponible'];
        }

        $allComplete = collect($availability)->every(static fn (bool $available): bool => $available)
            && collect($completeness)->every(static fn (string $value): bool => $value === 'complete');

        return $allComplete
            ? ['kind' => 'complete-zero', 'message' => 'Aucun résultat sur la période']
            : ['kind' => 'partial-subset', 'message' => 'Aucun compte dans le sous-ensemble connu · sources partielles.'];
    };
    $queueEmpty = $queueEmptyState($queueAvailability, $queueCompleteness);
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
    $freshnessClass = match ($freshness['state'] ?? null) {
        'Fiable' => 'success', 'Partiel' => 'warning', default => 'danger',
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
    $numeric = static function (mixed $value): int|float|null {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? $value + 0 : null;
    };
    $ratio = static function (mixed $numerator, mixed $denominator) use ($numeric): ?float {
        $knownNumerator = $numeric($numerator);
        $knownDenominator = $numeric($denominator);

        return $knownNumerator !== null && $knownDenominator !== null && $knownDenominator > 0
            ? round(($knownNumerator / $knownDenominator) * 100, 1)
            : null;
    };
    $formatPercentage = static fn (?float $value): string => $value === null
        ? 'Indisponible'
        : number_format($value, 1, ',', ' ');
    $queueSignalTotals = is_array($queueData['total_by_signal'] ?? null)
        ? $queueData['total_by_signal']
        : [];
    $filterTotals = collect(['P1', 'P2', 'P3', 'enrichment'])->mapWithKeys(
        static function (string $priority) use ($numeric, $queue, $queueData, $queueAvailability, $queueCompleteness, $queueSignalTotals): array {
            $signalTotal = $numeric($queueSignalTotals[$priority] ?? null);
            if ($signalTotal !== null) {
                return [$priority => $signalTotal];
            }

            $canDeriveFromVisibleRows = ($queueData['truncated'] ?? false) === false
                && $queueAvailability[$priority]
                && $queueCompleteness[$priority] === 'complete';
            if (! $canDeriveFromVisibleRows) {
                return [$priority => null];
            }

            $visibleSignalTotal = collect($queue)->filter(static function (array $item) use ($priority): bool {
                $signals = is_array($item['signals'] ?? null)
                    ? $item['signals']
                    : [$item['priority'] ?? 'enrichment'];

                return in_array($priority, $signals, true);
            })->count();

            return [$priority => $visibleSignalTotal];
        },
    )->all();
    $knownQuotes = $numeric($metrics['quotes'] ?? null);
    $knownMissingStatus = $numeric($metrics['missing_status'] ?? null);
    $missingStatusRatio = $ratio($knownMissingStatus, $knownQuotes);
    $traceabilityRatio = $ratio($ceo['funnel']['current_decisions'] ?? null, $ceo['funnel']['quotes'] ?? null);
    $heroNarrative = $knownQuotes === null || $knownMissingStatus === null
        ? 'Production et décisions : données insuffisantes pour une synthèse fiable.'
        : ($knownQuotes === 0
            ? 'Aucun devis enregistré sur la période sélectionnée.'
            : $format($knownQuotes).' devis émis sur la période · '.$format($knownMissingStatus).' sans décision renseignée.');
    $missingStatusSummary = $missingStatusRatio === null
        ? 'Part de devis sans décision renseignée : Indisponible.'
        : 'Part sans décision renseignée : '.$formatPercentage($missingStatusRatio).' %.';
    $kpis = [
        ['key' => 'quotes', 'label' => 'Devis émis', 'note' => 'Date métier quote_date ; timestamp Zoho indisponible.', 'tone' => 'primary', 'icon' => 'ki-document'],
        ['key' => 'missing_status', 'label' => 'Sans décision renseignée', 'note' => $missingStatusRatio !== null ? $formatPercentage($missingStatusRatio).' % du volume de la période.' : 'Part du volume indisponible.', 'tone' => 'danger', 'icon' => 'ki-information-5'],
        ['key' => 'tasks_created', 'label' => 'Tâches créées', 'note' => $format($metrics['not_started_tasks'] ?? null).' non commencées ; création ≠ exécution.', 'tone' => 'info', 'icon' => 'ki-notepad'],
        ['key' => 'proven_human_follow_up', 'label' => 'Activités humaines prouvées', 'note' => $format($metrics['completed_tasks'] ?? null).' tâches terminées + '.$format($metrics['meetings'] ?? null).' réunions + '.$format($metrics['calls'] ?? null).' appels + '.$format($metrics['notes'] ?? null).' notes.', 'tone' => 'success', 'icon' => 'ki-verify'],
    ];
    $monthlyChart = collect($ceo['monthly'] ?? [])->map(static function (array $month) use ($numeric): array {
        $evidence = collect(['completed_tasks', 'meetings', 'calls', 'notes'])
            ->mapWithKeys(static fn (string $key): array => [$key => $numeric($month[$key] ?? null)]);

        return [
            'month' => $month['month'] ?? 'Indisponible',
            'quotes' => $numeric($month['quotes'] ?? null),
            'currentDecisions' => $numeric($month['current_decisions'] ?? null),
            'tasksCreated' => $numeric($month['tasks_created'] ?? null),
            'completedTasks' => $numeric($month['completed_tasks'] ?? null),
            'meetings' => $numeric($month['meetings'] ?? null),
            'calls' => $numeric($month['calls'] ?? null),
            'notes' => $numeric($month['notes'] ?? null),
            'deals' => $numeric($month['deals'] ?? null),
            'humanEvidence' => $evidence->every(static fn (mixed $value): bool => $value !== null)
                ? $evidence->sum()
                : null,
        ];
    })->values();
    $transportCounts = collect($ceo['transport_mix'] ?? [])
        ->map(static fn (mixed $value): int|float|null => $numeric($value))
        ->filter(static fn (mixed $value): bool => $value !== null)
        ->sortDesc();
    $topTransport = $transportCounts->take(4);
    $otherTransport = $transportCounts->slice(4)->sum();
    if ($otherTransport > 0) {
        $topTransport->put('Autres', $otherTransport);
    }
    $monthlyHasPlottableEvidence = $monthlyChart->contains(static function (array $month): bool {
        return collect(['quotes', 'currentDecisions', 'tasksCreated', 'humanEvidence'])
            ->contains(static fn (string $key): bool => $month[$key] !== null);
    });
    $monthlyChartAvailable = $panelConfidence('monthly') !== 'Indisponible'
        && $monthlyChart->isNotEmpty()
        && $monthlyHasPlottableEvidence;
    $transportSourceAvailable = $panelConfidence('mix') !== 'Indisponible';
    $transportChartAvailable = $transportSourceAvailable
        && $topTransport->isNotEmpty()
        && $topTransport->sum() > 0;
    $monthlyLabels = $monthlyChart->pluck('month')
        ->filter(static fn (mixed $month): bool => is_string($month) && $month !== '' && $month !== 'Indisponible')
        ->values();
    $monthlyBounds = $monthlyLabels->isEmpty()
        ? 'bornes indisponibles'
        : $monthlyLabels->first().' → '.$monthlyLabels->last();
    $monthlyCohortLabel = 'Fenêtre fixe de 6 mois calendaires · '.$monthlyBounds
        .($monthlyAsOf === null ? ' · date d’arrêté indisponible' : ' · mois courant au '.$monthlyAsOf);
    $chartPayload = [
        'traceability' => [
            'value' => $traceabilityRatio,
            'decisions' => $numeric($ceo['funnel']['current_decisions'] ?? null),
            'quotes' => $numeric($ceo['funnel']['quotes'] ?? null),
        ],
        'monthly' => $monthlyChart,
        'transport' => [
            'labels' => $topTransport->keys()->values(),
            'series' => $topTransport->values(),
        ],
    ];
    $readinessLabel = static fn (string $key): string => match ($key) {
        'quote_timestamp' => 'Horodatage des devis',
        'status_history' => 'Historique des statuts',
        'identity_links' => 'Liens d’identité',
        'missing_status' => 'Statuts manquants',
        'future_dates' => 'Dates futures',
        'filters' => 'Filtres',
        'forecast' => 'Prévisions',
        default => ucfirst(str_replace('_', ' ', $key)),
    };
@endphp

<x-default-layout>
    @section('title', 'Vue commerciale exécutive')

    @section('breadcrumbs')
        <x-crud.breadcrumb :items="[['label' => 'Zoho'], ['label' => 'Vue commerciale exécutive']]" />
    @endsection

    <div data-ceo-root class="ceo-control-tower">
        <header class="ceo-toolbar d-flex flex-wrap align-items-end justify-content-between gap-4 mb-6">
            <div>
                <div class="ceo-eyebrow">Dashboard CEO · Lecture seule</div>
                <h1 class="fs-2hx fw-bolder mb-2">Vue commerciale exécutive</h1>
                <p class="text-muted mb-0">Production, décisions, activité consignée et qualité du portefeuille Zoho.</p>
            </div>

            <div class="d-flex flex-column align-items-xl-end gap-3">
                <span class="badge badge-light-{{ $freshnessClass }}">Synchronisation {{ $freshness['state'] ?? 'Indisponible' }} · {{ $freshnessStatus }} · {{ $lastSynced }} ({{ $businessTimezone }})</span>
                <div class="ceo-periods" role="group" aria-label="Période des indicateurs">
                    @foreach(['30d' => '30 j', '90d' => '90 j', '365d' => '365 j'] as $period => $label)
                        <a class="btn btn-sm {{ ($controls['period'] ?? '90d') === $period ? 'btn-primary' : 'btn-light' }}"
                           href="{{ request()->fullUrlWithQuery(['period' => $period]) }}"
                           data-testid="period-{{ str_replace('d', '', $period) }}">{{ $label }}</a>
                    @endforeach
                </div>
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

        <section class="row g-5 mb-6">
            <div class="col-xl-8">
                <article class="card ceo-executive-hero h-100" data-ceo-executive-hero>
                    <div class="card-body position-relative p-8 p-xl-10">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="ceo-eyebrow text-primary-200">Briefing exécutif · {{ $controls['period'] ?? '90d' }}</span>
                            <span class="badge badge-light-{{ $confidenceClass($briefingConfidence) }}">{{ $briefingConfidence }}</span>
                            @if($filterScopeNeedsNotice($briefingFilterScope))
                                <span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($briefingFilterScope) }}" role="status">Filtre du briefing : {{ $briefingFilterScope }}</span>
                            @endif
                        </div>
                        <h2 class="text-white fs-2x fw-bolder mt-4 mb-3">{{ $heroNarrative }}</h2>
                        <p class="text-white-50 mb-7">{{ $missingStatusSummary }}</p>
                        <div class="row g-5 ceo-hero-facts">
                            <div class="col-6 col-md-4"><strong>{{ $format($ceo['briefing']['highest_deadline'] ?? null) }}</strong><span>échéance la plus haute</span></div>
                            <div class="col-6 col-md-4"><strong>{{ $format(data_get($cards, 'P2.value')) }}</strong><span>signal de progression</span></div>
                            <div class="col-6 col-md-4"><strong>{{ $format(data_get($cards, 'P3.value')) }}</strong><span>signal campagne doux</span></div>
                        </div>
                        <div class="ceo-source-completeness small text-white-50 mt-5">Sources : comptes/échéance — {{ $completenessLabel($briefingCompleteness['reachable_accounts'] ?? 'unavailable') }} · escalades — {{ $completenessLabel($briefingCompleteness['owner_escalations'] ?? 'unavailable') }}.</div>
                    </div>
                </article>
            </div>
            <div class="col-xl-4">
                <article class="card card-flush h-100">
                    <div class="card-header flex-wrap gap-2">
                        <div><span class="ceo-eyebrow">Traçabilité CRM</span><h2 class="card-title">Entonnoir de traçabilité</h2><p class="text-muted mb-0">Décisions renseignées</p></div>
                        <span class="badge badge-light-{{ $confidenceClass($panelConfidence('funnel')) }}">{{ $panelConfidence('funnel') }}</span>
                        @if($filterScopeNeedsNotice($panelFilterScope('funnel')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('funnel')) }}" role="status">Filtre : {{ $panelFilterScope('funnel') }}</span>@endif
                    </div>
                    <div class="card-body pt-0">
                        <div id="ceo-traceability-fallback" class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 border-bottom pb-3 mb-2" data-ceo-traceability-fallback>
                            <strong>{{ $traceabilityRatio === null ? 'Indisponible' : $formatPercentage($traceabilityRatio).' %' }}</strong>
                            <span class="small text-muted">Décisions renseignées : {{ $format($ceo['funnel']['current_decisions'] ?? null) }} / {{ $format($ceo['funnel']['quotes'] ?? null) }} devis</span>
                        </div>
                        @if($traceabilityRatio !== null)
                            <div class="ceo-chart ceo-chart-radial" data-ceo-chart="traceability" data-ceo-chart-state="available" role="img" aria-label="{{ $formatPercentage($traceabilityRatio) }} % des devis ont une décision renseignée" aria-describedby="ceo-traceability-fallback"></div>
                        @else
                            <div class="ceo-chart-unavailable" data-ceo-chart="traceability" data-ceo-chart-state="unavailable" role="status">Indisponible</div>
                        @endif
                        @foreach(['named_contacts' => 'Contacts nommés liés', 'linked_deals' => 'Deals liés', 'completed_quote_tasks' => 'Tâches devis terminées'] as $key => $label)
                            <div class="d-flex justify-content-between border-bottom py-3"><span>{{ $label }}</span><strong>{{ $format($ceo['funnel'][$key] ?? null) }}</strong></div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

        <section class="row g-5 mb-6" aria-label="Indicateurs commerciaux principaux">
            @foreach($kpis as $kpi)
                @php
                    $key = $kpi['key'];
                    $metricConfidence = $metricConfidences[$key] ?? 'Indisponible';
                    $metricFilterScope = $filterScopeStatus('metrics', $key);
                @endphp
                <div class="col-sm-6 col-xl-3">
                    <article class="card card-flush h-100 ceo-kpi">
                        <div class="card-body p-6">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <span class="ceo-icon-tile bg-light-{{ $kpi['tone'] }} text-{{ $kpi['tone'] }}" aria-hidden="true"><i class="ki-outline {{ $kpi['icon'] }} fs-2x"></i></span>
                                <span class="badge badge-light-{{ $confidenceClass($metricConfidence) }}">{{ $metricConfidence }}</span>
                            </div>
                            <div class="fs-2qx fw-bolder mt-5 mb-1" data-testid="ceo-{{ $key }}">{{ $format($metrics[$key] ?? null) }}</div>
                            <h3 class="fs-6 fw-bold mb-2">{{ $kpi['label'] }}</h3>
                            <p class="small text-muted mb-0">{{ $kpi['note'] }}</p>
                            @if($filterScopeNeedsNotice($metricFilterScope))
                                <div class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($metricFilterScope) }} mt-3" role="status">Filtre de cet indicateur : {{ $metricFilterScope }}</div>
                            @endif
                        </div>
                    </article>
                </div>
            @endforeach
        </section>

        <section class="row g-5 mb-6">
            <div class="col-xl-8">
                <article class="card card-flush h-100">
                    <div class="card-header flex-wrap gap-2">
                        <div>
                            <span class="ceo-eyebrow">Dynamique mensuelle</span>
                            <h2 class="card-title">Production et activité consignée</h2>
                            <p class="small text-muted mb-0" data-ceo-monthly-cohort>{{ $monthlyCohortLabel }}</p>
                        </div>
                        <span class="badge badge-light-{{ $confidenceClass($panelConfidence('monthly')) }}">{{ $panelConfidence('monthly') }}</span>
                        @if($filterScopeNeedsNotice($panelFilterScope('monthly')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('monthly')) }}" role="status">Filtre : {{ $panelFilterScope('monthly') }}</span>@endif
                    </div>
                    <div class="card-body">
                        @if($monthlyChartAvailable)
                            <div class="ceo-chart" data-ceo-chart="monthly" data-ceo-chart-state="available" role="img" aria-label="Production et activité consignée par mois"></div>
                        @else
                            <div class="ceo-chart-unavailable" data-ceo-chart="monthly" data-ceo-chart-state="unavailable" role="status">Indisponible</div>
                        @endif
                        <p class="small text-muted mb-0">Les décisions sont l’état actuel des devis, pas un historique de conversion. Les tâches terminées sont les tâches créées ce mois et dont le statut actuel est terminé ; la date de fin indisponible empêche une lecture historique de l’exécution.</p>
                        <details class="ceo-exact-values mt-4">
                            <summary class="fw-bold">Valeurs exactes</summary>
                            <div class="table-responsive mt-3">
                                <table class="table table-row-dashed align-middle">
                                    <caption class="visually-hidden">Valeurs mensuelles exactes utilisées par le graphique</caption>
                                    <thead><tr><th>Mois</th><th>Devis</th><th>Décisions actuelles</th><th>Tâches créées</th><th>Terminées</th><th>Réunions</th><th>Appels</th><th>Notes</th><th>Deals</th></tr></thead>
                                    <tbody>
                                        @forelse($ceo['monthly'] ?? [] as $month)
                                            @php($monthLabel = $month['month'] ?? 'Indisponible')
                                            <tr data-tasks-state="{{ $numeric($month['tasks_created'] ?? null) === null ? 'unavailable' : 'available' }}" data-tasks-value="{{ $numeric($month['tasks_created'] ?? null) ?? '' }}"
                                                data-human-evidence-state="{{ collect(['completed_tasks', 'meetings', 'calls', 'notes'])->every(fn (string $key): bool => $numeric($month[$key] ?? null) !== null) ? 'available' : 'unavailable' }}">
                                                <td>{{ $monthLabel }}@if($monthLabel === $currentMonth && $monthlyAsOf) · en cours au {{ $monthlyAsOf }}@endif</td>
                                                <td>{{ $format($month['quotes'] ?? null) }}</td>
                                                <td>{{ $format($month['current_decisions'] ?? null) }}</td>
                                                <td>{{ $format($month['tasks_created'] ?? null) }}</td>
                                                <td>{{ $format($month['completed_tasks'] ?? null) }}</td>
                                                <td>{{ $format($month['meetings'] ?? null) }}</td>
                                                <td>{{ $format($month['calls'] ?? null) }}</td>
                                                <td>{{ $format($month['notes'] ?? null) }}</td>
                                                <td>{{ $format($month['deals'] ?? null) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="9">{{ $emptyState('monthly') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </article>
            </div>
            <div class="col-xl-4">
                <article class="card card-flush h-100">
                    <div class="card-header flex-wrap gap-2">
                        <h2 class="card-title">Points d’attention</h2>
                        <span class="badge badge-light-{{ $confidenceClass($panelConfidence('quote_risks')) }}">{{ $panelConfidence('quote_risks') }}</span>
                        @if($filterScopeNeedsNotice($panelFilterScope('quote_risks')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('quote_risks')) }}" role="status">Filtre : {{ $panelFilterScope('quote_risks') }}</span>@endif
                    </div>
                    <div class="card-body pt-0">
                        @foreach(['expired_missing_decision' => 'Expirés sans décision', 'due_7_days' => 'Sous 7 jours', 'due_30_days' => 'Sous 30 jours', 'unreachable' => 'Sans canal joignable', 'future_date_anomalies' => 'Dates futures'] as $key => $label)
                            <div class="d-flex justify-content-between border-bottom py-3"><span>{{ $label }}</span><strong>{{ $format($ceo['quote_risks'][$key] ?? null) }}</strong></div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

        <section class="row g-5 mb-6">
            <div class="col-xl-8">
                <article class="card card-flush h-100">
                    <div class="card-header flex-wrap gap-3">
                        <div>
                            <h2 class="card-title">Comptes à surveiller</h2>
                            <span class="text-muted small">Dédupliqués par compte · canaux masqués · {{ count($queue) }} affiché(s) · sources {{ mb_strtolower($completenessLabel($overallQueueCompleteness)) }}</span>
                            @if($filterScopeNeedsNotice($queueFilterScope))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($queueFilterScope) }} d-table mt-2" role="status">Filtre de la file : {{ $queueFilterScope }}</span>@endif
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        @if(($queueData['truncated'] ?? false) === true)
                            <p class="alert alert-light-info small mt-4 mb-3">File tronquée : {{ $format($numericQueueTotal) }} comptes identifiés dans les priorités disponibles, {{ count($queue) }} affichés. {{ $overallQueueCompleteness === 'complete' ? 'Les compteurs par priorité sont complets.' : 'Les compteurs marqués partiels couvrent uniquement le sous-ensemble connu.' }}</p>
                        @endif
                        <div class="d-flex flex-wrap gap-2 mb-4" data-testid="ceo-watchlist-filters">
                            <div class="d-flex flex-wrap gap-1" role="group" aria-label="Filtrer les comptes par signal">
                                <button type="button" class="btn btn-sm btn-primary ceo-filter-button" data-ceo-priority="all" aria-pressed="true" data-ceo-completeness="{{ $overallQueueCompleteness }}">Tous</button>
                                @foreach(['P1', 'P2', 'P3', 'enrichment'] as $priority)
                                    <button type="button"
                                            class="btn btn-sm btn-light ceo-filter-button"
                                            data-ceo-priority="{{ $priority }}"
                                            data-ceo-completeness="{{ $queueCompleteness[$priority] ?? 'unavailable' }}"
                                            aria-pressed="false"
                                            aria-disabled="{{ $queueAvailability[$priority] ? 'false' : 'true' }}"
                                            @disabled(! $queueAvailability[$priority])>
                                        {{ $priorityLabel($priority) }} <span class="badge badge-light">{{ $filterTotals[$priority] === null ? 'Indisponible' : $format($filterTotals[$priority]) }}</span>@if(($queueCompleteness[$priority] ?? 'unavailable') === 'partial') <span class="badge badge-light-warning">partiel</span>@endif
                                    </button>
                                @endforeach
                            </div>
                            <select class="form-select form-select-sm w-auto" data-ceo-owner aria-label="Filtrer par propriétaire parmi les comptes affichés">
                                <option value="all">Tous les propriétaires affichés</option>
                                @foreach($displayedOwners as $owner)<option value="{{ $owner }}">{{ $owner }}</option>@endforeach
                            </select>
                        </div>
                        <div class="table-responsive ceo-table">
                            <table class="table align-middle table-row-dashed ceo-watchlist">
                                <thead><tr><th>Compte / contact</th><th>Signal</th><th>Constat observé</th><th>Dernière activité humaine prouvée</th><th>Propriétaire</th><th>Horizon</th></tr></thead>
                                <tbody>
                                    @forelse($queue as $item)
                                        @php($priority = $item['priority'] ?? 'enrichment')
                                        <tr data-ceo-row data-priority="{{ $priority }}" data-signals="{{ collect($item['signals'] ?? [$priority])->implode(',') }}" data-owner="{{ $item['owner'] ?? 'Propriétaire Zoho indisponible' }}">
                                            <td data-label="Compte / contact"><strong>{{ $item['account'] ?? 'Compte non nommé' }}</strong><span class="d-block small text-muted">{{ $item['contact'] ?? 'Contact non nommé' }}</span></td>
                                            <td data-label="Signal"><span class="badge badge-light-{{ $priority === 'P1' ? 'danger' : ($priority === 'P2' ? 'warning' : 'info') }}">{{ $priorityLabel($priority) }}</span></td>
                                            <td data-label="Constat observé">{{ implode(' · ', $item['reasons'] ?? []) }}<span class="d-block small text-muted">{{ $item['quote_context'] ?? 'Contexte indisponible' }}</span></td>
                                            <td data-label="Dernière activité humaine prouvée">{{ $item['last_proven_action'] ?? 'Indisponible' }}</td>
                                            <td data-label="Propriétaire">{{ $item['owner'] ?? 'Propriétaire Zoho indisponible' }}</td>
                                            <td data-label="Horizon">{{ $item['deadline'] ?? 'Indisponible' }}</td>
                                        </tr>
                                    @empty
                                        <tr data-ceo-queue-empty-state="{{ $queueEmpty['kind'] }}"><td colspan="6" class="text-muted">{{ $queueEmpty['message'] }}</td></tr>
                                    @endforelse
                                    <tr data-ceo-empty-filter hidden><td colspan="6">Aucun compte ne correspond aux filtres affichés.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-xl-4">
                <article class="card card-flush h-100">
                    <div class="card-header flex-wrap gap-2">
                        <div>
                            <h2 class="card-title">Mix de volume, pas de revenu</h2>
                            <span class="text-muted small">Volume de devis observé, pas revenu attribué.</span>
                        </div>
                        <span class="badge badge-light-{{ $confidenceClass($panelConfidence('mix')) }}">{{ $panelConfidence('mix') }}</span>
                        @if($filterScopeNeedsNotice($panelFilterScope('mix')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('mix')) }}" role="status">Filtre : {{ $panelFilterScope('mix') }}</span>@endif
                    </div>
                    <div class="card-body pt-0">
                        @if($transportChartAvailable)
                            <div class="ceo-chart" data-ceo-chart="transport" data-ceo-chart-state="available" role="img" aria-label="Répartition du volume de devis par transport"></div>
                            <div class="mt-2" aria-label="Valeurs exactes par transport">
                                @foreach($topTransport as $transport => $count)
                                    <div class="d-flex justify-content-between border-bottom py-2"><span>{{ $transport }}</span><strong>{{ $format($count) }}</strong></div>
                                @endforeach
                            </div>
                        @elseif($transportSourceAvailable)
                            <div class="ceo-chart-known-zero" data-ceo-chart="transport" data-ceo-chart-state="known-zero" role="status">Aucun résultat sur la période</div>
                        @else
                            <div class="ceo-chart-unavailable" data-ceo-chart="transport" data-ceo-chart-state="unavailable" role="status">Indisponible</div>
                        @endif
                        <h3 class="fs-7 text-uppercase text-muted fw-bold mt-3">Axes principaux</h3>
                        @forelse($ceo['lane_mix'] ?? [] as $lane => $count)
                            <div class="d-flex justify-content-between border-bottom py-2"><span>{{ $lane }}</span><strong>{{ $format($count) }}</strong></div>
                        @empty
                            <div class="text-muted">{{ $emptyState('mix') }}</div>
                        @endforelse
                    </div>
                </article>
            </div>
        </section>

        <section class="card card-flush mb-6">
            <div class="card-header flex-wrap gap-2">
                <div>
                    <h2 class="card-title">Preuves CRM par propriétaire</h2>
                    <span class="text-muted small">Éléments observés, pas un score de performance. La barre représente les devis sans décision parmi les devis du propriétaire.</span>
                </div>
                <span class="badge badge-light-{{ $confidenceClass($panelConfidence('owners')) }}">{{ $panelConfidence('owners') }}</span>
                @if($filterScopeNeedsNotice($panelFilterScope('owners')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('owners')) }}" role="status">Filtre : {{ $panelFilterScope('owners') }}</span>@endif
            </div>
            <div class="card-body pt-0">
                @forelse($owners as $owner)
                    @php($ownerMissingRatio = $ratio($owner['missing_status'] ?? null, $owner['quotes'] ?? null))
                    <article class="ceo-owner-evidence border-bottom py-5">
                        <div class="row g-5 align-items-center">
                            <div class="col-lg-3">
                                <h3 class="fs-6 fw-bolder mb-1">{{ $owner['owner'] ?? 'Propriétaire Zoho indisponible' }}</h3>
                                <span class="text-muted small">{{ $format($owner['quotes'] ?? null) }} devis observés</span>
                            </div>
                            <div class="col-lg-4">
                                <div class="d-flex justify-content-between gap-3 mb-2">
                                    <span class="small text-muted">Sans décision renseignée</span>
                                    <strong>{{ $format($owner['missing_status'] ?? null) }}</strong>
                                </div>
                                @if($ownerMissingRatio !== null)
                                    <div class="small text-muted mb-2">{{ $format($owner['missing_status'] ?? null) }} / {{ $format($owner['quotes'] ?? null) }} devis · {{ $formatPercentage($ownerMissingRatio) }} %</div>
                                    <div class="progress h-6px">
                                        <div class="progress-bar"
                                            role="progressbar"
                                            style="width: {{ min(100, $ownerMissingRatio) }}%"
                                            aria-label="{{ $formatPercentage($ownerMissingRatio) }} % des devis de ce propriétaire sont sans décision renseignée"
                                            aria-valuenow="{{ $ownerMissingRatio }}"
                                            aria-valuemin="0"
                                            aria-valuemax="100"></div>
                                    </div>
                                @else
                                    <span class="text-muted small">Ratio indisponible</span>
                                @endif
                            </div>
                            <dl class="col-lg-5 ceo-owner-metrics mb-0">
                                @foreach([
                                    'overdue_tasks' => 'Tâches en retard',
                                    'completed_tasks' => 'Tâches terminées',
                                    'meetings' => 'Réunions',
                                    'calls' => 'Appels',
                                    'notes' => 'Notes',
                                    'linked_contacts' => 'Contacts liés',
                                    'linked_deals' => 'Deals liés',
                                ] as $key => $label)
                                    <div><dt>{{ $label }}</dt><dd>{{ $format($owner[$key] ?? null) }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    </article>
                @empty
                    <div class="text-muted">{{ $emptyState('owners') }}</div>
                @endforelse
            </div>
        </section>

        <section class="card card-flush">
            <div class="card-header flex-wrap gap-2">
                <h2 class="card-title">Qualité et limites des données</h2>
                <span class="badge badge-light-{{ $confidenceClass($panelConfidence('readiness')) }}">{{ $panelConfidence('readiness') }}</span>
                @if($filterScopeNeedsNotice($panelFilterScope('readiness')))<span class="ceo-filter-scope ceo-filter-scope-{{ $filterScopeClass($panelFilterScope('readiness')) }}" role="status">Filtre : {{ $panelFilterScope('readiness') }}</span>@endif
            </div>
            <div class="card-body pt-0">
                @forelse($ceo['readiness'] ?? [] as $key => $note)
                    <p class="border-bottom pb-3"><strong>{{ $readinessLabel((string) $key) }} :</strong> {{ $note }}</p>
                @empty
                    <p class="text-muted">Indisponible</p>
                @endforelse
                <p class="text-muted mb-0">Limites explicites : aucun forecast ni taux de gain historique fiable, et aucune attribution de revenu, ne sont calculés à partir de ces données.</p>
            </div>
        </section>

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
    </div>

    @push('styles')
        <style>
            .ceo-control-tower .ceo-toolbar .badge{max-width:100%;white-space:normal;text-align:start}
            .ceo-control-tower .ceo-eyebrow{color:var(--bs-gray-600);font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
            .ceo-control-tower .ceo-executive-hero{position:relative;overflow:hidden;border:0;color:#fff;background:radial-gradient(circle at 87% 22%,rgba(87,137,255,.55),transparent 28%),linear-gradient(125deg,#111d35 0%,#172a51 55%,#202d5f 100%);box-shadow:0 1rem 2.5rem rgba(30,58,138,.18)}
            .ceo-control-tower .ceo-executive-hero::after{position:absolute;right:-5rem;bottom:-8rem;width:19rem;height:19rem;border:1px solid rgba(255,255,255,.12);border-radius:50%;content:""}
            .ceo-control-tower .ceo-executive-hero .card-body{z-index:1}
            .ceo-control-tower .ceo-hero-facts strong,.ceo-control-tower .ceo-hero-facts span{display:block}
            .ceo-control-tower .ceo-hero-facts strong{color:#fff;font-size:1.65rem;line-height:1.2}
            .ceo-control-tower .ceo-hero-facts span{margin-top:.4rem;color:rgba(255,255,255,.58);font-size:.8rem}
            .ceo-control-tower .ceo-kpi{border:1px solid var(--bs-gray-200);transition:transform .2s ease,box-shadow .2s ease}
            .ceo-control-tower .ceo-kpi:hover{transform:translateY(-2px);box-shadow:var(--bs-box-shadow-sm)}
            .ceo-control-tower .ceo-icon-tile{display:inline-flex;width:3.25rem;height:3.25rem;align-items:center;justify-content:center;border-radius:.85rem}
            .ceo-control-tower .ceo-filter-scope{display:inline-flex;align-items:center;padding:.35rem .55rem;border:1px solid currentColor;border-radius:.475rem;font-size:.75rem;font-weight:700;line-height:1.2}
            .ceo-control-tower .ceo-filter-scope-danger{color:var(--bs-danger);background:var(--bs-danger-light,#fff5f8)}
            .ceo-control-tower .ceo-filter-scope-warning{color:var(--bs-warning-text-emphasis,#8a5d00);background:var(--bs-warning-light,#fff8dd)}
            .ceo-control-tower .ceo-chart{min-height:240px}
            .ceo-control-tower [data-ceo-chart="monthly"]{min-height:360px}
            .ceo-control-tower .ceo-chart-radial{min-height:240px}
            .ceo-control-tower .ceo-chart-unavailable,.ceo-control-tower .ceo-chart-known-zero{display:grid;min-height:240px;place-items:center;border:1px dashed var(--bs-gray-300);border-radius:.75rem;color:var(--bs-gray-600);font-weight:700}
            .ceo-control-tower .ceo-filter-button[aria-pressed="true"]{box-shadow:0 0 0 2px rgba(62,123,250,.2)}
            .ceo-control-tower .ceo-table{overflow-x:auto}
            .ceo-control-tower .ceo-watchlist th{white-space:nowrap;color:var(--bs-gray-600);font-size:.75rem;text-transform:uppercase}
            .ceo-control-tower .ceo-watchlist td{vertical-align:top}
            .ceo-control-tower .ceo-owner-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
            .ceo-control-tower .ceo-owner-metrics dt{color:var(--bs-gray-600);font-size:.72rem;font-weight:700;text-transform:uppercase}
            .ceo-control-tower .ceo-owner-metrics dd{margin:0;color:var(--bs-gray-900);font-size:1.1rem;font-weight:700}
            @media(max-width:767.98px){
                .ceo-control-tower .ceo-toolbar>div,.ceo-control-tower .ceo-toolbar .d-flex.flex-column{width:100%;min-width:0;align-items:flex-start!important}
                .ceo-control-tower .ceo-periods{display:flex;width:100%;gap:.35rem}
                .ceo-control-tower .ceo-periods .btn{flex:1}
                .ceo-control-tower .ceo-table{overflow:visible}
                .ceo-control-tower .ceo-watchlist,.ceo-control-tower .ceo-watchlist tbody,.ceo-control-tower .ceo-watchlist tr,.ceo-control-tower .ceo-watchlist td{display:block;width:100%}
                .ceo-control-tower .ceo-watchlist thead{display:none}
                .ceo-control-tower .ceo-watchlist tr[data-ceo-row]{display:grid;gap:.55rem;padding:1rem;border-bottom:1px solid var(--bs-gray-300)}
                .ceo-control-tower .ceo-watchlist tr[data-ceo-row][hidden]{display:none!important}
                .ceo-control-tower .ceo-watchlist td{display:grid;grid-template-columns: minmax(8rem, 38%) 1fr;gap:.75rem;padding:.15rem 0;border:0}
                .ceo-control-tower .ceo-watchlist td::before{color:var(--bs-gray-600);font-size:.78rem;font-weight:700;content:attr(data-label)}
                .ceo-control-tower .ceo-watchlist tr:not([data-ceo-row]) td{display:block}
                .ceo-control-tower .ceo-watchlist tr:not([data-ceo-row]) td::before{content:none}
                .ceo-control-tower .ceo-chart,.ceo-control-tower .ceo-chart-radial,.ceo-control-tower .ceo-chart-unavailable,.ceo-control-tower .ceo-chart-known-zero{min-height:220px}
                .ceo-control-tower .ceo-owner-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
            }
            @media(max-width:359.98px){
                .ceo-control-tower .ceo-periods{flex-wrap:wrap}
                .ceo-control-tower .ceo-periods .btn{flex:1 1 calc(50% - .35rem)}
                .ceo-control-tower .ceo-hero-facts>[class*="col-"]{width:100%}
            }
            @media(prefers-reduced-motion:reduce){.ceo-control-tower .ceo-kpi{transition:none}.ceo-control-tower .ceo-kpi:hover{transform:none}}
        </style>
    @endpush

    @push('scripts')
        <script>
            (function () {
                const payload = {{ Illuminate\Support\Js::from($chartPayload) }};
                const lifecycle = window.FretiqMarketingDashboardLifecycle ??= {
                    queue: Promise.resolve(),
                    handler: null,
                };

                if (typeof lifecycle.handler === 'function') {
                    document.removeEventListener('DOMContentLoaded', lifecycle.handler);
                    document.removeEventListener('livewire:navigated', lifecycle.handler);
                }

                const destroyCharts = async () => {
                    window.FretiqMarketingDashboardCharts ??= [];
                    const charts = window.FretiqMarketingDashboardCharts.splice(0);
                    await Promise.allSettled(charts.map(chart => Promise.resolve().then(() => chart.destroy())));
                };
                const formatNumber = value => typeof value === 'number'
                    ? new Intl.NumberFormat('fr-FR').format(value)
                    : 'Indisponible';
                const bindWatchlistFilters = root => {
                    if (root.dataset.ceoFiltersBound === 'true') return;
                    root.dataset.ceoFiltersBound = 'true';

                    const rows = [...root.querySelectorAll('[data-ceo-row]')];
                    const emptyFilter = root.querySelector('[data-ceo-empty-filter]');
                    const emptyFilterCell = emptyFilter?.querySelector('td');
                    if (rows.length === 0) {
                        if (emptyFilter) emptyFilter.hidden = true;
                        return;
                    }

                    let selectedPriority = 'all';
                    let selectedOwner = 'all';
                    let selectedCompleteness = root.querySelector('[data-ceo-priority="all"]')?.dataset.ceoCompleteness || 'unavailable';

                    const renderWatchlist = () => {
                        let visible = 0;
                        rows.forEach(row => {
                            const signals = (row.dataset.signals || row.dataset.priority || '').split(',');
                            const matchesPriority = selectedPriority === 'all' || signals.includes(selectedPriority);
                            const matchesOwner = selectedOwner === 'all' || row.dataset.owner === selectedOwner;
                            row.hidden = !(matchesPriority && matchesOwner);
                            if (!row.hidden) visible += 1;
                        });
                        if (emptyFilter) {
                            const filteredEmptyMessage = selectedCompleteness === 'partial'
                                ? 'Aucun compte dans le sous-ensemble connu pour ces filtres.'
                                : 'Aucun compte ne correspond aux filtres affichés.';
                            if (visible === 0 && emptyFilterCell) {
                                emptyFilterCell.textContent = filteredEmptyMessage;
                            }
                            emptyFilter.hidden = visible !== 0;
                        }
                    };

                    root.querySelectorAll('[data-ceo-priority]').forEach(button => {
                        button.addEventListener('click', () => {
                            if (button.disabled) return;
                            selectedPriority = button.dataset.ceoPriority || 'all';
                            selectedCompleteness = button.dataset.ceoCompleteness || 'unavailable';
                            root.querySelectorAll('[data-ceo-priority]').forEach(candidate => {
                                const active = candidate === button;
                                candidate.setAttribute('aria-pressed', String(active));
                                candidate.classList.toggle('btn-primary', active);
                                candidate.classList.toggle('btn-light', !active);
                            });
                            renderWatchlist();
                        });
                    });

                    root.querySelector('[data-ceo-owner]')?.addEventListener('change', event => {
                        selectedOwner = event.target.value;
                        renderWatchlist();
                    });
                };

                const runBoot = async () => {
                    await destroyCharts();

                    const root = document.querySelector('[data-ceo-root]');
                    if (!root) return;

                    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    const register = async (element, options) => {
                        if (!element || typeof window.ApexCharts !== 'function') return;

                        const chart = new window.ApexCharts(element, {
                            ...options,
                            chart: {...options.chart, animations: {enabled: !reducedMotion}},
                        });
                        window.FretiqMarketingDashboardCharts.push(chart);

                        try {
                            await chart.render();
                        } catch (error) {
                            const chartIndex = window.FretiqMarketingDashboardCharts.indexOf(chart);
                            if (chartIndex !== -1) {
                                window.FretiqMarketingDashboardCharts.splice(chartIndex, 1);
                            }
                            await Promise.resolve().then(() => chart.destroy()).catch(() => undefined);
                        }
                    };
                    const renders = [];

                    if (typeof payload.traceability.value === 'number') {
                        renders.push(register(root.querySelector('[data-ceo-chart="traceability"]'), {
                            series: [payload.traceability.value],
                            chart: {type: 'radialBar', height: 190, sparkline: {enabled: true}},
                            colors: ['#3e7bfa'],
                            plotOptions: {radialBar: {hollow: {size: '68%'}, dataLabels: {name: {show: false}, value: {formatter: value => `${value}%`}}}},
                            stroke: {lineCap: 'round'},
                        }));
                    }

                    const monthlyElement = root.querySelector('[data-ceo-chart="monthly"][data-ceo-chart-state="available"]');
                    if (monthlyElement && payload.monthly.length > 0) {
                        renders.push(register(monthlyElement, {
                            series: [
                                {name: 'Devis', type: 'column', data: payload.monthly.map(row => row.quotes)},
                                {name: 'Tâches créées', type: 'column', data: payload.monthly.map(row => row.tasksCreated)},
                                {name: 'Décisions actuelles', type: 'line', data: payload.monthly.map(row => row.currentDecisions)},
                                {name: 'Activité humaine prouvée', type: 'line', data: payload.monthly.map(row => row.humanEvidence)},
                            ],
                            chart: {type: 'line', height: 360, toolbar: {show: false}},
                            xaxis: {categories: payload.monthly.map(row => row.month)},
                            stroke: {width: [0, 0, 3, 3], curve: 'smooth', dashArray: [0, 0, 0, 5]},
                            dataLabels: {enabled: false},
                            legend: {position: 'top', horizontalAlign: 'right'},
                            tooltip: {shared: true, intersect: false, y: {formatter: formatNumber}},
                            noData: {text: 'Indisponible'},
                        }));
                    }

                    const transportElement = root.querySelector('[data-ceo-chart="transport"][data-ceo-chart-state="available"]');
                    if (transportElement && payload.transport.labels.length > 0 && payload.transport.labels.length === payload.transport.series.length) {
                        renders.push(register(transportElement, {
                            series: payload.transport.series,
                            labels: payload.transport.labels,
                            chart: {type: 'donut', height: 240},
                            legend: {show: false},
                            dataLabels: {enabled: false},
                            tooltip: {y: {formatter: formatNumber}},
                            noData: {text: 'Indisponible'},
                        }));
                    }

                    await Promise.allSettled(renders);
                    bindWatchlistFilters(root);
                };

                const queueBoot = () => {
                    lifecycle.queue = lifecycle.queue.then(runBoot, runBoot);

                    return lifecycle.queue.catch(() => undefined);
                };

                lifecycle.handler = () => {
                    void queueBoot();
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', lifecycle.handler, {once: true});
                } else {
                    lifecycle.handler();
                }
                document.addEventListener('livewire:navigated', lifecycle.handler);
            })();
        </script>
    @endpush
</x-default-layout>
