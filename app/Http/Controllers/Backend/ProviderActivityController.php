<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProviderCallsDataTable;
use App\Models\ProviderCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderActivityController extends BackendController
{
    public function __construct(Request $request, ProviderCallsDataTable $dataTable)
    {
        parent::__construct($request, new ProviderCall, $dataTable);
        $this->middleware('permission:view provider activity');
    }

    public function index(ProviderCallsDataTable $dataTable)
    {
        if (request()->ajax() && request()->wantsJson()) {
            return $dataTable->ajax();
        }

        $since = now()->subDay();
        $stats = ProviderCall::query()
            ->where('created_at', '>=', $since)
            ->select([
                DB::raw('COUNT(*) as calls'),
                DB::raw("SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded"),
                DB::raw("SUM(CASE WHEN status IN ('retryable', 'pending') THEN 1 ELSE 0 END) as waiting"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw('COALESCE(SUM(consumed_units), 0) as units'),
            ])->first();

        return $dataTable->render('backend.contents.provider_activity.index', [
            'dataTableConfig' => $dataTable->getIndexConfig(),
            'stats' => $stats,
        ]);
    }
}
