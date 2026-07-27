<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Analytics\PlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PlannerController — campaign calendar / planning screen.
 *
 * index()  renders the Blade shell (FullCalendar is initialised in JS).
 * feed()   returns the FullCalendar-compatible JSON event array for the
 *          requested date range (start / end query params, ISO 8601).
 */
class PlannerController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view campaigns');
    }

    /**
     * Render the planner shell view.
     */
    public function index()
    {
        addVendors(['fullcalendar']);

        return view('backend.contents.planner.index', [
            'plannerTimezone' => Setting::get('decouverte.timezone', 'Europe/Paris'),
        ]);
    }

    /**
     * Return FullCalendar event JSON for the given date range.
     *
     * FullCalendar sends ?start=YYYY-MM-DD&end=YYYY-MM-DD (inclusive start,
     * exclusive end). Both are passed through to PlannerService as-is.
     */
    public function feed(Request $request): JsonResponse
    {
        $start  = $request->query('start') ?: null;
        $end    = $request->query('end')   ?: null;

        $events = app(PlannerService::class)->runsFeed($start, $end);

        return response()->json($events);
    }
}
