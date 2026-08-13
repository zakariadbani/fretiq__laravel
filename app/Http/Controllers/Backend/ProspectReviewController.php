<?php

namespace App\Http\Controllers\Backend;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProspectReviewController extends BackendController
{
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
        $filters = [
            'batch' => isset($validated['batch']) ? (int) $validated['batch'] : null,
            'tab' => 'companies',
            'reason' => $validated['reason'] ?? null,
            'state' => $validated['state'] ?? 'all',
            'q' => trim((string) ($validated['q'] ?? '')),
            'item' => isset($validated['item']) ? (int) $validated['item'] : null,
            'monitor_item' => isset($validated['monitor_item']) ? (int) $validated['monitor_item'] : null,
        ];

        return view('backend.contents.prospect_review.index', [
            ...$workspace->build($request->user(), $filters),
            'reviewPresenter' => $presenter,
        ]);
    }

    public function itemStatus(ProspectBatchItem $item, ProspectReviewPresenter $presenter, ProspectReviewWorkspace $workspace)
    {
        $this->authorizeBatch($item->batch()->firstOrFail());
        $outcome = $presenter->retryOutcome($item->fresh());
        $facts = $workspace->statusFacts($item->fresh());

        return response()->json([
            'id' => (int) $item->getKey(),
            'status' => $outcome['status'],
            'terminal' => $outcome['terminal'],
            'level' => $outcome['level'],
            'title' => $outcome['title'],
            'message' => $outcome['message'],
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
            $persisted = DB::transaction(function () use ($item, $validated, $domains, $providerCalls, &$dispatch): ProspectBatchItem {
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
