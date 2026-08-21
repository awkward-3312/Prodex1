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
        $locationTransfer = $this->isLocationTransferPayload($request);

        // Global warehouse users retain their historical broad access. Location
        // selectors are still validated against existence/activeness below.
        if ((int) ($user->is_all_warehouses ?? 0) !== 1) {
            $this->validateRequestSelectors($request, $user, $warehouseScope, $locationScope, $locationTransfer);
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
        bool $locationTransfer
    ): void {
        // A location-aware transfer carries legacy warehouse anchors only for
        // backward-compatible columns. Do not treat those anchors as work scope.
        $protectedKeys = $locationTransfer
            ? ['warehouse_id', 'default_warehouse_id']
            : ['warehouse_id', 'default_warehouse_id', 'from_warehouse_id', 'from_warehouse'];

        $this->walk($request->all(), function (string $key, $value) use ($protectedKeys, $user, $warehouseScope) {
            if (! in_array($key, $protectedKeys, true)) return;
            if ($value === null || $value === '' || is_array($value) || is_object($value) || ! is_numeric($value)) return;
            $warehouseScope->assertAccess($user, (int) $value, 'No tienes permiso para operar con la bodega seleccionada.');
        });

        $fromLocation = $request->input('transfer.from_inventory_location_id')
            ?: $request->input('from_inventory_location_id');
        if ($fromLocation && ! $locationScope->canAccess($user, (int) $fromLocation)) {
            throw new AuthorizationException('No tienes permiso para operar con la ubicación de inventario de origen.');
        }

        // Destination may belong to another branch/CD; that is exactly what a
        // transfer is for. It only has to be an active inventory location.
        $toLocation = $request->input('transfer.to_inventory_location_id')
            ?: $request->input('to_inventory_location_id');
        if ($toLocation && ! InventoryLocation::active()->whereKey((int) $toLocation)->exists()) {
            throw new AuthorizationException('La ubicación de inventario destino no existe o está inactiva.');
        }

        if ($request->isMethod('get') && $request->filled('warehouse_id') && is_numeric($request->query('warehouse_id'))) {
            $warehouseScope->assertAccess($user, (int) $request->query('warehouse_id'), 'No tienes permiso para consultar esa bodega.');
        }
    }

    private function validateLocationExistence(Request $request): void
    {
        foreach (['transfer.from_inventory_location_id', 'transfer.to_inventory_location_id', 'from_inventory_location_id', 'to_inventory_location_id'] as $key) {
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
        if (! $route || ! method_exists($route, 'getActionName')) return;
        $action = (string) $route->getActionName();
        if (! str_contains($action, 'TransferController@')) return;

        $method = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '';
        $id = $request->route('id') ?: $request->route('transfer');
        if (! $id || ! is_numeric($id)) return;

        $transfer = Transfer::whereNull('deleted_at')->find((int) $id);
        if (! $transfer) return;

        if ($transfer->from_inventory_location_id && $transfer->to_inventory_location_id) {
            $sourceAllowed = $locationScope->canAccess($user, (int) $transfer->from_inventory_location_id);
            $destinationAllowed = $locationScope->canAccess($user, (int) $transfer->to_inventory_location_id);
        } else {
            $allowed = $warehouseScope->allowedWarehouseIds($user);
            $sourceAllowed = in_array((int) $transfer->from_warehouse_id, $allowed, true);
            $destinationAllowed = in_array((int) $transfer->to_warehouse_id, $allowed, true);
        }

        $readMethods = ['show', 'edit', 'get_transfer_detail', 'Print_Transfer'];
        if (in_array($method, $readMethods, true)) {
            if (! $sourceAllowed && ! $destinationAllowed) throw new AuthorizationException('No tienes acceso a esta transferencia.');
            return;
        }

        $sourceMutationMethods = ['update', 'destroy', 'approve', 'reject'];
        if (in_array($method, $sourceMutationMethods, true) && ! $sourceAllowed) {
            throw new AuthorizationException('Solo usuarios con acceso al origen pueden modificar o aprobar esta transferencia.');
        }
    }

    private function isLocationTransferPayload(Request $request): bool
    {
        return (bool) ($request->input('transfer.from_inventory_location_id')
            ?: $request->input('from_inventory_location_id'));
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
