<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\Warehouse;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationScopeService;
use App\Services\WarehouseScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashDrawerController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CashDrawer::class);
        $actor = $request->user('api');

        $branchIds = app(BranchScopeService::class)->allowedBranchIds($actor);
        $legacyWarehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($actor);

        $query = CashDrawer::with([
                'branch:id,code,name',
                'inventoryLocation:id,branch_id,code,name,type,is_sellable,is_active',
                'warehouse:id,name',
            ])
            ->whereNull('deleted_at');

        if ((int) $actor->role_id !== 1) {
            $query->where(function ($scope) use ($branchIds, $legacyWarehouseIds) {
                if ($branchIds) {
                    $scope->whereIn('branch_id', $branchIds);
                } else {
                    $scope->whereRaw('1 = 0');
                }

                if ($legacyWarehouseIds) {
                    $scope->orWhere(function ($legacy) use ($legacyWarehouseIds) {
                        $legacy->whereNull('branch_id')->whereIn('warehouse_id', $legacyWarehouseIds);
                    });
                }
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('inventory_location_id')) {
            $query->where('inventory_location_id', (int) $request->inventory_location_id);
        }
        // Temporary compatibility for old callers.
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->warehouse_id);
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $branches = Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', $branchIds ?: [0])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'default_inventory_location_id']);

        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($actor);
        $locations = InventoryLocation::active()
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $branchIds ?: [0])
            ->when((int) $actor->role_id !== 1, fn ($q) => $q->whereIn('id', $locationIds ?: [0]))
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales']);

        return response()->json([
            'cash_drawers' => $query->orderByRaw('COALESCE(branch_id, 2147483647)')->orderBy('name')->get(),
            'branches' => $branches,
            'inventory_locations' => $locations,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', CashDrawer::class);
        $actor = $request->user('api');
        $data = $this->validated($request);

        [$branch, $location, $warehouseId] = $this->resolveOperationalContext($actor, $data);

        $drawer = DB::transaction(function () use ($data, $branch, $location, $warehouseId) {
            return CashDrawer::create([
                'warehouse_id' => $warehouseId,
                'branch_id' => $branch?->id,
                'inventory_location_id' => $location?->id,
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });

        return response()->json([
            'success' => true,
            'cash_drawer' => $drawer->load(['branch:id,code,name', 'inventoryLocation:id,branch_id,code,name,type,is_sellable', 'warehouse:id,name']),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CashDrawer::class);
        $actor = $request->user('api');
        $drawer = CashDrawer::whereNull('deleted_at')->findOrFail($id);
        $this->assertCanManageDrawer($actor, $drawer);

        $data = $this->validated($request, $drawer->id);
        [$branch, $location, $warehouseId] = $this->resolveOperationalContext($actor, $data, $drawer);

        $drawer->update([
            'warehouse_id' => $warehouseId,
            'branch_id' => $branch?->id,
            'inventory_location_id' => $location?->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return response()->json([
            'success' => true,
            'cash_drawer' => $drawer->fresh()->load(['branch:id,code,name', 'inventoryLocation:id,branch_id,code,name,type,is_sellable', 'warehouse:id,name']),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CashDrawer::class);
        $actor = $request->user('api');
        $drawer = CashDrawer::whereNull('deleted_at')->findOrFail($id);
        $this->assertCanManageDrawer($actor, $drawer);

        $drawer->update([
            'deleted_at' => Carbon::now(),
            'is_active' => false,
        ]);

        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?int $drawerId = null): array
    {
        return $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'inventory_location_id' => ['nullable', 'integer'],
            // warehouse_id is legacy-only and may be null for new branch-owned drawers.
            'warehouse_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:191'],
            'code' => [
                'required', 'string', 'max:64',
                Rule::unique('cash_drawers', 'code')->ignore($drawerId)->where(fn ($q) => $q->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * New drawers use Branch + sellable InventoryLocation. Legacy warehouse-only
     * payloads remain accepted until every POS client has been migrated.
     */
    private function resolveOperationalContext($actor, array $data, ?CashDrawer $existing = null): array
    {
        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        $locationId = ! empty($data['inventory_location_id']) ? (int) $data['inventory_location_id'] : null;
        $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : ($existing?->warehouse_id ? (int) $existing->warehouse_id : null);

        if ($branchId || $locationId) {
            if (! $branchId || ! $locationId) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La caja debe tener una sucursal y una ubicación de inventario.',
                ]);
            }

            $branch = Branch::whereNull('deleted_at')->where('is_active', true)->find($branchId);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'La sucursal seleccionada no existe o está inactiva.']);
            }

            if ((int) $actor->role_id !== 1 && ! app(BranchScopeService::class)->canAccess($actor, $branchId)) {
                abort(403, 'No tienes acceso a la sucursal seleccionada.');
            }

            $location = InventoryLocation::active()->find($locationId);
            if (! $location || (int) $location->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación debe estar activa y pertenecer a la sucursal seleccionada.',
                ]);
            }
            if (! $location->is_sellable) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'Una caja física solo puede operar desde una ubicación habilitada para venta.',
                ]);
            }
            if ((int) $actor->role_id !== 1 && ! app(InventoryLocationScopeService::class)->canAccess($actor, $locationId)) {
                abort(403, 'No tienes acceso a la ubicación de inventario seleccionada.');
            }

            if ($warehouseId && ! Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->exists()) {
                $warehouseId = null;
            }

            return [$branch, $location, $warehouseId];
        }

        // Legacy compatibility path. It cannot create fake branch ownership.
        if (! $warehouseId) {
            throw ValidationException::withMessages([
                'branch_id' => 'Selecciona una sucursal y su ubicación de venta.',
            ]);
        }

        $warehouse = Warehouse::whereNull('deleted_at')->find($warehouseId);
        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => 'El almacén legado seleccionado no existe.']);
        }

        if ((int) $actor->role_id !== 1
            && ! in_array($warehouseId, app(WarehouseScopeService::class)->allowedWarehouseIds($actor), true)) {
            abort(403, 'No tienes acceso al almacén legado seleccionado.');
        }

        return [null, null, $warehouseId];
    }

    private function assertCanManageDrawer($actor, CashDrawer $drawer): void
    {
        if ((int) $actor->role_id === 1) return;

        if ($drawer->branch_id) {
            abort_unless(app(BranchScopeService::class)->canAccess($actor, (int) $drawer->branch_id), 403);
            return;
        }

        abort_unless(
            $drawer->warehouse_id
            && in_array((int) $drawer->warehouse_id, app(WarehouseScopeService::class)->allowedWarehouseIds($actor), true),
            403
        );
    }
}
