<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectReviewDataTable;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Services\Discovery\DomainCanonicalizer;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProspectReviewController extends BackendController
{
    public function __construct(Request $request, ProspectReviewDataTable $dataTable)
    {
        parent::__construct($request, new ProspectBatchItem, $dataTable);
        $this->middleware('permission:review prospect matches');
    }

    public function index(ProspectReviewDataTable $dataTable)
    {
        if (request()->ajax() && request()->wantsJson()) {
            return $dataTable->ajax();
        }

        $candidates = ProspectContactCandidate::query()
            ->with(['batch:id,name,created_by', 'item:id,company_name'])
            ->where('decision', 'pending')
            ->when(! $this->privileged(), fn ($query) => $query->whereHas('batch', fn ($batch) => $batch->where('created_by', request()->user()->id)))
            ->latest('id')
            ->paginate(25, ['*'], 'contacts_page');

        return $dataTable->render('backend.contents.prospect_review.index', [
            'dataTableConfig' => $dataTable->getIndexConfig(),
            'candidates' => $candidates,
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
        ]);
        $this->authorizeBatch($item->batch()->firstOrFail());

        $dispatch = false;
        $persisted = DB::transaction(function () use ($item, $validated, $domains, $providerCalls, &$dispatch): ProspectBatchItem {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            $metadata = is_array($locked->source_metadata) ? $locked->source_metadata : [];
            $action = $validated['action'];

            if ($action === 'approve_domain') {
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

        $batches->refreshCounters($persisted->batch);
        if ($dispatch) {
            ProcessProspectBatchItemJob::dispatch($persisted->id)->afterCommit();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'success', 'id' => $persisted->id, 'status' => $persisted->status]);
        }

        return redirect()->route('admin.prospect_review.index')->with('success', 'Décision enregistrée.');
    }

    public function decideCandidate(Request $request, ProspectContactCandidate $candidate, ProspectBatchService $batches)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', Rule::in(['not_relevant', 'bad_data', 'risky_email'])],
        ]);
        $this->authorizeBatch($candidate->batch()->firstOrFail());

        $candidate = DB::transaction(function () use ($candidate, $validated): ProspectContactCandidate {
            $locked = ProspectContactCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            $target = $validated['action'] === 'approve' ? 'approved' : 'rejected';
            if (in_array($locked->decision, [$target, 'promoted'], true)) {
                return $locked;
            }
            abort_unless($locked->decision === 'pending', 409);
            $locked->forceFill([
                'decision' => $target,
                'decision_reason' => $validated['reason'] ?? ($target === 'rejected' ? 'reviewer_rejected' : null),
                'decided_by' => request()->user()->id,
                'decided_at' => now(),
            ])->save();

            return $locked->fresh();
        });

        if ($validated['action'] === 'approve' && $candidate->decision !== 'promoted') {
            try {
                $batches->promoteContactCandidate($candidate, $request->user());
            } catch (LogicException $exception) {
                $code = $this->safeCode($exception->getMessage());
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'error', 'code' => $code], 422);
                }

                return redirect()->route('admin.prospect_review.index')->with('error', 'Ce contact ne peut pas être importé automatiquement.');
            }
            $candidate->refresh();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'success', 'id' => $candidate->id, 'decision' => $candidate->decision]);
        }

        return redirect()->route('admin.prospect_review.index')->with('success', 'Décision enregistrée.');
    }

    private function authorizeBatch(ProspectBatch $batch): void
    {
        abort_unless($this->privileged() || (int) $batch->created_by === (int) request()->user()->id, 403);
    }

    private function privileged(): bool
    {
        return request()->user()?->hasRole(['admin', 'superadmin']) ?? false;
    }

    private function safeCode(string $value): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1 ? $value : 'candidate_promotion_failed';
    }
}
