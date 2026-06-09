<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\CampaignsReadinessService;
use App\Services\Zoho\ZohoCrmSyncService;
use Illuminate\Http\Request;

class ZohoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view zoho')->only(['index']);
        $this->middleware('permission:sync zoho')->only(['sync']);
    }

    /**
     * Display the Zoho sync-status screen.
     */
    public function index()
    {
        // ── Driver config ─────────────────────────────────────────────────────
        $crmDriver       = config('services.zoho.crm_driver', 'local');
        $campaignsDriver = config('services.zoho.driver', 'local');

        // ── Last sync per module ──────────────────────────────────────────────
        $lastByModule = [];
        foreach (['Accounts', 'Contacts'] as $module) {
            $lastByModule[$module] = ZohoSyncLog::where('module', $module)
                ->orderByDesc('synced_at')
                ->first(['synced_at', 'records_synced', 'status', 'error']);
        }

        // ── OAuth token ───────────────────────────────────────────────────────
        $token = ZohoToken::where('service', 'crm')->first();

        $tokenStatus     = 'absent';
        $tokenMinutes    = null;
        $tokenExpiry     = null;

        if ($token && $token->expires_at) {
            $tokenExpiry  = $token->expires_at;
            $tokenMinutes = (int) now()->diffInMinutes($token->expires_at, false);

            if ($tokenMinutes <= 0) {
                $tokenStatus = 'expired';
            } elseif ($tokenMinutes < 15) {
                $tokenStatus = 'soon';
            } else {
                $tokenStatus = 'ok';
            }
        }

        // ── History (last 15 rows) ────────────────────────────────────────────
        $history = ZohoSyncLog::orderByDesc('synced_at')->limit(15)->get();

        // ── Campaigns driver readiness checklist (Phase 5) ────────────────────
        $campaignsReadiness = app(CampaignsReadinessService::class)->check();

        return view('backend.contents.zoho.index', [
            'crmDriver'           => $crmDriver,
            'campaignsDriver'     => $campaignsDriver,
            'lastByModule'        => $lastByModule,
            'token'               => $token,
            'tokenStatus'         => $tokenStatus,
            'tokenMinutes'        => $tokenMinutes,
            'tokenExpiry'         => $tokenExpiry,
            'history'             => $history,
            'campaignsReadiness'  => $campaignsReadiness,
        ]);
    }

    /**
     * Run a synchronous on-demand sync and redirect back with a flash message.
     *
     * The optional `module` parameter restricts to Accounts or Contacts only;
     * omitting it runs both. RunZohoCrmSyncJob remains available for async/
     * scheduled use — this action is intentionally synchronous for immediacy.
     */
    public function sync(Request $request)
    {
        $request->validate([
            'module' => ['nullable', 'in:Accounts,Contacts'],
        ]);

        $module = $request->input('module');

        try {
            app(ZohoCrmSyncService::class)->sync($module);

            $label = $module ? $module : 'Accounts + Contacts';
            session()->flash('success', "Synchronisation terminée — {$label} mis à jour.");
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);
            session()->flash('error', "Erreur lors de la synchronisation : {$message}");
        }

        return redirect()->route('admin.zoho.index');
    }
}
