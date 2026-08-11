<?php

namespace App\Services\Zoho\V2\Marketing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only CEO projection built exclusively from the local Zoho mirror.
 *
 * This service never calls Zoho and never returns raw contact details or raw payloads.
 */
final class ZohoCeoControlTower
{
    private const QUOTE_COLUMNS = [
        'zoho_id', 'owner_zoho_id', 'deal_zoho_id', 'account_zoho_id', 'contact_zoho_id',
        'subject', 'quote_number', 'follow_up_status', 'valid_till', 'quote_date',
        'transport_type', 'country', 'currency_code', 'origin', 'destination', 'last_synced_at',
    ];

    private const ACTIVITY_COLUMNS = [
        'activity_type', 'zoho_id', 'owner_zoho_id', 'parent_zoho_id', 'contact_zoho_id',
        'status', 'activity_at', 'due_at', 'start_at', 'zoho_created_at', 'last_synced_at',
    ];

    private const DEAL_COLUMNS = [
        'zoho_id', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'name', 'stage',
        'zoho_created_at', 'last_activity_at', 'stage_modified_at', 'currency_code', 'last_synced_at',
    ];

    private const PRIORITIES = ['P1', 'P2', 'P3', 'enrichment'];

    /** @var array<string,int> */
    private const PRIORITY_RANK = ['P1' => 1, 'P2' => 2, 'P3' => 3, 'enrichment' => 4];

