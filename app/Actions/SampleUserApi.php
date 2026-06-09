<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SampleUserApi
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
        $allowedColumns = ['id', 'name', 'email', 'created_at', 'updated_at'];
        $requestedColumn = $columns[$orderColumn]['data'] ?? 'id';
        $orderColumnName = in_array($requestedColumn, $allowedColumns, true) ? $requestedColumn : 'id';

        // Validate order direction
        $orderDir = strtolower((string) $orderDir) === 'desc' ? 'desc' : 'asc';

        // Base query: exclude core user (id=1) — this constraint is part of "total"
        $query = User::query()->with('roles')->whereNotIn('id', [1]);

        // recordsTotal = unfiltered count (excluding core user only)
        $recordsTotal = (clone $query)->count();

        if ($searchValue) {
            $searchColumns = ['name', 'email'];
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
        $user = $request->all();

        $rules = [
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string',
        ];

        $validator = Validator::make($user, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        // Use only(['name','email','password']) instead of validated() because
        // password is not in the original validator rules subset and we need it
        // for the NOT NULL constraint, while excluding any other injected fields.
        $updated = User::create($request->only(['name', 'email', 'password']));

        return response()->json(['success' => $updated]);
    }

    public function get($id)
    {
        return User::findOrFail($id);
    }

    public function update($id, Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email,' . $id,
            'role' => 'required|string',
        ]);

        $user = User::findOrFail($id);

        // Update only real user columns; 'role' is not a DB column
        $user->update(['name' => $data['name'], 'email' => $data['email']]);

        // syncRoles replaces existing roles instead of accumulating them
        $user->syncRoles([$data['role']]);

        return response()->json(['success' => true]);
    }

    public function delete($id)
    {
        return User::destroy($id);
    }
}
