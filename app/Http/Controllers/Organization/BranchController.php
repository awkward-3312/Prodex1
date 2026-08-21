<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\InventoryLocation;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationService;
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
                'inventoryLocations' => fn ($q) => $q->whereNull('deleted_at')->where('is_active', true)
                    ->select('id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales', 'is_quarantine', 'is_active'),
                'manager:id,firstname,lastname',
                'defaultInventoryLocation:id,branch_id,code,name,type,is_sellable,is_default_sales',
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
        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);

        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        $employees = Employee::whereNull('deleted_at')->whereNull('leaving_date')->orderBy('firstname')->orderBy('lastname');

        if ((int) $user->role_id !== 1) {
            $branches->whereIn('id', $branchIds ?: [0]);
            if ($branchIds) {
                $employees->where(function ($q) use ($branchIds) {
                    $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds);
                });
            } else {
                $employees->whereNull('branch_id');
            }
        }

        return response()->json([
            'branches' => $branches->get(['id', 'code', 'name']),
            'employees' => $employees->get(['id', 'branch_id', 'firstname', 'lastname']),
            'inventory_location_types' => $this->locationTypeOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'branches_add');
        $data = $this->validated($request);

        $branch = DB::transaction(function () use ($data) {
            $inventoryEnabled = (bool) ($data['inventory_enabled'] ?? true);
            $createStorage = (bool) ($data['create_storage_location'] ?? true);
            unset($data['inventory_enabled'], $data['create_storage_location']);

            $branch = Branch::create($data);
            if ($inventoryEnabled) {
                $this->ensureInitialInventoryLocations($branch, $createStorage);
            }

            return $branch;
        });

        return response()->json([
            'success' => true,
            'branch' => $branch->fresh(['inventoryLocations', 'manager', 'defaultInventoryLocation']),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizePermission($request, 'branches_edit');
        $user = $request->user('api');
        $this->assertBranchAccess($user, $id);

        $branch = Branch::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validated($request, $branch->id);

        DB::transaction(function () use ($branch, $data) {
            $inventoryEnabled = (bool) ($data['inventory_enabled'] ?? false);
            $createStorage = (bool) ($data['create_storage_location'] ?? true);
            unset($data['inventory_enabled'], $data['create_storage_location']);

            $branch->update($data);
            if ($inventoryEnabled && ! $branch->inventoryLocations()->whereNull('deleted_at')->where('is_active', true)->exists()) {
                $this->ensureInitialInventoryLocations($branch, $createStorage);
            }
        });

        return response()->json([
            'success' => true,
            'branch' => $branch->fresh(['inventoryLocations', 'manager', 'defaultInventoryLocation']),
        ]);
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
        if ((int) $user->role_id !== 1 && $employee->branch_id) {
            $this->assertBranchAccess($user, (int) $employee->branch_id);
        }
        $employee->update(['branch_id' => $branch->id]);

        return response()->json(['success' => true]);
    }

    public function storeInventoryLocation(Request $request, int $branchId)
    {
        $this->authorizePermission($request, 'branches_edit');
        $this->assertBranchAccess($request->user('api'), $branchId);
        $branch = Branch::whereNull('deleted_at')->where('is_active', true)->findOrFail($branchId);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:192'],
            'type' => ['required', Rule::in(InventoryLocation::TYPES)],
            'is_sellable' => ['sometimes', 'boolean'],
            'is_default_sales' => ['sometimes', 'boolean'],
            'is_quarantine' => ['sometimes', 'boolean'],
        ]);

        $location = app(InventoryLocationService::class)->createForBranch($branch, $data);

        return response()->json(['success' => true, 'location' => $location], 201);
    }

    private function validated(Request $request, ?int $branchId = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:192'],
            'code' => ['nullable', 'string', 'max:40'],
            // distribution_center remains accepted only for backward compatibility;
            // new CDs are Warehouses and the UI no longer offers this branch type.
            'type' => ['required', Rule::in(['branch', 'distribution_center', 'office', 'other'])],
            'phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:192'],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'manager_employee_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'inventory_enabled' => ['sometimes', 'boolean'],
            'create_storage_location' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['manager_employee_id'])) {
            abort_unless(Employee::whereNull('deleted_at')->whereKey($validated['manager_employee_id'])->exists(), 422, 'El responsable seleccionado no existe.');
        }

        $validated['is_active'] = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true;
        return $validated;
    }

    private function ensureInitialInventoryLocations(Branch $branch, bool $createStorage): void
    {
        $service = app(InventoryLocationService::class);

        if (! $branch->inventoryLocations()->whereNull('deleted_at')->where('code', 'PISO')->exists()) {
            $service->createForBranch($branch, [
                'code' => 'PISO',
                'name' => 'Piso de venta',
                'type' => InventoryLocation::TYPE_SALES_FLOOR,
                'is_sellable' => true,
                'is_default_sales' => true,
                'is_active' => true,
            ]);
        }

        if ($createStorage && ! $branch->inventoryLocations()->whereNull('deleted_at')->where('code', 'BODEGA')->exists()) {
            $service->createForBranch($branch, [
                'code' => 'BODEGA',
                'name' => 'Bodega de sucursal',
                'type' => InventoryLocation::TYPE_STORAGE,
                'is_sellable' => false,
                'is_active' => true,
            ]);
        }
    }

    private function locationTypeOptions(): array
    {
        return [
            ['value' => InventoryLocation::TYPE_SALES_FLOOR, 'label' => 'Piso de venta'],
            ['value' => InventoryLocation::TYPE_STORAGE, 'label' => 'Bodega'],
            ['value' => InventoryLocation::TYPE_QUARANTINE, 'label' => 'Cuarentena'],
            ['value' => InventoryLocation::TYPE_DAMAGED, 'label' => 'Dañados'],
            ['value' => InventoryLocation::TYPE_RETURNS, 'label' => 'Devoluciones'],
            ['value' => InventoryLocation::TYPE_OTHER, 'label' => 'Otra'],
        ];
    }

    private function scopeBranches($query, $user): void
    {
        if ((int) $user->role_id === 1) return;
        $query->whereIn('id', app(BranchScopeService::class)->allowedBranchIds($user) ?: [0]);
    }

    private function assertBranchAccess($user, int $branchId): void
    {
        if ((int) $user->role_id === 1) return;
        abort_unless(app(BranchScopeService::class)->canAccess($user, $branchId), 403, 'No tienes acceso a esta sucursal.');
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        abort_unless((int) $user->role_id === 1 || $user->hasPermissionName($permission), 403);
    }
}