    /** @return array<string,mixed> */
    public function analyse(MarketingAnalyticsQuery $input, MarketingScope $scope): array
    {
        $period = $input->period;
        $filters = $input->normalizedFilters();

        if (! $scope->crmVisible) {
            return $this->unavailable($period, $scope, $filters, scopeUnavailable: true);
        }

        if (! $this->mirrorAvailable()) {
            return $this->unavailable($period, $scope, $filters);
        }

        $now = CarbonImmutable::now($period->timezone);
        [$periodStartUtc, $periodEndUtc] = $period->databaseBounds();
        $evidenceEndUtc = $periodEndUtc->lt($now->utc()) ? $periodEndUtc : $now->utc();
        $today = $now->toDateString();
        $freshness = $this->freshnessEvidence($scope, $now);
        if ($freshness['state'] === 'Indisponible') {
            $payload = $this->unavailable($period, $scope, $filters);
            $payload['freshness'] = $freshness;

            return $payload;
        }
        $moduleAvailable = fn (string $module): bool => (bool) ($freshness['modules'][$module]['available'] ?? false);

        $quotes = $moduleAvailable('quotes')
            ? $this->quotes($scope, $filters)
                ->whereBetween('quote_date', [$period->startsAt->toDateString(), $period->endsAt->toDateString()])
                ->whereDate('quote_date', '<=', $today)
                ->get(self::QUOTE_COLUMNS)
            : collect();

        $activityCandidates = $moduleAvailable('activities')
            ? $this->activitiesInBounds(
                $this->activities($scope),
                $periodStartUtc,
                $evidenceEndUtc,
            )->get(self::ACTIVITY_COLUMNS)
            : collect();
        $eventActivities = $activityCandidates->filter(
            fn (object $activity): bool => $this->activityInPeriod($activity, $period)
                && $this->activityOccurredBy($activity, $evidenceEndUtc),
        )->values();

        $overdueTasks = $moduleAvailable('activities')
            ? $this->activities($scope)
                ->whereRaw('LOWER(activity_type) = ?', ['task'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', $now->utc())
                ->get(self::ACTIVITY_COLUMNS)
                ->reject(fn (object $activity): bool => $this->isCompletedTask($activity))
                ->values()
            : collect();

        $deals = $moduleAvailable('deals')
            ? $this->deals($scope, $filters)->get(self::DEAL_COLUMNS)
            : collect();
        $campaignSignals = $this->campaignSignals($scope, $filters, $period);
        $accountsAvailable = $moduleAvailable('accounts');
        $contactsAvailable = $moduleAvailable('contacts');
        $hasChannelSource = $accountsAvailable || $contactsAvailable;
        $allChannelSources = $accountsAvailable && $contactsAvailable;
        $channelRuleCompleteness = function (array $requiredModules) use (
            $moduleAvailable,
            $hasChannelSource,
            $allChannelSources,
        ): string {
            if (! $hasChannelSource || collect($requiredModules)->contains(
                fn (string $module): bool => ! $moduleAvailable($module),
            )) {
                return 'unavailable';
            }

            return $allChannelSources ? 'complete' : 'partial';
        };
        $ruleCompleteness = [
            'P1' => $channelRuleCompleteness(['quotes']),
            'P2_repeated' => $channelRuleCompleteness(['quotes', 'deals', 'activities']),
            'P2_stale' => $channelRuleCompleteness(['deals', 'activities']),
            'P3' => match (true) {
                ! $campaignSignals['available'] => 'unavailable',
                $campaignSignals['candidate_count'] === 0 => 'complete',
                ! $contactsAvailable => 'unavailable',
                $campaignSignals['completeness'] === 'complete' && $campaignSignals['rows']->isEmpty() => 'complete',
                $campaignSignals['completeness'] === 'complete' && $accountsAvailable => 'complete',
                default => 'partial',
            },
        ];
        $ruleAvailability = collect($ruleCompleteness)->map(
            fn (string $completeness): bool => $completeness !== 'unavailable',
        )->all();
        $campaignRows = $contactsAvailable ? $campaignSignals['rows'] : collect();
        $accountIds = $quotes->pluck('account_zoho_id')
            ->merge($deals->pluck('account_zoho_id'))
            ->merge($campaignRows->pluck('account_zoho_id'))
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values();
        $explicitContactIds = $quotes->pluck('contact_zoho_id')
            ->merge($deals->pluck('contact_zoho_id'))
            ->merge($campaignRows->pluck('zoho_id'))
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values();

        // Never turn rows from a stale/failed module into positive action evidence.
        // A known channel from the other fresh source remains usable, but incomplete.
        $accounts = $accountsAvailable ? $this->accountRows($scope, $accountIds) : collect();
        $contacts = $contactsAvailable ? $this->contactRows($scope, $explicitContactIds, $accountIds) : collect();
        $contactsByAccount = $contacts->filter(fn (object $contact): bool => filled($contact->account_zoho_id))
            ->groupBy(fn (object $contact): string => (string) $contact->account_zoho_id);
        $quotesByAccount = $quotes->filter(fn (object $quote): bool => filled($quote->account_zoho_id))
            ->groupBy(fn (object $quote): string => (string) $quote->account_zoho_id);
        $dealsByAccount = $deals->filter(fn (object $deal): bool => filled($deal->account_zoho_id))
            ->groupBy(fn (object $deal): string => (string) $deal->account_zoho_id);
        $contactRestrictions = $this->contactRestrictions($contacts);
        $linkedParentIds = $quotes->pluck('zoho_id')->merge($deals->pluck('zoho_id'))->merge($accountIds)
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values();
        $linkedContactIds = $explicitContactIds->merge($contacts->keys())
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values();
        $linkedProvenActivities = ! $moduleAvailable('activities')
            || ($linkedParentIds->isEmpty() && $linkedContactIds->isEmpty())
            ? collect()
            : $this->activities($scope)
                ->where(function (Builder $linked) use ($linkedParentIds, $linkedContactIds): void {
                    if ($linkedParentIds->isNotEmpty()) {
                        $linked->whereIn('parent_zoho_id', $linkedParentIds);
                    }
                    if ($linkedContactIds->isNotEmpty()) {
                        $method = $linkedParentIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $linked->{$method}('contact_zoho_id', $linkedContactIds);
                    }
                })
                ->get(self::ACTIVITY_COLUMNS)
                ->filter(fn (object $activity): bool => $this->isProvenHumanActivity($activity)
                    && $this->activityOccurredBy($activity, $evidenceEndUtc))
                ->values();

        $ownerIds = $quotes->pluck('owner_zoho_id')
            ->merge($deals->pluck('owner_zoho_id'))
            ->merge($accounts->pluck('owner_zoho_id'))
            ->merge($activityCandidates->pluck('owner_zoho_id'))
            ->merge($overdueTasks->pluck('owner_zoho_id'))
            ->merge($campaignRows->pluck('owner_zoho_id'))
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values();
        $owners = $this->ownerLabels($scope, $ownerIds);

        $tasksCreated = $activityCandidates
            ->filter(fn (object $activity): bool => $this->activityType($activity) === 'task'
                && $this->inUtcBounds($activity->zoho_created_at, $periodStartUtc, $evidenceEndUtc))
            ->values();
        $completedTasks = $tasksCreated->filter(fn (object $activity): bool => $this->isCompletedTask($activity))->values();
        $meetings = $eventActivities->filter(fn (object $activity): bool => $this->activityType($activity) === 'meeting')->values();
        $calls = $eventActivities->filter(fn (object $activity): bool => $this->activityType($activity) === 'call')->values();
        $notes = $eventActivities->filter(fn (object $activity): bool => $this->activityType($activity) === 'note')->values();
        $provenActivities = $completedTasks->concat($meetings)->concat($calls)->concat($notes)
            ->unique(fn (object $activity): string => $this->activityType($activity).':'.$activity->zoho_id)
            ->values();
        $notStartedTasks = $tasksCreated->filter(fn (object $activity): bool => $this->isNotStartedTask($activity))->values();

        $queueAll = $this->buildQueue(
            $quotes,
            $deals,
            $campaignRows,
            $linkedProvenActivities,
            $accounts,
            $contacts,
            $contactsByAccount,
            $contactRestrictions,
            $ruleAvailability,
            ['accounts' => $accountsAvailable, 'contacts' => $contactsAvailable],
            $owners,
            $now,
        );
        $totalByPriority = $this->priorityCounts(collect($queueAll)->countBy('priority')->all());
        $totalBySignal = collect(self::PRIORITIES)->mapWithKeys(
            fn (string $signal): array => [$signal => collect($queueAll)->filter(
                fn (array $row): bool => in_array($signal, $row['signals'], true),
            )->count()],
        )->all();
        $queue = $this->priorityDiverseQueue($queueAll);
        $displayedByPriority = $this->priorityCounts(collect($queue)->countBy('priority')->all());

        $ownerMetrics = $this->ownerMetrics(
            $quotes,
            $deals,
            $tasksCreated,
            $eventActivities,
            $overdueTasks,
            $contacts,
            $owners,
        );
        $ownerEvidenceAvailable = $moduleAvailable('quotes') && $moduleAvailable('deals')
            && $moduleAvailable('activities') && $moduleAvailable('contacts');
        $ownerEscalationEvidenceAvailable = $moduleAvailable('quotes') && $moduleAvailable('activities');
        if (! $ownerEvidenceAvailable) {
            $ownerMetrics = [];
        }
        $monthly = $this->monthlyEvidence($scope, $filters, $now);
        $missing = $quotes->filter(fn (object $quote): bool => blank($quote->follow_up_status))->values();
        $decisions = $quotes->filter(
            fn (object $quote): bool => ZohoMarketingAnalytics::quoteOutcome($quote->follow_up_status) !== null,
        )->values();
        $quoteIds = $quotes->pluck('zoho_id')->filter()->map(fn (mixed $id): string => (string) $id)->all();
        $futureQuotes = $moduleAvailable('quotes')
            ? $this->quotes($scope, $filters)->whereDate('quote_date', '>', $today)->count()
            : 0;

        $transportMix = $quotes->flatMap(
            fn (object $quote): array => $this->transportLabels($quote->transport_type),
        )->countBy()->sortDesc()->take(6)->all();
        $laneMix = $quotes
            ->filter(fn (object $quote): bool => filled($quote->origin) && filled($quote->destination))
            ->map(fn (object $quote): string => trim((string) $quote->origin).' → '.trim((string) $quote->destination))
            ->countBy()->sortDesc()->take(6)->all();
        $unreachableQuotes = $missing->filter(function (object $quote) use ($quotesByAccount, $dealsByAccount, $accounts, $contacts, $contactsByAccount, $contactRestrictions, $accountsAvailable, $contactsAvailable): bool {
            $accountId = $this->stringOrNull($quote->account_zoho_id);
            $accountQuotes = $accountId === null ? collect([$quote]) : ($quotesByAccount->get($accountId) ?? collect());
            $accountDeals = $accountId === null ? collect() : ($dealsByAccount->get($accountId) ?? collect());
            $contactIds = $accountQuotes->pluck('contact_zoho_id')
                ->merge($accountDeals->pluck('contact_zoho_id'))
                ->merge($accountId === null ? collect() : ($contactsByAccount->get($accountId)?->pluck('zoho_id') ?? collect()))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->all();

            return $this->contactability(
                $accountId,
                $contactIds,
                $accounts,
                $contacts,
                $contactsByAccount,
                $contactRestrictions,
                ['accounts' => $accountsAvailable, 'contacts' => $contactsAvailable],
            )['state'] === 'unreachable';
        })->count();

        $p3Value = $campaignSignals['available']
            ? $campaignSignals['rows']->pluck('fretiq_contact_id')->filter()->unique()->count()
            : 'Indisponible';
        $ownerEscalations = $ownerEscalationEvidenceAvailable
            ? $missing->pluck('owner_zoho_id')->merge($overdueTasks->pluck('owner_zoho_id'))
                ->map(fn (mixed $ownerId): string => filled($ownerId) ? (string) $ownerId : '__unassigned__')
                ->unique()->count()
            : 0;
        $reachableAccounts = count(array_filter(
            $queueAll,
            fn (array $row): bool => $row['priority'] !== 'enrichment' && $row['channel'] !== 'Indisponible',
        ));
        $priorityRuleSets = [
            'P1' => [$ruleCompleteness['P1']],
            'P2' => [$ruleCompleteness['P2_repeated'], $ruleCompleteness['P2_stale']],
            'P3' => [$ruleCompleteness['P3']],
            'enrichment' => [
                $ruleCompleteness['P1'],
                $ruleCompleteness['P2_repeated'],
                $ruleCompleteness['P2_stale'],
                $ruleCompleteness['P3'],
            ],
        ];
        $priorityCompleteness = collect($priorityRuleSets)->map(
            fn (array $rules): string => $this->combineCompleteness($rules),
        )->all();
        $priorityAvailability = collect($priorityCompleteness)->map(
            fn (string $completeness): bool => $completeness !== 'unavailable',
        )->all();
        $queueCompleteness = $this->combineCompleteness(array_values($ruleCompleteness));
        $queueTotals = $totalByPriority;
        $queueSignalTotals = $totalBySignal;
        $displayedQueueTotals = $displayedByPriority;
        foreach (self::PRIORITIES as $priority) {
            if (! $priorityAvailability[$priority]) {
                $queueTotals[$priority] = 'Indisponible';
                $queueSignalTotals[$priority] = null;
                $displayedQueueTotals[$priority] = 'Indisponible';
            }
        }
        $metrics = [
            'quotes' => $quotes->count(),
            'missing_status' => $missing->count(),
            'tasks_created' => $tasksCreated->count(),
            'not_started_tasks' => $notStartedTasks->count(),
            'completed_tasks' => $completedTasks->count(),
            'meetings' => $meetings->count(),
            'calls' => $calls->count(),
            'notes' => $notes->count(),
            'proven_human_follow_up' => $provenActivities->count(),
        ];
        foreach ($metrics as $key => $value) {
            $sourceModule = in_array($key, ['quotes', 'missing_status'], true) ? 'quotes' : 'activities';
            if (! $moduleAvailable($sourceModule)) {
                $metrics[$key] = 'Indisponible';
            }
        }
        $briefingQueueAvailable = in_array(true, $priorityAvailability, true);
        $briefingAvailable = $briefingQueueAvailable || $ownerEscalationEvidenceAvailable;
        $monthlyAvailability = [
            'quotes' => $moduleAvailable('quotes'),
            'activities' => $moduleAvailable('activities'),
            'deals' => $moduleAvailable('deals'),
        ];
        $monthly = array_map(function (array $row) use ($monthlyAvailability): array {
            foreach (['quotes', 'current_decisions'] as $key) {
                if (! $monthlyAvailability['quotes']) {
                    $row[$key] = 'Indisponible';
                }
            }
            foreach (['tasks_created', 'completed_tasks', 'meetings', 'calls', 'notes'] as $key) {
                if (! $monthlyAvailability['activities']) {
                    $row[$key] = 'Indisponible';
                }
            }
            if (! $monthlyAvailability['deals']) {
                $row['deals'] = 'Indisponible';
            }

            return $row;
        }, $monthly);
        $funnel = [
            'quotes' => $moduleAvailable('quotes') ? $quotes->count() : 'Indisponible',
            'named_contacts' => $moduleAvailable('quotes') && $moduleAvailable('contacts')
                ? $quotes->whereNotNull('contact_zoho_id')->filter(
                    fn (object $quote): bool => $this->isNamedContact($contacts->get((string) $quote->contact_zoho_id)),
                )->count() : 'Indisponible',
            'linked_deals' => $moduleAvailable('quotes')
                ? $quotes->filter(fn (object $quote): bool => filled($quote->deal_zoho_id))->count() : 'Indisponible',
            'current_decisions' => $moduleAvailable('quotes') ? $decisions->count() : 'Indisponible',
            'completed_quote_tasks' => $moduleAvailable('quotes') && $moduleAvailable('activities')
                ? $completedTasks->whereIn('parent_zoho_id', $quoteIds)->count() : 'Indisponible',
        ];
        $quoteRisks = [
            'expired_missing_decision' => $moduleAvailable('quotes') ? $missing->filter(
                fn (object $quote): bool => filled($quote->valid_till)
                    && CarbonImmutable::parse($quote->valid_till, $period->timezone)->startOfDay()->lt($now->startOfDay()),
            )->count() : 'Indisponible',
            'due_7_days' => $moduleAvailable('quotes') ? $missing->filter(
                fn (object $quote): bool => filled($quote->valid_till)
                    && CarbonImmutable::parse($quote->valid_till, $period->timezone)->startOfDay()
                        ->betweenIncluded($now->startOfDay(), $now->startOfDay()->addDays(7)),
            )->count() : 'Indisponible',
            'due_30_days' => $moduleAvailable('quotes') ? $missing->filter(
                fn (object $quote): bool => filled($quote->valid_till)
                    && CarbonImmutable::parse($quote->valid_till, $period->timezone)->startOfDay()
                        ->betweenIncluded($now->startOfDay(), $now->startOfDay()->addDays(30)),
            )->count() : 'Indisponible',
            'unreachable' => $moduleAvailable('quotes') && $moduleAvailable('accounts') && $moduleAvailable('contacts')
                ? $unreachableQuotes : 'Indisponible',
            'future_date_anomalies' => $moduleAvailable('quotes') ? $futureQuotes : 'Indisponible',
        ];
        $confidence = [
            'briefing' => $briefingAvailable ? 'Partiel' : 'Indisponible',
            'metrics' => collect($metrics)->map(fn (mixed $value): string => is_numeric($value) ? 'Partiel' : 'Indisponible')->all(),
            'decision_cards' => collect($priorityAvailability)->map(fn (bool $available): string => $available ? 'Partiel' : 'Indisponible')->all(),
            'panels' => [
                'monthly' => in_array(true, $monthlyAvailability, true) ? 'Partiel' : 'Indisponible',
                'funnel' => collect($funnel)->contains(fn (mixed $value): bool => is_numeric($value)) ? 'Partiel' : 'Indisponible',
                'quote_risks' => collect($quoteRisks)->contains(fn (mixed $value): bool => is_numeric($value)) ? 'Partiel' : 'Indisponible',
                'mix' => $moduleAvailable('quotes') ? 'Partiel' : 'Indisponible',
                'readiness' => 'Partiel',
                'owners' => $ownerEvidenceAvailable ? 'Partiel' : 'Indisponible',
            ],
        ];

        return [
            'period' => $period->toArray(),
            'filters' => $filters,
            'scope' => $scope->metadata(),
            'freshness' => $freshness,
            'metrics' => $metrics,
            'briefing' => [
                'reachable_accounts' => $briefingQueueAvailable ? $reachableAccounts : 'Indisponible',
                'highest_deadline' => $briefingQueueAvailable ? ($queue[0]['deadline'] ?? 'Aucune échéance') : 'Indisponible',
                'owner_escalations' => $ownerEscalationEvidenceAvailable ? $ownerEscalations : 'Indisponible',
                'completeness' => [
                    'reachable_accounts' => $queueCompleteness,
                    'highest_deadline' => $queueCompleteness,
                    'owner_escalations' => $ownerEscalationEvidenceAvailable ? 'complete' : 'unavailable',
                ],
            ],
            'decision_cards' => [
                'P1' => $this->card($priorityAvailability['P1'] ? $totalBySignal['P1'] : 'Indisponible', 'Décision urgente', 'Comptes dédupliqués avec devis expiré ou à échéance sous 7 jours et canal autorisé.', $confidence['decision_cards']['P1']),
                'P2' => $this->card($priorityAvailability['P2'] ? $totalBySignal['P2'] : 'Indisponible', 'Progression à obtenir', 'Comptes dédupliqués avec devis répétés sans progrès ou deal joignable inactif.'.$this->partialSubsetNote($priorityCompleteness['P2']), $confidence['decision_cards']['P2']),
                'P3' => $this->card($priorityAvailability['P3'] ? $p3Value : 'Indisponible', 'Signal campagne doux', $campaignSignals['note'].' La carte compte les contacts distincts ; la file reste dédupliquée par compte.', $confidence['decision_cards']['P3']),
                'enrichment' => $this->card($priorityAvailability['enrichment'] ? $totalByPriority['enrichment'] : 'Indisponible', 'Canal à enrichir', 'Urgence ou dossier sans téléphone ni email autorisé.'.$this->partialSubsetNote($priorityCompleteness['enrichment']), $confidence['decision_cards']['enrichment']),
            ],
            'queue' => [
                'items' => $queue,
                'truncated' => count($queueAll) > count($queue),
                'total_by_priority' => $queueTotals,
                'total_by_signal' => $queueSignalTotals,
                'displayed_by_priority' => $displayedQueueTotals,
                'availability_by_priority' => $priorityAvailability,
                'completeness_by_priority' => $priorityCompleteness,
            ],
            'owners' => $ownerMetrics,
            'monthly' => $monthly,
            'monthly_meta' => [
                'current_month' => $now->format('Y-m'),
                'as_of' => $now->toDateString(),
                'completion_basis' => 'current_status_of_tasks_created_in_month',
            ],
            'funnel' => $funnel,
            'quote_risks' => $quoteRisks,
            'transport_mix' => $moduleAvailable('quotes') ? $transportMix : [],
            'lane_mix' => $moduleAvailable('quotes') ? $laneMix : [],
            'confidence' => $confidence,
            'readiness' => [
                'quote_timestamp' => 'Partiel — quote_date est une date métier proxy, pas le timestamp de création Zoho du devis.',
                'status_history' => 'Partiel — les décisions mensuelles utilisent l’état actuel gagné/perdu du cohort.',
                'identity_links' => $campaignSignals['note'],
                'missing_status' => $moduleAvailable('quotes') ? $missing->count().' devis du périmètre sans décision enregistrée.' : 'Indisponible — module devis non synchronisé.',
                'future_dates' => $moduleAvailable('quotes') ? $futureQuotes.' devis daté(s) dans le futur, exclus des volumes de période.' : 'Indisponible — module devis non synchronisé.',
                'filters' => $this->filterReadiness($filters),
                'forecast' => 'Indisponible — aucun forecast, revenu attribué ou taux de gain n’est calculé.',
            ],
            'meta' => [
                'quote_period_basis' => 'quote_date',
                'task_creation_basis' => 'zoho_created_at',
                'task_completion_basis' => 'current_status_of_tasks_created_in_period; completion_timestamp_unavailable',
                'proven_human_follow_up' => 'completed_task_plus_meeting_call_note',
                'read_only' => true,
                'causal_attribution' => false,
                'filter_applicability' => $this->filterApplicability($filters),
                'filter_scope' => $this->filterScope($filters),
                'soft_campaign_signal' => $campaignSignals['available'] ? 'Partiel' : 'Indisponible',
            ],
        ];
    }

    private function mirrorAvailable(): bool
    {
        $required = [
            'zoho_quotes' => [...self::QUOTE_COLUMNS, 'zoho_deleted_at'],
            'zoho_activities' => [...self::ACTIVITY_COLUMNS, 'zoho_deleted_at'],
            'zoho_deals' => [...self::DEAL_COLUMNS, 'zoho_deleted_at'],
            'zoho_accounts' => ['zoho_id', 'owner_zoho_id', 'name', 'phone', 'last_synced_at', 'zoho_deleted_at'],
            'zoho_contacts' => [
                'zoho_id', 'owner_zoho_id', 'fretiq_contact_id', 'account_zoho_id',
                'first_name', 'last_name', 'full_name', 'email', 'phone', 'mobile',
                'email_opt_out', 'unsubscribed_at',
                'last_synced_at', 'zoho_deleted_at',
            ],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,scalar|null> $filters */
    private function quotes(MarketingScope $scope, array $filters): Builder
    {
        $query = $this->scoped('zoho_quotes', $scope);
        if (isset($filters['country'])) {
            $query->where('country', $filters['country']);
        }
        if (isset($filters['currency'])) {
            $query->where('currency_code', $filters['currency']);
        }
        if (isset($filters['transport'])) {
            $query->whereJsonContains('transport_type', $filters['transport']);
        }

        return $query;
    }

    /** @param array<string,scalar|null> $filters */
    private function deals(MarketingScope $scope, array $filters): Builder
    {
        $query = $this->scoped('zoho_deals', $scope);
        if (isset($filters['currency'])) {
            $query->where('currency_code', $filters['currency']);
        }

        return $query;
    }

    private function activities(MarketingScope $scope): Builder
    {
        return $this->scoped('zoho_activities', $scope);
    }

    private function scoped(string $table, MarketingScope $scope): Builder
    {
        $query = DB::table($table)->whereNull('zoho_deleted_at');
        if ($scope->ownerIds !== []) {
            $query->whereIn('owner_zoho_id', $scope->ownerIds);
        }

        return $query;
    }

    private function activitiesInBounds(Builder $query, CarbonImmutable $startUtc, CarbonImmutable $endUtc): Builder
    {
        return $query->where(function (Builder $activity) use ($startUtc, $endUtc): void {
            $activity->whereBetween('activity_at', [$startUtc, $endUtc])
                ->orWhereBetween('start_at', [$startUtc, $endUtc])
                ->orWhereBetween('zoho_created_at', [$startUtc, $endUtc]);
        });
    }

    /** @param Collection<int,string> $accountIds @return Collection<string,object> */
    private function accountRows(MarketingScope $scope, Collection $accountIds): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('zoho_accounts')
            ->whereNull('zoho_deleted_at')
            ->whereIn('zoho_id', $accountIds)
            ->when($scope->ownerIds !== [], fn (Builder $builder) => $builder->whereIn('owner_zoho_id', $scope->ownerIds));

        return $query->get(['zoho_id', 'owner_zoho_id', 'name', 'phone', 'last_synced_at'])->keyBy('zoho_id');
    }

    /**
     * Load only contacts attached to an already authorized account or explicitly linked
     * record, and keep the owner boundary for commercial users.
     *
     * @param Collection<int,string> $contactIds
     * @param Collection<int,string> $accountIds
     * @return Collection<string,object>
     */
    private function contactRows(MarketingScope $scope, Collection $contactIds, Collection $accountIds): Collection
    {
        if ($contactIds->isEmpty() && $accountIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('zoho_contacts')->whereNull('zoho_deleted_at');
        if ($contactIds->isNotEmpty() && $accountIds->isNotEmpty()) {
            $query->where(function (Builder $linked) use ($contactIds, $accountIds): void {
                $linked->whereIn('zoho_id', $contactIds)->orWhereIn('account_zoho_id', $accountIds);
            });
        } elseif ($contactIds->isNotEmpty()) {
            $query->whereIn('zoho_id', $contactIds);
        } else {
            $query->whereIn('account_zoho_id', $accountIds);
        }
        if ($scope->ownerIds !== []) {
            $query->whereIn('owner_zoho_id', $scope->ownerIds);
        }

        return $query->get([
            'zoho_id', 'owner_zoho_id', 'fretiq_contact_id', 'account_zoho_id',
            'first_name', 'last_name', 'full_name', 'email', 'phone', 'mobile',
            'email_opt_out', 'unsubscribed_at', 'last_synced_at',
        ])->keyBy('zoho_id');
    }

    /**
     * Build one conservative authorization map for every email recommendation.
     * Phone/mobile remains usable when email is suppressed or unsubscribed.
     *
     * @param Collection<string,object> $contacts
     * @return array{email_evidence_available:bool,suppressed_emails:array<string,true>,unsubscribed_local_ids:array<string,true>}
     */
    private function contactRestrictions(Collection $contacts): array
    {
        $emailEvidenceAvailable = Schema::hasTable('suppressions')
            && Schema::hasColumns('suppressions', ['email'])
            && Schema::hasTable('campaign_recipients')
            && Schema::hasColumns('campaign_recipients', ['contact_id', 'status']);
        if (! $emailEvidenceAvailable) {
            return [
                'email_evidence_available' => false,
                'suppressed_emails' => [],
                'unsubscribed_local_ids' => [],
            ];
        }

        $emails = $contacts->pluck('email')->filter()->map(
            fn (mixed $email): string => mb_strtolower(trim((string) $email)),
        )->unique()->values();
        $suppressed = $emails->isEmpty()
            ? collect()
            : DB::table('suppressions')->whereIn('email', $emails)->pluck('email')->map(
                fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            );
        $localIds = $contacts->pluck('fretiq_contact_id')->filter()->unique()->values();
        $unsubscribed = $localIds->isEmpty()
            ? collect()
            : DB::table('campaign_recipients')->whereIn('contact_id', $localIds)
                ->whereRaw('LOWER(status) = ?', ['unsubscribed'])->pluck('contact_id')
                ->map(fn (mixed $id): string => (string) $id);

        return [
            'email_evidence_available' => true,
            'suppressed_emails' => $suppressed->mapWithKeys(fn (string $email): array => [$email => true])->all(),
            'unsubscribed_local_ids' => $unsubscribed->mapWithKeys(fn (string $id): array => [$id => true])->all(),
        ];
    }

    /**
     * @param Collection<int,string> $ownerIds
     * @return array<string,string>
     */
    private function ownerLabels(MarketingScope $scope, Collection $ownerIds): array
    {
        if ($ownerIds->isEmpty() || ! Schema::hasTable('zoho_users')
            || ! Schema::hasColumns('zoho_users', ['zoho_id', 'full_name', 'zoho_deleted_at'])) {
            return [];
        }

        $query = DB::table('zoho_users')->whereNull('zoho_deleted_at')->whereIn('zoho_id', $ownerIds);
        if ($scope->ownerIds !== []) {
            $query->whereIn('zoho_id', $scope->ownerIds);
        }

        return $query->get(['zoho_id', 'full_name'])
            ->mapWithKeys(fn (object $owner): array => [(string) $owner->zoho_id => filled($owner->full_name)
                ? (string) $owner->full_name
                : 'Propriétaire Zoho non identifié'])
            ->all();
    }

    /**
     * @param array<string,scalar|null> $filters
     * @return array{available:bool,completeness:string,rows:Collection<int,object>,note:string,ambiguous_count:int,missing_count:int,candidate_count:int}
     */
    private function campaignSignals(MarketingScope $scope, array $filters, MarketingPeriod $period): array
    {
        $required = [
            'campaign_recipients' => ['contact_id', 'campaign_run_id', 'status', 'opened_at', 'replied_at'],
            'campaign_runs' => ['id', 'campaign_id'],
            'contacts' => ['id', 'assigned_to', 'deleted_at'],
            'zoho_contacts' => [
                'zoho_id', 'owner_zoho_id', 'fretiq_contact_id', 'account_zoho_id', 'country',
                'first_name', 'last_name', 'full_name', 'email', 'phone', 'mobile',
                'email_opt_out', 'unsubscribed_at',
                'last_synced_at', 'zoho_deleted_at',
            ],
        ];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
                return [
                    'available' => false,
                    'completeness' => 'unavailable',
                    'rows' => collect(),
                    'note' => 'Indisponible — les tables ou champs nécessaires au rapprochement campagne/CRM sont absents.',
                    'ambiguous_count' => 0,
                    'missing_count' => 0,
                    'candidate_count' => 0,
                ];
            }
        }

        $identityBase = DB::table('campaign_recipients as recipient')
            ->join('campaign_runs as run', 'run.id', '=', 'recipient.campaign_run_id')
            ->join('contacts as local_contact', 'local_contact.id', '=', 'recipient.contact_id')
            ->whereNull('local_contact.deleted_at');
        if ($scope->commercialUserId !== null) {
            $identityBase->where('local_contact.assigned_to', $scope->commercialUserId);
        }
        if (isset($filters['campaign'])) {
            $identityBase->where('run.campaign_id', $filters['campaign']);
        }

        [$startUtc, $endUtc] = $period->databaseBounds();
        $nowUtc = CarbonImmutable::now($period->timezone)->utc();
        $signalEndUtc = $endUtc->lt($nowUtc) ? $endUtc : $nowUtc;
        $signalScope = function (Builder $query) use ($startUtc, $signalEndUtc): void {
            $query->whereNotNull('recipient.opened_at')
                ->whereBetween('recipient.opened_at', [$startUtc, $signalEndUtc])
                ->whereNull('recipient.replied_at')
                ->where(function (Builder $status): void {
                    $status->whereNull('recipient.status')
                        ->orWhereRaw('LOWER(recipient.status) NOT IN (?, ?)', ['replied', 'unsubscribed']);
                });
        };
        $candidateLocalIdsQuery = clone $identityBase;
        $signalScope($candidateLocalIdsQuery);
        $candidateLocalIds = $candidateLocalIdsQuery->distinct()->pluck('recipient.contact_id')
            ->filter()->map(fn (mixed $id): string => (string) $id)->values();
        if ($candidateLocalIds->isEmpty()) {
            return [
                'available' => true,
                'completeness' => 'complete',
                'rows' => collect(),
                'note' => 'Aucun signal d’ouverture sans réponse sur la période dans ce périmètre.',
                'ambiguous_count' => 0,
                'missing_count' => 0,
                'candidate_count' => 0,
            ];
        }

        $mappingCounts = DB::table('zoho_contacts')
            ->whereNull('zoho_deleted_at')
            ->whereIn('fretiq_contact_id', $candidateLocalIds)
            ->get(['fretiq_contact_id', 'zoho_id'])
            ->groupBy(fn (object $row): string => (string) $row->fretiq_contact_id)
            ->map(fn (Collection $mappings): int => $mappings->pluck('zoho_id')->filter()->unique()->count());
        $missingLocalIds = $candidateLocalIds->filter(
            fn (string $localId): bool => (int) $mappingCounts->get($localId, 0) === 0,
        )->unique()->values();
        $ambiguousLocalIds = $candidateLocalIds->filter(
            fn (string $localId): bool => (int) $mappingCounts->get($localId, 0) > 1,
        )->unique()->values();
        $excludedLocalIds = $missingLocalIds->merge($ambiguousLocalIds)->unique()->values();

        $authorizedBase = (clone $identityBase)
            ->join('zoho_contacts as zoho_contact', 'zoho_contact.fretiq_contact_id', '=', 'local_contact.id')
            ->whereNull('zoho_contact.zoho_deleted_at')
            ->whereNotNull('zoho_contact.fretiq_contact_id');
        if ($scope->ownerIds !== []) {
            $authorizedBase->whereIn('zoho_contact.owner_zoho_id', $scope->ownerIds);
        }
        if (isset($filters['country'])) {
            $authorizedBase->where('zoho_contact.country', $filters['country']);
        }

        $rawRowsQuery = clone $authorizedBase;
        $signalScope($rawRowsQuery);
        $rawRows = $rawRowsQuery
            ->whereNotNull('recipient.opened_at')
            ->get([
                'zoho_contact.zoho_id',
                'recipient.contact_id as fretiq_contact_id',
                'zoho_contact.owner_zoho_id',
                'zoho_contact.account_zoho_id',
                'zoho_contact.first_name',
                'zoho_contact.last_name',
                'zoho_contact.full_name',
                'zoho_contact.email',
                'zoho_contact.phone',
                'zoho_contact.mobile',
                'zoho_contact.email_opt_out',
                'zoho_contact.unsubscribed_at',
                'zoho_contact.last_synced_at',
                'recipient.opened_at',
            ]);
        $rows = $rawRows
            ->reject(fn (object $row): bool => $excludedLocalIds->contains((string) $row->fretiq_contact_id))
            ->sortByDesc('opened_at')
            ->unique('fretiq_contact_id')
            ->values();
        $identityNotes = [];
        if ($missingLocalIds->isNotEmpty()) {
            $identityNotes[] = $missingLocalIds->count().' lien(s) local/CRM absent(s) ont été exclus';
        }
        if ($ambiguousLocalIds->isNotEmpty()) {
            $identityNotes[] = $ambiguousLocalIds->count().' lien(s) local/CRM ambigu(s) ont été exclus';
        }
        $identityNote = $identityNotes === [] ? '' : ' '.implode(' ; ', $identityNotes).'.';

        return [
            'available' => true,
            'completeness' => $excludedLocalIds->isEmpty() ? 'complete' : 'partial',
            'rows' => $rows,
            'note' => 'Ouverture sans réponse observée via un lien contact déterministe ; signal doux, jamais un lead chaud.'.$identityNote,
            'ambiguous_count' => $ambiguousLocalIds->count(),
            'missing_count' => $missingLocalIds->count(),
            'candidate_count' => $candidateLocalIds->count(),
        ];
    }

    /**
     * @param Collection<int,object> $quotes
     * @param Collection<int,object> $deals
     * @param Collection<int,object> $campaignSignals
     * @param Collection<int,object> $provenActivities
     * @param Collection<string,object> $accounts
     * @param Collection<string,object> $contacts
     * @param Collection<string,Collection<int,object>> $contactsByAccount
     * @param array{email_evidence_available:bool,suppressed_emails:array<string,true>,unsubscribed_local_ids:array<string,true>} $contactRestrictions
     * @param array{P1:bool,P2_repeated:bool,P2_stale:bool,P3:bool} $ruleAvailability
     * @param array{accounts:bool,contacts:bool} $channelAvailability
     * @param array<string,string> $owners
     * @return list<array<string,mixed>>
     */
    private function buildQueue(
        Collection $quotes,
        Collection $deals,
        Collection $campaignSignals,
        Collection $provenActivities,
        Collection $accounts,
        Collection $contacts,
        Collection $contactsByAccount,
        array $contactRestrictions,
        array $ruleAvailability,
        array $channelAvailability,
        array $owners,
        CarbonImmutable $now,
    ): array {
        $rows = [];
        $quotesByAccount = $quotes->groupBy(fn (object $quote): string => $this->recordKey('quote', $quote));
        $dealsByAccount = $deals->groupBy(fn (object $deal): string => $this->recordKey('deal', $deal));
        $ownerRecordsByKey = $quotes->map(fn (object $quote): array => [
            'key' => $this->recordKey('quote', $quote),
            'record' => $quote,
        ])->concat($deals->map(fn (object $deal): array => [
            'key' => $this->recordKey('deal', $deal),
            'record' => $deal,
        ]))->concat($campaignSignals->map(fn (object $signal): array => [
            'key' => filled($signal->account_zoho_id)
                ? 'account:'.(string) $signal->account_zoho_id
                : 'contact:'.(string) $signal->zoho_id,
            'record' => $signal,
        ]))->groupBy('key')->map(
            fn (Collection $entries): Collection => $entries->pluck('record')->values(),
        );
        $provenByParent = $provenActivities->filter(fn (object $activity): bool => filled($activity->parent_zoho_id))
            ->groupBy(fn (object $activity): string => (string) $activity->parent_zoho_id);
        $provenByContact = $provenActivities->filter(fn (object $activity): bool => filled($activity->contact_zoho_id))
            ->groupBy(fn (object $activity): string => (string) $activity->contact_zoho_id);

        foreach ($quotesByAccount as $key => $accountQuotes) {
            $representativeQuote = $accountQuotes->sortBy(
                fn (object $quote): string => (string) $quote->zoho_id,
            )->first();
            $accountId = $this->stringOrNull($representativeQuote->account_zoho_id);
            $accountDeals = $accountId === null
                ? collect()
                : ($dealsByAccount->get((string) $key) ?? collect());
            $ownerResolution = $this->ownerResolution(
                $accountId,
                $accounts,
                $ownerRecordsByKey->get((string) $key, collect()),
            );
            $contactIds = $accountQuotes->pluck('contact_zoho_id')->merge($accountDeals->pluck('contact_zoho_id'))
                ->merge($accountId === null ? collect() : ($contactsByAccount->get($accountId)?->pluck('zoho_id') ?? collect()))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->all();
            $parentIds = $accountQuotes->pluck('zoho_id')->merge($accountDeals->pluck('zoho_id'))
                ->when($accountId !== null, fn (Collection $ids): Collection => $ids->push($accountId))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->all();
            $accountProvenActivities = $this->relatedActivities($provenByParent, $provenByContact, $parentIds, $contactIds);
            $contactability = $this->contactability(
                $accountId,
                $contactIds,
                $accounts,
                $contacts,
                $contactsByAccount,
                $contactRestrictions,
                $channelAvailability,
            );
            if ($contactability['state'] === 'unknown') {
                continue;
            }

            $urgent = $ruleAvailability['P1'] ? $accountQuotes->filter(function (object $quote) use ($now): bool {
                if (filled($quote->follow_up_status) || blank($quote->valid_till)) {
                    return false;
                }

                return CarbonImmutable::parse($quote->valid_till, $now->timezone)->startOfDay()
                    ->lte($now->startOfDay()->addDays(7));
            }) : collect();
            foreach ($urgent as $quote) {
                $validTill = CarbonImmutable::parse($quote->valid_till, $now->timezone)->startOfDay();
                $reason = $validTill->lt($now->startOfDay())
                    ? 'Devis expiré sans décision'
                    : 'Devis arrivant à échéance sans décision renseignée';
                $priority = $contactability['reachable'] ? 'P1' : 'enrichment';
                $action = $contactability['reachable']
                    ? $this->contactAction($contactability['kind'], 'obtenir une décision et une prochaine étape datée')
                    : 'Compléter un téléphone ou un email autorisé avant toute relance.';
                if (! $contactability['reachable']) {
                    $reason .= ' · Aucun canal autorisé observé';
                }
                $this->appendQueueReason($rows, (string) $key, $this->queueRow(
                    priority: $priority,
                    signals: [$priority],
                    stableKey: 'quote:'.(string) $quote->zoho_id,
                    account: $this->accountLabel($accountId, $accounts, $quote->subject),
                    reason: $reason,
                    quoteContext: $this->quoteContext($quote),
                    ownerId: $ownerResolution['id'],
                    deadline: $validTill->toDateString(),
                    action: $action,
                    contactability: $contactability,
                    lastAction: $this->lastProvenAction($accountProvenActivities, $now->timezone),
                    owners: $owners,
                    mixedOwner: $ownerResolution['mixed'],
                    resolvedOwnerIds: $ownerResolution['ids'],
                ));
            }

            $hasDecision = $accountQuotes->contains(
                fn (object $quote): bool => ZohoMarketingAnalytics::quoteOutcome($quote->follow_up_status) !== null,
            );
            $hasLinkedDeal = $accountDeals->isNotEmpty()
                || $accountQuotes->contains(fn (object $quote): bool => filled($quote->deal_zoho_id));
            $hasProvenFollowUp = $accountProvenActivities->isNotEmpty();
            if ($ruleAvailability['P2_repeated'] && $accountQuotes->count() >= 2
                && ! $hasDecision && ! $hasLinkedDeal && ! $hasProvenFollowUp) {
                $priority = $contactability['reachable'] ? 'P2' : 'enrichment';
                $reason = 'Au moins deux devis sur la période, sans deal, décision ni suivi humain prouvé';
                if (! $contactability['reachable']) {
                    $reason .= ' · Aucun canal autorisé observé';
                }
                $this->appendQueueReason($rows, (string) $key, $this->queueRow(
                    priority: $priority,
                    signals: [$priority],
                    stableKey: 'account:'.(string) $key,
                    account: $this->accountLabel($accountId, $accounts, $representativeQuote->subject),
                    reason: $reason,
                    quoteContext: $accountQuotes->count().' devis sur la période',
                    ownerId: $ownerResolution['id'],
                    deadline: 'Cette semaine',
                    action: $contactability['reachable']
                        ? $this->contactAction($contactability['kind'], 'requalifier le besoin et consigner la prochaine action')
                        : 'Compléter un téléphone ou un email autorisé avant toute relance.',
                    contactability: $contactability,
                    lastAction: $this->lastProvenAction($accountProvenActivities, $now->timezone),
                    owners: $owners,
                    mixedOwner: $ownerResolution['mixed'],
                    resolvedOwnerIds: $ownerResolution['ids'],
                ));
            }
        }

        foreach ($deals as $deal) {
            if (! $ruleAvailability['P2_stale'] || ZohoMarketingAnalytics::dealOutcome($deal->stage) !== null) {
                continue;
            }

            $key = $this->recordKey('deal', $deal);
            $accountId = $this->stringOrNull($deal->account_zoho_id);
            $accountQuotes = $accountId === null ? collect() : ($quotesByAccount->get($key) ?? collect());
            $accountDeals = $accountId === null ? collect([$deal]) : ($dealsByAccount->get($key) ?? collect([$deal]));
            $ownerResolution = $this->ownerResolution(
                $accountId,
                $accounts,
                $ownerRecordsByKey->get($key, collect()),
            );
            $contactIds = $accountQuotes->pluck('contact_zoho_id')->merge($accountDeals->pluck('contact_zoho_id'))
                ->merge($accountId === null ? collect() : ($contactsByAccount->get($accountId)?->pluck('zoho_id') ?? collect()))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->all();
            $parentIds = $accountQuotes->pluck('zoho_id')->merge($accountDeals->pluck('zoho_id'))
                ->when($accountId !== null, fn (Collection $ids): Collection => $ids->push($accountId))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->all();
            $linkedActivities = $this->relatedActivities($provenByParent, $provenByContact, $parentIds, $contactIds);
            if ($linkedActivities->contains(fn (object $activity): bool => $this->isCompletedTask($activity))) {
                // A completion is proven, but the mirror has no completion timestamp.
                // Even an older dated event cannot prove that this completion is stale.
                continue;
            }
            $datedHumanActivities = $linkedActivities
                ->reject(fn (object $activity): bool => $this->activityType($activity) === 'task')
                ->filter(fn (object $activity): bool => filled($this->activityAnchor($activity)))
                ->values();
            if ($datedHumanActivities->isNotEmpty()) {
                $anchor = $datedHumanActivities->map(
                    fn (object $activity): CarbonImmutable => CarbonImmutable::parse($this->activityAnchor($activity), 'UTC'),
                )->sort()->last();
                $reason = 'Deal ouvert sans suivi humain prouvé récent depuis plus de 30 jours';
            } else {
                $anchor = $this->dealRecordAnchor($deal);
                $reason = 'Deal ouvert âgé de plus de 30 jours sans suivi humain prouvé';
            }
            if ($anchor === null || ! $anchor->lt($now->utc()->subDays(30))) {
                continue;
            }

            $contactability = $this->contactability(
                $accountId,
                $contactIds,
                $accounts,
                $contacts,
                $contactsByAccount,
                $contactRestrictions,
                $channelAvailability,
            );
            if ($contactability['state'] === 'unknown') {
                continue;
            }
            $priority = $contactability['reachable'] ? 'P2' : 'enrichment';
            if (! $contactability['reachable']) {
                $reason .= ' · Aucun canal autorisé observé';
            }

            $this->appendQueueReason($rows, $key, $this->queueRow(
                priority: $priority,
                signals: [$priority],
                stableKey: 'deal:'.(string) $deal->zoho_id,
                account: $this->accountLabel($accountId, $accounts, $deal->name),
                reason: $reason,
                quoteContext: 'Deal ouvert · '.(filled($deal->stage) ? (string) $deal->stage : 'étape indisponible'),
                ownerId: $ownerResolution['id'],
                deadline: 'Cette semaine',
                action: $contactability['reachable']
                    ? $this->contactAction($contactability['kind'], 'requalifier ou clore le deal avec un motif')
                    : 'Compléter un téléphone ou un email autorisé avant toute relance.',
                contactability: $contactability,
                lastAction: $this->lastProvenAction($linkedActivities, $now->timezone),
                owners: $owners,
                mixedOwner: $ownerResolution['mixed'],
                resolvedOwnerIds: $ownerResolution['ids'],
            ));
        }

        $actionableCampaignSignals = $ruleAvailability['P3'] ? $campaignSignals : collect();
        foreach ($actionableCampaignSignals->groupBy(fn (object $contact): string => filled($contact->account_zoho_id)
            ? 'account:'.(string) $contact->account_zoho_id
            : 'contact:'.(string) $contact->zoho_id) as $key => $signals) {
            $signal = $signals->sort(function (object $left, object $right): int {
                return strcmp((string) $right->opened_at, (string) $left->opened_at)
                    ?: strcmp((string) $left->fretiq_contact_id, (string) $right->fretiq_contact_id)
                    ?: strcmp((string) $left->zoho_id, (string) $right->zoho_id);
            })->first();
            $accountId = $this->stringOrNull($signal->account_zoho_id);
            $ownerResolution = $this->ownerResolution(
                $accountId,
                $accounts,
                $ownerRecordsByKey->get((string) $key, collect()),
            );
            $contactIds = $signals->pluck('zoho_id')->map(fn (mixed $id): string => (string) $id)->unique()->all();
            $contactability = $this->contactability(
                $accountId,
                $contactIds,
                $accounts,
                $contacts,
                $contactsByAccount,
                $contactRestrictions,
                $channelAvailability,
            );
            $openedAt = CarbonImmutable::parse($signals->max('opened_at'), 'UTC')->setTimezone($now->timezone);

            $channelUnknown = $contactability['state'] === 'unknown';
            $priority = $channelUnknown || $contactability['reachable'] ? 'P3' : 'enrichment';
            $reason = 'Message de campagne ouvert sans réponse enregistrée · signal doux, pas un lead chaud';
            if ($channelUnknown) {
                $reason .= ' · joignabilité du compte non évaluée';
            } elseif (! $contactability['reachable']) {
                $reason .= ' · Aucun canal autorisé observé';
            }
            $this->appendQueueReason($rows, (string) $key, $this->queueRow(
                priority: $priority,
                signals: array_values(array_unique(['P3', $priority])),
                stableKey: 'campaign:'.(string) $signal->fretiq_contact_id,
                account: $this->accountLabel($accountId, $accounts, null),
                reason: $reason,
                quoteContext: 'Ouverture observée le '.$openedAt->format('d/m/Y H:i'),
                ownerId: $ownerResolution['id'],
                deadline: 'Sous 48 h',
                action: $channelUnknown
                    ? 'Attendre ou rétablir la synchronisation du compte avant toute prise de contact.'
                    : ($contactability['reachable']
                        ? $this->contactAction($contactability['kind'], 'faire une relance courte et contextuelle')
                        : 'Vérifier un canal autorisé avant toute prise de contact.'),
                contactability: $contactability,
                lastAction: 'Indisponible',
                owners: $owners,
                mixedOwner: $ownerResolution['mixed'],
                resolvedOwnerIds: $ownerResolution['ids'],
            ));
        }

        $rows = array_values($rows);
        usort($rows, fn (array $left, array $right): int => self::PRIORITY_RANK[$left['priority']]
            <=> self::PRIORITY_RANK[$right['priority']]
            ?: strcmp((string) $left['deadline'], (string) $right['deadline'])
            ?: strcmp((string) $left['_stable_key'], (string) $right['_stable_key']));

        $rows = array_map(function (array $row): array {
            unset($row['_stable_key'], $row['_owner_ids']);

            return $row;
        }, $rows);

        return $rows;
    }

    /**
     * @param Collection<string,object> $accounts
     * @param Collection<string,object> $contacts
     * @param Collection<string,Collection<int,object>> $contactsByAccount
     * @param list<string> $explicitContactIds
     * @param array{email_evidence_available:bool,suppressed_emails:array<string,true>,unsubscribed_local_ids:array<string,true>} $restrictions
     * @param array{accounts:bool,contacts:bool} $channelAvailability
     * @return array{state:string,reachable:bool,kind:?string,channel:string,contact:string}
     */
    private function contactability(
        ?string $accountId,
        array $explicitContactIds,
        Collection $accounts,
        Collection $contacts,
        Collection $contactsByAccount,
        array $restrictions,
        array $channelAvailability,
    ): array {
        $explicit = $contacts->only($explicitContactIds)->values();
        $accountContacts = $accountId === null
            ? collect()
            : ($contactsByAccount->get($accountId) ?? collect());
        $candidates = $explicit->concat($accountContacts)->unique('zoho_id')->values();
        $namedContacts = $candidates->filter(fn (object $contact): bool => $this->isNamedContact($contact))->values();
        $named = $namedContacts->first();

        foreach ($namedContacts as $contact) {
            if (filled($contact->phone) || filled($contact->mobile)) {
                return ['state' => 'reachable', 'reachable' => true, 'kind' => 'phone', 'channel' => 'Téléphone contact masqué', 'contact' => 'Contact nommé · identité masquée'];
            }
        }
        foreach ($namedContacts as $contact) {
            if ($this->emailAllowed($contact, $restrictions)) {
                return ['state' => 'reachable', 'reachable' => true, 'kind' => 'email', 'channel' => 'Email contact masqué', 'contact' => 'Contact nommé · identité masquée'];
            }
        }
        $account = $accountId === null ? null : $accounts->get($accountId);
        if ($account !== null && filled($account->phone)) {
            return [
                'state' => 'reachable',
                'reachable' => true,
                'kind' => 'phone',
                'channel' => 'Téléphone compte masqué',
                'contact' => $named === null ? 'Contact non nommé' : 'Contact nommé · identité masquée',
            ];
        }

        $fullyEvaluated = $channelAvailability['accounts'] && $channelAvailability['contacts'];

        return [
            'state' => $fullyEvaluated ? 'unreachable' : 'unknown',
            'reachable' => false,
            'kind' => null,
            'channel' => 'Indisponible',
            'contact' => ! $channelAvailability['contacts']
                ? 'Identité contact non évaluée'
                : ($named === null ? 'Contact non nommé' : 'Contact nommé · identité masquée'),
        ];
    }

    /**
     * @param array{email_evidence_available:bool,suppressed_emails:array<string,true>,unsubscribed_local_ids:array<string,true>} $restrictions
     */
    private function emailAllowed(object $contact, array $restrictions): bool
    {
        if (! $restrictions['email_evidence_available'] || blank($contact->email)
            || (bool) $contact->email_opt_out || filled($contact->unsubscribed_at)) {
            return false;
        }

        $email = mb_strtolower(trim((string) $contact->email));
        $localId = $this->stringOrNull($contact->fretiq_contact_id);

        return ! isset($restrictions['suppressed_emails'][$email])
            && ($localId === null || ! isset($restrictions['unsubscribed_local_ids'][$localId]));
    }

    private function isNamedContact(?object $contact): bool
    {
        return $contact !== null && filled(trim(implode(' ', array_filter([
            $contact->full_name ?? null,
            $contact->first_name ?? null,
            $contact->last_name ?? null,
        ], fn (mixed $part): bool => filled($part)))));
    }

    /** @return array<string,mixed> */
    private function queueRow(
        string $priority,
        array $signals,
        string $stableKey,
        string $account,
        string $reason,
        string $quoteContext,
        ?string $ownerId,
        string $deadline,
        string $action,
        array $contactability,
        string $lastAction,
        array $owners,
        bool $mixedOwner = false,
        array $resolvedOwnerIds = [],
    ): array {
        $owner = $ownerId === null
            ? 'Propriétaire Zoho indisponible'
            : ($owners[$ownerId] ?? 'Propriétaire Zoho non identifié');
        if ($mixedOwner) {
            $owner .= ' · propriétaires multiples';
        }

        return [
            'priority' => $priority,
            'signals' => $signals,
            '_stable_key' => $stableKey,
            '_owner_ids' => collect([...$resolvedOwnerIds, $ownerId])->filter()->map(
                fn (mixed $resolvedOwnerId): string => (string) $resolvedOwnerId,
            )->unique()->sort()->values()->all(),
            'account' => $account,
            'contact' => $contactability['contact'],
            'reasons' => [$reason],
            'quote_context' => $quoteContext,
            'last_proven_action' => $lastAction,
            'channel' => $contactability['channel'],
            'owner' => $owner,
            'deadline' => $deadline,
            'recommended_action' => $action,
            'confidence' => 'Partiel',
        ];
    }

    /**
     * The account owner is the stable responsibility anchor. When it is missing,
     * choose the lexically first record owner so identical data always renders alike.
     *
     * @param Collection<int,object> $records
     * @return array{id:?string,mixed:bool,ids:list<string>}
     */
    private function ownerResolution(?string $accountId, Collection $accounts, Collection $records): array
    {
        $accountOwner = $accountId === null
            ? null
            : $this->stringOrNull($accounts->get($accountId)?->owner_zoho_id);
        $recordOwners = $records->pluck('owner_zoho_id')->filter()->map(
            fn (mixed $ownerId): string => (string) $ownerId,
        )->unique()->sort()->values();
        $allOwners = collect([$accountOwner])->merge($recordOwners)->filter()->unique()->values();

        return [
            'id' => $accountOwner ?? $recordOwners->first(),
            'mixed' => $allOwners->count() > 1,
            'ids' => $allOwners->sort()->values()->all(),
        ];
    }

    /** @param array<string,array<string,mixed>> $rows @param array<string,mixed> $candidate */
    private function appendQueueReason(array &$rows, string $key, array $candidate): void
    {
        if (! isset($rows[$key])) {
            $rows[$key] = $candidate;

            return;
        }

        $current = $rows[$key];
        $reasons = array_values(array_unique([...$current['reasons'], ...$candidate['reasons']]));
        $signals = array_values(array_unique([...$current['signals'], ...$candidate['signals']]));
        $candidateWins = self::PRIORITY_RANK[$candidate['priority']] < self::PRIORITY_RANK[$current['priority']];
        if ($candidate['priority'] === $current['priority']
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $candidate['deadline'])
            && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $current['deadline'])
                || $candidate['deadline'] < $current['deadline'])) {
            $candidateWins = true;
        }
        if ($candidate['priority'] === $current['priority']
            && (string) $candidate['deadline'] === (string) $current['deadline']
            && strcmp((string) $candidate['_stable_key'], (string) $current['_stable_key']) < 0) {
            $candidateWins = true;
        }
        if ($candidate['priority'] === 'enrichment' && $current['priority'] === 'P3'
            && $candidate['channel'] === 'Indisponible') {
            $candidateWins = true;
        }
        if ($current['priority'] === 'enrichment' && $candidate['priority'] === 'P3'
            && $candidate['channel'] === 'Indisponible') {
            $candidateWins = false;
        }

