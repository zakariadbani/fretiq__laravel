<?php

namespace App\DataTables\Backend;

use App\Services\Zoho\V2\Explorer\ZohoExplorer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Small server-side-only table adapter; every selectable column is registry-reviewed. */
final class ZohoRecordsDataTable
{
    private const MAX_PAGE_LENGTH = 100;

    private const MAX_SEARCH_LENGTH = 100;

    public function __construct(private readonly ZohoExplorer $explorer) {}

    public function make(string $module, Builder $query, Request $request)
    {
        $this->normalizeRequest($request);
        $fields = $this->explorer->listingFields($module);
        $allowed = array_flip($fields);
        $query->select(array_values(array_unique(array_merge($fields, ['id', 'zoho_id']))));

        $dataTable = datatables()->eloquent($query)
            ->filter(function (Builder $builder) use ($request, $fields): void {
                $term = trim((string) data_get($request->input('search'), 'value', ''));
                if ($term === '') {
                    return;
                }

                $builder->where(function (Builder $nested) use ($fields, $term): void {
                    foreach ($fields as $field) {
                        if (in_array($field, ['zoho_id', 'owner_zoho_id', 'currency_code', 'zoho_modified_at', 'amount', 'probability', 'line_items_total', 'closing_date', 'valid_till'], true)) {
                            continue;
                        }
                        $nested->orWhere($field, 'like', '%'.$term.'%');
                    }
                });
            }, true)
            ->order(function (Builder $builder) use ($request, $fields, $allowed): void {
                $order = $request->input('order.0', []);
                $column = $request->input('columns.'.((int) ($order['column'] ?? -1)).'.data');
                $direction = strtolower((string) ($order['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
                $field = is_string($column) && isset($allowed[$column]) ? $column : (in_array('zoho_modified_at', $fields, true) ? 'zoho_modified_at' : 'zoho_id');
                $builder->orderBy($field, $direction);
            })
            ->addColumn('detail_url', function ($row) use ($module, $request): string {
                $url = url('/admin/zoho/records/'.$module.'/'.$row->zoho_id);
                $filters = $this->explorer->preservedFilters($request);

                return $filters === [] ? $url : $url.'?'.http_build_query($filters);
            })
            // This value is assigned to window.location, never inserted as
            // HTML. Escaping it would turn query separators into `&amp;` and
            // silently rename every inherited filter after the first one.
            ->rawColumns(['detail_url'])
            ->removeColumn('id');

        if (! in_array('zoho_id', $fields, true)) {
            $dataTable->removeColumn('zoho_id');
        }

        return $dataTable->toJson();
    }

    private function normalizeRequest(Request $request): void
    {
        $rawLength = $request->input('length', 25);
        $length = is_scalar($rawLength) ? filter_var($rawLength, FILTER_VALIDATE_INT) : false;
        if ($length === -1) {
            abort(422, 'La pagination est obligatoire pour les données Zoho.');
        }

        $length = $length === false ? 25 : max(1, min(self::MAX_PAGE_LENGTH, $length));
        $rawStart = $request->input('start', 0);
        $start = is_scalar($rawStart) ? filter_var($rawStart, FILTER_VALIDATE_INT) : false;
        $start = $start === false ? 0 : max(0, min(1_000_000, $start));

        $search = $request->input('search');
        if (is_array($search)) {
            $value = $search['value'] ?? '';
            $search['value'] = is_scalar($value)
                ? mb_substr((string) $value, 0, self::MAX_SEARCH_LENGTH)
                : '';
        } else {
            $search = ['value' => ''];
        }

        $request->merge(compact('length', 'start', 'search'));
    }
}
