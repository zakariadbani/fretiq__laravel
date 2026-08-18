<?php

namespace App\Services\Prospecting;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class ProspectReviewWorkspace
{
    public function __construct(private readonly ProspectReviewPresenter $presenter) {}

    /** @return array{imported_contacts_count:int,provider_result_count:?int,recorded_units:?float} */
    public function statusFacts(ProspectBatchItem $item): array
    {
        $imported = ProspectBatchContact::query()
            ->where('prospect_batch_item_id', $item->getKey())
            ->count();
        $startedAt = $item->processing_started_at;
        $finishedAt = $item->processed_at;

        if ($startedAt === null || $finishedAt === null || $finishedAt->lt($startedAt)) {
            return [
                'imported_contacts_count' => $imported,
                'provider_result_count' => null,
                'recorded_units' => null,
            ];
        }

        $call = ProviderCall::query()
            ->where('prospect_batch_item_id', $item->getKey())
            ->where('provider', 'hunter')
            ->where('operation', 'domain_search')
            ->where('status', 'succeeded')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereBetween('started_at', [$startedAt, $finishedAt])
            ->whereBetween('finished_at', [$startedAt, $finishedAt])
            ->latest('finished_at')
            ->latest('id')
            ->first(['result_count', 'consumed_units']);

        return [
            'imported_contacts_count' => $imported,
            'provider_result_count' => $call?->result_count,
            'recorded_units' => $call === null || $call->consumed_units === null
                ? null
                : (float) $call->consumed_units,
        ];
    }

    /**
     * Sanitizes-and-falls-back raw request params into filters and builds the
     * full workspace payload — never throws on invalid input (criteria-controller
     * style: unknown/invalid values silently fall back to their default). Used
     * by the batch-hosted "À vérifier" pane (ProspectBatchController::view()),
     * where a junk query string must never bounce the whole batch page.
     *
     * @return array{filters:array,data:array<string,mixed>,blockedDrainReady:?bool,stateExplicit:bool}
     */
    public function buildFromRequest(Request $request, User $user, int $hostBatchId): array
    {
        $reasonParam = $request->query('reason');
        $reason = is_string($reasonParam) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reasonParam) === 1
            ? $reasonParam
            : null;

        $stateParam = $request->query('state');
        $stateExplicit = is_string($stateParam) && in_array($stateParam, ['all', 'attention', 'blocked'], true);

        $qParam = $request->query('q', '');
        $q = trim(is_string($qParam) ? $qParam : '');
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        $itemParam = $request->query('item');
        $itemId = is_numeric($itemParam) && (int) $itemParam >= 1 ? (int) $itemParam : null;

        $monitorParam = $request->query('monitor_item');
        $monitorItemId = is_numeric($monitorParam) && (int) $monitorParam >= 1 ? (int) $monitorParam : null;

        $state = $stateExplicit
            ? $stateParam
            : ($itemId !== null
                ? $this->defaultStateForItem($user, $itemId)
                : $this->defaultState($user, $hostBatchId, $reason, $q));

        $filters = [
            'batch' => $hostBatchId,
            'tab' => 'companies',
            'reason' => $reason,
            'state' => $state,
            'q' => $q,
            'item' => $itemId,
            'monitor_item' => $monitorItemId,
        ];

        $data = $this->build($user, $filters);

        $blockedDrainReady = $filters['state'] === 'blocked' && $data['stateCounts']['blocked'] > 0
            ? $this->hasRetryableBlockedItem($user, $filters)
            : null;