        $ownerIds = collect([...($current['_owner_ids'] ?? []), ...($candidate['_owner_ids'] ?? [])])
            ->filter()->map(fn (mixed $ownerId): string => (string) $ownerId)->unique()->sort()->values()->all();
        $rows[$key] = $candidateWins ? $candidate : $current;
        $rows[$key]['reasons'] = $reasons;
        $rows[$key]['signals'] = $signals;
        $rows[$key]['_owner_ids'] = $ownerIds;
        if (count($ownerIds) > 1) {
            $rows[$key]['owner'] = preg_replace(
                '/ · propriétaires multiples$/u',
                '',
                (string) $rows[$key]['owner'],
            ).' · propriétaires multiples';
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function priorityDiverseQueue(array $rows): array
    {
        if (count($rows) <= 50) {
            return $rows;
        }

        $groups = collect($rows)->groupBy('priority')->map(fn (Collection $items): array => $items->values()->all());
        $picked = array_fill_keys(self::PRIORITIES, []);
        $remainingCapacity = 50;

        foreach (['P2', 'P3', 'enrichment'] as $priority) {
            $items = $groups->get($priority, []);
            if ($items !== []) {
                $picked[$priority][] = array_shift($items);
                $groups->put($priority, $items);
                $remainingCapacity--;
            }
        }
        foreach (self::PRIORITIES as $priority) {
            $items = $groups->get($priority, []);
            while ($remainingCapacity > 0 && $items !== []) {
                $picked[$priority][] = array_shift($items);
                $remainingCapacity--;
            }
            if ($remainingCapacity === 0) {
                break;
            }
        }

        return array_values(array_merge(...array_values($picked)));
    }

    /** @param array<string,int> $counts @return array{P1:int,P2:int,P3:int,enrichment:int} */
    private function priorityCounts(array $counts): array
    {
        return [
            'P1' => (int) ($counts['P1'] ?? 0),
            'P2' => (int) ($counts['P2'] ?? 0),
            'P3' => (int) ($counts['P3'] ?? 0),
            'enrichment' => (int) ($counts['enrichment'] ?? 0),
        ];
    }

    /** @return array{value:int|string,label:string,note:string,confidence:string} */
    private function card(int|string $value, string $label, string $note, string $confidence): array
    {
        return compact('value', 'label', 'note', 'confidence');
    }

    /** @param list<string> $states */
    private function combineCompleteness(array $states): string
    {
        $available = array_values(array_filter(
            $states,
            fn (string $state): bool => $state !== 'unavailable',
        ));

        return match (true) {
            $available === [] => 'unavailable',
            count($available) === count($states)
                && ! in_array('partial', $available, true) => 'complete',
            default => 'partial',
        };
    }

    private function partialSubsetNote(string $completeness): string
    {
        return $completeness === 'partial'
            ? ' Sous-ensemble connu seulement : certaines règles sources sont indisponibles.'
            : '';
    }

    /**
     * @param Collection<int,object> $quotes
     * @param Collection<int,object> $deals
     * @param Collection<int,object> $tasksCreated
     * @param Collection<int,object> $eventActivities
     * @param Collection<int,object> $overdueTasks
     * @param Collection<string,object> $contacts
     * @param array<string,string> $owners
     * @return list<array<string,mixed>>
     */
    private function ownerMetrics(
        Collection $quotes,
        Collection $deals,
        Collection $tasksCreated,
        Collection $eventActivities,
        Collection $overdueTasks,
        Collection $contacts,
        array $owners,
    ): array {
        $ownerIds = $quotes->pluck('owner_zoho_id')
            ->merge($deals->pluck('owner_zoho_id'))
            ->merge($tasksCreated->pluck('owner_zoho_id'))
            ->merge($eventActivities->pluck('owner_zoho_id'))
            ->merge($overdueTasks->pluck('owner_zoho_id'))
            ->map(fn (mixed $id): string => filled($id) ? (string) $id : '__unassigned__')
            ->unique()->values();

        return $ownerIds->map(function (string $ownerId) use ($quotes, $deals, $tasksCreated, $eventActivities, $overdueTasks, $contacts, $owners): array {
            $realOwnerId = $ownerId === '__unassigned__' ? null : $ownerId;
            $ownerQuotes = $quotes->filter(fn (object $quote): bool => $this->sameOwner($quote->owner_zoho_id, $realOwnerId));
            $ownerDeals = $deals->filter(fn (object $deal): bool => $this->sameOwner($deal->owner_zoho_id, $realOwnerId));
            $ownerTasks = $tasksCreated->filter(fn (object $activity): bool => $this->sameOwner($activity->owner_zoho_id, $realOwnerId));
            $ownerEvents = $eventActivities->filter(fn (object $activity): bool => $this->sameOwner($activity->owner_zoho_id, $realOwnerId));
            $ownerOverdue = $overdueTasks->filter(fn (object $activity): bool => $this->sameOwner($activity->owner_zoho_id, $realOwnerId));

            return [
                'owner' => $realOwnerId === null
                    ? 'Non attribué'
                    : ($owners[$realOwnerId] ?? 'Propriétaire Zoho non identifié'),
                'quotes' => $ownerQuotes->count(),
                'missing_status' => $ownerQuotes->filter(fn (object $quote): bool => blank($quote->follow_up_status))->count(),
                'overdue_tasks' => $ownerOverdue->count(),
                'completed_tasks' => $ownerTasks->filter(fn (object $activity): bool => $this->isCompletedTask($activity))->count(),
                'meetings' => $ownerEvents->filter(fn (object $activity): bool => $this->activityType($activity) === 'meeting')->count(),
                'calls' => $ownerEvents->filter(fn (object $activity): bool => $this->activityType($activity) === 'call')->count(),
                'notes' => $ownerEvents->filter(fn (object $activity): bool => $this->activityType($activity) === 'note')->count(),
                'linked_contacts' => $ownerQuotes->pluck('contact_zoho_id')->merge($ownerDeals->pluck('contact_zoho_id'))->filter()
                    ->filter(fn (mixed $contactId): bool => $contacts->has((string) $contactId))->unique()->count(),
                'linked_deals' => $ownerQuotes->pluck('deal_zoho_id')->merge($ownerDeals->pluck('zoho_id'))
                    ->filter()->unique()->count(),
            ];
        })->sortByDesc(fn (array $owner): int => $owner['overdue_tasks'] + $owner['missing_status'])
            ->values()->all();
    }

    /** @param array<string,scalar|null> $filters @return list<array<string,int|string>> */
    private function monthlyEvidence(MarketingScope $scope, array $filters, CarbonImmutable $now): array
    {
        $firstMonth = $now->startOfMonth()->subMonths(5);
        $lastInstant = $now;
        $startUtc = $firstMonth->utc();
        $endUtc = $lastInstant->utc();

        $quotes = $this->quotes($scope, $filters)
            ->whereBetween('quote_date', [$firstMonth->toDateString(), $now->toDateString()])
            ->get(self::QUOTE_COLUMNS);
        $deals = $this->deals($scope, $filters)
            ->whereBetween('zoho_created_at', [$startUtc, $endUtc])
            ->get(self::DEAL_COLUMNS);
        $activities = $this->activitiesInBounds($this->activities($scope), $startUtc, $endUtc)
            ->get(self::ACTIVITY_COLUMNS);

        $months = collect(range(0, 5))->mapWithKeys(function (int $offset) use ($firstMonth): array {
            $month = $firstMonth->addMonths($offset)->format('Y-m');

            return [$month => [
                'month' => $month,
                'quotes' => 0,
                'current_decisions' => 0,
                'tasks_created' => 0,
                'completed_tasks' => 0,
                'meetings' => 0,
                'calls' => 0,
                'notes' => 0,
                'deals' => 0,
            ]];
        })->all();

        foreach ($quotes as $quote) {
            $month = substr((string) $quote->quote_date, 0, 7);
            if (! isset($months[$month])) {
                continue;
            }
            $months[$month]['quotes']++;
            if (ZohoMarketingAnalytics::quoteOutcome($quote->follow_up_status) !== null) {
                $months[$month]['current_decisions']++;
            }
        }
        foreach ($deals as $deal) {
            $month = $this->monthKey($deal->zoho_created_at, $now->timezone);
            if ($month !== null && isset($months[$month])) {
                $months[$month]['deals']++;
            }
        }
        foreach ($activities as $activity) {
            if (! $this->activityOccurredBy($activity, $endUtc)) {
                continue;
            }
            if ($this->activityType($activity) === 'task') {
                $createdMonth = $this->monthKey($activity->zoho_created_at, $now->timezone);
                if ($createdMonth !== null && isset($months[$createdMonth])) {
                    $months[$createdMonth]['tasks_created']++;
                    if ($this->isCompletedTask($activity)) {
                        $months[$createdMonth]['completed_tasks']++;
                    }
                }

                continue;
            }
            $anchorMonth = $this->monthKey($this->activityAnchor($activity), $now->timezone);
            if ($anchorMonth === null || ! isset($months[$anchorMonth])) {
                continue;
            }
            if (in_array($this->activityType($activity), ['meeting', 'call', 'note'], true)) {
                $months[$anchorMonth][$this->activityType($activity).'s']++;
            }
        }

        return array_values($months);
    }

    private function activityType(object $activity): string
    {
        $type = mb_strtolower(trim((string) $activity->activity_type));

        return match ($type) {
            'tasks' => 'task',
            'meetings' => 'meeting',
            'calls' => 'call',
            'notes' => 'note',
            default => $type,
        };
    }

    private function isCompletedTask(object $activity): bool
    {
        return $this->activityType($activity) === 'task'
            && in_array(mb_strtolower(trim((string) $activity->status)), ['terminé', 'termine', 'completed'], true);
    }

    private function isNotStartedTask(object $activity): bool
    {
        return $this->activityType($activity) === 'task'
            && in_array(mb_strtolower(trim((string) $activity->status)), [
                "n'a pas commencé", "n'a pas commence", 'not started',
            ], true);
    }

    private function isProvenHumanActivity(object $activity): bool
    {
        return $this->isCompletedTask($activity)
            || in_array($this->activityType($activity), ['meeting', 'call', 'note'], true);
    }

    private function activityInPeriod(object $activity, MarketingPeriod $period): bool
    {
        [$startUtc, $endUtc] = $period->databaseBounds();

        return $this->inUtcBounds($this->activityAnchor($activity), $startUtc, $endUtc);
    }

    private function activityAnchor(object $activity): mixed
    {
        return $this->activityType($activity) === 'meeting'
            ? ($activity->start_at ?: $activity->activity_at ?: $activity->zoho_created_at)
            : ($activity->activity_at ?: $activity->zoho_created_at);
    }

    private function activityOccurredBy(object $activity, CarbonImmutable $cutoffUtc): bool
    {
        $anchor = $this->activityType($activity) === 'task'
            ? $activity->zoho_created_at
            : $this->activityAnchor($activity);

        return filled($anchor) && CarbonImmutable::parse($anchor, 'UTC')->lte($cutoffUtc);
    }

    private function inUtcBounds(mixed $value, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool
    {
        return filled($value)
            && CarbonImmutable::parse($value, 'UTC')->betweenIncluded($startUtc, $endUtc);
    }

    private function dealRecordAnchor(object $deal): ?CarbonImmutable
    {
        $value = $deal->stage_modified_at ?: $deal->zoho_created_at;

        return filled($value) ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /**
     * @param Collection<string,Collection<int,object>> $byParent
     * @param Collection<string,Collection<int,object>> $byContact
     * @param list<string> $parentIds
     * @param list<string> $contactIds
     * @return Collection<int,object>
     */
    private function relatedActivities(Collection $byParent, Collection $byContact, array $parentIds, array $contactIds): Collection
    {
        return collect($parentIds)->flatMap(
            fn (string $id): Collection => $byParent->get($id, collect()),
        )->merge(collect($contactIds)->flatMap(
            fn (string $id): Collection => $byContact->get($id, collect()),
        ))->unique(fn (object $activity): string => $this->activityType($activity).':'.$activity->zoho_id)->values();
    }

    /** @param Collection<int,object> $linked */
    private function lastProvenAction(Collection $linked, string $timezone): string
    {
        if ($linked->contains(fn (object $activity): bool => $this->isCompletedTask($activity))) {
            return 'Tâche terminée · date de réalisation indisponible';
        }

        $latest = $linked
            ->reject(fn (object $activity): bool => $this->activityType($activity) === 'task')
            ->filter(fn (object $activity): bool => filled($this->activityAnchor($activity)))
            ->sortByDesc(fn (object $activity): string => (string) $this->activityAnchor($activity))
            ->first();
        if ($latest === null) {
            return 'Indisponible';
        }

        $label = match ($this->activityType($latest)) {
            'meeting' => 'Réunion',
            'call' => 'Appel',
            'note' => 'Note',
            default => 'Activité',
        };

        return $label.' · '.CarbonImmutable::parse($this->activityAnchor($latest), 'UTC')
            ->setTimezone($timezone)->format('d/m/Y H:i');
    }

    private function recordKey(string $kind, object $record): string
    {
        return filled($record->account_zoho_id)
            ? 'account:'.(string) $record->account_zoho_id
            : $kind.':'.(string) $record->zoho_id;
    }

    private function accountLabel(?string $accountId, Collection $accounts, mixed $fallback): string
    {
        $account = $accountId === null ? null : $accounts->get($accountId);
        if ($account !== null && filled($account->name)) {
            return (string) $account->name;
        }

        return filled($fallback) ? (string) $fallback : 'Compte non lié';
    }

    private function quoteContext(object $quote): string
    {
        $transport = $this->transportLabels($quote->transport_type);

        return (filled($quote->quote_number) ? (string) $quote->quote_number : 'Devis')
            .' · '.($transport === [] ? 'transport indisponible' : implode(', ', $transport));
    }

    /** @return list<string> */
    private function transportLabels(mixed $raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $values = is_array($decoded) ? $decoded : [$decoded ?? $raw];
        }

        return collect($values)->flatten()->filter(fn (mixed $value): bool => is_scalar($value) && filled($value))
            ->map(fn (mixed $value): string => trim((string) $value))->unique()->values()->all();
    }

    private function contactAction(?string $kind, string $objective): string
    {
        return $kind === 'email'
            ? 'Envoyer un email court pour '.$objective.'.'
            : 'Appeler pour '.$objective.'.';
    }

    private function monthKey(mixed $value, string $timezone): ?string
    {
        return filled($value)
            ? CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m')
            : null;
    }

    private function sameOwner(mixed $candidate, ?string $ownerId): bool
    {
        return $ownerId === null ? blank($candidate) : (string) $candidate === $ownerId;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }

    /** @return array<string,mixed> */
    private function freshnessEvidence(MarketingScope $scope, CarbonImmutable $now): array
    {
        $tables = [
            'quotes' => 'zoho_quotes',
            'deals' => 'zoho_deals',
            'activities' => 'zoho_activities',
            'accounts' => 'zoho_accounts',
            'contacts' => 'zoho_contacts',
        ];
        $logModules = [
            'quotes' => ['quotes'],
            'deals' => ['deals'],
            'activities' => ['tasks', 'events', 'calls', 'notes'],
            'accounts' => ['accounts'],
            'contacts' => ['contacts'],
        ];
        $logsAvailable = Schema::hasTable('zoho_sync_logs')
            && Schema::hasColumns('zoho_sync_logs', ['module', 'status', 'synced_at']);
        $requiredLogModules = collect($logModules)->flatten()->values();
        $latestLogs = collect();
        $latestSuccessfulLogs = collect();
        if ($logsAvailable) {
            $latestIds = DB::table('zoho_sync_logs')->whereIn('module', $requiredLogModules)
                ->selectRaw('MAX(id) as id')->groupBy('module')->pluck('id');
            $latestLogs = DB::table('zoho_sync_logs')->whereIn('id', $latestIds)
                ->get(['module', 'status', 'synced_at'])->keyBy('module');
            $latestSuccessfulIds = DB::table('zoho_sync_logs')->whereIn('module', $requiredLogModules)
                ->whereRaw('LOWER(status) = ?', ['success'])
                ->selectRaw('MAX(id) as id')->groupBy('module')->pluck('id');
            $latestSuccessfulLogs = DB::table('zoho_sync_logs')->whereIn('id', $latestSuccessfulIds)
                ->get(['module', 'status', 'synced_at'])->keyBy('module');
        }
        $activityRecordTimestamps = $this->scoped('zoho_activities', $scope)
            ->whereNotNull('last_synced_at')
            ->selectRaw('LOWER(activity_type) as activity_type, MAX(last_synced_at) as last_synced_at')
            ->groupByRaw('LOWER(activity_type)')
            ->get()
            ->mapWithKeys(function (object $row): array {
                $submodule = match ($this->activityType($row)) {
                    'task' => 'tasks',
                    'meeting' => 'events',
                    'call' => 'calls',
                    'note' => 'notes',
                    default => null,
                };

                return $submodule === null ? [] : [$submodule => $row->last_synced_at];
            });
        $warning = (int) config('zoho-v2.freshness.hourly_warning_minutes', 90);
        $critical = (int) config('zoho-v2.freshness.hourly_critical_minutes', 180);
        $nowUtc = $now->utc();
        $modules = [];
        $timestamps = collect();
        $affected = [];

        foreach ($tables as $module => $table) {
            $recordTimestamp = $this->scoped($table, $scope)->max('last_synced_at');
            $required = collect($logModules[$module]);
            $moduleLogs = $required->mapWithKeys(
                fn (string $logModule): array => [$logModule => $latestLogs->get($logModule)],
            );
            $successfulModuleLogs = $required->mapWithKeys(
                fn (string $logModule): array => [$logModule => $latestSuccessfulLogs->get($logModule)],
            );
            $usableEvidence = $required->mapWithKeys(function (string $logModule) use (
                $module,
                $recordTimestamp,
                $activityRecordTimestamps,
                $successfulModuleLogs,
            ): array {
                $successfulLog = $successfulModuleLogs->get($logModule);
                $recordEvidence = $module === 'activities'
                    ? $activityRecordTimestamps->get($logModule)
                    : $recordTimestamp;

                return [$logModule => $successfulLog?->synced_at ?: $recordEvidence];
            });
            $missingSubmodules = $usableEvidence
                ->filter(fn (mixed $value): bool => blank($value))->keys()->values()->all();
            $failedSubmodules = $moduleLogs->filter(
                fn (mixed $log): bool => $log !== null && mb_strtolower((string) $log->status) === 'error',
            )->keys()->values()->all();
            $partialSubmodules = $moduleLogs->filter(
                fn (mixed $log): bool => $log !== null && ! in_array(
                    mb_strtolower((string) $log->status),
                    ['success', 'error'],
                    true,
                ),
            )->keys()->values()->all();
            $usableTimestamps = $usableEvidence->filter(fn (mixed $value): bool => filled($value))
                ->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC'));
            $value = $usableTimestamps->sort()->first();
            $available = $missingSubmodules === [];
            if (blank($value)) {
                $modules[$module] = [
                    'available' => false,
                    'last_synced_at' => null,
                    'status' => $failedSubmodules !== []
                        ? 'Échec récent'
                        : ($partialSubmodules !== [] ? 'Synchronisation partielle' : 'Jamais synchronisé'),
                    'age_minutes' => null,
                    'missing_submodules' => $missingSubmodules,
                    'failed_submodules' => $failedSubmodules,
                    'partial_submodules' => $partialSubmodules,
                ];
                $affected[] = $module;

                continue;
            }

            $timestamp = $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value, 'UTC');
            $age = max(0, (int) $timestamp->diffInMinutes($nowUtc));
            $status = $failedSubmodules !== []
                ? 'Échec récent'
                : ($partialSubmodules !== []
                    ? 'Synchronisation partielle'
                    : ($missingSubmodules !== []
                    ? 'Synchronisation incomplète'
                    : ($age > $critical ? 'Critique' : ($age > $warning ? 'À surveiller' : 'À jour'))));
            $modules[$module] = [
                'available' => $available,
                'last_synced_at' => $timestamp->toIso8601String(),
                'status' => $status,
                'age_minutes' => $age,
                'missing_submodules' => $missingSubmodules,
                'failed_submodules' => $failedSubmodules,
                'partial_submodules' => $partialSubmodules,
            ];
            if ($available) {
                $timestamps->push($timestamp);
            }
            if (! $available || $failedSubmodules !== [] || $partialSubmodules !== [] || $age > $warning) {
                $affected[] = $module;
            }
        }

        $availableCount = collect($modules)->where('available', true)->count();
        if ($timestamps->isEmpty() || $availableCount === 0) {
            $moduleEvidence = collect($modules);
            $hasFailures = $moduleEvidence->contains(
                fn (array $module): bool => $module['status'] === 'Échec récent',
            );
            $hasPartialEvidence = $moduleEvidence->contains(
                fn (array $module): bool => filled($module['last_synced_at'])
                    || in_array($module['status'], ['Synchronisation partielle', 'Synchronisation incomplète'], true),
            );

            return [
                'last_synced_at' => null,
                'state' => 'Indisponible',
                'stale' => true,
                'status' => $hasFailures ? 'Échec récent' : ($hasPartialEvidence ? 'Incomplet' : 'Jamais synchronisé'),
                'age_minutes' => null,
                'affected_modules' => array_keys($tables),
                'modules' => $modules,
            ];
        }

        /** @var CarbonImmutable $oldest */
        $oldest = $timestamps->sort()->first();
        $age = max(0, (int) $oldest->diffInMinutes($nowUtc));
        $hasUnavailable = collect($modules)->contains(fn (array $module): bool => ! $module['available']);
        $hasFailures = collect($modules)->contains(fn (array $module): bool => $module['status'] === 'Échec récent');
        $hasPartialSync = collect($modules)->contains(
            fn (array $module): bool => in_array(
                $module['status'],
                ['Synchronisation partielle', 'Synchronisation incomplète'],
                true,
            ),
        );
        $status = $hasFailures
            ? 'Échec récent'
            : ($hasUnavailable || $hasPartialSync
                ? 'Incomplet'
                : ($age > $critical ? 'Critique' : ($age > $warning ? 'À surveiller' : 'À jour')));

        return [
            'last_synced_at' => $oldest->toIso8601String(),
            'state' => $affected === [] ? 'Fiable' : 'Partiel',
            'stale' => $affected !== [],
            'status' => $status,
            'age_minutes' => $age,
            'affected_modules' => array_values(array_unique($affected)),
            'modules' => $modules,
        ];
    }

    /** @param array<string,scalar|null> $filters @return array{applied:list<string>,partial:list<string>,ignored:list<string>} */
    private function filterApplicability(array $filters): array
    {
        $keys = array_keys($filters);
        $applied = array_values(array_intersect($keys, ['commercial']));
        $partial = array_values(array_intersect($keys, ['campaign', 'country', 'transport', 'currency']));
        $ignored = array_values(array_diff($keys, $applied, $partial));

        return compact('applied', 'partial', 'ignored');
    }

    /** @param array<string,scalar|null> $filters */
    private function filterReadiness(array $filters): string
    {
        $applicability = $this->filterApplicability($filters);
        if ($filters === []) {
            return 'Aucun filtre additionnel demandé.';
        }

        $parts = [];
        if ($applicability['applied'] !== []) {
            $parts[] = 'appliqués : '.implode(', ', $applicability['applied']);
        }
        if ($applicability['partial'] !== []) {
            $parts[] = 'partiels : '.implode(', ', $applicability['partial']);
        }
        if ($applicability['ignored'] !== []) {
            $parts[] = 'ignorés : '.implode(', ', $applicability['ignored']);
        }

        return ucfirst(implode(' · ', $parts)).'.';
    }

    /** @param array<string,scalar|null> $filters @return array<string,mixed> */
    private function filterScope(array $filters): array
    {
        $requested = array_values(array_keys($filters));
        $quoteKeys = ['commercial', 'country', 'transport', 'currency'];
        $activityKeys = ['commercial'];
        $mixedKeys = ['country', 'transport', 'currency'];
        $queueMixedKeys = [...$mixedKeys, 'campaign'];
        $status = fn (array $full, array $partial = []): string => $this->filterScopeStatus(
            $requested,
            $full,
            $partial,
        );

        return [
            'requested' => $requested,
            'briefing' => $status(['commercial'], $queueMixedKeys),
            'queue' => $status(['commercial'], $queueMixedKeys),
            'monthly' => $status(['commercial'], $mixedKeys),
            'metrics' => [
                'quotes' => $status($quoteKeys),
                'missing_status' => $status($quoteKeys),
                'tasks_created' => $status($activityKeys),
                'not_started_tasks' => $status($activityKeys),
                'completed_tasks' => $status($activityKeys),
                'meetings' => $status($activityKeys),
                'calls' => $status($activityKeys),
                'notes' => $status($activityKeys),
                'proven_human_follow_up' => $status($activityKeys),
            ],
            'panels' => [
                'monthly' => $status(['commercial'], $mixedKeys),
                'funnel' => $status(['commercial'], $mixedKeys),
                'quote_risks' => $status($quoteKeys),
                'mix' => $status($quoteKeys),
                'readiness' => $requested === [] ? 'Sans filtre' : 'Non filtré',
                'owners' => $status(['commercial'], $mixedKeys),
            ],
        ];
    }

    /** @param list<string> $requested @param list<string> $fullyApplied @param list<string> $partiallyApplied */
    private function filterScopeStatus(array $requested, array $fullyApplied, array $partiallyApplied = []): string
    {
        if ($requested === []) {
            return 'Sans filtre';
        }

        $fullHits = array_intersect($requested, $fullyApplied);
        $partialHits = array_intersect($requested, $partiallyApplied);
        $unsupported = array_diff($requested, $fullyApplied, $partiallyApplied);
        if ($partialHits === [] && $unsupported === [] && count($fullHits) === count($requested)) {
            return 'Filtré';
        }

        return ($fullHits !== [] || $partialHits !== []) ? 'Partiel' : 'Non filtré';
    }

    /** @param array<string,scalar|null> $filters @return array<string,mixed> */
    private function unavailable(
        MarketingPeriod $period,
        MarketingScope $scope,
        array $filters,
        bool $scopeUnavailable = false,
    ): array
    {
        $metricKeys = [
            'quotes', 'missing_status', 'tasks_created', 'not_started_tasks', 'completed_tasks',
            'meetings', 'calls', 'notes', 'proven_human_follow_up',
        ];
        $funnelKeys = ['quotes', 'named_contacts', 'linked_deals', 'current_decisions', 'completed_quote_tasks'];
        $riskKeys = ['expired_missing_decision', 'due_7_days', 'due_30_days', 'unreachable', 'future_date_anomalies'];
        $cards = [];
        foreach (self::PRIORITIES as $priority) {
            $cards[$priority] = $this->card(
                'Indisponible',
                $priority === 'enrichment' ? 'Canal à enrichir' : $priority,
                'Source ou périmètre CRM indisponible.',
                'Indisponible',
            );
        }
        $unavailableByPriority = array_fill_keys(self::PRIORITIES, false);
        $unavailableCompleteness = array_fill_keys(self::PRIORITIES, 'unavailable');
        $unavailableTotals = array_fill_keys(self::PRIORITIES, 'Indisponible');
        $unavailableMetricConfidence = array_fill_keys($metricKeys, 'Indisponible');
        $unavailablePanelConfidence = array_fill_keys(
            ['monthly', 'funnel', 'quote_risks', 'mix', 'readiness', 'owners'],
            'Indisponible',
        );
        $moduleShape = [];
        foreach (['quotes', 'deals', 'activities', 'accounts', 'contacts'] as $module) {
            $moduleShape[$module] = [
                'available' => false,
                'last_synced_at' => null,
                'status' => $scopeUnavailable ? 'Non évalué' : 'Jamais synchronisé',
                'age_minutes' => null,
                'missing_submodules' => $scopeUnavailable
                    ? []
                    : ($module === 'activities' ? ['tasks', 'events', 'calls', 'notes'] : [$module]),
                'failed_submodules' => [],
                'partial_submodules' => [],
            ];
        }

        return [
            'period' => $period->toArray(),
            'filters' => $filters,
            'scope' => $scope->metadata(),
            'freshness' => [
                'last_synced_at' => null,
                'state' => $scopeUnavailable ? 'Périmètre indisponible' : 'Indisponible',
                'stale' => ! $scopeUnavailable,
                'status' => $scopeUnavailable ? 'Non évaluée' : 'Jamais synchronisé',
                'age_minutes' => null,
                'affected_modules' => $scopeUnavailable
                    ? []
                    : ['quotes', 'deals', 'activities', 'accounts', 'contacts'],
                'modules' => $moduleShape,
            ],
            'metrics' => array_fill_keys($metricKeys, 'Indisponible'),
            'briefing' => [
                'reachable_accounts' => 'Indisponible',
                'highest_deadline' => 'Indisponible',
                'owner_escalations' => 'Indisponible',
                'completeness' => [
                    'reachable_accounts' => 'unavailable',
                    'highest_deadline' => 'unavailable',
                    'owner_escalations' => 'unavailable',
                ],
            ],
            'decision_cards' => $cards,
            'queue' => [
                'items' => [],
                'truncated' => false,
                'total_by_priority' => $unavailableTotals,
                'total_by_signal' => array_fill_keys(self::PRIORITIES, null),
                'displayed_by_priority' => $unavailableTotals,
                'availability_by_priority' => $unavailableByPriority,
                'completeness_by_priority' => $unavailableCompleteness,
            ],
            'owners' => [],
            'monthly' => [],
            'monthly_meta' => [
                'current_month' => CarbonImmutable::now($period->timezone)->format('Y-m'),
                'as_of' => CarbonImmutable::now($period->timezone)->toDateString(),
                'completion_basis' => 'current_status_of_tasks_created_in_month',
            ],
            'funnel' => array_fill_keys($funnelKeys, 'Indisponible'),
            'quote_risks' => array_fill_keys($riskKeys, 'Indisponible'),
            'transport_mix' => [],
            'lane_mix' => [],
            'confidence' => [
                'briefing' => 'Indisponible',
                'metrics' => $unavailableMetricConfidence,
                'decision_cards' => array_fill_keys(self::PRIORITIES, 'Indisponible'),
                'panels' => $unavailablePanelConfidence,
            ],
            'readiness' => [
                'source' => $scopeUnavailable
                    ? 'Périmètre CRM indisponible — fraîcheur non évaluée tant que le rattachement commercial n’est pas confirmé.'
                    : 'Indisponible — miroir CRM incomplet.',
                'filters' => $this->filterReadiness($filters),
            ],
            'meta' => [
                'quote_period_basis' => 'quote_date',
                'task_creation_basis' => 'zoho_created_at',
                'task_completion_basis' => 'current_status_of_tasks_created_in_period; completion_timestamp_unavailable',
                'proven_human_follow_up' => 'completed_task_plus_meeting_call_note',
                'read_only' => true,
                'causal_attribution' => false,
                'filter_applicability' => $this->filterApplicability($filters),
                'filter_scope' => $this->filterScope($filters),
                'soft_campaign_signal' => 'Indisponible',
            ],
        ];
    }
}
