<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;

class ProspectionDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:backend.access');
    }

    public function index()
    {
        $analytics = app(AnalyticsService::class);

        return view('backend.contents.dashboard.index', [
            'kpis'              => $analytics->dashboardKpis(),
            'funnel'            => $analytics->funnel(),
            'engagementOverTime'=> $analytics->engagementOverTime(),
            'topCampaigns'      => $analytics->topCampaigns(),
        ]);
    }
}
