<?php

namespace App\Services\Prospecting;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /** @param array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int,monitor_item?:?int} $filters @return array<string,mixed> */
    public function build(User $user, array $filters): array
    {
        $batches = $this->batchProgress($user, $filters['batch']);
        $items = $this->items($user, $filters);
        $monitoredItem = isset($filters['monitor_item'])
            ? $this->authorizedItems($user)
                ->select(['id', 'prospect_batch_id', 'company_name', 'status', 'domain_reason', 'error_code', 'processing_started_at', 'processed_at'])
                ->with('batch:id,name,status,created_by')
                ->find($filters['monitor_item'])
            : null;
        $requestedItemId = $filters['item'] ?? null;
        $activeItem = $requestedItemId === null
            ? $items->getCollection()->first()
            : $this->authorizedItems($user)
                ->whereIn('status', ['review', 'failed'])
                ->withCount('importedContacts')
                ->find($requestedItemId);
        $monitoredFacts = $monitoredItem === null ? null : $this->statusFacts($monitoredItem);
        $nextItem = $activeItem === null ? null : $this->nextQueuedItem($user, (int) $activeItem->getKey());
        $nextMonitoredItem = $monitoredItem === null ? null : $this->nextQueuedItem($user, (int) $monitoredItem->getKey());

        return [
            'summary' => [
                'handled' => (int) $batches->sum('handled_items_count'),
                'companies_pending' => (int) $batches->sum('company_decisions_count'),
                'imported_contacts' => (int) $batches->sum('imported_contacts_count'),
                'batches' => $batches->count(),
            ],
            'batches' => $batches,
            'items' => $items,
            'activeItem' => $activeItem,
            'activeReview' => $activeItem === null ? null : $this->presenter->companyDecision($activeItem),
            'nextItem' => $nextItem,
            'monitoredItem' => $monitoredItem,
            'monitoredFacts' => $monitoredFacts,
            'nextMonitoredItem' => $nextMonitoredItem,
            'filters' => $filters,
        ];
    }

    private function nextQueuedItem(User $user, int $excludedItemId): ?ProspectBatchItem
    {
        return $this->authorizedItems($user)
            ->select(['id', 'prospect_batch_id', 'company_name'])
            ->whereIn('status', ['review', 'failed'])
            ->whereKeyNot($excludedItemId)
            ->latest('id')
            ->first();
    }

    private function authorizedBatches(User $user): Builder
    {
        return ProspectBatch::query()
            ->when(! $user->hasRole(['admin', 'superadmin']), fn (Builder $query) => $query->where('created_by', $user->id));
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
    private function batchProgress(User $user, ?int $batchId): Collection
    {
        return $this->authorizedBatches($user)
            ->when($batchId !== null, fn (Builder $query) => $query->whereKey($batchId))
            ->when($batchId === null, fn (Builder $query) => $query->where(function (Builder $scope): void {
                $scope->whereIn('status', ['queued', 'running', 'review'])
                    ->orWhereHas('items', fn (Builder $items) => $items->whereIn('status', ['review', 'failed']));
            }))
            ->withCount([
                'items as handled_items_count' => fn (Builder $query) => $query->whereIn('status', ['ready', 'promoted', 'skipped']),
                'items as company_decisions_count' => fn (Builder $query) => $query->whereIn('status', ['review', 'failed']),
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

    /** @param array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int} $filters */
    private function items(User $user, array $filters): LengthAwarePaginator
    {
        $search = mb_strtolower($filters['q']);

        return $this->authorizedItems($user)
            ->select([
                'id', 'prospect_batch_id', 'company_name', 'country', 'city', 'provided_domain',
                'selected_domain', 'domain_alternatives', 'domain_confidence', 'domain_reason',
                'error_code', 'status', 'source_metadata', 'created_at',
            ])
            ->with('batch:id,name,status,source_type,created_by')
            ->whereIn('status', ['review', 'failed'])
            ->withCount('importedContacts')
            ->when($filters['batch'] !== null, fn (Builder $query) => $query->where('prospect_batch_id', $filters['batch']))
            ->when(($filters['state'] ?? 'all') === 'attention', fn (Builder $query) => $query->where('status', 'review'))
            ->when(($filters['state'] ?? 'all') === 'blocked', fn (Builder $query) => $query->where('status', 'failed'))
            ->when($filters['reason'] !== null, fn (Builder $query) => $query->where(fn (Builder $reasons) => $reasons
                ->where('domain_reason', $filters['reason'])
                ->orWhere('error_code', $filters['reason'])))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matches) use ($search): void {
                $like = '%'.$search.'%';
                $matches->whereRaw('LOWER(company_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(country) LIKE ?', [$like])
                    ->orWhereHas('batch', fn (Builder $batch) => $batch->whereRaw('LOWER(name) LIKE ?', [$like]));
            }))
            ->latest('id')
            ->paginate(10, ['*'], 'companies_page')
            ->withQueryString();
    }
}
