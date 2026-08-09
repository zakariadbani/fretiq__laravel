<?php

namespace App\Services\Zoho\V2\Marketing;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoDeal;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\ZohoSyncLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Presentation-neutral, read-only marketing and CRM intelligence. */
final class ZohoMarketingAnalytics
{
    private const TERMINAL_WON = ['closed won', 'affaire gagné'];

    private const TERMINAL_LOST = ['closed lost', 'affaire perdu', 'closed lost to competition'];

    /** @var array<string,list<string>> */
    private array $columns = [];

    public function analyse(MarketingAnalyticsQuery $input): MarketingAnalyticsResult
    {
        $filters = $input->normalizedFilters();
        $available = $this->available();
        $crmAvailable = $this->crmAvailable();
        $scope = $this->resolveScope($input, $filters);

        if (! $available) {
            return new MarketingAnalyticsResult($this->empty($input, $filters, $scope, true));
        }

        $schemaFingerprint = $this->schemaFingerprint($crmAvailable);

        $cacheKey = 'zoho-v2:marketing:'.hash('sha256', json_encode([
            'user' => $input->user?->getKey(),
            'roles' => $input->user?->getRoleNames()->sort()->values()->all(),
            'period' => $input->period->toArray(),
            'filters' => $filters,
            'scope' => $scope->cacheFragment(),
            'crm_available' => $crmAvailable,
            'schema' => $schemaFingerprint,
            'freshness' => $this->freshnessVersion($crmAvailable, $schemaFingerprint),
        ], JSON_THROW_ON_ERROR));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('zoho-v2.marketing.cache_seconds', 60)),
            fn (): MarketingAnalyticsResult => new MarketingAnalyticsResult($crmAvailable
                ? $this->build($input, $filters, $scope)
                : $this->buildFretiqOnly($input, $filters, $scope)),
        );
    }

    public function available(): bool
    {
        $required = [
            'companies' => ['id', 'source', 'country', 'sector', 'relationship', 'created_at', 'updated_at'],
            'contacts' => ['id', 'company_id', 'assigned_to', 'source', 'created_at', 'updated_at', 'deleted_at'],
            'campaigns' => ['id', 'name', 'updated_at'],
            'campaign_runs' => ['id', 'campaign_id', 'status', 'run_at', 'updated_at'],
            'campaign_recipients' => ['campaign_run_id', 'contact_id', 'status', 'sent_at', 'opened_at', 'clicked_at', 'replied_at', 'bounced_at', 'updated_at'],
            'demandes' => ['contact_id', 'campaign_id', 'captured_at', 'updated_at'],
        ];
        if ($this->columns === []) {
            $tables = [...array_keys($required), 'zoho_user_mappings'];
            $cacheKey = 'zoho-v2:marketing:schema:v4:'.DB::connection()->getDatabaseName();
            $this->columns = Cache::remember($cacheKey, now()->addMinute(), fn (): array => $this->inspectColumns($tables));
        }
        foreach ($required as $table => $columns) {
            if ($this->columns($table) === [] || array_diff($columns, $this->columns($table)) !== []) {
                return false;
            }
        }

        return true;
    }

    private function crmAvailable(): bool
    {
        $required = [
            'zoho_leads' => ['zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_deleted_at', 'status', 'lead_source', 'country', 'industry'],
            'zoho_accounts' => ['zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_deleted_at', 'country', 'industry', 'account_type'],
            'zoho_contacts' => ['zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_deleted_at', 'country'],
            'zoho_deals' => ['zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_deleted_at', 'stage', 'lead_source', 'amount', 'weighted_amount', 'currency_code'],
            'zoho_quotes' => ['zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_deleted_at', 'follow_up_status', 'currency_code', 'line_items_total', 'line_items_total_complete', 'transport_type', 'country', 'origin', 'destination', 'valid_till', 'quote_date'],
            'zoho_deal_stage_history' => ['deal_zoho_id', 'stage', 'occurred_at', 'zoho_deleted_at'],
            'zoho_quote_status_history' => ['quote_zoho_id', 'status', 'previous_status', 'occurred_at', 'zoho_deleted_at'],
            'zoho_activities' => ['owner_zoho_id', 'parent_zoho_id', 'activity_at', 'zoho_deleted_at'],
            'zoho_marketing_links' => ['zoho_module', 'zoho_record_id', 'fretiq_entity_type', 'fretiq_entity_id', 'match_type', 'is_active', 'matched_at', 'updated_at'],
            'zoho_sync_logs' => ['module', 'mode', 'status', 'sync_batch_id', 'synced_at'],
            'zoho_sync_failures' => ['resolved_at', 'updated_at'],
        ];

        if (! array_key_exists('zoho_leads', $this->columns)) {
            $this->columns = [...$this->columns, ...$this->inspectColumns([...array_keys($required), 'zoho_user_mappings'])];
        }

        if ($this->columns === []) {
            $tables = [...array_keys($required), 'zoho_user_mappings'];
            $cacheKey = 'zoho-v2:marketing:schema:v4:'.DB::connection()->getDatabaseName();
            $this->columns = Cache::remember($cacheKey, now()->addMinute(), fn (): array => $this->inspectColumns($tables));
        }

        foreach ($required as $table => $columns) {
            $actual = $this->columns($table);
            if ($actual === [] || array_diff($columns, $actual) !== []) {
                return false;
            }
        }

        return true;
    }

    /** Preserve Fretiq intelligence even while the CRM mirror is absent or migrating. */
    private function buildFretiqOnly(MarketingAnalyticsQuery $input, array $filters, MarketingScope $scope): array
    {
        $data = $this->empty($input, $filters, $scope, true);
        $bounds = $input->period->databaseBounds();
        $previous = $input->period->databaseBounds(true);
        $data['campaign'] = $this->campaignMetrics($bounds, $scope, $filters);
        $data['comparison']['campaign'] = $this->campaignMetrics($previous, $scope, $filters);
        $data['kpis']['discovered_companies'] = $this->fretiqCount(Company::query(), $bounds, $scope, 'companies', $filters);
        $data['kpis']['discovered_contacts'] = $this->fretiqCount(Contact::query(), $bounds, $scope, 'contacts', $filters);
        foreach (['new_leads', 'new_accounts', 'new_contacts', 'deals_created', 'quotes_created'] as $key) {
            $data['kpis'][$key] = 0;
        }
        $data['comparison']['discovered_companies'] = $this->fretiqCount(Company::query(), $previous, $scope, 'companies', $filters);
        $data['comparison']['discovered_contacts'] = $this->fretiqCount(Contact::query(), $previous, $scope, 'contacts', $filters);
        $data['funnels']['campaign'] = $data['campaign']['funnel'];
        $data['trends']['demandes'] = $this->dateTrend($this->demandeQuery($bounds, $scope, $filters), 'demande.captured_at', $input->period->timezone);
        $data['filter_options'] = $this->fretiqFilterOptions($input, $scope);

        return $data;
    }

    public static function dealOutcome(?string $stage): ?string
    {
        $stage = self::normalizeStatus($stage);
        if (in_array($stage, self::TERMINAL_WON, true)) {
            return 'won';
        }
        if (in_array($stage, self::TERMINAL_LOST, true)) {
            return 'lost';
        }

        return null;
    }

    public static function quoteOutcome(?string $status): ?string
    {
        return match (self::normalizeStatus($status)) {
            'affaire gagnée' => 'won', 'affaire perdue' => 'lost', default => null,
        };
    }

    /** @param array<string,scalar|null> $filters */
    private function build(MarketingAnalyticsQuery $input, array $filters, MarketingScope $scope): array
    {
        $period = $input->period;
        $currentBounds = $period->databaseBounds();
        $previousBounds = $period->databaseBounds(true);
        $leads = $this->scoped('zoho_leads', $scope, $filters);
        $accounts = $this->scoped('zoho_accounts', $scope, $filters);
        $contacts = $this->scoped('zoho_contacts', $scope, $filters);
        $deals = $this->scoped('zoho_deals', $scope, $filters);
        $quotes = $this->scoped('zoho_quotes', $scope, $filters);
        $leadPeriod = (clone $leads)->whereBetween('zoho_created_at', $currentBounds);
        $dealPeriod = (clone $deals)->whereBetween('zoho_created_at', $currentBounds);
        $quotePeriod = (clone $quotes)->whereBetween('zoho_created_at', $currentBounds);
        $previousDeals = (clone $deals)->whereBetween('zoho_created_at', $previousBounds);
        $previousQuotes = (clone $quotes)->whereBetween('zoho_created_at', $previousBounds);
        $campaign = $this->campaignMetrics($currentBounds, $scope, $filters);
        $previousCampaign = $this->campaignMetrics($previousBounds, $scope, $filters);
        $dealOutcomes = $this->dealOutcomes($dealPeriod);
        $quoteMetrics = $this->quoteMetrics($quotePeriod);
        $previousDealOutcomes = $this->dealOutcomes(clone $previousDeals);
        $previousQuoteMetrics = $this->quoteMetrics(clone $previousQuotes);
        $newLeadCount = (clone $leadPeriod)->count();
        $leadQualification = $this->breakdown(clone $leadPeriod, 'status');
        $dealCreatedCount = (clone $dealPeriod)->count();
        $quoteCreatedCount = (clone $quotePeriod)->count();

        return [
            'period' => $period->toArray(),
            'filters' => $filters,
            'filter_applicability' => $this->filterApplicability($filters),
            'filter_options' => $this->filterOptions($input, $scope),
            'scope' => $scope->metadata(),
            'campaign' => $campaign,
            'kpis' => [
                'discovered_companies' => $this->fretiqCount(Company::query(), $currentBounds, $scope, 'companies', $filters),
                'discovered_contacts' => $this->fretiqCount(Contact::query(), $currentBounds, $scope, 'contacts', $filters),
                'new_leads' => $newLeadCount,
                'lead_cohort_current_qualification' => $leadQualification,
                'new_accounts' => (clone $accounts)->whereBetween('zoho_created_at', $currentBounds)->count(),
                'new_contacts' => (clone $contacts)->whereBetween('zoho_created_at', $currentBounds)->count(),
                'deals_created' => $dealCreatedCount,
                'quotes_created' => $quoteCreatedCount,
                'pipeline' => $this->pipeline(clone $deals),
                'created_cohort_pipeline' => $this->pipeline(clone $dealPeriod),
                'deal_outcomes' => $dealOutcomes,
                'quotes' => $quoteMetrics,
            ],
            'comparison' => [
                'campaign' => $previousCampaign,
                'discovered_companies' => $this->fretiqCount(Company::query(), $previousBounds, $scope, 'companies', $filters),
                'discovered_contacts' => $this->fretiqCount(Contact::query(), $previousBounds, $scope, 'contacts', $filters),
                'new_leads' => (clone $leads)->whereBetween('zoho_created_at', $previousBounds)->count(),
                'new_accounts' => (clone $accounts)->whereBetween('zoho_created_at', $previousBounds)->count(),
                'new_contacts' => (clone $contacts)->whereBetween('zoho_created_at', $previousBounds)->count(),
                'deals_created' => (clone $previousDeals)->count(),
                'quotes_created' => (clone $previousQuotes)->count(),
                'deal_outcomes' => $previousDealOutcomes,
                'quotes' => $previousQuoteMetrics,
                'quote_outcomes' => $previousQuoteMetrics,
                'created_cohort_pipeline' => $this->pipeline(clone $previousDeals),
            ],
            'funnels' => [
                'campaign' => $campaign['funnel'],
                'zoho' => [
                    'leads' => $newLeadCount,
                    'lead_qualification' => $leadQualification,
                    'deals' => $dealCreatedCount,
                    'deal_stages' => $this->breakdown(clone $dealPeriod, 'stage'),
                    'deal_outcomes' => array_intersect_key($dealOutcomes, array_flip(['won', 'lost', 'open'])),
                    'quotes' => $quoteCreatedCount,
                    'quote_statuses' => $this->breakdown(clone $quotePeriod, 'follow_up_status'),
                    'quote_outcomes' => ['won' => $quoteMetrics['won_count'], 'lost' => $quoteMetrics['lost_count'], 'open' => $quoteMetrics['open_count']],
                    'transitions' => $this->historyTransitions($deals, $quotes, $currentBounds),
                ],
            ],
            'trends' => $this->trends($period, $scope, $filters, $deals),
            'breakdowns' => $this->breakdowns($leadPeriod, $dealPeriod, $quotePeriod),
            'attention' => $this->attention($leads, $deals, $quotes, $scope),
            'identity_coverage' => $this->identityCoverage($scope, $filters),
            'matched_touches' => $this->touchTimeline($period, $scope, $filters),
            'meta' => [
                'currency_policy' => 'native_currency_buckets_only',
                'causal_attribution' => false,
                'demande_label' => 'Demandes observées — sans attribution de revenu',
                'comparison_basis' => 'current_state_outcomes_for_records_created_in_each_period',
                'pipeline_basis' => 'current_state_all_active_deals',
                'pipeline_comparable' => false,
                'created_cohort_pipeline_basis' => 'current_state_of_deals_created_in_each_period',
                'win_trend_basis' => 'deal_stage_history_transitions',
                'stale_or_unavailable' => false,
            ],
        ];
    }

    /** @param array<string,scalar|null> $filters */
    private function resolveScope(MarketingAnalyticsQuery $input, array &$filters): MarketingScope
    {
        $user = $input->user;
        if (! $user) {
            return new MarketingScope(null, [], false, false, true);
        }

        if ($user->hasRole('commercial')) {
            $commercialId = (int) $user->getKey();
            $filters['commercial'] = (string) $commercialId;

            return $this->mappedScope($commercialId);
        }

        if (! $user->hasAnyRole(['admin', 'superadmin'])) {
            return new MarketingScope(null, [], false, false, true);
        }
        if (! isset($filters['commercial'])) {
            return new MarketingScope(null, [], true, false);
        }
        if (! ctype_digit((string) $filters['commercial'])) {
            return new MarketingScope(null, [], false, false, true);
        }

        $commercialId = (int) $filters['commercial'];
        $commercial = User::query()->whereKey($commercialId)->where('is_active', true)->role('commercial')->exists();
        if (! $commercial) {
            return new MarketingScope($commercialId, [], false, false, true);
        }

        return $this->mappedScope($commercialId);
    }

    private function mappedScope(int $commercialId): MarketingScope
    {
        if (! $this->mappingAvailable()) {
            return new MarketingScope($commercialId, [], false, true);
        }
        $owners = DB::table('zoho_user_mappings')->where('fretiq_user_id', $commercialId)->where('is_confirmed', true)
            ->whereNotNull('zoho_user_id')->pluck('zoho_user_id')->map(fn ($id) => (string) $id)->unique()->values()->all();

        // A portfolio must resolve to exactly one reviewed owner. Multiple mappings are
        // ambiguous and must not silently broaden a commercial's CRM visibility.
        return new MarketingScope($commercialId, $owners, count($owners) === 1, count($owners) !== 1);
    }

    private function mappingAvailable(): bool
    {
        $columns = $this->columns('zoho_user_mappings');

        return array_diff(['fretiq_user_id', 'zoho_user_id', 'is_confirmed', 'updated_at'], $columns) === [];
    }

    /** @param array<string,scalar|null> $filters */
    private function scoped(string $table, MarketingScope $scope, array $filters): Builder
    {
        $model = match ($table) {
            'zoho_leads' => ZohoLead::class,
            'zoho_accounts' => ZohoAccount::class,
            'zoho_contacts' => ZohoContact::class,
            'zoho_deals' => ZohoDeal::class,
            'zoho_quotes' => ZohoQuote::class,
            default => throw new \InvalidArgumentException("Unsupported mirror table [{$table}]."),
        };
        $query = $model::query()->whereNull('zoho_deleted_at');
        if (! $scope->crmVisible) {
            return $query->whereRaw('1 = 0');
        }
        if ($scope->ownerIds !== []) {
            $query->whereIn('owner_zoho_id', $scope->ownerIds);
        }

        $columns = match ($table) {
            'zoho_leads' => ['source' => 'lead_source', 'lead_source' => 'lead_source', 'country' => 'country', 'sector' => 'industry'],
            'zoho_accounts' => ['country' => 'country', 'sector' => 'industry', 'client_type' => 'account_type'],
            'zoho_contacts' => ['country' => 'country'],
            'zoho_deals' => ['lead_source' => 'lead_source', 'currency' => 'currency_code'],
            'zoho_quotes' => ['country' => 'country', 'transport' => 'transport_type', 'currency' => 'currency_code'],
        };
        foreach ($columns as $filter => $column) {
            if (isset($filters[$filter])) {
                $column === 'transport_type' ? $query->whereJsonContains($column, $filters[$filter]) : $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    /** @param array{0:mixed,1:mixed} $bounds @param array<string,scalar|null> $filters */
    private function campaignMetrics(array $bounds, MarketingScope $scope, array $filters): array
    {
        $recipients = DB::table('campaign_recipients as r')->join('campaign_runs as run', 'run.id', '=', 'r.campaign_run_id')
            ->join('campaigns as campaign', 'campaign.id', '=', 'run.campaign_id')->join('contacts as contact', 'contact.id', '=', 'r.contact_id')
            ->leftJoin('companies as company', 'company.id', '=', 'contact.company_id')->where('run.status', 'sent')->whereBetween('run.run_at', $bounds);
        $this->applyFretiqFilters($recipients, $scope, $filters, 'contact', 'company', 'campaign');
        $row = $recipients->selectRaw("
            COALESCE(SUM(CASE WHEN r.sent_at IS NOT NULL OR r.status IN ('sent','delivered','opened','clicked','replied','bounced','unsubscribed') THEN 1 ELSE 0 END), 0) as sent,
            COALESCE(SUM(CASE WHEN r.status IN ('delivered','opened','clicked','replied') THEN 1 ELSE 0 END), 0) as delivered,
            COALESCE(SUM(CASE WHEN r.opened_at IS NOT NULL OR r.status IN ('opened','clicked') THEN 1 ELSE 0 END), 0) as opened,
            COALESCE(SUM(CASE WHEN r.clicked_at IS NOT NULL OR r.status = 'clicked' THEN 1 ELSE 0 END), 0) as clicked,
            COALESCE(SUM(CASE WHEN r.replied_at IS NOT NULL OR r.status = 'replied' THEN 1 ELSE 0 END), 0) as replied
        ")->first();
        $counts = [
            'sent' => (int) ($row->sent ?? 0), 'delivered' => (int) ($row->delivered ?? 0),
            'opened' => (int) ($row->opened ?? 0), 'clicked' => (int) ($row->clicked ?? 0), 'replied' => (int) ($row->replied ?? 0),
        ];
        $counts['demandes'] = $this->demandeQuery($bounds, $scope, $filters)->count();
        $counts['conversions'] = $counts['demandes']; // Backward-compatible UI key; explicitly non-causal in meta.
        $counts['engagement_rate'] = $this->rate($counts['opened'], $counts['delivered'] ?: $counts['sent']);
        $counts['demande_conversion_rate'] = $this->rate($counts['demandes'], $counts['delivered'] ?: $counts['sent']);

        return ['counts' => $counts, 'funnel' => [
            'recipients' => $counts['sent'], 'delivered' => $counts['delivered'], 'opened' => $counts['opened'],
            'clicked' => $counts['clicked'], 'replied' => $counts['replied'], 'demandes' => $counts['demandes'],
        ]];
    }

    /** @param array{0:mixed,1:mixed} $bounds @param array<string,scalar|null> $filters */
    private function demandeQuery(array $bounds, MarketingScope $scope, array $filters): QueryBuilder
    {
        $query = DB::table('demandes as demande')->leftJoin('contacts as contact', 'contact.id', '=', 'demande.contact_id')
            ->leftJoin('companies as company', 'company.id', '=', 'contact.company_id')->leftJoin('campaigns as campaign', 'campaign.id', '=', 'demande.campaign_id')
            ->whereBetween('demande.captured_at', $bounds);
        $this->applyFretiqFilters($query, $scope, $filters, 'contact', 'company', 'campaign');

        return $query;
    }

    /** @param array<string,scalar|null> $filters */
    private function applyFretiqFilters(QueryBuilder $query, MarketingScope $scope, array $filters, string $contact, string $company, string $campaign): void
    {
        if ($scope->selectionInvalid) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope->commercialUserId !== null) {
            $query->where($contact.'.assigned_to', $scope->commercialUserId);
        }
        if (isset($filters['campaign'])) {
            $query->where($campaign.'.id', ctype_digit((string) $filters['campaign']) ? (int) $filters['campaign'] : -1);
        }
        if (isset($filters['source'])) {
            $query->where($contact.'.source', $filters['source']);
        }
        if (isset($filters['country'])) {
            $query->where($company.'.country', $filters['country']);
        }
        if (isset($filters['sector'])) {
            $query->where($company.'.sector', $filters['sector']);
        }
        if (isset($filters['client_type'])) {
            $query->where($company.'.relationship', $filters['client_type']);
        }
    }

    private function pipeline(Builder $deals): array
    {
        $active = $this->openDeals($deals);
        $coverage = (clone $active)->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("COALESCE(SUM(CASE WHEN amount IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN 1 ELSE 0 END), 0) as amount_known")
            ->selectRaw('COALESCE(SUM(CASE WHEN amount IS NULL THEN 1 ELSE 0 END), 0) as amount_missing_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN TRIM(COALESCE(currency_code, '')) = '' THEN 1 ELSE 0 END), 0) as amount_missing_currency")
            ->selectRaw("COALESCE(SUM(CASE WHEN weighted_amount IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN 1 ELSE 0 END), 0) as weighted_known")
            ->selectRaw('COALESCE(SUM(CASE WHEN weighted_amount IS NULL THEN 1 ELSE 0 END), 0) as weighted_missing_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN TRIM(COALESCE(currency_code, '')) = '' THEN 1 ELSE 0 END), 0) as weighted_missing_currency")
            ->first();
        $count = (int) ($coverage?->aggregate ?? 0);
        $amountKnown = (int) ($coverage?->amount_known ?? 0);
        $amountMissingValue = (int) ($coverage?->amount_missing_value ?? 0);
        $amountMissingCurrency = (int) ($coverage?->amount_missing_currency ?? 0);
        $weightedKnown = (int) ($coverage?->weighted_known ?? 0);
        $weightedMissingValue = (int) ($coverage?->weighted_missing_value ?? 0);
        $weightedMissingCurrency = (int) ($coverage?->weighted_missing_currency ?? 0);
        $currencyRows = (clone $active)->whereRaw("TRIM(COALESCE(currency_code, '')) <> ''")->selectRaw('TRIM(currency_code) as currency_code')
            ->selectRaw('SUM(amount) as amount_total')->selectRaw('SUM(weighted_amount) as weighted_total')
            ->groupByRaw('TRIM(currency_code)')->get();
        $amountByCurrency = [];
        $weightedByCurrency = [];
        foreach ($currencyRows as $currencyRow) {
            $currency = (string) $currencyRow->currency_code;
            if ($currencyRow->amount_total !== null) {
                $amountByCurrency[$currency] = (float) $currencyRow->amount_total;
            }
            if ($currencyRow->weighted_total !== null) {
                $weightedByCurrency[$currency] = (float) $currencyRow->weighted_total;
            }
        }

        return [
            'count' => $count,
            'amount_known_count' => $amountKnown,
            'amount_unknown_count' => $count - $amountKnown,
            'amount_missing_value_count' => $amountMissingValue,
            'amount_missing_currency_count' => $amountMissingCurrency,
            'weighted_known_count' => $weightedKnown,
            'weighted_unknown_count' => $count - $weightedKnown,
            'weighted_missing_value_count' => $weightedMissingValue,
            'weighted_missing_currency_count' => $weightedMissingCurrency,
            'amount_by_currency' => $amountByCurrency,
            'weighted_by_currency' => $weightedByCurrency,
        ];
    }

    private function quoteMetrics(Builder $quotes): array
    {
        $coverage = (clone $quotes)->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("COALESCE(SUM(CASE WHEN line_items_total_complete = 1 AND line_items_total IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN 1 ELSE 0 END), 0) as known_value")
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(line_items_total_complete, 0) <> 1 OR line_items_total IS NULL THEN 1 ELSE 0 END), 0) as missing_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN TRIM(COALESCE(currency_code, '')) = '' THEN 1 ELSE 0 END), 0) as missing_currency")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire gagnée' THEN 1 ELSE 0 END), 0) as won")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire perdue' THEN 1 ELSE 0 END), 0) as lost")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire gagnée' AND line_items_total_complete = 1 AND line_items_total IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN 1 ELSE 0 END), 0) as won_known_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire gagnée' AND (COALESCE(line_items_total_complete, 0) <> 1 OR line_items_total IS NULL) THEN 1 ELSE 0 END), 0) as won_missing_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire gagnée' AND TRIM(COALESCE(currency_code, '')) = '' THEN 1 ELSE 0 END), 0) as won_missing_currency")
            ->first();
        $total = (int) ($coverage?->aggregate ?? 0);
        $knownValue = (int) ($coverage?->known_value ?? 0);
        $missingValue = (int) ($coverage?->missing_value ?? 0);
        $missingCurrency = (int) ($coverage?->missing_currency ?? 0);
        $won = (int) ($coverage?->won ?? 0);
        $lost = (int) ($coverage?->lost ?? 0);
        $wonKnownValue = (int) ($coverage?->won_known_value ?? 0);
        $wonMissingValue = (int) ($coverage?->won_missing_value ?? 0);
        $wonMissingCurrency = (int) ($coverage?->won_missing_currency ?? 0);
        $currencyRows = (clone $quotes)->whereRaw("TRIM(COALESCE(currency_code, '')) <> ''")->selectRaw('TRIM(currency_code) as currency_code')
            ->selectRaw('SUM(CASE WHEN line_items_total_complete = 1 AND line_items_total IS NOT NULL THEN line_items_total ELSE NULL END) as quote_total')
            ->selectRaw("SUM(CASE WHEN LOWER(TRIM(follow_up_status)) = 'affaire gagnée' AND line_items_total_complete = 1 AND line_items_total IS NOT NULL THEN line_items_total ELSE NULL END) as won_total")
            ->groupByRaw('TRIM(currency_code)')->get();
        $valueByCurrency = [];
        $wonValueByCurrency = [];
        foreach ($currencyRows as $currencyRow) {
            $currency = (string) $currencyRow->currency_code;
            if ($currencyRow->quote_total !== null) {
                $valueByCurrency[$currency] = (float) $currencyRow->quote_total;
            }
            if ($currencyRow->won_total !== null) {
                $wonValueByCurrency[$currency] = (float) $currencyRow->won_total;
            }
        }
        $decision = $this->quoteDecisionMetrics($quotes, $won + $lost);

        return [
            'count' => $total, 'known_value_count' => $knownValue, 'unknown_value_count' => $total - $knownValue,
            'missing_value_count' => $missingValue,
            'unknown_currency_count' => $missingCurrency, 'missing_currency_count' => $missingCurrency,
            'value_by_currency' => $valueByCurrency,
            'won_count' => $won, 'lost_count' => $lost, 'open_count' => max(0, $total - $won - $lost),
            'won_known_value_count' => $wonKnownValue,
            'won_unknown_value_count' => $won - $wonKnownValue,
            'won_missing_value_count' => $wonMissingValue,
            'won_missing_currency_count' => $wonMissingCurrency,
            'won_value_by_currency' => $wonValueByCurrency,
            'win_rate' => $this->rate($won, $won + $lost),
            ...$decision,
        ];
    }

    /** @return array<string,mixed> */
    private function dealOutcomes(Builder $deals): array
    {
        $normalized = "LOWER(TRIM(REPLACE(stage, '  ', ' ')))";
        $rows = (clone $deals)->selectRaw('TRIM(currency_code) as currency_code')->selectRaw('COUNT(*) as aggregate')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalized} IN ('closed won', 'affaire gagné') THEN 1 ELSE 0 END), 0) as won")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalized} IN ('closed lost', 'affaire perdu', 'closed lost to competition') THEN 1 ELSE 0 END), 0) as lost")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalized} IN ('closed won', 'affaire gagné') AND amount IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN 1 ELSE 0 END), 0) as won_known_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalized} IN ('closed won', 'affaire gagné') AND amount IS NULL THEN 1 ELSE 0 END), 0) as won_missing_value")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$normalized} IN ('closed won', 'affaire gagné') AND TRIM(COALESCE(currency_code, '')) = '' THEN 1 ELSE 0 END), 0) as won_missing_currency")
            ->selectRaw("SUM(CASE WHEN {$normalized} IN ('closed won', 'affaire gagné') AND amount IS NOT NULL AND TRIM(COALESCE(currency_code, '')) <> '' THEN amount ELSE NULL END) as won_total")
            ->groupByRaw('TRIM(currency_code)')->get();
        $total = $won = $lost = $wonKnownValue = $wonMissingValue = $wonMissingCurrency = 0;
        $wonValueByCurrency = [];
        foreach ($rows as $row) {
            $total += (int) $row->aggregate;
            $won += (int) $row->won;
            $lost += (int) $row->lost;
            $wonKnownValue += (int) $row->won_known_value;
            $wonMissingValue += (int) $row->won_missing_value;
            $wonMissingCurrency += (int) $row->won_missing_currency;
            $currency = trim((string) $row->currency_code);
            if ($currency !== '' && $row->won_total !== null) {
                $wonValueByCurrency[$currency] = (float) $row->won_total;
            }
        }

        return [
            'won' => $won, 'lost' => $lost, 'open' => max(0, $total - $won - $lost),
            'won_known_value_count' => $wonKnownValue,
            'won_unknown_value_count' => $won - $wonKnownValue,
            'won_missing_value_count' => $wonMissingValue,
            'won_missing_currency_count' => $wonMissingCurrency,
            'won_value_by_currency' => $wonValueByCurrency,
        ];
    }

    private static function normalizeStatus(?string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?: '';
    }

    private function openDeals(Builder $deals): Builder
    {
        return $deals->where(function (Builder $query): void {
            $query->whereNull('stage')->orWhereNotIn(
                DB::raw("LOWER(TRIM(REPLACE(stage, '  ', ' ')))"),
                array_merge(self::TERMINAL_WON, self::TERMINAL_LOST),
            );
        });
    }

    private function openQuotes(Builder $quotes): Builder
    {
        return $quotes->where(function (Builder $query): void {
            $query->whereNull('follow_up_status')->orWhereNotIn(
                DB::raw('LOWER(TRIM(follow_up_status))'),
                ['affaire gagnée', 'affaire perdue'],
            );
        });
    }

    /** @return array<string,int> */
    private function breakdown(Builder $query, string $column): array
    {
        return $query->selectRaw("COALESCE({$column}, 'Non renseigné') as label, COUNT(*) as aggregate")
            ->groupBy($column)->pluck('aggregate', 'label')->map(fn ($value) => (int) $value)->all();
    }

    private function breakdowns(Builder $leads, Builder $deals, Builder $quotes): array
    {
        return [
            'commercial' => $this->commercialBreakdown(clone $deals),
            'source' => $this->breakdown(clone $leads, 'lead_source'),
            'sector' => $this->breakdown(clone $leads, 'industry'),
            'country' => $this->breakdown(clone $leads, 'country'),
            'transport' => $this->transportBreakdown(clone $quotes),
            'lane' => $this->laneBreakdown(clone $quotes),
        ];
    }

    /** @return list<array{value:string,label:string,count:int}> */
    private function commercialBreakdown(Builder $deals): array
    {
        $counts = $deals->selectRaw('owner_zoho_id, COUNT(*) as aggregate')->groupBy('owner_zoho_id')->get();
        $ownerIds = $counts->pluck('owner_zoho_id')->filter()->map(fn ($id): string => (string) $id)->values();
        $mappings = collect();
        if ($this->mappingAvailable() && $ownerIds->isNotEmpty()) {
            $mappings = DB::table('zoho_user_mappings as mapping')->join('users as commercial', 'commercial.id', '=', 'mapping.fretiq_user_id')
                ->where('mapping.is_confirmed', true)->whereIn('mapping.zoho_user_id', $ownerIds)
                ->get(['mapping.zoho_user_id', 'commercial.id as commercial_id', 'commercial.name']);
        }

        $byOwner = $mappings->keyBy(fn ($mapping): string => (string) $mapping->zoho_user_id);
        $buckets = [];
        foreach ($counts as $count) {
            $ownerId = $count->owner_zoho_id === null ? null : (string) $count->owner_zoho_id;
            $mapping = $ownerId === null ? null : $byOwner->get($ownerId);
            $value = $mapping ? (string) $mapping->commercial_id : ($ownerId === null ? 'unassigned' : 'unmapped');
            $label = $mapping ? (string) $mapping->name : ($ownerId === null ? 'Non attribué' : 'Non mappé');
            $buckets[$value] ??= ['value' => $value, 'label' => $label, 'count' => 0];
            $buckets[$value]['count'] += (int) $count->aggregate;
        }
        usort($buckets, fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']) ?: strnatcasecmp($left['value'], $right['value']));

        return array_values($buckets);
    }

    private function laneBreakdown(Builder $quotes): array
    {
        return $quotes->selectRaw("CONCAT(COALESCE(origin, '—'), ' → ', COALESCE(destination, '—')) as label, COUNT(*) as aggregate")
            ->groupBy('origin', 'destination')->pluck('aggregate', 'label')->map(fn ($value) => (int) $value)->all();
    }

    private function transportBreakdown(Builder $quotes): array
    {
        $counts = [];
        foreach ($quotes->whereNotNull('transport_type')->select('transport_type')->cursor() as $quote) {
            $raw = $quote->transport_type;
            $values = is_array($raw) ? $raw : json_decode((string) $raw, true);
            foreach (is_array($values) ? $values : [] as $value) {
                $label = trim((string) $value);
                if ($label !== '') {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @param array{0:mixed,1:mixed} $bounds */
    private function historyTransitions(Builder $deals, Builder $quotes, array $bounds): array
    {
        $dealTransitions = DB::table('zoho_deal_stage_history')->whereNull('zoho_deleted_at')->whereBetween('occurred_at', $bounds)
            ->whereIn('deal_zoho_id', (clone $deals)->select('zoho_id'))->selectRaw("COALESCE(stage, 'Non renseigné') as label, COUNT(*) as aggregate")
            ->groupBy('stage')->pluck('aggregate', 'label')->map(fn ($value) => (int) $value)->all();
        $quoteTransitions = DB::table('zoho_quote_status_history')->whereNull('zoho_deleted_at')->whereBetween('occurred_at', $bounds)
            ->whereNotNull('previous_status')
            ->whereIn('quote_zoho_id', (clone $quotes)->select('zoho_id'))->selectRaw("COALESCE(status, 'Non renseigné') as label, COUNT(*) as aggregate")
            ->groupBy('status')->pluck('aggregate', 'label')->map(fn ($value) => (int) $value)->all();

        return ['deals' => $dealTransitions, 'quotes' => $quoteTransitions];
    }

    /** @param array<string,scalar|null> $filters */
    private function trends(MarketingPeriod $period, MarketingScope $scope, array $filters, Builder $deals): array
    {
        $bounds = $period->databaseBounds();
        $out = [];
        foreach (['zoho_leads' => 'leads', 'zoho_deals' => 'deals', 'zoho_quotes' => 'quotes'] as $table => $name) {
            $out[$name] = $this->dateTrend($this->scoped($table, $scope, $filters)->whereBetween('zoho_created_at', $bounds), 'zoho_created_at', $period->timezone);
        }
        $wins = DB::table('zoho_deal_stage_history')->whereNull('zoho_deleted_at')->whereBetween('occurred_at', $bounds)
            ->whereIn('deal_zoho_id', (clone $deals)->select('zoho_id'))->whereIn(DB::raw("LOWER(TRIM(REPLACE(stage, '  ', ' ')))"), self::TERMINAL_WON);
        $out['wins'] = $this->dateTrend($wins, 'occurred_at', $period->timezone);
        $out['demandes'] = $this->dateTrend($this->demandeQuery($bounds, $scope, $filters), 'demande.captured_at', $period->timezone);

        return $out;
    }

    private function dateTrend(Builder|QueryBuilder $query, string $column, string $timezone): array
    {
        $counts = [];
        foreach ($query->selectRaw($column.' as event_at')->cursor() as $event) {
            $value = $event->event_at;
            if ($value === null) {
                continue;
            }
            $date = CarbonImmutable::parse((string) $value, 'UTC')->setTimezone($timezone)->toDateString();
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function attention(Builder $leads, Builder $deals, Builder $quotes, MarketingScope $scope): array
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(14);
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();
        $openLeads = (clone $leads)->where('zoho_created_at', '<=', $cutoff)->where(function (Builder $query): void {
            $query->whereNull('status')->orWhereNotIn(DB::raw('LOWER(TRIM(status))'), ['converted', 'unqualified', 'not interested']);
        });
        $openDeals = $this->openDeals(clone $deals)->where('zoho_created_at', '<=', $cutoff);

        return [
            'quotes_expiring_7_days' => $this->openQuotes(clone $quotes)->whereBetween('valid_till', [$today, CarbonImmutable::now('Europe/Paris')->addDays(7)->toDateString()])->count(),
            'open_leads_without_activity_14_days' => $this->withoutRecentActivity($openLeads, $cutoff)->count(),
            'open_deals_without_activity_14_days' => $this->withoutRecentActivity($openDeals, $cutoff)->count(),
            'unassigned' => ['leads' => (clone $leads)->whereNull('owner_zoho_id')->count(), 'deals' => (clone $deals)->whereNull('owner_zoho_id')->count(), 'quotes' => (clone $quotes)->whereNull('owner_zoho_id')->count()],
            'sync_failures' => $scope->commercialUserId === null ? ZohoSyncFailure::query()->whereNull('resolved_at')->count() : 0,
            'stale_modules' => $this->staleModules(),
        ];
    }

    private function withoutRecentActivity(Builder $records, CarbonImmutable $cutoff): Builder
    {
        $recordId = $records->getModel()->qualifyColumn('zoho_id');

        return $records->whereNotExists(function (QueryBuilder $activity) use ($recordId, $cutoff): void {
            $activity->selectRaw('1')->from('zoho_activities as recent_activity')
                ->whereColumn('recent_activity.parent_zoho_id', $recordId)
                ->whereNull('recent_activity.zoho_deleted_at')
                ->where('recent_activity.activity_at', '>=', $cutoff);
        });
    }

    private function staleModules(): array
    {
        $modules = ['leads', 'accounts', 'contacts', 'deals', 'quotes'];
        $logs = ZohoSyncLog::query()->whereNotNull('sync_batch_id')->whereIn('module', $modules)->where('status', 'success')
            ->select('module')
            ->selectRaw("MAX(CASE WHEN mode <> 'reconcile' THEN synced_at END) as hourly_at")
            ->selectRaw("MAX(CASE WHEN mode = 'reconcile' THEN synced_at END) as nightly_at")
            ->groupBy('module')->get()->keyBy('module');
        $stale = [];
        foreach ($modules as $module) {
            $hourly = $logs->get($module)?->hourly_at;
            $nightly = $logs->get($module)?->nightly_at;
            $hourlyState = $this->freshnessState($hourly, 90, 180);
            $nightlyState = $this->freshnessState($nightly, 30 * 60, 48 * 60, true);
            if ($hourlyState !== 'green' || $nightlyState !== 'green') {
                $stale[$module] = ['hourly' => $hourlyState, 'nightly' => $nightlyState];
            }
        }

        return $stale;
    }

    private function freshnessState(mixed $at, int $warningMinutes, int $criticalMinutes, bool $warningInclusive = false): string
    {
        if (! $at) {
            return 'critical_no_success';
        }
        $minutes = CarbonImmutable::parse((string) $at, 'UTC')->diffInMinutes(CarbonImmutable::now('UTC'));
        $warning = $warningInclusive ? $minutes >= $warningMinutes : $minutes > $warningMinutes;

        return $minutes > $criticalMinutes ? 'critical' : ($warning ? 'warning' : 'green');
    }

    /** @param array<string,scalar|null> $filters */
    private function identityCoverage(MarketingScope $scope, array $filters): array
    {
        $label = 'Couverture des identités déterministes — sans attribution causale';
        if (! $scope->crmVisible) {
            return ['kind' => 'identity_coverage', 'label' => $label, 'count' => 0, 'coverage' => ['matched' => 0, 'eligible' => null, 'unknown' => true, 'rate' => null]];
        }
        $leads = $this->scoped('zoho_leads', $scope, $filters);
        $contacts = $this->scoped('zoho_contacts', $scope, $filters);
        if (array_intersect_key($filters, array_flip(['source', 'sector', 'lead_source'])) !== []) {
            $contacts->whereRaw('1 = 0');
        }
        $links = DB::table('zoho_marketing_links as link')->join('contacts as matched_contact', function ($join): void {
            $join->on('matched_contact.id', '=', 'link.fretiq_entity_id')->where('link.fretiq_entity_type', '=', 'contact');
        })->where('link.match_type', 'exact_email')->where('link.is_active', true)->whereNull('matched_contact.deleted_at')
            ->when($scope->commercialUserId !== null, fn (QueryBuilder $query) => $query->where('matched_contact.assigned_to', $scope->commercialUserId))
            ->where(function (QueryBuilder $query) use ($leads, $contacts): void {
                $query->where(function (QueryBuilder $module) use ($leads): void {
                    $module->whereRaw('LOWER(link.zoho_module) = ?', ['leads'])->whereIn('link.zoho_record_id', (clone $leads)->select('zoho_id'));
                })->orWhere(function (QueryBuilder $module) use ($contacts): void {
                    $module->whereRaw('LOWER(link.zoho_module) = ?', ['contacts'])->whereIn('link.zoho_record_id', (clone $contacts)->select('zoho_id'));
                });
            });
        $distinctRecords = $links->selectRaw('LOWER(link.zoho_module) as module_key')->addSelect('link.zoho_record_id')->distinct();
        $coverage = DB::query()
            ->selectSub(DB::query()->fromSub($distinctRecords, 'identity_records')->selectRaw('COUNT(*)'), 'matched')
            ->selectSub((clone $leads)->selectRaw('COUNT(*)'), 'eligible_leads')
            ->selectSub((clone $contacts)->selectRaw('COUNT(*)'), 'eligible_contacts')
            ->first();
        $matched = (int) ($coverage->matched ?? 0);
        $eligible = (int) ($coverage->eligible_leads ?? 0) + (int) ($coverage->eligible_contacts ?? 0);

        return ['kind' => 'identity_coverage', 'label' => $label, 'count' => $matched, 'coverage' => ['matched' => $matched, 'eligible' => $eligible, 'unknown' => false, 'rate' => $this->rate($matched, $eligible)]];
    }

    /** @param array<string,scalar|null> $filters */
    private function touchTimeline(MarketingPeriod $period, MarketingScope $scope, array $filters): array
    {
        $bounds = $period->databaseBounds();
        $recipientBase = DB::table('campaign_recipients as r')->join('campaign_runs as run', 'run.id', '=', 'r.campaign_run_id')
            ->join('campaigns as campaign', 'campaign.id', '=', 'run.campaign_id')->join('contacts as contact', 'contact.id', '=', 'r.contact_id')
            ->leftJoin('companies as company', 'company.id', '=', 'contact.company_id');
        $this->applyFretiqFilters($recipientBase, $scope, $filters, 'contact', 'company', 'campaign');
        $this->requireCurrentDeterministicLink($recipientBase, $scope, 'contact');

        $sent = (clone $recipientBase)
            ->where(fn (QueryBuilder $query) => $query->whereNotNull('r.sent_at')->orWhereIn('r.status', ['sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed']))
            ->whereBetween(DB::raw('COALESCE(r.sent_at, run.run_at)'), $bounds)
            ->selectRaw("'sent' as event_type, COALESCE(r.sent_at, run.run_at) as event_at, 'contact' as fretiq_entity_type, r.contact_id as fretiq_entity_id, run.campaign_id");
        $events = $sent;
        foreach (['opened' => 'opened_at', 'clicked' => 'clicked_at', 'replied' => 'replied_at', 'bounced' => 'bounced_at'] as $event => $column) {
            $query = (clone $recipientBase)->whereBetween('r.'.$column, $bounds)
                ->selectRaw("? as event_type, r.{$column} as event_at, 'contact' as fretiq_entity_type, r.contact_id as fretiq_entity_id, run.campaign_id", [$event]);
            $events->unionAll($query);
        }
        $demandes = $this->demandeQuery($bounds, $scope, $filters);
        $this->requireCurrentDeterministicLink($demandes, $scope, 'contact');
        $demandes = $demandes
            ->selectRaw("'demande' as event_type, demande.captured_at as event_at, 'contact' as fretiq_entity_type, demande.contact_id as fretiq_entity_id, demande.campaign_id");
        $events->unionAll($demandes);

        $timeline = DB::query()->fromSub($events, 'fretiq_timeline');
        $count = (clone $timeline)->count();
        $items = (clone $timeline)->orderByDesc('event_at')->limit(100)->get(['event_type', 'event_at'])
            ->map(fn ($event): array => ['event_type' => (string) $event->event_type, 'event_at' => (string) $event->event_at])->all();

        return [
            'kind' => 'fretiq_lifecycle_timeline',
            'label' => 'Interactions Fretiq observées — sans attribution causale',
            'count' => $count,
            'items' => $items,
            'items_truncated' => $count > count($items),
        ];
    }

    /** Require a non-deleted Leads/Contacts record behind an exact-email link without multiplying Fretiq events. */
    private function requireCurrentDeterministicLink(QueryBuilder $query, MarketingScope $scope, string $contactAlias): void
    {
        if (! $scope->crmVisible) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull($contactAlias.'.deleted_at')->whereExists(function (QueryBuilder $link) use ($scope, $contactAlias): void {
            $link->selectRaw('1')->from('zoho_marketing_links as link')
                ->whereColumn('link.fretiq_entity_id', $contactAlias.'.id')
                ->where('link.fretiq_entity_type', 'contact')->where('link.match_type', 'exact_email')->where('link.is_active', true)
                ->where(function (QueryBuilder $record): void {
                    $record->where(function (QueryBuilder $lead): void {
                        $lead->whereRaw('LOWER(link.zoho_module) = ?', ['leads'])->whereExists(function (QueryBuilder $current): void {
                            $current->selectRaw('1')->from('zoho_leads as current_lead')->whereColumn('current_lead.zoho_id', 'link.zoho_record_id')->whereNull('current_lead.zoho_deleted_at');
                        });
                    })->orWhere(function (QueryBuilder $contact): void {
                        $contact->whereRaw('LOWER(link.zoho_module) = ?', ['contacts'])->whereExists(function (QueryBuilder $current): void {
                            $current->selectRaw('1')->from('zoho_contacts as current_contact')->whereColumn('current_contact.zoho_id', 'link.zoho_record_id')->whereNull('current_contact.zoho_deleted_at');
                        });
                    });
                });

            if ($scope->ownerIds !== []) {
                $link->where(function (QueryBuilder $owner) use ($scope): void {
                    $owner->where(function (QueryBuilder $lead) use ($scope): void {
                        $lead->whereRaw('LOWER(link.zoho_module) = ?', ['leads'])->whereExists(function (QueryBuilder $current) use ($scope): void {
                            $current->selectRaw('1')->from('zoho_leads as owner_lead')->whereColumn('owner_lead.zoho_id', 'link.zoho_record_id')->whereIn('owner_lead.owner_zoho_id', $scope->ownerIds)->whereNull('owner_lead.zoho_deleted_at');
                        });
                    })->orWhere(function (QueryBuilder $contact) use ($scope): void {
                        $contact->whereRaw('LOWER(link.zoho_module) = ?', ['contacts'])->whereExists(function (QueryBuilder $current) use ($scope): void {
                            $current->selectRaw('1')->from('zoho_contacts as owner_contact')->whereColumn('owner_contact.zoho_id', 'link.zoho_record_id')->whereIn('owner_contact.owner_zoho_id', $scope->ownerIds)->whereNull('owner_contact.zoho_deleted_at');
                        });
                    });
                });
            }
        });
    }

    /** @param array{0:mixed,1:mixed} $bounds @param array<string,scalar|null> $filters */
    private function fretiqCount(Builder $query, array $bounds, MarketingScope $scope, string $kind, array $filters): int
    {
        if ($scope->selectionInvalid) {
            return 0;
        }

        $query->whereBetween('created_at', $bounds);
        if ($scope->commercialUserId !== null) {
            $kind === 'companies'
                ? $query->whereHas('contacts', fn (Builder $contacts) => $contacts->where('assigned_to', $scope->commercialUserId))
                : $query->where('assigned_to', $scope->commercialUserId);
        }
        if ($kind === 'companies') {
            foreach (['source', 'country', 'sector'] as $filter) {
                if (isset($filters[$filter])) {
                    $query->where($filter, $filters[$filter]);
                }
            }
            if (isset($filters['client_type'])) {
                $query->where('relationship', $filters['client_type']);
            }
        } else {
            if (isset($filters['source'])) {
                $query->where('source', $filters['source']);
            }
            $companyFilters = array_intersect_key($filters, array_flip(['country', 'sector', 'client_type']));
            if ($companyFilters) {
                $query->whereHas('company', function (Builder $company) use ($companyFilters): void {
                    foreach ($companyFilters as $filter => $value) {
                        $company->where($filter === 'client_type' ? 'relationship' : $filter, $value);
                    }
                });
            }
        }

        return $query->count();
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private function filterOptions(MarketingAnalyticsQuery $input, MarketingScope $scope): array
    {
        $options = $this->emptyFilterOptions();
        $user = $input->user;

        if ($user?->hasRole('commercial')) {
            $commercials = User::query()->whereKey($user->getKey())->where('is_active', true)->get(['id', 'name']);
        } elseif ($user?->hasAnyRole(['admin', 'superadmin'])) {
            $commercials = User::query()->where('is_active', true)->role('commercial')->get(['id', 'name']);
        } else {
            $commercials = collect();
        }

        $options['commercials'] = $this->labelledOptions($commercials
            ->mapWithKeys(fn (User $commercial): array => [(string) $commercial->getKey() => (string) $commercial->name])
            ->all());

        if ($scope->selectionInvalid) {
            return $options;
        }

        $companies = DB::table('companies');
        $contacts = DB::table('contacts')->whereNull('deleted_at');
        if ($scope->commercialUserId !== null) {
            $contacts->where('assigned_to', $scope->commercialUserId);
            $companies->whereExists(function (QueryBuilder $query) use ($scope): void {
                $query->selectRaw('1')->from('contacts as option_contact')
                    ->whereColumn('option_contact.company_id', 'companies.id')->whereNull('option_contact.deleted_at')
                    ->where('option_contact.assigned_to', $scope->commercialUserId);
            });
        }

        $leads = $this->scoped('zoho_leads', $scope, []);
        $accounts = $this->scoped('zoho_accounts', $scope, []);
        $zohoContacts = $this->scoped('zoho_contacts', $scope, []);
        $deals = $this->scoped('zoho_deals', $scope, []);
        $quotes = $this->scoped('zoho_quotes', $scope, []);

        $options['sources'] = $this->valueOptions($this->mergedValues([
            [clone $companies, 'source'], [clone $contacts, 'source'], [clone $leads, 'lead_source'],
        ]));
        $options['countries'] = $this->valueOptions($this->mergedValues([
            [clone $companies, 'country'], [clone $leads, 'country'], [clone $accounts, 'country'],
            [clone $zohoContacts, 'country'], [clone $quotes, 'country'],
        ]));
        $options['sectors'] = $this->valueOptions($this->mergedValues([
            [clone $companies, 'sector'], [clone $leads, 'industry'], [clone $accounts, 'industry'],
        ]));
        $options['client_types'] = $this->valueOptions($this->mergedValues([
            [clone $companies, 'relationship'], [clone $accounts, 'account_type'],
        ]));
        $options['lead_sources'] = $this->valueOptions($this->mergedValues([
            [clone $leads, 'lead_source'], [clone $deals, 'lead_source'],
        ]));
        $options['currencies'] = $this->valueOptions($this->mergedValues([
            [clone $deals, 'currency_code'], [clone $quotes, 'currency_code'],
        ]), true);

        $transports = [];
        foreach ((clone $quotes)->whereNotNull('transport_type')->select('transport_type')->cursor() as $quote) {
            $raw = $quote->transport_type;
            $values = is_array($raw) ? $raw : json_decode((string) $raw, true);
            foreach (is_array($values) ? $values : [] as $value) {
                $transports[] = $value;
            }
        }
        $options['transports'] = $this->valueOptions($transports);
        $options['campaigns'] = $this->campaignOptions($scope);

        return $options;
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private function fretiqFilterOptions(MarketingAnalyticsQuery $input, MarketingScope $scope): array
    {
        $options = $this->emptyFilterOptions();
        $user = $input->user;
        $commercials = $user?->hasRole('commercial')
            ? User::query()->whereKey($user->getKey())->where('is_active', true)->get(['id', 'name'])
            : ($user?->hasAnyRole(['admin', 'superadmin']) ? User::query()->where('is_active', true)->role('commercial')->get(['id', 'name']) : collect());
        $options['commercials'] = $this->labelledOptions($commercials->mapWithKeys(fn (User $commercial): array => [(string) $commercial->getKey() => (string) $commercial->name])->all());
        $companies = DB::table('companies');
        $contacts = DB::table('contacts')->whereNull('deleted_at');
        if ($scope->commercialUserId !== null) {
            $contacts->where('assigned_to', $scope->commercialUserId);
            $companies->whereExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('contacts as option_contact')->whereColumn('option_contact.company_id', 'companies.id')->whereNull('option_contact.deleted_at')->where('option_contact.assigned_to', $scope->commercialUserId));
        }
        $options['sources'] = $this->valueOptions($this->mergedValues([[clone $companies, 'source'], [clone $contacts, 'source']]));
        $options['countries'] = $this->valueOptions($this->mergedValues([[clone $companies, 'country']]));
        $options['sectors'] = $this->valueOptions($this->mergedValues([[clone $companies, 'sector']]));
        $options['client_types'] = $this->valueOptions($this->mergedValues([[clone $companies, 'relationship']]));
        $options['campaigns'] = $this->campaignOptions($scope);

        return $options;
    }

    /** @return list<array{value:string,label:string}> */
    private function campaignOptions(MarketingScope $scope): array
    {
        $campaigns = DB::table('campaigns')->select(['id', 'name']);
        if ($scope->commercialUserId !== null) {
            $campaigns->where(function (QueryBuilder $visible) use ($scope): void {
                $visible->whereExists(function (QueryBuilder $recipient) use ($scope): void {
                    $recipient->selectRaw('1')->from('campaign_recipients as option_recipient')
                        ->join('campaign_runs as option_run', 'option_run.id', '=', 'option_recipient.campaign_run_id')
                        ->join('contacts as option_contact', 'option_contact.id', '=', 'option_recipient.contact_id')
                        ->whereColumn('option_run.campaign_id', 'campaigns.id')->whereNull('option_contact.deleted_at')
                        ->where('option_contact.assigned_to', $scope->commercialUserId);
                })->orWhereExists(function (QueryBuilder $demande) use ($scope): void {
                    $demande->selectRaw('1')->from('demandes as option_demande')
                        ->join('contacts as option_contact', 'option_contact.id', '=', 'option_demande.contact_id')
                        ->whereColumn('option_demande.campaign_id', 'campaigns.id')->whereNull('option_contact.deleted_at')
                        ->where('option_contact.assigned_to', $scope->commercialUserId);
                });
            });
        }

        return $this->labelledOptions($campaigns->pluck('name', 'id')->mapWithKeys(
            fn ($label, $value): array => [(string) $value => (string) $label],
        )->all());
    }

    /** @param list<array{0:Builder|QueryBuilder,1:string}> $sources @return list<mixed> */
    private function mergedValues(array $sources): array
    {
        $union = null;
        foreach ($sources as [$query, $column]) {
            $part = $query->whereNotNull($column)->selectRaw("{$column} as option_value");
            $part = $part instanceof Builder ? $part->toBase() : $part;
            $union = $union === null ? $part : $union->unionAll($part);
        }

        if ($union === null) {
            return [];
        }

        return DB::query()->fromSub($union, 'marketing_filter_values')
            ->whereNotNull('option_value')->distinct()->orderBy('option_value')->limit(500)->pluck('option_value')->all();
    }

    /** @param iterable<mixed> $values @return list<array{value:string,label:string}> */
    private function valueOptions(iterable $values, bool $uppercase = false): array
    {
        $labels = [];
        foreach ($values as $raw) {
            if (! is_scalar($raw)) {
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }
            if ($uppercase) {
                $value = mb_strtoupper($value);
            }
            $labels[$value] = $value;
        }

        return $this->labelledOptions($labels);
    }

    /** @param array<string,string> $labels @return list<array{value:string,label:string}> */
    private function labelledOptions(array $labels): array
    {
        uksort($labels, fn (string $left, string $right): int => strnatcasecmp($labels[$left], $labels[$right]) ?: strnatcasecmp($left, $right));
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    /** @return array<string,list<array{value:string,label:string}>> */
    private function emptyFilterOptions(): array
    {
        return array_fill_keys(['commercials', 'sources', 'campaigns', 'countries', 'sectors', 'transports', 'client_types', 'lead_sources', 'currencies'], []);
    }

    /** @param array<string,scalar|null> $filters */
    private function filterApplicability(array $filters): array
    {
        $map = [
            'commercial' => ['campaign', 'demandes', 'discovery', 'crm_leads', 'crm_accounts', 'crm_contacts', 'crm_deals', 'crm_quotes', 'identity_coverage', 'matched_touches'],
            'campaign' => ['campaign', 'demandes', 'matched_touches'],
            'source' => ['campaign', 'demandes', 'discovery', 'crm_leads', 'matched_touches'],
            'country' => ['campaign', 'demandes', 'discovery', 'crm_leads', 'crm_accounts', 'crm_contacts', 'crm_quotes', 'identity_coverage', 'matched_touches'],
            'sector' => ['campaign', 'demandes', 'discovery', 'crm_leads', 'crm_accounts', 'matched_touches'],
            'transport' => ['crm_quotes'],
            'client_type' => ['campaign', 'demandes', 'discovery', 'crm_accounts', 'matched_touches'],
            'lead_source' => ['crm_leads', 'crm_deals'],
            'currency' => ['crm_deals', 'crm_quotes'],
        ];
        $partial = array_fill_keys(['source', 'sector', 'lead_source'], [
            'identity_coverage' => [
                'applies_to_modules' => ['crm_leads'],
                'not_applicable_to_modules' => ['crm_contacts'],
            ],
        ]);
        $contexts = ['campaign', 'demandes', 'discovery', 'crm_leads', 'crm_accounts', 'crm_contacts', 'crm_deals', 'crm_quotes', 'identity_coverage', 'matched_touches'];
        $result = [];
        foreach ($filters as $filter => $_) {
            $applies = $map[$filter] ?? [];
            $partialContexts = array_keys($partial[$filter] ?? []);
            $result[$filter] = [
                'applies_to' => $applies,
                'partially_applies_to' => $partial[$filter] ?? [],
                'ignored_by' => array_values(array_diff($contexts, $applies, $partialContexts)),
            ];
        }

        return $result;
    }

    /** @return array{median_decision_days:?float,terminal_decision_known_count:int,terminal_decision_unknown_count:int} */
    private function quoteDecisionMetrics(Builder $quotes, int $terminalCount): array
    {
        $terminal = DB::table('zoho_quote_status_history as history')
            ->whereNull('history.zoho_deleted_at')->whereNotNull('history.previous_status')->whereNotNull('history.occurred_at')
            ->whereIn('history.status', ['Affaire gagnée', 'Affaire perdue'])
            ->whereNotExists(function (QueryBuilder $later): void {
                $later->selectRaw('1')->from('zoho_quote_status_history as later_history')
                    ->whereColumn('later_history.quote_zoho_id', 'history.quote_zoho_id')->whereNull('later_history.zoho_deleted_at')
                    ->where(function (QueryBuilder $order): void {
                        $order->where('later_history.occurred_at', '>', DB::raw('history.occurred_at'))
                            ->orWhere(function (QueryBuilder $tie): void {
                                $tie->whereColumn('later_history.occurred_at', 'history.occurred_at')->where('later_history.id', '>', DB::raw('history.id'));
                            });
                    });
            })->select(['history.quote_zoho_id', 'history.occurred_at as terminal_at']);
        $decisionRows = (clone $quotes)->whereIn('zoho_quotes.follow_up_status', ['Affaire gagnée', 'Affaire perdue'])
            ->joinSub($terminal, 'terminal', fn ($join) => $join->on('terminal.quote_zoho_id', '=', 'zoho_quotes.zoho_id'))
            ->whereNotNull('zoho_quotes.quote_date')
            ->selectRaw('GREATEST(0, DATEDIFF(terminal.terminal_at, zoho_quotes.quote_date)) as decision_days');
        $ordered = DB::query()->fromSub($decisionRows->toBase(), 'quote_decisions');
        $known = (clone $ordered)->count();
        if ($known === 0) {
            return ['median_decision_days' => null, 'terminal_decision_known_count' => 0, 'terminal_decision_unknown_count' => $terminalCount];
        }

        $lower = (float) (clone $ordered)->orderBy('decision_days')->offset(intdiv($known - 1, 2))->value('decision_days');
        $upper = (float) (clone $ordered)->orderBy('decision_days')->offset(intdiv($known, 2))->value('decision_days');

        return ['median_decision_days' => round(($lower + $upper) / 2, 2), 'terminal_decision_known_count' => $known, 'terminal_decision_unknown_count' => max(0, $terminalCount - $known)];

    }

    private function rate(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : null;
    }

    /** @param array<string,scalar|null> $filters */
    private function empty(MarketingAnalyticsQuery $input, array $filters, MarketingScope $scope, bool $unavailable): array
    {
        $campaign = ['counts' => ['sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'replied' => 0, 'demandes' => 0, 'conversions' => 0, 'engagement_rate' => null, 'demande_conversion_rate' => null], 'funnel' => []];

        return [
            'period' => $input->period->toArray(), 'filters' => $filters, 'filter_applicability' => $this->filterApplicability($filters),
            'filter_options' => $this->emptyFilterOptions(),
            'scope' => $scope->metadata(), 'campaign' => $campaign, 'kpis' => [], 'comparison' => [],
            'funnels' => ['campaign' => [], 'zoho' => []], 'trends' => ['leads' => [], 'deals' => [], 'quotes' => [], 'wins' => [], 'demandes' => []],
            'breakdowns' => [], 'attention' => [],
            'identity_coverage' => ['kind' => 'identity_coverage', 'label' => 'Couverture des identités déterministes — sans attribution causale', 'count' => 0, 'coverage' => ['matched' => 0, 'eligible' => null, 'unknown' => true, 'rate' => null]],
            'matched_touches' => ['kind' => 'fretiq_lifecycle_timeline', 'label' => 'Interactions Fretiq observées — sans attribution causale', 'count' => 0, 'items' => [], 'items_truncated' => false],
            'meta' => [
                'currency_policy' => 'native_currency_buckets_only', 'causal_attribution' => false,
                'demande_label' => 'Demandes observées — sans attribution de revenu',
                'comparison_basis' => 'current_state_outcomes_for_records_created_in_each_period',
                'pipeline_basis' => 'current_state_all_active_deals', 'pipeline_comparable' => false,
                'created_cohort_pipeline_basis' => 'current_state_of_deals_created_in_each_period',
                'win_trend_basis' => 'deal_stage_history_transitions', 'stale_or_unavailable' => $unavailable,
            ],
        ];
    }

    private function freshnessVersion(bool $crmAvailable, string $schemaFingerprint): string
    {
        $cacheKey = 'zoho-v2:marketing:freshness:v3:'.hash('sha256', implode('|', [
            DB::connection()->getName(),
            DB::connection()->getDatabaseName(),
            $schemaFingerprint,
        ]));

        return Cache::remember($cacheKey, now()->addSeconds(5), function () use ($crmAvailable, $schemaFingerprint): string {
            $query = DB::query()
                ->selectSub(DB::table('companies')->selectRaw('MAX(updated_at)'), 'company_at')
                ->selectSub(DB::table('campaign_runs')->selectRaw('MAX(updated_at)'), 'run_at')
                ->selectSub(DB::table('campaign_recipients')->selectRaw('MAX(updated_at)'), 'recipient_at')
                ->selectSub(DB::table('campaigns')->selectRaw('MAX(updated_at)'), 'campaign_at')
                ->selectSub(DB::table('demandes')->selectRaw('MAX(updated_at)'), 'demande_at')
                ->selectSub(DB::table('contacts')->selectRaw('MAX(updated_at)'), 'contact_at');

            if ($crmAvailable) {
                $query
                    ->selectSub(DB::table('zoho_sync_logs')->selectRaw('MAX(synced_at)'), 'sync_at')
                    ->selectSub(DB::table('zoho_marketing_links')->selectRaw('MAX(updated_at)'), 'link_at')
                    ->selectSub(DB::table('zoho_sync_failures')->selectRaw('MAX(updated_at)'), 'failure_at');
            }

            if ($this->mappingAvailable()) {
                $query->selectSub(DB::table('zoho_user_mappings')->selectRaw('MAX(updated_at)'), 'mapping_at');
            }

            $row = $query->first();

            return implode('|', [
                $schemaFingerprint,
                $crmAvailable ? (string) ($row->sync_at ?? '') : 'crm-unavailable',
                $crmAvailable ? (string) ($row->link_at ?? '') : 'crm-unavailable',
                (string) ($row->company_at ?? ''),
                (string) ($row->run_at ?? ''),
                (string) ($row->recipient_at ?? ''),
                (string) ($row->campaign_at ?? ''),
                (string) ($row->demande_at ?? ''),
                $crmAvailable ? (string) ($row->failure_at ?? '') : 'crm-unavailable',
                (string) ($row->contact_at ?? ''),
                $this->mappingAvailable() ? (string) ($row->mapping_at ?? '') : 'mapping-unavailable',
            ]);
        });
    }

    private function schemaFingerprint(bool $crmAvailable): string
    {
        $schema = $this->columns;
        ksort($schema);
        foreach ($schema as &$columns) {
            sort($columns);
        }
        unset($columns);

        return hash('sha256', json_encode([
            'crm_available' => $crmAvailable,
            'tables' => $schema,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $tables @return array<string,list<string>> */
    private function inspectColumns(array $tables): array
    {
        $columns = array_fill_keys($tables, []);
        if (DB::connection()->getDriverName() !== 'mysql') {
            foreach ($tables as $table) {
                $columns[$table] = Schema::getColumnListing($table);
            }

            return $columns;
        }

        $rows = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->whereIn('table_name', $tables)
            ->orderBy('ordinal_position')
            ->get(['table_name as marketing_table', 'column_name as marketing_column']);
        foreach ($rows as $row) {
            $columns[(string) $row->marketing_table][] = (string) $row->marketing_column;
        }

        return $columns;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if (! array_key_exists($table, $this->columns)) {
            $this->columns[$table] = Schema::getColumnListing($table);
        }

        return $this->columns[$table];
    }
}
