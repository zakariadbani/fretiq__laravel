<?php

namespace App\Http\Controllers\Backend;

use App\Models\ProspectBatch;
use Illuminate\Http\Request;

class ProspectingDashboardController extends BackendController
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->middleware('permission:view prospect_batches');
    }

    public function index()
    {
        $query = ProspectBatch::query();
        $user = request()->user();
        if (! $user->hasRole(['admin', 'superadmin'])) {
            $query->where('created_by', $user->id);
        }

        $active = (clone $query)->whereIn('status', ['queued', 'running'])->count();
        $review = (clone $query)->where('status', 'review')->count();
        $processed = (int) (clone $query)->sum('processed_items');
        $promoted = (int) (clone $query)->sum('promoted_companies');

        return view('backend.contents.prospecting.dashboard', [
            'stats' => [
                'active' => $active,
                'review' => $review,
                'processed' => $processed,
                'promoted' => $promoted,
                'yield' => $processed > 0 ? round(($promoted / $processed) * 100, 1) : 0,
            ],
            'recent' => (clone $query)->latest('id')->limit(5)->get(),
            'capacity' => [
                'discovery' => config('prospecting.provider_discovery_monthly_capacity'),
                'enrichment' => config('prospecting.provider_enrich_monthly_capacity'),
            ],
        ]);
    }
}
