<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\CampaignRun;
use App\Models\InboxEmail;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Request;

class ProspectionDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:backend.access');
    }

    public function index(Request $request, AnalyticsService $analytics)
    {
        $data = $analytics->dashboardData();
        // ponytail: one global threshold; make it driver-specific only if real send durations diverge.
        $staleBefore = now()->subMinutes(30);
        $operations = [
            'replies_awaiting_triage' => $request->user()->can('view inbox')
                ? InboxEmail::where('status', InboxEmail::STATUS_NOUVEAU)
                    ->whereNull('processed_at')
                    ->count()
                : 0,
            'failed_sends' => 0,
            'stale_sends' => 0,
        ];

        if ($request->user()->can('view campaigns')) {
            $operations['failed_sends'] = CampaignRun::where('status', 'failed')->count();
            $operations['stale_sends'] = CampaignRun::where('status', 'sending')
                ->where(fn ($query) => $query
                    ->where('started_at', '<=', $staleBefore)
                    ->orWhere(fn ($query) => $query->whereNull('started_at')->where('updated_at', '<=', $staleBefore)))
                ->count();
        }

        if (! $request->user()->can('view campaigns')) {
            $data['campaigns'] = ['total' => 0, 'active' => 0, 'rows' => []];
            $data['planning'] = ['upcoming' => [], 'overdue' => [], 'upcoming_count' => 0, 'overdue_count' => 0];
            $data['topCampaigns'] = [];
            $data['funnel'] = [];
            $data['engagementOverTime'] = ['labels' => [], 'series' => ['opens' => [], 'clicks' => [], 'replies' => []]];
            $data['kpis'] = [
                ...$data['kpis'],
                'active_campaigns' => 0,
                'emails_sent_30d' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'conversion_rate' => 0,
            ];
        }

        if (! $request->user()->can('view prospect_criteria')) {
            $data['criteria'] = ['total' => 0, 'active' => 0, 'auto_run' => 0, 'rows' => []];
        }

        if (! $request->user()->can('view companies')) {
            $data['criteria']['rows'] = array_map(
                fn (array $row): array => [...$row, 'companies_count' => 0],
                $data['criteria']['rows']
            );
            $data['enterprises'] = [
                'total' => 0,
                'with_contacts' => 0,
                'qualification' => [],
                'sources' => [],
                'enrichment' => [],
                'recent' => [],
            ];
            $data['kpis'] = [...$data['kpis'], 'companies' => 0];
        }

        if (! $request->user()->can('view contacts')) {
            $data['criteria']['rows'] = array_map(
                fn (array $row): array => [...$row, 'contacts_count' => 0],
                $data['criteria']['rows']
            );
            $data['enterprises']['with_contacts'] = 0;
            $data['enterprises']['recent'] = array_map(
                fn (array $row): array => [...$row, 'contacts_count' => 0],
                $data['enterprises']['recent']
            );
            $data['kpis'] = [...$data['kpis'], 'contacts' => 0];
            $data['funnel'] = [];
        }

        if (! $request->user()->can('view demandes')) {
            $data['kpis'] = [...$data['kpis'], 'demandes' => 0, 'demandes_30d' => 0, 'conversion_rate' => 0.0];
            $data['topCampaigns'] = [];
        }

        if (! $request->user()->can('view companies')
            || ! $request->user()->can('view contacts')
            || ! $request->user()->can('view campaigns')
            || ! $request->user()->can('view demandes')) {
            $data['funnel'] = [];
        }

        return view('backend.contents.dashboard.index', [
            ...$data,
            'operations' => $operations,
        ]);
    }
}
