<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Jobs\RunZohoCrmSyncJob;
use App\Jobs\Zoho\RetryZohoFailuresJob;
use App\Models\User;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Identity\ZohoIdentityLinker;
use App\Services\Zoho\V2\Operations\ZohoOperationsDashboard;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoManualSyncCoordinator;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use App\Services\Zoho\ZohoCrmTemplatesService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ZohoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view zoho')->only(['index', 'v2Progress']);
        $this->middleware('permission:sync zoho')->only(['sync', 'v2Sync', 'v2Pause', 'retry']);
        $this->middleware('permission:backfill zoho')->only(['v2Backfill']);
        $this->middleware('permission:manage zoho mappings')->only(['mapping']);
        $this->middleware('permission:create campaign_templates')->only(['syncTemplates']);
    }

    /**
     * Display the Zoho sync-status screen.
     */
    public function index()
    {
        $operations = app(ZohoOperationsDashboard::class);
        if ($operations->available()) {
            return view('backend.contents.zoho.v2-index', ['dashboard' => $operations->data()]);
        }
        // ── Driver config ─────────────────────────────────────────────────────
        $crmDriver = config('services.zoho.crm_driver', 'local');
        $campaignsDriver = config('services.zoho.driver', 'local');

        // ── Last sync per module ──────────────────────────────────────────────
        $hasV2LogColumn = Schema::hasColumn('zoho_sync_logs', 'sync_batch_id');
        $lastByModule = [];
        foreach (['Accounts', 'Contacts'] as $module) {
            $query = ZohoSyncLog::query()->where('module', $module);
            if ($hasV2LogColumn) {
                $query->whereNull('sync_batch_id');
            }
            $lastByModule[$module] = $query
                ->orderByDesc('synced_at')
                ->first(['synced_at', 'records_synced', 'status', 'error']);
        }

        // ── OAuth token ───────────────────────────────────────────────────────
        $token = ZohoToken::where('service', 'crm')->first();

        $tokenStatus = 'absent';
        $tokenMinutes = null;
        $tokenExpiry = null;

        if ($token && $token->expires_at) {
            $tokenExpiry = $token->expires_at;
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
                            ? $lastCrmSync->synced_at->format('d/m/Y H:i').' · '.$lastCrmBadge['label']
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
        $historyQuery = ZohoSyncLog::query();
        if ($hasV2LogColumn) {
            $historyQuery->whereNull('sync_batch_id');
        }
        $history = $historyQuery->orderByDesc('synced_at')->limit(15)->get();

        return view('backend.contents.zoho.index', [
            'crmDriver' => $crmDriver,
            'campaignsDriver' => $campaignsDriver,
            'lastByModule' => $lastByModule,
            'token' => $token,
            'tokenStatus' => $tokenStatus,
            'tokenMinutes' => $tokenMinutes,
            'tokenExpiry' => $tokenExpiry,
            'history' => $history,
            'zohoIntegrations' => $zohoIntegrations,
        ]);
    }

    public function v2Progress(ZohoSyncBatch $batch, ZohoOperationsDashboard $operations): JsonResponse
    {
        abort_unless($operations->progressAvailable(), 404);
        $progress = $operations->syncProgress($batch);
        abort_if($progress === null, 404);

        return response()->json($progress)->header('Cache-Control', 'no-store, private');
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

    public function v2Sync(
        Request $request,
        ZohoModuleRegistry $registry,
        ZohoSyncOrchestrator $orchestrator,
        ZohoModuleDispatcher $dispatcher,
        ZohoManualSyncCoordinator $coordinator,
    ) {
        abort_unless(app(ZohoOperationsDashboard::class)->available(), 404);
        $data = $request->validate(['module' => ['nullable', 'string', 'max:64']]);
        $module = $this->moduleKey($registry, $data['module'] ?? null);

        if ($module !== null) {
            if ($coordinator->pausedBatchOwningModule($module) !== null) {
                return back()->with(
                    'error',
                    'Ce module appartient au lot complet en pause. Reprenez la synchronisation complète pour continuer.',
                );
            }

            $batch = $orchestrator->createBatch([$module], 'delta', 'manual', (int) $request->user()->id);

            return $this->dispatchBatchModules($batch, [$module], 'delta', $orchestrator, $dispatcher);
        }

        $decision = $coordinator->beginOrResumeAll($this->syncAllModuleKeys($registry), (int) $request->user()->id);
        if ($decision->outcome === 'already_running') {
            return back()->with('success', 'Une synchronisation complète est déjà en cours. Aucun nouveau lot n’a été créé.');
        }
        if ($decision->needsFinalization) {
            $orchestrator->finalizeBatch((int) $decision->batch->id);

            return back()->with('success', 'La synchronisation complète reprise était déjà terminée. Le lot a été finalisé.');
        }

        return $this->dispatchBatchModules(
            $decision->batch,
            $decision->modulesToDispatch,
            'delta',
            $orchestrator,
            $dispatcher,
        );
    }

    public function v2Pause(
        Request $request,
        ZohoModuleRegistry $registry,
        ZohoManualSyncCoordinator $coordinator,
    ) {
        abort_unless(app(ZohoOperationsDashboard::class)->available(), 404);
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'min:1', 'exists:zoho_sync_batches,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $coordinator->pauseAll(
                (int) $data['batch_id'],
                $this->syncAllModuleKeys($registry),
                $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException) {
            return back()->with('error', 'Ce lot ne peut pas être mis en pause. Actualisez la page puis réessayez.');
        }

        return back()->with('success', 'La synchronisation complète est en pause. Elle reprendra avec le même lot au prochain clic.');
    }

    public function v2Backfill(
        Request $request,
        ZohoModuleRegistry $registry,
        ZohoSyncOrchestrator $orchestrator,
        ZohoModuleDispatcher $dispatcher,
    ) {
        return $this->dispatchV2(
            $request,
            $registry,
            $orchestrator,
            $dispatcher,
            (string) $request->input('mode', 'backfill'),
        );
    }

    public function retry(Request $request, ZohoModuleRegistry $registry)
    {
        abort_unless(app(ZohoOperationsDashboard::class)->available(), 404);
        $data = $request->validate(['module' => ['nullable', 'string', 'max:64']]);
        $module = $this->moduleKey($registry, $data['module'] ?? null);
        RetryZohoFailuresJob::dispatch($module, (int) config('zoho-v2.retry.batch_size', 100));

        return back()->with('success', 'Nouvelle tentative planifiée.');
    }

    public function mapping(Request $request, ZohoIdentityLinker $identity, ZohoOperationsDashboard $operations)
    {
        abort_unless($operations->available(), 404);
        $observedOwnerIds = $operations->observedOwnerIdentities()->pluck('zoho_id')->all();
        $commercialUserIds = User::query()->where('is_active', true)->role('commercial')->pluck('id')->all();
        $data = $request->validate([
            'zoho_user_id' => ['required', 'string', 'max:100', Rule::in($observedOwnerIds)],
            'fretiq_user_id' => ['nullable', 'integer', Rule::in($commercialUserIds)],
        ]);
        $mapping = $identity->overrideUserMapping($data['zoho_user_id'], $data['fretiq_user_id'] ?? null, (int) $request->user()->id);
        if (($data['fretiq_user_id'] ?? null) === null) {
            $mapping->update(['is_confirmed' => false]);
        }

        return back()->with('success', 'Correspondance utilisateur mise à jour.');
    }

    private function dispatchV2(
        Request $request,
        ZohoModuleRegistry $registry,
        ZohoSyncOrchestrator $orchestrator,
        ZohoModuleDispatcher $dispatcher,
        string $mode,
    ) {
        abort_unless(app(ZohoOperationsDashboard::class)->available(), 404);
        $data = $request->validate(['module' => ['nullable', 'string', 'max:64'], 'mode' => ['nullable', 'in:backfill,reconcile']]);
        $mode = $mode === 'delta' ? 'delta' : ($data['mode'] ?? $mode);
        $module = $this->moduleKey($registry, $data['module'] ?? null);
        $modules = $module ? [$module] : $this->syncAllModuleKeys($registry);
        $batch = $orchestrator->createBatch($modules, $mode, 'manual', (int) $request->user()->id);

        return $this->dispatchBatchModules($batch, $modules, $mode, $orchestrator, $dispatcher);
    }

    /** @param list<string> $modules */
    private function dispatchBatchModules(
        ZohoSyncBatch $batch,
        array $modules,
        string $mode,
        ZohoSyncOrchestrator $orchestrator,
        ZohoModuleDispatcher $dispatcher,
    ) {
        foreach ($modules as $index => $key) {
            try {
                $dispatcher->dispatch((int) $batch->id, $key, $mode, (string) $batch->correlation_id);
            } catch (\Throwable) {
                foreach (array_slice($modules, $index) as $undispatched) {
                    $orchestrator->terminalizeModule(
                        (int) $batch->id,
                        $undispatched,
                        $mode,
                        'dispatch_failed',
                    );
                }

                return back()->with(
                    'error',
                    'La synchronisation V2 n\'a pas pu être planifiée. Consultez les identifiants de corrélation.',
                );
            }
        }

        return back()->with('success', 'Synchronisation V2 planifiée.');
    }

    /** @return list<string> */
    private function syncAllModuleKeys(ZohoModuleRegistry $registry): array
    {
        return array_keys(array_filter(
            $registry->all(),
            fn ($definition): bool => ! $definition->activationGated && $definition->key !== 'quoted_items',
        ));
    }

    private function moduleKey(ZohoModuleRegistry $registry, ?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }
        foreach ($registry->all() as $definition) {
            if ($definition->key === $requested || strcasecmp($definition->apiName, $requested) === 0) {
                abort_if($definition->activationGated || $definition->key === 'quoted_items', 422, 'Module Zoho indisponible.');

                return $definition->key;
            }
        }
        abort(422, 'Module Zoho invalide.');
    }
}
