<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;

class ProviderQuotaController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view provider quota')->only(['index']);
    }

    /**
     * Display the superadmin-only "Quota fournisseurs" page — real remaining
     * quota fetched live from the SerpAPI and Hunter.io account endpoints.
     */
    public function index()
    {
        return view('backend.contents.provider_quota.index', [
            'serpapi'              => app(CompanyDiscoveryService::class)->accountUsage(),
            'hunter'               => app(HunterEnrichmentService::class)->accountUsage(),
            'providerReservations' => app(DiscoveryQuotaService::class)->providerReservationsToday(),
            'driverLive'           => config('services.serpapi.driver', 'local') !== 'local',
        ]);
    }
}
