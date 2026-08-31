<?php

namespace App\Http\Middleware;

use App\Models\InventoryLocation;
use App\Models\Transfer;
use App\Services\InventoryLocationScopeService;
use App\Services\WarehouseScopeService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class EnforceWarehouseScope
{
    public function handle(Request $request, Closure $next)
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) return $next($request);

        try { $user = $request->user('api') ?: auth('api')->user(); }
        catch (\Throwable $e) { return $next($request); }

        if (! $user) return $next($request);

        $warehouseScope = app(WarehouseScopeService::class);
        $locationScope = app(InventoryLocationScopeService::class);

        // The modern POS exposes InventoryLocation ids through the legacy
        // `warehouse_id` UI field. CreateDraft still persists a legacy
        // warehouse_id, so translate that virtual location selector to a real
        // compatibility warehouse before legacy persistence runs. Authorization
        // remains location-native; the compatibility warehouse never grants or
        // restricts the cashier's operational access.
        $this->prepareLocationPosDraftRequest($request, $user, $locationScope);

        $locationTransfer = $this->isLocationTransferPayload($request);
        $locationPosSale = $this->isLocationPosSale($request);

        if ((int) ($user->is_all_warehouses ?? 0) !== 1) {
            $this->validateRequestSelectors($request, $user, $warehouseScope, $locationScope, $locationTransfer, $locationPosSale);
            $this->validateScopeEscalation($request, $user, $warehouseScope);
            $this->validateTransferRoute($request, $user, $warehouseScope, $locationScope);
        } else {
            $this->validateLocationExistence($request);
        }

        return $next($request);
    }

    private function validateRequestSelectors(
        Request $request,
        $user,
        WarehouseScopeService $warehouseScope,
        InventoryLocationScopeService $locationScope,
        bool $locationTransfer,
        bool $locationPosSale
    ): void {
        // Location-native POS sales and held sales are authorized by Branch ->
        // InventoryLocation -> CashDrawer. Their warehouse_id is only a historical
        // compatibility pointer and must not decide whether the cashier may operate.
        if ($locationPosSale) {
            $locationId = (int) $request->input('inventory_location_id');
            if (! $locationScope->canAccess($user, $locationId)) {
                throw new AuthorizationException('No tienes permiso para operar con la ubicación de inventario seleccionada.');
            }
            $protectedKeys = ['default_warehouse_id', 'from_warehouse_id', 'from_warehouse'];
        } else {
            $protectedKeys = $locationTransfer
                ? ['warehouse_id', 'default_warehouse_id']
                : ['warehouse_id', 'default_warehouse_id', 'from_warehouse_id', 'from_warehouse'];
        }

        $this->walk($request->all(), function (string $key, $value) use ($protectedKeys, $user, $warehouseScope) {
            if (! in_array($key, $protectedKeys, true)) return;
            if ($value === null || $value === '' || is_array($value) || is_object($value) || ! is_numeric($value)) return;
            // 0 (and any non-positive) is the app-wide "all / none selected"
            // sentinel — never a real warehouse. Downstream services already
            // restrict a limited user to their own scope in that case, so there
            // is nothing to assert here.
            if ((int) $value <= 0) return;
            $warehouseScope->assertAccess($user, (int) $value, 'No tienes permiso para operar con la bodega seleccionada.');
        });

        $fromLocation = $request->input('transfer.from_inventory_location_id')
            ?: $request->input('from_inventory_location_id');
        if ($fromLocation && ! $locationScope->canAccess($user, (int) $fromLocation)) {
            throw new AuthorizationException('No tienes permiso para operar con la ubicación de inventario de origen.');
        }

        $toLocation = $request->input('transfer.to_inventory_location_id')
            ?: $request->input('to_inventory_location_id');
        if ($toLocation && ! InventoryLocation::active()->whereKey((int) $toLocation)->exists()) {
            throw new AuthorizationException('La ubicación de inventario destino no existe o está inactiva.');
        }

        if ($request->isMethod('get')
            && $request->filled('warehouse_id')
            && is_numeric($request->query('warehouse_id'))
            && (int) $request->query('warehouse_id') > 0) {
            $warehouseScope->assertAccess($user, (int) $request->query('warehouse_id'), 'No tienes permiso para consultar esa bodega.');
        }
    }

    private function validateLocationExistence(Request $request): void
    {
        foreach (['transfer.from_inventory_location_id', 'transfer.to_inventory_location_id', 'from_inventory_location_id', 'to_inventory_location_id', 'inventory_location_id'] as $key) {
            $value = $request->input($key);
            if ($value && ! InventoryLocation::active()->whereKey((int) $value)->exists()) {
                throw new AuthorizationException('La ubicación de inventario seleccionada no existe o está inactiva.');
            }
        }
    }

    private function validateScopeEscalation(Request $request, $user, WarehouseScopeService $scope): void
    {
        if ($request->isMethod('get') || $request->isMethod('head')) return;
        $wantsAll = $request->input('scope') === 'all' || $this->truthy($request->input('is_all_warehouses'));
        if ($wantsAll) throw new AuthorizationException('No puedes conceder acceso global a bodegas desde una cuenta con alcance restringido.');

        $allowed = $scope->allowedWarehouseIds($user);
        foreach (['assigned_to', 'warehouse_ids'] as $key) {
            if (! $request->has($key)) continue;
            $requested = $request->input($key);
            if ($requested === null || $requested === '') continue;
            if (! is_array($requested)) $requested = [$requested];
            foreach ($requested as $warehouseId) {
                if ($warehouseId === null || $warehouseId === '' || ! is_numeric($warehouseId)) continue;
                if (! in_array((int) $warehouseId, $allowed, true)) {
                    throw new AuthorizationException('No puedes asignar una bodega fuera de tu propio alcance operativo.');
                }
            }
        }
    }

    private function validateTransferRoute(
        Request $request,
        $user,
        WarehouseScopeService $warehouseScope,
        InventoryLocationScopeService $locationScope
    ): void {
        $route = $request->route();
        if (! $route) return;

        $action = $this->resolvedRouteAction($route);
        if ($action === '' || ! str_contains($action, 'TransferController@')) return;

        $method = substr($action, strrpos($action, '@') + 1);

        if ($method === 'delete_by_selection') {
            $selectedIds = collect((array) $request->input('selectedIds', []))
                ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($selectedIds->isEmpty()) return;

            $transfers = Transfer::whereNull('deleted_at')->whereIn('id', $selectedIds)->get();
            if ($transfers->count() !== $selectedIds->count()) {
                throw new AuthorizationException('Una o más transferencias seleccionadas no existen o ya no están disponibles.');
            }

            foreach ($transfers as $transfer) {
                if (! $this->canMutateTransferSource($transfer, $user, $warehouseScope, $locationScope)) {
                    throw new AuthorizationException('Solo usuarios con acceso al origen pueden eliminar transferencias seleccionadas.');
                }
            }
            return;
        }

        $id = $request->route('id') ?: $request->route('transfer');
        if (! $id || ! is_numeric($id)) return;

        $transfer = Transfer::whereNull('deleted_at')->find((int) $id);
        if (! $transfer) return;

        [$sourceAllowed, $destinationAllowed] = $this->transferAccess($transfer, $user, $warehouseScope, $locationScope);

        $readMethods = ['show', 'edit', 'get_transfer_detail', 'Print_Transfer', 'transfer_pdf'];
        if (in_array($method, $readMethods, true)) {
            if (! $sourceAllowed && ! $destinationAllowed) throw new AuthorizationException('No tienes acceso a esta transferencia.');
            return;
        }

        $sourceMutationMethods = ['update', 'destroy', 'approve', 'reject'];
        if (in_array($method, $sourceMutationMethods, true) && ! $sourceAllowed) {
            throw new AuthorizationException('Solo usuarios con acceso al origen pueden modificar o aprobar esta transferencia.');
        }
    }

    private function resolvedRouteAction($route): string
    {
        $candidates = [];
        if (method_exists($route, 'getActionName')) $candidates[] = (string) $route->getActionName();
        if (method_exists($route, 'getAction')) {
            foreach (['controller', 'uses'] as $key) {
                $value = $route->getAction($key);
                if (is_string($value)) $candidates[] = $value;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            if (str_contains($candidate, 'TransferController@')) return $candidate;
        }
        return '';
    }

    private function canMutateTransferSource(Transfer $transfer, $user, WarehouseScopeService $warehouseScope, InventoryLocationScopeService $locationScope): bool
    {
        return $this->transferAccess($transfer, $user, $warehouseScope, $locationScope)[0];
    }

    private function transferAccess(Transfer $transfer, $user, WarehouseScopeService $warehouseScope, InventoryLocationScopeService $locationScope): array
    {
        if ($transfer->from_inventory_location_id && $transfer->to_inventory_location_id) {
            return [
                $locationScope->canAccess($user, (int) $transfer->from_inventory_location_id),
                $locationScope->canAccess($user, (int) $transfer->to_inventory_location_id),
            ];
        }

        $allowed = $warehouseScope->allowedWarehouseIds($user);
        return [
            in_array((int) $transfer->from_warehouse_id, $allowed, true),
            in_array((int) $transfer->to_warehouse_id, $allowed, true),
        ];
    }

    /**
     * `pos.vue` still posts the selected InventoryLocation through warehouse_id
     * when placing a sale on hold. Translate only the CreateDraft action; other
     * warehouse-based endpoints retain their normal scope enforcement.
     */
    private function prepareLocationPosDraftRequest(
        Request $request,
        $user,
        InventoryLocationScopeService $locationScope
    ): void {
        if (! $this->isPosCreateDraftRequest($request)) return;

        $virtualId = $request->input('warehouse_id');
        if ($virtualId === null || $virtualId === '' || ! is_numeric($virtualId)) return;

        $location = InventoryLocation::active()
            ->with('branch')
            ->find((int) $virtualId);

        // If the id is not an InventoryLocation, this is a true legacy warehouse
        // request and the existing warehouse checks below must handle it unchanged.
        if (! $location) return;

        if (! $locationScope->canAccess($user, (int) $location->id)) {
            throw new AuthorizationException('No tienes permiso para poner en espera una venta de esta ubicación de inventario.');
        }

        $compatibilityWarehouseId = null;

        // Warehouse-owned locations can use their owner as the persistence pointer.
        if ($location->warehouse_id) {
            $compatibilityWarehouseId = (int) $location->warehouse_id;
        }

        // Branch-owned locations prefer the branch's historical default warehouse.
        // This warehouse is NOT used for authorization or stock; it only satisfies
        // the legacy non-null draft_sales.warehouse_id column.
        if (! $compatibilityWarehouseId && $location->branch && $location->branch->default_warehouse_id) {
            $compatibilityWarehouseId = (int) $location->branch->default_warehouse_id;
        }

        // Migrated branches may have no default warehouse. Pick an existing legacy
        // warehouse solely as a compatibility pointer. The location authorization
        // above is authoritative and a held sale does not move inventory.
        if (! $compatibilityWarehouseId) {
            $compatibilityWarehouseId = (int) (\App\Models\Warehouse::whereNull('deleted_at')
                ->where('branch_id', $location->branch_id)
                ->value('id') ?: 0);
        }
        if (! $compatibilityWarehouseId) {
            $compatibilityWarehouseId = (int) (\App\Models\Warehouse::whereNull('deleted_at')->value('id') ?: 0);
        }

        if (! $compatibilityWarehouseId) {
            throw new AuthorizationException('No existe una referencia de almacén compatible para guardar la venta en espera.');
        }

        $request->merge([
            'warehouse_id' => $compatibilityWarehouseId,
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'inventory_location_id' => (int) $location->id,
        ]);
    }

    private function isPosCreateDraftRequest(Request $request): bool
    {
        if (! $request->isMethod('post')) return false;

        $route = $request->route();
        if ($route) {
            $candidates = [];
            if (method_exists($route, 'getActionName')) $candidates[] = (string) $route->getActionName();
            if (method_exists($route, 'getAction')) {
                foreach (['controller', 'uses'] as $key) {
                    $value = $route->getAction($key);
                    if (is_string($value)) $candidates[] = $value;
                }
            }
            foreach (array_unique($candidates) as $action) {
                if (str_contains($action, 'PosController@CreateDraft') || str_contains($action, 'PosController::CreateDraft')) return true;
            }
        }

        return trim($request->path(), '/') === 'api/pos/create_draft';
    }

    private function isLocationTransferPayload(Request $request): bool
    {
        return (bool) ($request->input('transfer.from_inventory_location_id') ?: $request->input('from_inventory_location_id'));
    }

    private function isLocationPosSale(Request $request): bool
    {
        if (! $request->isMethod('post') || ! $request->filled('branch_id') || ! $request->filled('inventory_location_id')) {
            return false;
        }

        $route = $request->route();
        if ($route) {
            $candidates = [];
            if (method_exists($route, 'getActionName')) $candidates[] = (string) $route->getActionName();
            if (method_exists($route, 'getAction')) {
                foreach (['controller', 'uses'] as $key) {
                    $value = $route->getAction($key);
                    if (is_string($value)) $candidates[] = $value;
                }
            }
            foreach (array_unique($candidates) as $action) {
                if (str_contains($action, 'PosController@CreatePOS') || str_contains($action, 'PosController::CreatePOS')) return true;
                if (str_contains($action, 'PosController@CreateDraft') || str_contains($action, 'PosController::CreateDraft')) return true;
            }
        }

        $path = trim($request->path(), '/');
        return $path === 'api/pos/create_pos' || $path === 'api/pos/create_draft';
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int) $value === 1;
        if (! is_string($value)) return false;
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function walk(array $data, callable $callback): void
    {
        foreach ($data as $key => $value) {
            $callback((string) $key, $value);
            if (is_array($value)) $this->walk($value, $callback);
        }
    }
}
