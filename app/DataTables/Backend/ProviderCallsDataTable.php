<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProviderCall;
use Illuminate\Http\Request;

class ProviderCallsDataTable extends BackendDataTable
{
    protected $skipDefaultAction = true;

    protected $columns = [
        'provider' => ['title' => 'Fournisseur', 'orderable' => true, 'searchable' => true, 'raw' => true],
        'operation' => ['title' => 'Opération', 'orderable' => true, 'searchable' => true],
        'engine' => ['title' => 'Moteur', 'orderable' => true, 'searchable' => true],
        'status' => ['title' => 'Statut', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'http_status' => ['title' => 'HTTP', 'orderable' => true, 'searchable' => false],
        'result_count' => ['title' => 'Résultats', 'orderable' => true, 'searchable' => false],
        'units' => ['title' => 'Unités', 'orderable' => false, 'searchable' => false],
        'duration_ms' => ['title' => 'Durée', 'orderable' => true, 'searchable' => false],
        'attempt_count' => ['title' => 'Tentatives', 'orderable' => true, 'searchable' => false],
        'safe_error' => ['title' => 'Erreur sûre', 'orderable' => false, 'searchable' => false],
        'created_at' => ['title' => 'Date', 'orderable' => true, 'searchable' => false],
    ];

    protected $table_filters = [
        'provider' => ['type' => 'text', 'filterKey' => 'provider', 'title' => 'Fournisseur'],
        'status' => ['type' => 'select_enum', 'filterKey' => 'status', 'configKey' => 'provider_call_statuses', 'title' => 'Statut'],
    ];

    public function __construct(ProviderCall $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    public function query()
    {
        return $this->currentModel->newQuery()->select([
            'id', 'provider', 'operation', 'engine', 'status', 'http_status',
            'duration_ms', 'result_count', 'reserved_units', 'consumed_units',
            'attempt_count', 'metadata', 'retry_at', 'started_at', 'finished_at', 'created_at',
        ]);
    }

    protected function createEditColumns(): void
    {
        $this->datatables->editColumn('provider', static fn (ProviderCall $row): string => '<span class="badge badge-light-primary">'.e(ucfirst($row->provider)).'</span>');
        $this->datatables->editColumn('status', static function (ProviderCall $row): string {
            $color = match ($row->status) {
                'succeeded' => 'success',
                'failed' => 'danger',
                'retryable', 'pending' => 'warning',
                'running', 'reserved' => 'primary',
                default => 'secondary',
            };

            return '<span class="badge badge-light-'.e($color).'">'.e($row->status).'</span>';
        });
        $this->datatables->addColumn('units', static fn (ProviderCall $row): string => e((string) $row->consumed_units).'/'.e((string) $row->reserved_units));
        $this->datatables->addColumn('safe_error', static function (ProviderCall $row): string {
            $value = data_get($row->metadata, 'error_code');

            return is_string($value) && preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $value) === 1 ? e($value) : '—';
        });
        $this->datatables->editColumn('duration_ms', static fn (ProviderCall $row): string => $row->duration_ms === null ? '—' : e((string) $row->duration_ms).' ms');
        $this->datatables->removeColumn('metadata');
    }

    protected function getEntityName(): string
    {
        return 'provider_call';
    }
}
