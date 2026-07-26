<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Request;

class ProspectionDashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:backend.access');
    }

    public function index(Request $request)
    {
        $prototype = (int) $request->query('prototype', 1);
        $prototype = in_array($prototype, [1, 2, 3, 4], true) ? $prototype : 1;
        $data = app(AnalyticsService::class)->dashboardData($prototype);

        if (! $request->user()->can('view campaigns')) {
            $data['campaigns']['rows'] = [];
            $data['planning'] = ['upcoming' => [], 'overdue' => [], 'upcoming_count' => 0, 'overdue_count' => 0];
            $data['topCampaigns'] = [];
        }

        if (! $request->user()->can('view prospect_criteria')) {
            $data['criteria']['rows'] = [];
        }

        if (! $request->user()->can('view companies')) {
            $data['enterprises']['recent'] = [];
        }

        return view('backend.contents.dashboard.index', [
            ...$data,
            'prototype' => $prototype,
        ]);
    }
}
