<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Quota\DiscoveryQuotaService;

class ConsumptionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view consumption')->only(['index']);
    }

    /**
     * Display the client-facing "Ma consommation" page.
     *
     * Zoho-style non-CRUD controller — DiscoveryQuotaService only, no direct
     * DiscoveryRun queries here besides what the service exposes. Guard order
     * matters: every *IsUnlimited()/monthly*IsUnlimited() check MUST run before
     * the matching remainingOn()/contactRemainingOn()/monthlyRemaining()/
     * monthlyContactRemaining() call — those throw LogicException on unlimited
     * packages, which would 500 this page for the "Illimité" pack.
     */
    public function index()
    {
        /** @var DiscoveryQuotaService $quota */
        $quota = app(DiscoveryQuotaService::class);

        $package = $quota->activePackage();
        $today   = $quota->today();
        [$periodStart, $periodEnd] = $quota->currentPeriod($today);
        $dailyQuotaSummary = $quota->dailyDisplaySummary();
        $monthlyQuotaSummary = $quota->monthlyDisplaySummary($today);

        // ── Today: company meter ────────────────────────────────────────────
        $isUnlimited   = $quota->isUnlimited();
        $usedToday     = $quota->usedOn($today);
        $dailyCap      = $isUnlimited ? null : $package->daily_credits;

        // ── Today: contact meter ────────────────────────────────────────────
        $contactIsUnlimited = $quota->contactIsUnlimited();
        $contactUsedToday   = $quota->contactUsedOn($today);
        $dailyContactCap    = $contactIsUnlimited ? null : $package->daily_contact_credits;

        // ── This month: company meter ───────────────────────────────────────
        $monthlyIsUnlimited = $quota->monthlyIsUnlimited();
        $usedThisMonth      = $quota->usedInPeriod($periodStart, $periodEnd);
        $monthlyCap         = $monthlyIsUnlimited ? null : $package->monthly_credits;

        // ── This month: contact meter ───────────────────────────────────────
        $monthlyContactIsUnlimited = $quota->monthlyContactIsUnlimited();
        $contactUsedThisMonth      = $quota->contactUsedInPeriod($periodStart, $periodEnd);
        $monthlyContactCap         = $monthlyContactIsUnlimited ? null : $package->monthly_contact_credits;

        // ── Series + breakdown ──────────────────────────────────────────────
        $series     = $quota->dailySeries($today->copy()->subDays(29), $today->copy()->addDay());
        $breakdown  = $quota->perCriteriaBreakdown($periodStart, $periodEnd);

        return view('backend.contents.consumption.index', [
            'package'                   => $package,
            'periodStart'               => $periodStart,
            'periodEnd'                 => $periodEnd,
            'dailyQuotaSummary'         => $dailyQuotaSummary,
            'monthlyQuotaSummary'       => $monthlyQuotaSummary,

            'isUnlimited'               => $isUnlimited,
            'usedToday'                 => $usedToday,
            'dailyCap'                  => $dailyCap,

            'contactIsUnlimited'        => $contactIsUnlimited,
            'contactUsedToday'          => $contactUsedToday,
            'dailyContactCap'           => $dailyContactCap,

            'monthlyIsUnlimited'        => $monthlyIsUnlimited,
            'usedThisMonth'             => $usedThisMonth,
            'monthlyCap'                => $monthlyCap,

            'monthlyContactIsUnlimited' => $monthlyContactIsUnlimited,
            'contactUsedThisMonth'      => $contactUsedThisMonth,
            'monthlyContactCap'         => $monthlyContactCap,

            'series'                    => $series,
            'breakdown'                 => $breakdown,
        ]);
    }
}
