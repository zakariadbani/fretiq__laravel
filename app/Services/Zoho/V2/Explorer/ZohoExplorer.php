<?php

namespace App\Services\Zoho\V2\Explorer;

use App\Services\Zoho\V2\Access\ZohoPortfolioScope;
use App\Services\Zoho\V2\Access\ZohoPortfolioScopeResult;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/** Read-only query builder for the five reviewed CRM explorer modules. */
final class ZohoExplorer
{
    private const MODULES = ['leads', 'accounts', 'contacts', 'deals', 'quotes'];

    /** @var array<string, string> */
    private const FILTER_ALIASES = [
        'owner_zoho_id' => 'owner_zoho_id',
        'sector' => 'industry',
        'industry' => 'industry',
        'transport' => 'transport_type',
        'transport_type' => 'transport_type',
        'client_type' => 'account_type',
        'account_type' => 'account_type',
        'currency' => 'currency_code',
        'currency_code' => 'currency_code',
        'lead_source' => 'lead_source',
        'country' => 'country',
        'status' => 'status',
        'stage' => 'stage',
    ];

    /** @var list<string> */
    private const ACTIVITY_DETAIL_FIELDS = [
        'activity_type', 'zoho_id', 'subject', 'status', 'activity_at', 'due_at', 'start_at', 'end_at',
    ];

