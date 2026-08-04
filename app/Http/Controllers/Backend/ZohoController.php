<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Jobs\RunZohoCrmSyncJob;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\ZohoCrmTemplatesService;
use Illuminate\Http\Request;

class ZohoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view zoho')->only(['index']);
        $this->middleware('permission:sync zoho')->only(['sync']);
        $this->middleware('permission:create campaign_templates')->only(['syncTemplates']);
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

        $hasValues = static fn (array $values): bool => collect($values)
            ->every(static fn ($value): bool => trim((string) $value) !== '');

        $lastCrmSync = collect($lastByModule)
            ->filter()
            ->sortByDesc(static fn (ZohoSyncLog $log): int => $log->synced_at?->timestamp ?? 0)
            ->first();
        $crmCredentialsReady = $hasValues([
            config('services.zoho.crm.client_id'),
            config('services.zoho.crm.client_secret'),
            config('services.zoho.crm.refresh_token'),
        ]);
        $crmTokenReady = $token
            && trim((string) $token->access_token) !== ''
            && in_array($tokenStatus, ['ok', 'soon'], true);
        $crmSyncVerified = collect(['Accounts', 'Contacts'])
            ->every(static fn (string $module): bool => ($lastByModule[$module] ?? null)?->status === 'success');
        $crmStatus = $this->readinessStatus(
            $crmDriver === 'zoho',
            $crmCredentialsReady && $crmTokenReady,
            $crmSyncVerified,
        );

        $campaignsCredentialsReady = $hasValues([
            config('services.zoho.campaigns.client_id'),
            config('services.zoho.campaigns.client_secret'),
            config('services.zoho.campaigns.refresh_token'),
        ]);
        $topicReady = trim((string) config('services.zoho.campaigns.topic_id')) !== '';
        $listReady = trim((string) config('services.zoho.campaigns.list_key')) !== '';
        $recipientListLiveVerified = (bool) config('services.zoho.campaigns.recipient_list_live_verified', false);
        $campaignsStatus = $this->readinessStatus(
            $campaignsDriver === 'zoho',
            $campaignsCredentialsReady && $topicReady && $listReady,
            false,
        );

        $driverLabels = config('global.data.zoho_driver_labels', []);
        $tokenStatuses = config('global.data.zoho_token_statuses', []);
        $syncStatuses = config('global.data.zoho_sync_statuses', []);
        $crmTokenBadge = $tokenStatuses[$tokenStatus] ?? ['label' => $tokenStatus, 'color' => 'secondary'];
        $lastCrmBadge = $syncStatuses[$lastCrmSync?->status ?? 'idle'] ?? ['label' => 'Inconnu', 'color' => 'secondary'];

        $zohoIntegrations = [
            'crm' => [
                'title' => 'Driver CRM',
                'subtitle' => 'Synchronisation des comptes et contacts',
                'icon' => 'bi-cloud',
                'status' => $crmStatus,
                'details' => [
                    ['label' => 'Pilote sélectionné', 'value' => $driverLabels[$crmDriver] ?? 'Source inconnue', 'color' => $crmDriver === 'zoho' ? 'success' : 'secondary'],
                    ['label' => 'Identifiants d’accès', 'value' => $crmCredentialsReady ? 'Configurés' : 'Incomplets', 'color' => $crmCredentialsReady ? 'success' : 'warning'],
                    ['label' => 'Token CRM', 'value' => $crmTokenBadge['label'], 'color' => $crmTokenBadge['color']],
                    [
                        'label' => 'Dernière synchronisation CRM',
                        'value' => $lastCrmSync?->synced_at
                            ? $lastCrmSync->synced_at->format('d/m/Y H:i') . ' · ' . $lastCrmBadge['label']
                            : 'Jamais synchronisé',
                        'color' => $lastCrmSync ? $lastCrmBadge['color'] : 'secondary',
                    ],
                ],
                'recovery' => $crmStatus === 'incomplete'
                    ? 'Complétez la connexion et renouvelez le token avant de relancer une synchronisation.'
                    : ($crmStatus === 'test_ready' ? 'Lancez une synchronisation de test pour confirmer la connexion.' : null),
            ],
            'campaigns' => [
                'title' => 'Driver Campaigns',
                'subtitle' => 'Préparation et envoi des campagnes',
                'icon' => 'bi-envelope',
                'status' => $campaignsStatus,
                'details' => [
                    ['label' => 'Pilote sélectionné', 'value' => $driverLabels[$campaignsDriver] ?? 'Source inconnue', 'color' => $campaignsDriver === 'zoho' ? 'success' : 'secondary'],
                    ['label' => 'Identifiants d’accès', 'value' => $campaignsCredentialsReady ? 'Configurés' : 'Incomplets', 'color' => $campaignsCredentialsReady ? 'success' : 'warning'],
                    ['label' => 'Sujet d’envoi', 'value' => $topicReady ? 'Configuré' : 'Manquant', 'color' => $topicReady ? 'success' : 'warning'],
                    ['label' => 'Liste d’envoi', 'value' => $listReady ? 'Configurée' : 'Manquante', 'color' => $listReady ? 'success' : 'warning'],
                    ['label' => 'Vérification de la liste', 'value' => $recipientListLiveVerified ? 'Liste de destinataires vérifiée' : 'À effectuer', 'color' => $recipientListLiveVerified ? 'success' : 'info'],
                ],
                'recovery' => $campaignsStatus === 'incomplete'
                    ? 'Complétez les éléments manquants avant tout test d’envoi.'
                    : ($campaignsStatus === 'test_ready' ? 'Effectuez le test en conditions réelles prévu avant de considérer l’envoi comme opérationnel.' : null),
            ],
        ];
        // ── History (last 15 rows) ────────────────────────────────────────────
        $history = ZohoSyncLog::orderByDesc('synced_at')->limit(15)->get();

        return view('backend.contents.zoho.index', [
            'crmDriver'           => $crmDriver,
            'campaignsDriver'     => $campaignsDriver,
            'lastByModule'        => $lastByModule,
            'token'               => $token,
            'tokenStatus'         => $tokenStatus,
            'tokenMinutes'        => $tokenMinutes,
            'tokenExpiry'         => $tokenExpiry,
            'history'             => $history,
            'zohoIntegrations'    => $zohoIntegrations,
        ]);
    }

    private function readinessStatus(bool $selected, bool $configured, bool $verified): string
    {
        if (! $selected) {
            return 'non_configure';
        }

        if (! $configured) {
            return 'incomplete';
        }

        return $verified ? 'verified' : 'test_ready';
    }
    /**
     * Dispatch an async Zoho CRM sync and redirect back with a flash message.
     *
     * The optional `module` parameter restricts to Accounts or Contacts only;
     * omitting it runs both. The work runs in RunZohoCrmSyncJob on the queue
     * (a full pull can take ~100 s — too long for a synchronous request), so a
     * queue worker must be running for the sync to actually execute.
     */
    public function sync(Request $request)
    {
        $request->validate([
            'module' => ['nullable', 'in:Accounts,Contacts'],
        ]);

        $module = $request->input('module');

        try {
            RunZohoCrmSyncJob::dispatch($module);

            $label = $module ? $module : 'Accounts + Contacts';
            session()->flash('success', "Synchronisation lancée en arrière-plan — {$label}. Les résultats s'afficheront ici une fois terminée.");
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);
            session()->flash('error', "Erreur lors du lancement de la synchronisation : {$message}");
        }

        return redirect()->route('admin.zoho.index');
    }

    /**
     * Import email templates from Zoho CRM into campaign_templates.
     *
     * Synchronous, on-demand. Redirects back to the Zoho dashboard with a
     * French flash message summarising the import counts.
     */
    public function syncTemplates(Request $request)
    {
        try {
            $result = app(ZohoCrmTemplatesService::class)->import();

            session()->flash(
                'success',
                "Modèles importés : {$result['imported']} créés, {$result['updated']} mis à jour, {$result['skipped']} ignorés."
            );
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);
            session()->flash('error', "Erreur lors de l'import des modèles : {$message}");
        }

        return redirect()->route('admin.zoho.index');
    }
}
