<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Analytics\QueueObservabilityService;

/**
 * ObservabilityController — queue / job failure monitoring screen.
 *
 * Gate: `manage roles` — superadmin-only under the seeded ACL.
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
