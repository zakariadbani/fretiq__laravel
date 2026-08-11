<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProspectBatchItem;
use Illuminate\Http\Request;

class ProspectReviewDataTable extends BackendDataTable
{
    protected $skipDefaultAction = true;

    protected $columns = [
        'company_name' => ['title' => 'Entreprise', 'orderable' => true, 'searchable' => true, 'raw' => true],
        'batch' => ['title' => 'Lot', 'orderable' => false, 'searchable' => true, 'raw' => true],
        'domain_reason' => ['title' => 'Pourquoi', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'alternatives' => ['title' => 'Domaines proposés', 'orderable' => false, 'searchable' => false, 'raw' => true],
        'action' => ['title' => 'Action', 'orderable' => false, 'searchable' => false, 'raw' => true],
    ];

    protected $table_filters = [
        'domain_reason' => [
            'type' => 'select_enum',
            'filterKey' => 'domain_reason',
            'configKey' => 'prospect_review_reasons',
            'title' => 'Motif',
        ],
    ];

    public function __construct(ProspectBatchItem $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    public function query()
    {
        $query = $this->currentModel->newQuery()
            ->select([
                'id',
                'prospect_batch_id',
                'company_name',
                'country',
                'city',
                'domain_alternatives',
                'domain_reason',
                'error_code',
                'status',
                'created_at',
            ])
            ->with('batch:id,name,created_by')
            ->whereIn('status', ['review', 'failed']);
        $user = request()->user();
        if ($user !== null && ! $user->hasRole(['admin', 'superadmin'])) {
            $query->whereHas('batch', fn ($batch) => $batch->where('created_by', $user->id));
        }

        return $query;
    }

    protected function createEditColumns(): void
    {
        $this->datatables->filterColumn('batch', function ($query, $keyword): void {
            $query->whereHas('batch', fn ($batch) => $batch->where('name', 'like', '%'.$keyword.'%'));
        });
        $this->datatables->editColumn('company_name', static fn (ProspectBatchItem $row): string => '<strong>'.e($row->company_name).'</strong><div class="text-muted fs-8">'.e(collect([$row->city, $row->country])->filter()->join(', ')).'</div>');
        $this->datatables->editColumn('batch', static function (ProspectBatchItem $row): string {
            return $row->batch === null ? '—' : '<a href="'.e(route('admin.prospect_batches.view', $row->batch)).'">'.e($row->batch->name).'</a>';
        });
        $this->datatables->editColumn('domain_reason', static function (ProspectBatchItem $row): string {
            $label = config('global.data.prospect_review_reasons.'.$row->domain_reason.'.label', str_replace('_', ' ', (string) ($row->domain_reason ?: $row->error_code)));

            return '<span class="badge badge-light-warning">'.e($label ?: 'À revoir').'</span>';
        });
        $this->datatables->addColumn('alternatives', static function (ProspectBatchItem $row): string {
            $domains = collect($row->domain_alternatives ?? [])->take(5)->map(fn ($domain) => '<span class="badge badge-light me-1">'.e($domain).'</span>');

            return $domains->isEmpty() ? '<span class="text-muted">Aucun domaine</span>' : $domains->join('');
        });
        $this->datatables->addColumn('action', static function (ProspectBatchItem $row): string {
            $url = route('admin.prospect_review.items.decide', $row);
            $token = csrf_token();
            $options = collect($row->domain_alternatives ?? [])->take(20)->map(fn ($domain) => '<option value="'.e($domain).'">'.e($domain).'</option>')->join('');
            $approve = $options === '' ? '' : '<form method="post" action="'.e($url).'" class="d-flex gap-2 mb-2"><input type="hidden" name="_token" value="'.e($token).'"><input type="hidden" name="action" value="approve_domain"><select name="selected_domain" class="form-select form-select-sm" required>'.$options.'</select><button class="btn btn-sm btn-primary">Choisir</button></form>';

            $retryConfirmation = $row->error_code === 'provider_outcome_uncertain'
                ? '<label class="form-check form-check-sm mb-1"><input class="form-check-input" type="checkbox" name="confirm_provider_reissue" value="1" required><span class="form-check-label fs-8">Je confirme la relance fournisseur</span></label>'
                : '';

            return $approve.'<div class="d-flex gap-2"><form method="post" action="'.e($url).'" class="d-flex flex-column"><input type="hidden" name="_token" value="'.e($token).'"><input type="hidden" name="action" value="retry">'.$retryConfirmation.'<button class="btn btn-sm btn-light-primary">Réessayer</button></form><form method="post" action="'.e($url).'"><input type="hidden" name="_token" value="'.e($token).'"><input type="hidden" name="action" value="reject"><button class="btn btn-sm btn-light-danger">Ignorer</button></form></div>';
        });
    }

    protected function getEntityName(): string
    {
        return 'prospect_review';
    }
}