    /** @var list<string> */
    private const QUOTE_ITEM_DETAIL_FIELDS = [
        'zoho_quote_id', 'zoho_line_item_id', 'product_name', 'description', 'unit_of_measure',
        'quantity', 'list_price', 'unit_price', 'discount', 'tax', 'total', 'currency_code',
    ];

    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoPortfolioScope $portfolioScope,
    ) {}

    public function definition(string $module): ModuleDefinition
    {
        if (! in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Module CRM non autorisé.');
        }

        return $this->registry->get($module);
    }

    public function scopedQuery(string $module, Request $request): ZohoPortfolioScopeResult
    {
        $definition = $this->definition($module);
        /** @var Builder $query */
        $query = $definition->modelClass::query()->current();
        $scope = $this->portfolioScope->apply($query, $request->user());
        $this->applyReviewedFilters($scope->query, $definition, $request);

        return $scope;
    }

    public function available(string $module): bool
    {
        try {
            $definition = $this->definition($module);
        } catch (InvalidArgumentException) {
            return false;
        }
        if (! Schema::hasTable($definition->table)) {
            return false;
        }

        $required = array_values(array_unique(array_merge(
            ['id', 'zoho_id', 'owner_zoho_id', 'zoho_deleted_at', 'raw_payload'],
            $this->visibleFields($module),
        )));
        if (! Schema::hasColumns($definition->table, $required)) {
            return false;
        }

        if (! Schema::hasTable('zoho_user_mappings') || ! Schema::hasColumns('zoho_user_mappings', [
            'zoho_user_id', 'fretiq_user_id', 'is_confirmed',
        ])) {
            return false;
        }

        if (! Schema::hasTable('zoho_activities') || ! Schema::hasColumns('zoho_activities', array_merge(
            ['id', 'owner_zoho_id', 'parent_zoho_id', 'contact_zoho_id', 'zoho_deleted_at'],
            self::ACTIVITY_DETAIL_FIELDS,
        ))) {
            return false;
        }

        return $module !== 'quotes'
            || (Schema::hasTable('zoho_quote_items') && Schema::hasColumns('zoho_quote_items', array_merge(
                ['id', 'zoho_deleted_at'],
                self::QUOTE_ITEM_DETAIL_FIELDS,
            )));
    }

    public function detailQuery(string $module, Request $request, bool $includeRawPayload): ZohoPortfolioScopeResult
    {
        $scope = $this->scopedQuery($module, $request);
        $fields = array_values(array_unique(array_merge(
            ['id'],
            $this->visibleFields($module),
            $includeRawPayload ? ['raw_payload'] : [],
        )));
        $scope->query->select($fields);

        return $scope;
    }

    /** @return list<string> */
    public function visibleFields(string $module): array
    {
        return $this->definition($module)->visibilityAllowlist;
    }

    /** @return list<string> */
    public function listingFields(string $module): array
    {
        $preferred = match ($module) {
            'leads' => ['full_name', 'company_name', 'status', 'lead_source', 'country', 'owner_zoho_id', 'zoho_modified_at'],
            'accounts' => ['name', 'account_type', 'industry', 'country', 'owner_zoho_id', 'zoho_modified_at'],
            'contacts' => ['full_name', 'email', 'title', 'country', 'owner_zoho_id', 'zoho_modified_at'],
            'deals' => ['name', 'stage', 'amount', 'probability', 'currency_code', 'closing_date', 'owner_zoho_id'],
            'quotes' => ['quote_number', 'subject', 'follow_up_status', 'line_items_total', 'currency_code', 'valid_till', 'owner_zoho_id'],
        };

        return array_values(array_intersect($preferred, $this->visibleFields($module)));
    }

    public function canShowRawPayload(Request $request): bool
    {
        return (bool) $request->user()?->can('view zoho raw payload');
    }

    /** @return list<string> */
    public function activityDetailFields(): array
    {
        return self::ACTIVITY_DETAIL_FIELDS;
    }

    /** @return list<string> */
    public function quoteItemDetailFields(): array
    {
        return self::QUOTE_ITEM_DETAIL_FIELDS;
    }

    public function nestedActivities(Model $record, Request $request): Collection
    {
        $query = \App\Models\Zoho\ZohoActivity::query()->current()
            ->where(function (Builder $nested) use ($record): void {
                $nested->where('parent_zoho_id', $record->zoho_id)
                    ->orWhere('contact_zoho_id', $record->zoho_id);
            });

        return $this->portfolioScope->apply($query, $request->user())->query
            ->select(array_merge(['id'], self::ACTIVITY_DETAIL_FIELDS))
            ->orderByDesc('activity_at')
            ->limit(100)
            ->get();
    }

    public function quoteItems(Model $quote): Collection
    {
        return \App\Models\Zoho\ZohoQuoteItem::query()
            ->current()
            ->where('zoho_quote_id', $quote->zoho_id)
            ->select(array_merge(['id'], self::QUOTE_ITEM_DETAIL_FIELDS))
            ->orderBy('sequence')
            ->get();
    }

    /** @return list<string> */
    public function filterNotices(string $module, Request $request): array
    {
        $notices = [];
        if (is_string($request->input('campaign')) && trim($request->input('campaign')) !== '') {
            $notices[] = 'Le filtre campagne ne s’applique pas aux enregistrements Zoho sans lien marketing déterministe.';
        }
        if (is_string($request->input('source')) && trim($request->input('source')) !== ''
            && ! in_array('lead_source', $this->visibleFields($module), true)) {
            $notices[] = 'Le filtre source n’est pas disponible pour ce module Zoho.';
        }

        if ($request->filled('period') && $this->periodBounds($request) === null) {
            $notices[] = 'Le filtre de période est invalide et n’a pas été appliqué.';
        }

        return $notices;
    }

    /** @return array<string, mixed> */
    public function preservedFilters(Request $request): array
    {
        $preserved = [];
        $keys = array_values(array_unique(array_merge(
            ['period', 'from', 'to', 'campaign', 'source', 'commercial'],
            array_keys(self::FILTER_ALIASES),
        )));
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $preserved[$key] = mb_substr(trim((string) $value), 0, 255);
        }

        return $preserved;
    }

    private function applyReviewedFilters(Builder $query, ModuleDefinition $definition, Request $request): void
    {
        $allowed = array_flip($definition->visibilityAllowlist);
        if ($request->has('period')) {
            $period = $request->input('period');
            if (! is_string($period) || trim($period) === '') {
                abort(422, 'La période demandée est invalide.');
            }
            $bounds = $this->periodBounds($request);
            if ($bounds === null) {
                abort(422, 'La période demandée est invalide.');
            }
            if (isset($allowed['zoho_created_at'])) {
                $query->whereBetween('zoho_created_at', $bounds);
            }
        }
        $this->applyCommercialFilter($query, $request);

        $filters = self::FILTER_ALIASES;
        if ($definition->key === 'leads' && isset($allowed['lead_source'])) {
            $filters['source'] = 'lead_source';
        }

        foreach ($filters as $input => $column) {
            $value = $request->input($input);
            if (! isset($allowed[$column]) || ! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = mb_substr(trim($value), 0, 255);

            if ($column === 'transport_type') {
                $query->whereJsonContains($column, $value);
            } else {
                $query->where($column, $value);
            }
        }
    }

    private function applyCommercialFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('commercial')) {
            return;
        }

        $rawCommercial = $request->input('commercial');
        if (! is_scalar($rawCommercial)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $commercial = trim((string) $rawCommercial);
        if (! ctype_digit($commercial) || ! Schema::hasTable('zoho_user_mappings')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $owners = DB::table('zoho_user_mappings')
            ->where('fretiq_user_id', (int) $commercial)
            ->where('is_confirmed', true)
            ->pluck('zoho_user_id')
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($owners === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('owner_zoho_id', $owners);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    private function periodBounds(Request $request): ?array
    {
        $rawPreset = $request->input('period', '');
        if (! is_string($rawPreset)) {
            return null;
        }
        $preset = trim($rawPreset);
        if ($preset === '') {
            return null;
        }

        try {
            $now = CarbonImmutable::now('Europe/Paris');
            [$start, $end] = match ($preset) {
                '7d' => [$now->startOfDay()->subDays(6), $now->endOfDay()],
                '30d' => [$now->startOfDay()->subDays(29), $now->endOfDay()],
                '90d' => [$now->startOfDay()->subDays(89), $now->endOfDay()],
                'qtd' => [$now->firstOfQuarter()->startOfDay(), $now->endOfDay()],
                'ytd' => [$now->startOfYear()->startOfDay(), $now->endOfDay()],
                'custom' => $this->customPeriod($request),
                default => [null, null],
            };
        } catch (Throwable) {
            return null;
        }

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable || $end->lessThan($start)) {
            return null;
        }

        return [$start->utc(), $end->utc()];
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private function customPeriod(Request $request): array
    {
        $rawFrom = $request->input('from', '');
        $rawTo = $request->input('to', '');
        if (! is_string($rawFrom) || ! is_string($rawTo)) {
            return [null, null];
        }
        $from = trim($rawFrom);
        $to = trim($rawTo);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [null, null];
        }

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'Europe/Paris');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'Europe/Paris');
        if ($start->toDateString() !== $from || $end->toDateString() !== $to) {
            return [null, null];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }
}
