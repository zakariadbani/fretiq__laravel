<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectBatchesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\ProspectBatch;
use App\Services\Prospecting\CompanyListParser;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectReviewPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProspectBatchController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, ProspectBatch $model, ProspectBatchesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->viewConfigClass = \App\Crud\ViewConfigs\ProspectBatchViewConfig::class;
        $this->middleware('permission:view prospect_batches')->only(['index', 'view', 'status']);
        $this->middleware('permission:create prospect_batches')->only(['create', 'store']);
        $this->middleware('permission:edit prospect_batches')->only(['edit', 'update']);
        $this->middleware('permission:delete prospect_batches')->only(['delete']);
        $this->middleware('permission:run prospect resolution')->only(['estimate', 'confirm', 'resumeDiscovery']);

        $this->listTitle = 'Lots de prospection';
        $this->title = 'name';

        $this->bootResource(new BackendResource(
            modelClass: ProspectBatch::class,
            modelName: 'prospect_batches',
            dataTableClass: ProspectBatchesDataTable::class,
            permissionEntity: 'prospect_batches',
            prefixName: 'admin',
            titleField: 'name',
        ));
    }

    public function index()
    {
        $this->currentRequest = request();
        $this->currentDataTable = app(ProspectBatchesDataTable::class);

        if ($this->currentRequest->ajax() && $this->currentRequest->wantsJson()) {
            return $this->currentDataTable->ajax();
        }

        return $this->currentDataTable->render('backend.contents.prospect_batches.crud.index', [
            'listTitle' => $this->listTitle,
            'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
        ]);
    }

    public function create()
    {
        return view('backend.contents.prospect_batches.crud.form', [
            'model' => new ProspectBatch(['quality_preset' => 'balanced']),
            'route' => route('admin.prospect_batches.store'),
            'method' => 'POST',
            'page' => 'create',
            'initialStep' => 1,
        ]);
    }

    public function store(Request $request, CompanyListParser $parser, ProspectBatchService $batches)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'companies_text' => ['nullable', 'string', 'max:10485760'],
            'companies_csv' => ['nullable', 'file', 'max:10240'],
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $hasText = trim((string) ($validated['companies_text'] ?? '')) !== '';
        $hasFile = $request->hasFile('companies_csv');
        if ($hasText === $hasFile) {
            throw ValidationException::withMessages([
                'companies_text' => 'Choisissez soit le copier-coller, soit un fichier CSV.',
            ]);
        }

        $parsed = $hasText
            ? $parser->parseText((string) $validated['companies_text'])
            : $parser->parseCsv($request->file('companies_csv'));
        if (($parsed['rows'] ?? []) === []) {
            throw ValidationException::withMessages([
                'companies_text' => $this->parserMessage($parsed['errors'][0]['code'] ?? 'empty_list'),
            ]);
        }

        $qualitySettings = [];
        if (isset($validated['domain_search_max_results'])) {
            $qualitySettings['domain_search_max_results'] = (int) $validated['domain_search_max_results'];
        }
        $safeErrors = collect($parsed['errors'] ?? [])->take(100)->map(static fn (array $error): array => [
            'row_number' => isset($error['row_number']) ? (int) $error['row_number'] : null,
            'code' => preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($error['code'] ?? '')) === 1
                ? (string) $error['code']
                : 'row_invalid',
        ])->values()->all();

        $batch = $batches->createListBatch($request->user(), $parsed['rows'], [
            'name' => trim((string) ($validated['name'] ?? '')) ?: null,
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => $qualitySettings,
            'source_options' => ['import_errors' => $safeErrors],
        ]);

        return redirect()->route('admin.prospect_batches.edit', ['id' => $batch->id, 'step' => 2])
            ->with('success', $batch->total_items.' entreprise(s) ajoutée(s).');
    }

    public function view(ProspectReviewPresenter $presenter, $id)
    {
        $batch = ProspectBatch::query()->with('creator')->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        return view('backend.contents.prospect_batches.crud.view', [
            'model' => $batch,
            'items' => $batch->items()->withCount('importedContacts')->orderBy('row_number')->paginate(25),
            'viewConfig' => \App\Crud\ViewConfigs\ProspectBatchViewConfig::make($batch),
            'presenter' => $presenter,
            'outcomeBreakdown' => $this->enrichmentOutcomeBreakdown($batch),
        ]);
    }

    /**
     * Why do this batch's promoted companies have no contacts? Scoped to
     * THIS batch's items (via company_id), not the criterion — grouping by
     * criteria_id would also pull in companies discovered by the separate
     * SerpAPI pipeline. One grouped query; never run per row.
     *
     * @return \Illuminate\Support\Collection<int, array{label:string,color:string,total:int}>
     */
    private function enrichmentOutcomeBreakdown(ProspectBatch $batch): \Illuminate\Support\Collection
    {
        return DB::table('prospect_batch_items')
            ->join('companies', 'companies.id', '=', 'prospect_batch_items.company_id')
            ->where('prospect_batch_items.prospect_batch_id', $batch->getKey())
            ->selectRaw('companies.enrichment_status as status, count(*) as total')
            ->groupBy('companies.enrichment_status')
            ->orderByDesc('total')
            ->get()
            ->map(static function (object $row): array {
                $config = $row->status !== null
                    ? config('global.data.company_enrichment_statuses.'.$row->status)
                    : config('global.data.company_enrichment_status_null');

                return [
                    'label' => $config['label'] ?? ($row->status ?? 'Non tenté'),
                    'color' => $config['color'] ?? 'secondary',
                    'total' => (int) $row->total,
                ];
            });
    }

    public function edit($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        return view('backend.contents.prospect_batches.crud.form', [
            'model' => $batch,
            'route' => route('admin.prospect_batches.update', $batch),
            'method' => 'PUT',
            'page' => 'edit',
            'initialStep' => max(1, min(4, (int) request('step', $batch->status === 'draft' ? 2 : 4))),
        ]);
    }

    public function update(Request $request, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if ($batch->status !== 'draft') {
            throw ValidationException::withMessages(['name' => 'Un lot lancé ne peut plus être modifié.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $batch->forceFill([
            'name' => trim($validated['name']),
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => isset($validated['domain_search_max_results'])
                ? ['domain_search_max_results' => (int) $validated['domain_search_max_results']]
                : [],
            'estimate' => null,
        ])->save();

        return redirect()->route('admin.prospect_batches.edit', ['id' => $batch->id, 'step' => 2])
            ->with('success', 'Préférences enregistrées.');
    }

    public function estimate(Request $request, ProspectBatchService $batches, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if ($batch->status !== 'draft') {
            return response()->json(['message' => 'error', 'code' => 'batch_not_draft'], 422);
        }

        $validated = $request->validate([
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $batch->forceFill([
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => isset($validated['domain_search_max_results'])
                ? ['domain_search_max_results' => (int) $validated['domain_search_max_results']]
                : [],
        ])->save();

        return response()->json([
            'estimate' => $batches->estimate($batch->fresh()),
            'estimated_at' => now()->toIso8601String(),
        ]);
    }

    public function confirm(Request $request, ProspectBatchService $batches, $id)
    {
        $request->validate(['confirm_cost' => ['accepted']]);
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        try {
            $batch = $batches->confirmAndDispatch($batch, $request->user());
        } catch (LogicException $exception) {
            $code = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'batch_confirmation_failed';

            return response()->json(['message' => 'error', 'code' => $code], $code === 'prospect_discover_active_batch_exists' ? 409 : 422);
        }

        return response()->json([
            'message' => 'success',
            'status' => $batch->status,
            'status_url' => route('admin.prospect_batches.status', $batch),
            'view_url' => route('admin.prospect_batches.view', $batch),
        ]);
    }

    public function resumeDiscovery(Request $request, ProspectBatchService $batches, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        try {
            $batch = $batches->resumeDiscoverBatch($batch, $request->user());
        } catch (LogicException $exception) {
            $code = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'batch_resume_failed';

            return response()->json(['message' => 'error', 'code' => $code], in_array($code, [
                'prospect_discover_not_resumable',
                'prospect_discover_resume_criteria_stale',
            ], true) ? 409 : 422);
        }

        return response()->json([
            'message' => 'success',
            'status' => $batch->status,
            'status_url' => route('admin.prospect_batches.status', $batch),
            'view_url' => route('admin.prospect_batches.view', $batch),
        ]);
    }

    public function status($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        $total = max(0, (int) $batch->total_items);
        $processed = max(0, min($total, (int) $batch->processed_items));
        $active = in_array($batch->status, ['queued', 'running'], true);
        $workerWaiting = $active
            && $processed < $total
            && $batch->updated_at?->lt(now()->subMinutes(2));

        return response()->json([
            'id' => $batch->id,
            'status' => $batch->status,
            'progress' => [
                'total' => $total,
                'processed' => $processed,
                'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
                'review' => (int) $batch->review_items,
                'failed' => (int) $batch->failed_items,
                'promoted' => (int) $batch->promoted_companies,
                'imported_contacts_count' => (int) $batch->imported_contacts,
            ],
            'worker_waiting' => $workerWaiting,
            'terminal' => in_array($batch->status, ['review', 'completed', 'failed', 'cancelled'], true),
            'view_url' => route('admin.prospect_batches.view', $batch),
            'review_url' => Route::has('admin.prospect_review.index')
                ? route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $batch->getKey()])
                : null,
        ]);
    }

    public function delete($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        abort_unless(
            in_array($batch->status, ['draft', 'cancelled'], true),
            409,
            'Seuls les lots en brouillon ou annulés peuvent être supprimés.',
        );
        $batch->delete();

        return response()->json(['success' => true, 'message' => 'Lot supprimé.']);
    }

    private function authorizeBatch(ProspectBatch $batch): void
    {
        $user = request()->user();
        abort_unless($user !== null && (
            (int) $batch->created_by === (int) $user->getKey()
            || $user->hasRole(['admin', 'superadmin'])
        ), 403);
    }

    private function parserMessage(string $code): string
    {
        return match ($code) {
            'file_too_large', 'input_too_large' => 'Le fichier dépasse 10 Mo.',
            'too_many_rows' => 'La liste dépasse 10 000 entreprises.',
            'unsafe_file', 'unsafe_content' => 'Ce fichier ne peut pas être importé.',
            default => 'Ajoutez au moins une entreprise valide.',
        };
    }
}
