<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;

class SamplePermissionApi
{
    public function datatableList(Request $request)
    {
        $draw = $request->input('draw', 0);
        $start = $request->input('start', 0);
        $length = $request->input('length', 10);
        $columns = $request->input('columns');
        $searchValue = $request->input('search.value');

        $orderColumn = $request->input('order.0.column', 0); // Get the order column index
        $orderDir = $request->input('order.0.dir', 'asc'); // Get the order direction (ASC or DESC)

        // Whitelist allowed sort columns to prevent SQL column injection
        $allowedColumns = ['id', 'name', 'guard_name', 'created_at', 'updated_at'];
        $requestedColumn = $columns[$orderColumn]['data'] ?? 'id';
        $orderColumnName = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';

        // Validate order direction
        $orderDir = strtolower((string) $orderDir) === 'desc' ? 'desc' : 'asc';

        $query = Permission::query()->with('roles');

        // recordsTotal = unfiltered grand total
        $recordsTotal = Permission::query()->count();

        if ($searchValue) {
            $searchColumns = ['name'];
            $query->where(function ($query) use ($searchValue, $searchColumns) {
                foreach ($searchColumns as $column) {
                    $query->orWhere(DB::raw("LOWER($column)"), 'LIKE', '%' . strtolower($searchValue) . '%');
                }
            });
        }

        // recordsFiltered = count after search filter but before offset/limit
        $recordsFiltered = (clone $query)->count();

        // Apply ordering to the query
        $query->orderBy($orderColumnName, $orderDir);

        $records = $query->offset($start)->limit($length)->get();

        $data = [
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records,
            'orderColumnName' => $orderColumnName,
        ];

        return $data;
    }

    public function create(Request $request)
    {
        $permission = $request->all();

        $rules = [
            'name' => 'required|string',
        ];

        $validator = Validator::make($permission, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $updated = Permission::create($validator->validated());

        return response()->json(['success' => $updated]);
    }

    public function get($id)
    {
        return Permission::findOrFail($id);
    }

    public function update($id, Request $request)
    {
        $permission = $request->all();

        $rules = [
            'name' => 'required|string',
        ];

        $validator = Validator::make($permission, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $updated = Permission::findOrFail($id)->update($validator->validated());

        return response()->json(['success' => $updated]);
    }

    public function delete($id)
    {
        return Permission::destroy($id);
    }
}