        return [
            'filters' => $filters,
            'data' => $data,
            'blockedDrainReady' => $blockedDrainReady,
            'stateExplicit' => $stateExplicit,
        ];
    }

    /**
     * $filters['batch'] is always the host batch — ProspectBatchController::
     * authorizeBatch() has already checked ownership for this exact batch id
     * before either real caller (buildFromRequest(), or a direct test call)
     * reaches here, so batchProgress() below goes straight to the row instead
     * of re-deriving an ownership-filtered batch list.
     *
     * @param  array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int,monitor_item?:?int}  $filters
     * @return array<string,mixed>
     */
    public function build(User $user, array $filters): array
    {
        $batches = $this->batchProgress($filters['batch']);
        $items = $this->items($user, $filters);
        $stateCounts = $this->stateCounts($user, $filters);
        $monitoredItem = isset($filters['monitor_item'])
            ? $this->authorizedItems($user)
                ->when($filters['batch'] !== null, fn (Builder $query) => $query->where('prospect_batch_id', $filters['batch']))
                ->select(['id', 'prospect_batch_id', 'company_name', 'status', 'domain_reason', 'error_code', 'processing_started_at', 'processed_at'])
                ->with([
                    'batch:id,name,status,created_by,prospect_criteria_id',
                    'batch.criteria:id,name,is_active',
                ])
                ->find($filters['monitor_item'])
            : null;
        $requestedItemId = $filters['item'] ?? null;
        // A requested item that no longer resolves (already decided, 409
        // double-submit, back/refresh, stale bookmark, or simply not owned
        // by this user) falls back to the first visible item instead of an
        // empty pane — this is the structural fix for the "disappearing
        // card" bug: post-decision, the requested item is gone from the
        // ['review','failed'] scope, but there's usually another item left
        // to review. Exception: when the requested item IS the monitored
        // item (a retry redirect — item and monitor_item are the same id),
        // that item is deliberately mid-flight (status flips to 'pending')
        // and the monitor banner above already reports on it. Falling back
        // here would silently swap in a *different* company's actionable
        // card under a banner reporting on the retried one, so the fallback
        // is suppressed and $activeItem stays null — the pane instead shows
        // the monitored-result placeholder (or the empty state until the
        // retry resolves).
        // Derived from the RESOLVED $monitoredItem, not the raw filter value: the
        // monitored lookup above is already batch-scoped when $filters['batch'] is
        // set, so a crafted ?item=X&monitor_item=X where X belongs to a different
        // batch fails to resolve $monitoredItem and correctly falls through to the
        // normal fallback instead of suppressing it for an item that never renders.
        $isMonitoredRetry = $requestedItemId !== null
            && $monitoredItem !== null
            && (int) $monitoredItem->getKey() === (int) $requestedItemId;
        $activeItem = $requestedItemId === null
            ? $items->getCollection()->first()
            : ($this->authorizedItems($user)
                ->when($filters['batch'] !== null, fn (Builder $query) => $query->where('prospect_batch_id', $filters['batch']))
                ->whereIn('status', ['review', 'failed'])
                ->with([
                    'batch:id,name,status,source_type,created_by,prospect_criteria_id',
                    'batch.criteria:id,name,is_active',
                ])
                ->withCount('importedContacts')
                ->find($requestedItemId)
                ?: ($isMonitoredRetry ? null : $items->getCollection()->first()));
        $monitoredFacts = $monitoredItem === null ? null : $this->statusFacts($monitoredItem);
        $nextItem = $activeItem === null ? null : $this->nextQueuedItem($user, $filters, (int) $activeItem->getKey());
        $nextMonitoredItem = $monitoredItem === null ? null : $this->nextQueuedItem($user, $filters, (int) $monitoredItem->getKey());

        return [
            'summary' => [
                'handled' => (int) $batches->sum('handled_items_count'),
                'companies_pending' => (int) $batches->sum('company_decisions_count'),
                'companies_attention' => (int) $batches->sum('attention_items_count'),
                'companies_blocked' => (int) $batches->sum('blocked_items_count'),
                'imported_contacts' => (int) $batches->sum('imported_contacts_count'),
                'batches' => $batches->count(),
            ],
            'batches' => $batches,
            'items' => $items,
            'stateCounts' => $stateCounts,
            'activeItem' => $activeItem,
            'activeReview' => $activeItem === null ? null : $this->presenter->companyDecision($activeItem),
            'nextItem' => $nextItem,
            'monitoredItem' => $monitoredItem,
            'monitoredFacts' => $monitoredFacts,
            'nextMonitoredItem' => $nextMonitoredItem,
            'filters' => $filters,
        ];
    }

    /**
     * Resolves which state pill a plain deep link (no explicit ?state=) to
     * a specific item should default to, so the pill/aria-current stays
     * truthful about the row it's actually showing — a failed item never
     * silently defaults onto the "À décider" pill it isn't part of. Only
     * fills the *default*; an explicit ?state= is never touched by this.
     * Falls back to the general "attention" default when the item can't be
     * resolved to review/failed (wrong owner, already decided, deleted).
     */
    public function defaultStateForItem(User $user, int $itemId): string
    {
        $status = $this->authorizedItems($user)->whereKey($itemId)->value('status');

        return $status === 'failed' ? 'blocked' : 'attention';
    }

    /**
     * Plain load, no ?item= to pin a default either — pick whichever pill
     * actually has something in it (attention first, since that's the
     * common "needs a decision" case) instead of hardcoding 'attention'.
     * A batch whose 51 open items are all "À relancer" (blocked) must not
     * default onto the empty "À décider" pill and render a false "nothing
     * to review" empty state. Runs before $filters exists (it feeds the
     * state that goes into $filters), so batch/reason/q are passed loose.
     */
    private function defaultState(User $user, ?int $batch, ?string $reason, string $q): string
    {
        $counts = $this->stateCounts($user, ['batch' => $batch, 'reason' => $reason, 'q' => $q]);

        return match (true) {
            $counts['attention'] > 0 => 'attention',
            $counts['blocked'] > 0 => 'blocked',
            default => 'all',
        };
    }

    /** @param array{batch:?int,reason:?string,state?:string,q:string} $filters */
    public function nextQueuedItem(User $user, array $filters, int $excludedItemId): ?ProspectBatchItem
    {
        // "Next" is the immediate neighbour in the same filtered + ordered list `items()`
        // renders (latest id first), not just any other review/failed row — otherwise
        // "Continuer la file" / "Décider plus tard" can silently jump the user to a
        // different page or a different state pill than the one they're looking at.
        return $this->stateFilteredItems($user, $filters)
            ->select(['id', 'prospect_batch_id', 'company_name'])
            ->where('id', '<', $excludedItemId)
            ->latest('id')
            ->first();
    }

    private function authorizedItems(User $user): Builder
    {
        return ProspectBatchItem::query()
            ->whereHas('batch', fn (Builder $query) => $query->when(
                ! $user->hasRole(['admin', 'superadmin']),
                fn (Builder $owned) => $owned->where('created_by', $user->id),
            ));
    }

    /** @return Collection<int, ProspectBatch> */
    private function batchProgress(?int $batchId): Collection
    {
        return ProspectBatch::query()
            ->whereKey($batchId)
            ->withCount([
                'items as handled_items_count' => fn (Builder $query) => $query->whereIn('status', ['ready', 'promoted', 'skipped']),
                'items as company_decisions_count' => fn (Builder $query) => $query->whereIn('status', ['review', 'failed']),
                'items as attention_items_count' => fn (Builder $query) => $query->where('status', 'review'),
                'items as blocked_items_count' => fn (Builder $query) => $query->where('status', 'failed'),
                'items as processing_items_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'processing']),
                'importedContacts',
            ])
            ->latest('id')
            ->get()
            ->map(function (ProspectBatch $batch): ProspectBatch {
                $manualPending = (int) $batch->company_decisions_count;
                $batch->setAttribute('manual_pending_count', $manualPending);
                $batch->setAttribute('next_state', $this->presenter->batchNextState($batch, $manualPending));

                return $batch;
            });
    }

    /**
     * @param array{batch:?int,reason:?string,q:string} $filters
     * @param list<string> $statuses
     */
    private function filteredItems(User $user, array $filters, array $statuses = ['review', 'failed']): Builder
    {
        $search = mb_strtolower($filters['q']);

        return $this->authorizedItems($user)
            ->whereIn('status', $statuses)
            ->when($filters['batch'] !== null, fn (Builder $query) => $query->where('prospect_batch_id', $filters['batch']))
            ->when($filters['reason'] !== null, fn (Builder $query) => $query->where(fn (Builder $reasons) => $reasons
                ->where('domain_reason', $filters['reason'])
                ->orWhere('error_code', $filters['reason'])))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matches) use ($search): void {
                $like = '%'.$search.'%';
                $matches->whereRaw('LOWER(company_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(country) LIKE ?', [$like])
                    ->orWhereHas('batch', fn (Builder $batch) => $batch->whereRaw('LOWER(name) LIKE ?', [$like]));
            }));
    }

    /** @param array{batch:?int,reason:?string,state?:string,q:string} $filters */
    private function stateFilteredItems(User $user, array $filters): Builder
    {
        return $this->filteredItems($user, $filters)
            ->when(($filters['state'] ?? 'all') === 'attention', fn (Builder $query) => $query->where('status', 'review'))
            ->when(($filters['state'] ?? 'all') === 'blocked', fn (Builder $query) => $query->where('status', 'failed'));
    }

    /**
     * Public "À relancer" query, reused by the retry-drain preview and
     * dispatch endpoints so a drain always targets exactly the same
     * ownership- and filter-scoped set the pill/list already show — never a
     * second, hand-rolled copy of the filtering rules.
     *
     * @param array{batch:?int,reason:?string,q:string} $filters
     */
    public function blockedItemsQuery(User $user, array $filters): Builder
    {
        return $this->stateFilteredItems($user, [...$filters, 'state' => 'blocked']);
    }

    /**
     * "Reprendre les lignes en attente" drain query — `pending` items an
     * operator's own retry click orphaned (decideItem() flipped them to
     * pending and dispatched a job, but nothing consumed the queue), never a
     * `pending` item mid-provider-backoff.
     *
     * `error_code IS NULL` is the load-bearing predicate, not the timestamp:
     * markRetryPending() (ProspectItemProcessor) always sets an error_code
     * on the backoff path, while decideItem() explicitly nulls it on the
     * operator-retry path — one predicate cleanly separates "operator queued
     * it, nobody picked it up" from "mid-backoff, never touch". The
     * 15-minute staleness window is a coarse second filter only.
     *
     * The batch-status exclusion keeps this away from batches whose
     * finalizer/lifecycle can no longer move (completed/cancelled/draft) —
     * ProcessProspectBatchItemJob won't flip those back to running and
     * FinalizeProspectBatchJob bails on them, so a retry there would
     * reprocess the item while the batch's status/counters stay stranded.
     *
     * Bypasses stateFilteredItems() deliberately: that helper hard-ANDs
     * status IN ('review','failed') from filteredItems()'s default before
     * any state branch runs, so a 'stalled' branch there could never match
     * a 'pending' row. Calling filteredItems() directly with the pending
     * status list is what actually works.
     *
     * @param array{batch:?int,reason:?string,q:string} $filters
     */
    public function stalledItemsQuery(User $user, array $filters): Builder
    {
        return $this->filteredItems($user, $filters, ['pending'])
            ->whereNull('error_code')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->whereHas('batch', fn (Builder $query) => $query->whereNotIn('status', ['completed', 'cancelled', 'draft']));
    }

    /**
     * Batch-scoped count the Résultats tab's "Reprendre les lignes en
     * attente" button gates on and labels itself with — the only shape the
     * UI needs, so the controller never has to hand-build a filters array.
     */
    public function stalledItemsCountForBatch(User $user, int $batchId): int
    {
        return $this->stalledItemsQuery($user, ['batch' => $batchId, 'reason' => null, 'q' => ''])->count();
    }

    /**
     * Cheap, structural-only check for the bulk action bar: is there at
     * least one blocked item whose batch isn't pinned to an inactive
     * criterion? Deliberately narrower than the drain's full eligibility
     * classification (retry window / ledger budget are per-attempt and
     * transient — worth surfacing via the preview dialog, not worth a query
     * on every page load) — this only covers the durable, structural block
     * the task calls out: a button that literally cannot work until an
     * admin reactivates a criterion.
     *
     * @param array{batch:?int,reason:?string,q:string} $filters
     */
    public function hasRetryableBlockedItem(User $user, array $filters): bool
    {
        return $this->blockedItemsQuery($user, $filters)
            ->whereHas('batch', function (Builder $query): void {
                $query->whereNull('prospect_criteria_id')
                    ->orWhereHas('criteria', fn (Builder $criteria) => $criteria->where('is_active', true));
            })
            ->exists();
    }

    /** @param array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int} $filters */
    private function items(User $user, array $filters): LengthAwarePaginator
    {
        return $this->stateFilteredItems($user, $filters)
            ->select([
                'id', 'prospect_batch_id', 'company_name', 'country', 'city', 'provided_domain',
                'selected_domain', 'domain_alternatives', 'domain_confidence', 'domain_reason',
                'error_code', 'status', 'source_metadata', 'created_at',
            ])
            ->with([
                'batch:id,name,status,source_type,created_by,prospect_criteria_id',
                'batch.criteria:id,name,is_active',
            ])
            ->withCount('importedContacts')
            ->latest('id')
            ->paginate(10, ['*'], 'companies_page')
            ->withQueryString();
    }

    /** @param array{batch:?int,reason:?string,q:string} $filters @return array{all:int,attention:int,blocked:int} */
    private function stateCounts(User $user, array $filters): array
    {
        $base = $this->filteredItems($user, $filters);
        $attention = (clone $base)->where('status', 'review')->count();
        $blocked = (clone $base)->where('status', 'failed')->count();

        return ['all' => $attention + $blocked, 'attention' => $attention, 'blocked' => $blocked];
    }
}
