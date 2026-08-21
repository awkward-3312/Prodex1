<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Warehouse;
use App\Services\WarehouseScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizePermission($request, 'branches_view');
        $user = $request->user('api');

        $branches = Branch::with([
                'warehouses' => fn ($q) => $q->whereNull('deleted_at')->select('id', 'branch_id', 'name'),
                'manager:id,firstname,lastname',
                'defaultWarehouse:id,name',
            ])
            ->whereNull('deleted_at');

        $this->scopeBranches($branches, $user);

        $branches->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('city', 'like', $term);
                });
            })
            ->orderBy('name');

        return response()->json(['branches' => $branches->get()]);
    }

    public function options(Request $request)
    {
        $this->authorizePermission($request, 'branches_view');
        $user = $request->user('api');
        $branchIds = $this->allowedBranchIds($user);
        $warehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($user);

        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        $warehouses = Warehouse::whereNull('deleted_at')->orderBy('name');
        $employees = Employee::whereNull('deleted_at')->whereNull('leaving_date')->orderBy('firstname')->orderBy('lastname');

        if ((int) $user->is_all_warehouses !== 1) {
            $branches->whereIn('id', $branchIds ?: [0]);
            $warehouses->whereIn('id', $warehouseIds ?: [0]);
            $employees->whereIn('branch_id', $branchIds ?: [0]);
        }

        return response()->json([
            'branches' => $branches->get(['id', 'code', 'name']),
            'warehouses' => $warehouses->get(['id', 'branch_id', 'name']),
            'employees' => $employees->get(['id', 'branch_id', 'firstname', 'lastname']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'branches_add');
        $data = $this->validated($request);

        $branch = DB::transaction(function () use ($data) {
            $branch = Branch::create($data);
            $this->syncWarehouses($branch, $data['warehouse_ids'] ?? []);
            return $branch;
        });

        return response()->json(['success' => true, 'branch' => $branch->fresh(['warehouses', 'manager', 'defaultWarehouse'])], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizePermission($request, 'branches_edit');
        $user = $request->user('api');
        $this->assertBranchAccess($user, $id);

        $branch = Branch::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validated($request, $branch->id);

        DB::transaction(function () use ($branch, $data) {
            $branch->update($data);
            $this->syncWarehouses($branch, $data['warehouse_ids'] ?? []);
        });

        return response()->json(['success' => true, 'branch' => $branch->fresh(['warehouses', 'manager', 'defaultWarehouse'])]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizePermission($request, 'branches_delete');
        $user = $request->user('api');
        $this->assertBranchAccess($user, $id);

        $branch = Branch::whereNull('deleted_at')->findOrFail($id);
        $branch->update(['is_active' => false, 'deleted_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function assignEmployee(Request $request, int $branchId, int $employeeId)
    {
        $this->authorizePermission($request, 'branches_edit');
        $user = $request->user('api');
        $this->assertBranchAccess($user, $branchId);

        $branch = Branch::whereNull('deleted_at')->where('is_active', true)->findOrFail($branchId);
        $employee = Employee::whereNull('deleted_at')->findOrFail($employeeId);
        if ((int) $user->is_all_warehouses !== 1 && $employee->branch_id) {
            $this->assertBranchAccess($user, (int) $employee->branch_id);
        }
        $employee->update(['branch_id' => $branch->id]);

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?int $branchId = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:192'],
            'code' => ['nullable', 'string', 'max:40'],
            'type' => ['required', Rule::in(['branch', 'distribution_center', 'office', 'other'])],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:192'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'manager_employee_id' => ['nullable', 'integer'],
            'default_warehouse_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'warehouse_ids' => ['sometimes', 'array'],
            'warehouse_ids.*' => ['integer'],
        ]);

        if (! empty($validated['manager_employee_id'])) {
            abort_unless(Employee::whereNull('deleted_at')->whereKey($validated['manager_employee_id'])->exists(), 422, 'El responsable seleccionado no existe.');
        }

        $warehouseIds = array_values(array_unique(array_map('intval', $validated['warehouse_ids'] ?? [])));
        if ($warehouseIds) {
            $existing = Warehouse::whereNull('deleted_at')->whereIn('id', $warehouseIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_unless(count($existing) === count($warehouseIds), 422, 'Una de las bodegas seleccionadas no existe.');
        }

        if (! empty($validated['default_warehouse_id'])) {
            abort_unless(in_array((int) $validated['default_warehouse_id'], $warehouseIds, true), 422, 'La bodega predeterminada debe pertenecer a la sucursal.');
        }

        $validated['warehouse_ids'] = $warehouseIds;
        $validated['is_active'] = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;
        return $validated;
    }

    private function syncWarehouses(Branch $branch, array $warehouseIds): void
    {
        Warehouse::where('branch_id', $branch->id)->whereNotIn('id', $warehouseIds ?: [0])->update(['branch_id' => null]);
        if ($warehouseIds) {
            Warehouse::whereIn('id', $warehouseIds)->update(['branch_id' => $branch->id]);
        }
    }

    private function allowedBranchIds($user): array
    {
        if ((int) $user->is_all_warehouses === 1) {
            return Branch::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $warehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($user);
        $branchIds = Warehouse::whereNull('deleted_at')
            ->whereIn('id', $warehouseIds ?: [0])
            ->whereNotNull('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $employeeBranch = optional($user->employee)->branch_id;
        if ($employeeBranch) $branchIds[] = (int) $employeeBranch;

        return array_values(array_unique($branchIds));
    }

    private function scopeBranches($query, $user): void
    {
        if ((int) $user->is_all_warehouses === 1) return;
        $query->whereIn('id', $this->allowedBranchIds($user) ?: [0]);
    }

    private function assertBranchAccess($user, int $branchId): void
    {
        if ((int) $user->is_all_warehouses === 1) return;
        abort_unless(in_array($branchId, $this->allowedBranchIds($user), true), 403, 'No tienes acceso a esta sucursal.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName($permission), 403);
    }
}
