<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Analytics\PlannerService;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;
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
     *
     * PHP owns the MM-DD recurrence semantics here (via BusinessCalendarService)
     * so the JS stays dumb: it only ever shades a fixed list of Y-m-d strings
     * plus, when plannerSkipWeekends is true, whatever day the browser resolves
     * as Sat/Sun in the planner timezone (FullCalendar's arg.dow — see the blade).
     */
    public function index(BusinessCalendarService $calendar)
    {
        addVendors(['fullcalendar']);

        $timezone     = Setting::get('decouverte.timezone', 'Europe/Paris');
        $skipWeekends = $calendar->skipWeekends();

        // Shading window: ~1 month back (so paging back a couple of weeks still
        // shows shading) to ~6 months forward (past FullCalendar's own 3-month
        // default projection window, with headroom).
        $from = now($timezone)->subMonth()->startOfDay();
        $to   = now($timezone)->addMonths(6)->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;

        $blackout = $calendar->blockedDatesFor($from, $days);

        if ($skipWeekends) {
            // Plain Saturdays/Sundays are derived client-side for free from
            // FullCalendar's arg.dow (see index.blade.php) — shipping ~50
            // redundant weekend dates in this payload buys nothing. Keep only
            // the blackout-driven entries; a blackout date that also happens to
            // fall on a weekend is still shaded client-side by the weekend
            // rule, so dropping it here loses no visual information.
            $blackout = array_values(array_filter(
                $blackout,
                static fn (string $date): bool => ! Carbon::createFromFormat('Y-m-d', $date)->isWeekend(),
            ));
        }

        return view('backend.contents.planner.index', [
            'plannerTimezone'     => $timezone,
            'plannerSkipWeekends' => $skipWeekends,
            'plannerBlackout'     => $blackout,
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

        $plannerTimezone = Setting::get('decouverte.timezone', 'Europe/Paris');
        $events = app(PlannerService::class)->runsFeed($start, $end, $plannerTimezone);

        if ($request->user()?->can('view prospect_criteria') !== true) {
            $events = array_values(array_filter(
                $events,
                static fn (array $event): bool => ($event['extendedProps']['eventKind'] ?? null) !== 'discovery-projection',
            ));
        }

        foreach ($events as &$event) {
            if (! empty($event['start'])) {
                $event['start'] = Carbon::parse($event['start'])
                    ->setTimezone($plannerTimezone)
                    ->toIso8601String();
            }
        }
        unset($event);

        return response()->json($events);
    }
}
