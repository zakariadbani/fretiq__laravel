<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ZohoRecordsDataTable;
use App\Services\Zoho\V2\Explorer\ZohoExplorer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Read-only CRM mirror explorer. It intentionally exposes no mutation action. */
final class ZohoRecordsController extends BackendController
{
    public function __construct(
        private readonly ZohoExplorer $explorer,
        private readonly ZohoRecordsDataTable $dataTable,
    ) {
        $this->middleware(['auth', 'verified']);
        $this->middleware('permission:view zoho records')->only(['index', 'data', 'show']);
        $this->middleware('permission:export zoho records')->only(['export']);
    }

    public function index(Request $request, string $module)
    {
        $this->ensureEnabled($module);
        $scope = $this->explorer->scopedQuery($module, $request);

        return view('backend.contents.zoho-records.index', [
            'module' => $module,
            'definition' => $this->explorer->definition($module),
            'fields' => $this->explorer->listingFields($module),
            'mappingRequired' => $scope->mappingRequired,
            'filters' => $this->explorer->preservedFilters($request),
            'filterNotices' => $this->explorer->filterNotices($module, $request),
        ]);
    }

    public function data(Request $request, string $module)
    {
        $this->ensureEnabled($module);
        $scope = $this->explorer->scopedQuery($module, $request);

        return $this->dataTable->make($module, $scope->query, $request);
    }

    public function show(Request $request, string $module, string $zohoId)
    {
        $this->ensureEnabled($module);
        $canViewRawPayload = $this->explorer->canShowRawPayload($request);
        $scope = $this->explorer->detailQuery($module, $request, $canViewRawPayload);
        $record = $scope->query->where('zoho_id', $zohoId)->firstOrFail();
        if ($canViewRawPayload) {
            $record->makeVisible('raw_payload');
        }
        $definition = $this->explorer->definition($module);
        $activities = $this->explorer->nestedActivities($record, $request);
        $items = $module === 'quotes' ? $this->explorer->quoteItems($record) : collect();

        return view('backend.contents.zoho-records.show', [
            'module' => $module,
            'definition' => $definition,
            'record' => $record,
            'fields' => $this->explorer->visibleFields($module),
            'activities' => $activities,
            'items' => $items,
            'canViewRawPayload' => $canViewRawPayload,
            'filters' => $this->explorer->preservedFilters($request),
        ]);
    }

    public function export(Request $request, string $module)
    {
        $this->ensureEnabled($module);
        $scope = $this->explorer->scopedQuery($module, $request);
        $fields = $this->explorer->visibleFields($module);
        $filename = 'zoho-'.Str::slug($module).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($scope, $fields): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $fields, ';');
            $scope->query->select(array_unique(array_merge(['id'], $fields)))->chunkById(500, function ($records) use ($out, $fields): void {
                foreach ($records as $record) {
                    fputcsv($out, array_map(fn (string $field): string => $this->csvValue($record->getAttribute($field)), $fields), ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn ($item) => (string) $item, $value));
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        $value = (string) ($value ?? '');
        $value = preg_replace('/[\t\r\n]/u', ' ', $value) ?? '';
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $value) ?? '';

        return preg_match('/^[\p{Z}\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }

    private function ensureEnabled(string $module): void
    {
        abort_unless(
            (bool) config('zoho-v2.features.explorer_enabled', false)
                && $this->explorer->available($module),
            404,
        );
    }
}
