<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Zoho\V2\Marketing\MarketingAnalyticsQuery;
use App\Services\Zoho\V2\Marketing\MarketingPeriod;
use App\Services\Zoho\V2\Marketing\ZohoMarketingAnalytics;
use App\Services\Zoho\V2\Marketing\ZohoCeoControlTower;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Read-only marketing and commercial intelligence; Zoho remains the source of truth. */
final class MarketingDashboardController extends Controller
{
    private const FILTERS = ['commercial', 'source', 'campaign', 'country', 'sector', 'transport', 'client_type', 'lead_source', 'currency'];

    public function __construct()
    {
        $this->middleware(['auth', 'verified', 'permission:view marketing dashboard']);
    }

    public function index(Request $request, ZohoMarketingAnalytics $analytics, ZohoCeoControlTower $controlTower)
    {
        $permitted = ['period', 'from', 'to', ...self::FILTERS];
        $unexpected = array_values(array_diff(array_keys($request->query()), $permitted));
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['filters' => 'Un filtre non autorisé a été refusé.']);
        }

        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:7d,30d,90d,365d,qtd,ytd,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'commercial' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:120'],
            'campaign' => ['nullable', 'integer'],
            'country' => ['nullable', 'string', 'max:120'],
            'sector' => ['nullable', 'string', 'max:120'],
            'transport' => ['nullable', 'string', 'max:120'],
            'client_type' => ['nullable', 'string', 'max:120'],
            'lead_source' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:12'],
        ]);

        try {
            $period = MarketingPeriod::fromInput([
                'preset' => $data['period'] ?? '90d',
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['period' => $exception->getMessage()]);
        }

        $filters = array_filter(
            array_intersect_key($data, array_flip(self::FILTERS)),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        // A commercial can only ever view their own CRM scope. Normalize the
        // request before building both analytics metadata and Explorer links so
        // neither can disclose or claim a different commercial selection.
        if ($request->user()->hasRole('commercial') && array_key_exists('commercial', $filters)) {
            $filters['commercial'] = (string) $request->user()->getKey();
            $data['commercial'] = $request->user()->getKey();
            $request->query->set('commercial', $request->user()->getKey());
        }

        $query = new MarketingAnalyticsQuery($request->user(), $period, $filters);
        $scope = $analytics->scopeFor($query);
        $ceo = $controlTower->analyse($query, $scope);
        $canUseExplorer = $request->user()->can('view zoho records');

        return view('backend.contents.marketing-dashboard.index', [
            'dashboard' => ['scope' => $scope->metadata(), 'meta' => [
                'stale_or_unavailable' => in_array(
                    $ceo['freshness']['state'] ?? null,
                    ['Indisponible', 'Périmètre indisponible'],
                    true,
                )
                    || ($ceo['freshness']['stale'] ?? false) === true,
            ]],
            'ceo' => $ceo,
            'controls' => ['period' => $data['period'] ?? '90d', 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'drilldowns' => $canUseExplorer ? $this->drilldowns($data) : [],
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function drilldowns(array $data): array
    {
        $safe = array_filter(
            array_intersect_key($data, ['commercial' => true]),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
        $query = http_build_query($safe);
        $suffix = $query === '' ? '' : '?'.$query;

        // Explorer periods and module filters use record creation dates and fields
        // that do not match this dashboard's evidence windows. Only the authorized
        // commercial scope is safe to carry without hiding the records just shown.
        return collect(['accounts', 'contacts', 'deals'])
            ->mapWithKeys(fn (string $module): array => [$module => url('/admin/zoho/records/'.$module).$suffix])
            ->all();
    }
}
