<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Analytics\QueueObservabilityService;

/**
 * ObservabilityController — queue / job failure monitoring screen.
 *
 * Gate: `manage roles` — only superadmin / admin have this permission;
 * commercial role does not, which satisfies the "admin only" requirement
 * without adding a new bespoke permission.
 */
class ObservabilityController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage roles');
    }

    /**
     * Render the observability index view with queue health data.
     */
    public function index()
    {
        $service = app(QueueObservabilityService::class);

        return view('backend.contents.observability.index', [
            'counts'      => $service->counts(),
            'failedJobs'  => $service->failedJobs(),
            'failedRuns'  => $service->failedRuns(),
        ]);
    }
}
