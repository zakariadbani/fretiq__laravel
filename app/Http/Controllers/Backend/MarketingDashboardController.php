<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Zoho\V2\Marketing\MarketingAnalyticsQuery;
use App\Services\Zoho\V2\Marketing\MarketingPeriod;
use App\Services\Zoho\V2\Marketing\ZohoMarketingAnalytics;
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

    public function index(Request $request, ZohoMarketingAnalytics $analytics)
    {
        abort_unless((bool) config('zoho-v2.features.marketing_dashboard_enabled', false), 404);

        $permitted = ['period', 'from', 'to', ...self::FILTERS];
        $unexpected = array_values(array_diff(array_keys($request->query()), $permitted));
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['filters' => 'Un filtre non autorisé a été refusé.']);
        }

        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:7d,30d,90d,qtd,ytd,custom'],
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
                'preset' => $data['period'] ?? '30d',
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
        $dashboard = $analytics->analyse(new MarketingAnalyticsQuery($request->user(), $period, $filters))->toArray();
        $canUseExplorer = (bool) config('zoho-v2.features.explorer_enabled', false)
            && $request->user()->can('view zoho records');

        return view('backend.contents.marketing-dashboard.index', [
            'dashboard' => $dashboard,
            'controls' => ['period' => $data['period'] ?? '30d', 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'drilldowns' => $canUseExplorer ? $this->drilldowns($data) : [],
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function drilldowns(array $data): array
    {
        $safe = array_filter(array_intersect_key($data, array_flip([
            'period', 'from', 'to', 'commercial', 'campaign', 'source', 'country', 'sector', 'transport', 'client_type', 'lead_source', 'currency',
        ])), static fn (mixed $value): bool => $value !== null && $value !== '');
        $query = http_build_query($safe);
        $suffix = $query === '' ? '' : '?'.$query;

        return collect(['leads', 'accounts', 'contacts', 'deals', 'quotes'])
            ->mapWithKeys(fn (string $module): array => [$module => url('/admin/zoho/records/'.$module).$suffix])
            ->all();
    }
}
