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

    public function index(Request $request, ProspectReviewWorkspace $workspace, ProspectReviewPresenter $presenter)
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', Rule::in(['companies', 'contacts'])],
            'reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'state' => ['nullable', Rule::in(['all', 'attention', 'blocked'])],
            'q' => ['nullable', 'string', 'max:100'],
            'item' => ['nullable', 'integer', 'min:1'],
            'monitor_item' => ['nullable', 'integer', 'min:1'],
        ]);
        if (($validated['tab'] ?? null) === 'contacts') {
            return redirect()->route('admin.prospect_review.index', array_filter([
                'batch' => $validated['batch'] ?? null,
                'tab' => 'companies',
                'state' => $validated['state'] ?? null,
                'q' => $validated['q'] ?? null,
            ]))->with('info', 'Les contacts sont désormais importés automatiquement ; cette file ne contient que les entreprises à vérifier.');
        }
        $itemId = isset($validated['item']) ? (int) $validated['item'] : null;

        // An explicit ?state= always wins. Only the *default* (no state param
        // at all) is item-aware: a deep link to one item — bookmark, email,
        // notification — must land on the pill that actually contains it, or
        // the item silently falls off the default "À décider" queue and its
        // card renders empty. A failed item defaults to "blocked", a review
        // item to "attention"; anything else (already decided, wrong owner)
        // keeps the general "attention" default.
        $state = $validated['state']
            ?? ($itemId !== null ? $workspace->defaultStateForItem($request->user(), $itemId) : 'attention');

        $filters = [
            'batch' => isset($validated['batch']) ? (int) $validated['batch'] : null,
            'tab' => 'companies',
            'reason' => $validated['reason'] ?? null,
            'state' => $state,
            'q' => trim((string) ($validated['q'] ?? '')),
            'item' => $itemId,
            'monitor_item' => isset($validated['monitor_item']) ? (int) $validated['monitor_item'] : null,
        ];

        $data = $workspace->build($request->user(), $filters);

        // Selecting a queue item is not a decision — it shouldn't cost a full page load.
        // The fetch-driven pane swap in index.blade.php requests this same route with
        // `partial=1` and the full current query string, and gets back just the detail
        // pane markup (`_review-detail`), built from the exact same $data as the full page.
        if ($request->boolean('partial')) {
            return view('backend.contents.prospect_review.partials._review-detail', [
                ...$data,
                'reviewPresenter' => $presenter,
            ]);
        }

        // null = bulk bar not shown at all (not on the "À relancer" pill, or
        // nothing blocked to act on); true = show the drain button; false =
        // every blocked item is structurally stuck on an inactive criterion,
        // so show the reason instead of a button that cannot work.
        $blockedDrainReady = $filters['state'] === 'blocked' && $data['stateCounts']['blocked'] > 0
            ? $workspace->hasRetryableBlockedItem($request->user(), $filters)
            : null;

        return view('backend.contents.prospect_review.index', [
            ...$data,
            'reviewPresenter' => $presenter,
            'blockedDrainReady' => $blockedDrainReady,
        ]);
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
        $itemIds = $workspace->blockedItemsQuery($request->user(), $filters)
            ->oldest('id')
            ->limit(self::DRAIN_ITEM_CAP)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($itemIds === []) {
            return response()->json(['message' => 'error', 'code' => 'prospect_retry_drain_empty'], 422);
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
            'review_url' => route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $item->prospect_batch_id,
                'item' => $item->getKey(),
                'monitor_item' => $item->getKey(),
            ], false),
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
                return $this->reviewRedirect($validated, 'warning', 'Cette décision a déjà été traitée ; la file a été actualisée.');
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

        $message = match ($validated['action']) {
            'approve_domain' => 'Domaine enregistré ; cette entreprise reprend son traitement.',
            'retry' => 'Relance demandée. Cette entreprise peut réapparaître ici si une étape échoue de nouveau.',
            'reject' => 'Entreprise exclue de ce lot.',
        };

        return $this->reviewRedirect(
            $validated,
            'success',
            $message,
            $validated['action'] === 'retry' ? ['monitor_item' => $persisted->id] : [],
            $validated['action'] !== 'retry',
        );
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
     * deliberately excludes state/item/tab, which don't apply to a bulk
     * action against the blocked set.
     *
     * @return array{batch:?int,reason:?string,q:string}
     */
    private function drainFilters(Request $request): array
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'batch' => isset($validated['batch']) ? (int) $validated['batch'] : null,
            'reason' => $validated['reason'] ?? null,
            'q' => trim((string) ($validated['q'] ?? '')),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function returnContextRules(): array
    {
        return [
            'return_batch' => ['nullable', 'integer', 'min:1'],
            'return_tab' => ['nullable', Rule::in(['companies'])],
            'return_reason' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'return_state' => ['nullable', Rule::in(['all', 'attention', 'blocked'])],
            'return_q' => ['nullable', 'string', 'max:100'],
            'return_item' => ['nullable', 'integer', 'min:1'],
            'return_companies_page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /** @param array<string, mixed> $extraQuery */
    private function reviewRedirect(array $validated, string $level, string $message, array $extraQuery = [], bool $withFeedback = true)
    {
        $query = array_filter([
            'batch' => $validated['return_batch'] ?? null,
            'tab' => 'companies',
            'reason' => $validated['return_reason'] ?? null,
            'state' => $validated['return_state'] ?? null,
            'q' => trim((string) ($validated['return_q'] ?? '')) ?: null,
            'item' => $validated['return_item'] ?? null,
            'companies_page' => $validated['return_companies_page'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $query = array_replace($query, $extraQuery);

        $redirect = redirect()->route('admin.prospect_review.index', $query);

        return $withFeedback ? $redirect->with($level, $message) : $redirect;
    }
}
