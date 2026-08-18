<?php

namespace App\Http\Controllers\Backend;

use App\Jobs\DrainRetryableProspectItemsJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Discovery\DomainCanonicalizer;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectReviewPresenter;
use App\Services\Prospecting\ProspectReviewWorkspace;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProspectReviewController extends BackendController
{
    private const DRAIN_ITEM_CAP = 100;

    public function __construct(Request $request)
    {
        parent::__construct($request, new ProspectBatchItem);
        $this->middleware('permission:review prospect matches');
    }

    /**
     * Dry-run for the "À relancer" bulk drain confirm dialog. Read-only —
     * scores the same (filter-scoped, ownership-scoped, capped-at-100) set
     * of items the drain itself would target, without authorizing or
     * mutating anything, so the operator sees real headroom before deciding.
     */
    public function drainPreview(Request $request, ProspectReviewWorkspace $workspace, ProspectBatchService $batches)
    {
        $filters = $this->drainFilters($request);
        $totalMatching = $workspace->blockedItemsQuery($request->user(), $filters)->count();
        $items = $workspace->blockedItemsQuery($request->user(), $filters)
            ->select(['id', 'prospect_batch_id', 'error_code'])
            ->with(['batch:id,status,created_by,prospect_criteria_id', 'batch.criteria:id,name,is_active'])
            ->oldest('id')
            ->limit(self::DRAIN_ITEM_CAP)
            ->get();

        return response()->json($batches->summarizeRetryDrain($items, $totalMatching));
    }

    /**
     * Enqueues the queued drain job — the request only selects and caps the
     * item ids; every skip decision, ledger call, and provider dispatch
     * happens in the job, spread over time. Never a request-time loop.
     */
    public function drainRetryable(Request $request, ProspectReviewWorkspace $workspace)
    {
        $filters = $this->drainFilters($request);
        $query = $filters['state'] === 'stalled'
            ? $workspace->stalledItemsQuery($request->user(), $filters)
            : $workspace->blockedItemsQuery($request->user(), $filters);
        $itemIds = $query
            ->oldest('id')
            ->limit(self::DRAIN_ITEM_CAP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($itemIds === []) {
            return response()->json(['message' => 'error', 'code' => 'prospect_retry_drain_empty'], 422);
        }

        // Belt-and-suspenders: drainFilters() already makes `batch` required
        // for state=stalled and the query above is already batch-scoped, but
        // an unpinned admin sweep across batches/owners is exactly the leak
        // this feature must never reopen — assert it rather than trust the
        // query alone.
        if ($filters['state'] === 'stalled') {
            abort_if(
                ProspectBatchItem::query()->whereIn('id', $itemIds)->where('prospect_batch_id', '!=', $filters['batch'])->exists(),
                500,
            );
        }

        $token = Str::random(40);
        $requestedBy = (int) $request->user()->id;
        Cache::put(DrainRetryableProspectItemsJob::cacheKeyFor($token), [
            'terminal' => false,
            'requested_by' => $requestedBy,
            'total' => count($itemIds),
        ], now()->addHour());

        DrainRetryableProspectItemsJob::dispatch($itemIds, $token, $requestedBy);

        return response()->json([
            'drain_token' => $token,
            'considered' => count($itemIds),
            'status_url' => route('admin.prospect_review.retry_drain.status', $token),
        ]);
    }

    /** Polled by the bulk-bar monitor banner until the drain job reports terminal. */
    public function drainStatus(Request $request, string $token)
    {
        $payload = Cache::get(DrainRetryableProspectItemsJob::cacheKeyFor($token));
        abort_if($payload === null, 404);
        abort_unless(
            $this->privileged() || (int) ($payload['requested_by'] ?? 0) === (int) $request->user()->id,
            403,
        );

        return response()->json($payload);
    }

    public function itemStatus(ProspectBatchItem $item, ProspectReviewPresenter $presenter, ProspectReviewWorkspace $workspace)
    {
        $this->authorizeBatch($item->batch()->firstOrFail());
        $freshItem = $item->fresh()->loadMissing('batch.criteria');
        $outcome = $presenter->retryOutcome($freshItem);
        $facts = $workspace->statusFacts($freshItem);

        return response()->json([
            'id' => (int) $item->getKey(),
            'status' => $outcome['status'],
            'terminal' => $outcome['terminal'],
            'level' => $outcome['level'],
            'title' => $outcome['title'],
            'message' => $outcome['message'],
            'result' => $outcome['result'],
            'next_step' => $outcome['next_step'],
            'review_url' => $presenter->workspaceUrl(
                $item->prospect_batch_id,
                [
                    'batch' => $item->prospect_batch_id,
                    'item' => $item->getKey(),
                    'monitor_item' => $item->getKey(),
                ],
                false,
            ),
            'batch_url' => route('admin.prospect_batches.view', ['id' => $item->prospect_batch_id], false),
            'company_name' => $item->company_name,
            'imported_contacts_count' => $facts['imported_contacts_count'],
            'provider_result_count' => $facts['provider_result_count'],
            'recorded_units' => $facts['recorded_units'],
        ]);
    }

    public function decideItem(
        Request $request,
        ProspectBatchItem $item,
        DomainCanonicalizer $domains,
        ProspectBatchService $batches,
        ProviderCallLedger $providerCalls,
        ProspectReviewWorkspace $workspace,
        ProspectReviewPresenter $presenter,
    ) {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve_domain', 'reject', 'retry'])],
            'selected_domain' => ['nullable', 'required_if:action,approve_domain', 'string', 'max:500'],
            'reason' => ['nullable', Rule::in(['not_a_match', 'not_relevant', 'bad_data'])],
            'confirm_provider_reissue' => ['nullable', 'boolean'],
            ...$this->returnContextRules(),
        ]);
        $this->authorizeBatch($item->batch()->firstOrFail());

        $dispatch = false;
        try {
            $persisted = DB::transaction(function () use ($item, $validated, $domains, $batches, $providerCalls, &$dispatch): ProspectBatchItem {
                $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->id);
                $metadata = is_array($locked->source_metadata) ? $locked->source_metadata : [];
                $action = $validated['action'];

                if ($action === 'approve_domain') {
                    if ($locked->selected_domain !== null && data_get($metadata, 'processing.resolution_done') === true) {
                        abort(409);
                    }
                    $selected = $domains->canonicalize((string) $validated['selected_domain']);
                    $allowed = collect($locked->domain_alternatives ?? [])
                        ->map(fn ($domain) => $domains->canonicalize((string) $domain))
                        ->filter(fn ($domain) => $domain !== null && ! $domain->isPlatform)
                        ->keyBy(fn ($domain) => $domain->host);
                    if ($selected === null || $selected->isPlatform || ! $allowed->has($selected->host)) {
                        throw ValidationException::withMessages(['selected_domain' => 'Choisissez un domaine proposé pour cette entreprise.']);
                    }
                    if ($locked->status === 'pending' && $locked->selected_domain === $selected->host) {
                        return $locked;
                    }
                    abort_unless(in_array($locked->status, ['review', 'failed'], true), 409);
                    $locked->forceFill([
                        'selected_domain' => $selected->host,
                        'registrable_domain' => $selected->registrableDomain,
                        'domain_reason' => 'reviewer_selected',
                        'status' => 'pending',
                        'error_code' => null,
                        'error_message' => null,
                        'processing_started_at' => null,
                        'processed_at' => null,
                    ]);
                    $dispatch = true;
                } elseif ($action === 'reject') {
                    if ($locked->status === 'skipped') {
                        return $locked;
                    }
                    abort_unless(in_array($locked->status, ['review', 'failed'], true), 409);
                    $locked->forceFill([
                        'status' => 'skipped',
                        'domain_reason' => $validated['reason'] ?? 'reviewer_rejected',
                        'error_code' => null,
                        'error_message' => null,
                        'processed_at' => now(),
                    ]);
                } else {
                    if ($locked->status === 'pending') {
                        return $locked;
                    }
                    abort_unless(in_array($locked->status, ['review', 'failed'], true), 409);
                    $batch = $locked->batch()->with('criteria:id,name,is_active')->firstOrFail();
                    $blockedByCriterion = $batches->retryBlockedByCriterion($batch);
                    if ($blockedByCriterion !== null) {
                        throw ValidationException::withMessages(['action' => $blockedByCriterion]);
                    }
                    if ($locked->error_code === 'provider_outcome_uncertain') {
                        if (($validated['confirm_provider_reissue'] ?? false) !== true) {
                            throw ValidationException::withMessages([
                                'confirm_provider_reissue' => 'Confirmez la relance de cet appel fournisseur incertain.',
                            ]);
                        }
                        try {
                            $providerCalls->authorizeUncertainRetryForItem((int) $locked->getKey());
                        } catch (ProviderRequestException) {
                            throw ValidationException::withMessages([
                                'confirm_provider_reissue' => 'Cet appel ne peut pas encore être relancé.',
                            ]);
                        }
                    }
                    try {
                        $providerCalls->authorizeKnownFailureRetryForItem(
                            (int) $locked->getKey(),
                            (string) $locked->error_code,
                        );
                    } catch (ProviderRequestException) {
                        throw ValidationException::withMessages([
                            'action' => 'Cette recherche a atteint sa limite de relance automatique.',
                        ]);
                    }
                    $locked->forceFill([
                        'status' => 'pending',
                        'error_code' => null,
                        'error_message' => null,
                        'processing_started_at' => null,
                        'processed_at' => null,
                    ]);
                    $dispatch = true;
                }

                $metadata['review_decision'] = [
                    'action' => $action,
                    'actor_id' => (int) request()->user()->id,
                    'reason' => $validated['reason'] ?? null,
                    'decided_at' => now()->toIso8601String(),
                ];
                $locked->source_metadata = $metadata;
                $locked->save();

                return $locked->fresh();
            });
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 409 && ! $request->expectsJson()) {
                return $this->reviewRedirect($presenter, $validated, 'warning', 'Cette décision a déjà été traitée ; la file a été actualisée.', [], $item->prospect_batch_id);
            }
            throw $exception;
        }

        $batches->refreshCounters($persisted->batch);
        if ($dispatch) {
            ProcessProspectBatchItemJob::dispatch($persisted->id)->afterCommit();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'success', 'id' => $persisted->id, 'status' => $persisted->status]);
        }

        $persisted->batch->loadMissing('criteria');
        $criteriaName = $persisted->batch->criteria?->name;

        $message = match ($validated['action']) {
            'approve_domain' => $criteriaName !== null
                ? "Domaine enregistré — le traitement de « {$persisted->company_name} » reprend. Elle apparaîtra dans les Résultats du critère « {$criteriaName} » une fois traitée."
                : "Domaine enregistré — le traitement de « {$persisted->company_name} » reprend. Elle apparaîtra dans le lot une fois traitée.",
            'retry' => "Relance demandée pour « {$persisted->company_name} » — suivez son avancement dans le bandeau ci-dessous.",
            'reject' => "Entreprise « {$persisted->company_name} » exclue de ce lot.",
        };

        // Auto-advance the redirect to the next reviewable item for a
        // completed decision (approve_domain/reject) so the reviewer isn't
        // left staring at their own just-decided card. Retry deliberately
        // stays on the same item — the monitor poller below can replace
        // this page up to 120s later, and auto-advancing would yank the
        // user off the next card mid-review if that fires late. The item
        // itself flips to 'pending' and no longer resolves in the
        // ['review','failed'] scope, so the workspace suppresses its usual
        // "fall back to another item" behaviour whenever item === monitor_item
        // (ProspectReviewWorkspace::build) — the pane shows the monitored
        // placeholder/empty state instead of a different company's card.
        if ($validated['action'] === 'retry') {
            $extraQuery = ['item' => $persisted->id, 'monitor_item' => $persisted->id];
        } else {
            $returnFilters = [
                'batch' => isset($validated['return_batch']) ? (int) $validated['return_batch'] : null,
                'reason' => $validated['return_reason'] ?? null,
                'state' => $validated['return_state'] ?? 'all',
                'q' => trim((string) ($validated['return_q'] ?? '')),
            ];
            $next = $workspace->nextQueuedItem($request->user(), $returnFilters, (int) $persisted->id);
            $extraQuery = ['item' => $next?->id];
        }

        return $this->reviewRedirect($presenter, $validated, 'success', $message, $extraQuery, $persisted->prospect_batch_id);
    }

    private function authorizeBatch(ProspectBatch $batch): void
    {
        abort_unless($this->privileged() || (int) $batch->created_by === (int) request()->user()->id, 403);
    }

    private function privileged(): bool
    {
        return request()->user()?->hasRole(['admin', 'superadmin']) ?? false;
    }

    /**
     * Same batch/reason/q scope the "À relancer" pill already filters on —
     * deliberately excludes item/tab, which don't apply to a bulk action.
     * `state` selects which drain query drainRetryable() targets
     * (blockedItemsQuery vs stalledItemsQuery); 'blocked' is the default so
     * every caller before this widened keeps behaving byte-identically.
     * `batch` becomes required when state=stalled — it's nullable and
     * admins are unscoped, so an unpinned stalled drain would otherwise
     * sweep the 100 oldest matching items across every batch and owner
     * while the button's own count is batch-scoped.
     *
     * @return array{batch:?int,reason:?string,q:string,state:string}
     */
    private function drainFilters(Request $request): array
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'integer', 'min:1', 'required_if:state,stalled'],
            'reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(['blocked', 'stalled'])],
        ]);

        return [
            'batch' => isset($validated['batch']) ? (int) $validated['batch'] : null,
            'reason' => $validated['reason'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
            'state' => $validated['state'] ?? 'blocked',
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function returnContextRules(): array
    {
        return [
            'return_batch' => ['nullable', 'integer', 'min:1'],
            'return_reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'return_state' => ['nullable', Rule::in(['all', 'attention', 'blocked'])],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_item' => ['nullable', 'integer', 'min:1'],
            'return_companies_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * Builds the post-decision redirect via the shared workspaceUrl() builder —
     * null/'' entries are filtered inside it, so this only needs to assemble the
     * raw return_* values. $batchId is always the host batch — every review
     * item is reached from (and returns to) its batch's "À vérifier" tab.
     *
     * @param array<string, mixed> $extraQuery
     */
    private function reviewRedirect(ProspectReviewPresenter $presenter, array $validated, string $level, string $message, array $extraQuery, int $batchId)
    {
        $query = array_replace([
            'batch' => $validated['return_batch'] ?? null,
            'reason' => $validated['return_reason'] ?? null,
            'state' => $validated['return_state'] ?? null,
            'q' => trim((string) ($validated['return_q'] ?? '')),
            'item' => $validated['return_item'] ?? null,
            'companies_page' => $validated['return_companies_page'] ?? null,
        ], $extraQuery);

        return redirect()->to($presenter->workspaceUrl($batchId, $query))->with($level, $message);
    }
}
