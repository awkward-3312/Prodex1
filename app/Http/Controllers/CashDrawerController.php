<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashDrawerController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CashDrawer::class);

        $query = CashDrawer::with('warehouse:id,name')->whereNull('deleted_at');
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return response()->json([
            'cash_drawers' => $query->orderBy('warehouse_id')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', CashDrawer::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:64', 'unique:cash_drawers,code'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $warehouse = Warehouse::whereNull('deleted_at')->findOrFail($data['warehouse_id']);
        $drawer = CashDrawer::create([
            'warehouse_id' => $warehouse->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['success' => true, 'cash_drawer' => $drawer->load('warehouse:id,name')]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CashDrawer::class);

        $drawer = CashDrawer::whereNull('deleted_at')->findOrFail($id);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:64', Rule::unique('cash_drawers', 'code')->ignore($drawer->id)],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        Warehouse::whereNull('deleted_at')->findOrFail($data['warehouse_id']);
        $drawer->update([
            'warehouse_id' => $data['warehouse_id'],
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return response()->json(['success' => true, 'cash_drawer' => $drawer->fresh()->load('warehouse:id,name')]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CashDrawer::class);

        CashDrawer::whereNull('deleted_at')->where('id', $id)->update([
            'deleted_at' => Carbon::now(),
            'is_active' => false,
        ]);

        return response()->json(['success' => true]);
    }
}
