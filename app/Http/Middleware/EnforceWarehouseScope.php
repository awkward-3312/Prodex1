<?php

namespace App\Http\Middleware;

use App\Models\Transfer;
use App\Services\WarehouseScopeService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class EnforceWarehouseScope
{
    public function handle(Request $request, Closure $next)
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return $next($request);
        }

        $user = null;
        try {
            $user = $request->user('api') ?: auth('api')->user();
        } catch (\Throwable $e) {
            return $next($request);
        }

        if (! $user || ! isset($user->is_all_warehouses) || (int) $user->is_all_warehouses === 1) {
            return $next($request);
        }

        $scope = app(WarehouseScopeService::class);

        $this->validateRequestSelectors($request, $user, $scope);
        $this->validateScopeEscalation($request, $user, $scope);
        $this->validateTransferRoute($request, $user, $scope);

        return $next($request);
    }

    private function validateRequestSelectors(Request $request, $user, WarehouseScopeService $scope): void
    {
        $protectedKeys = [
            'warehouse_id',
            'default_warehouse_id',
            'from_warehouse_id',
            'from_warehouse',
        ];

        $this->walk($request->all(), function (string $key, $value) use ($protectedKeys, $user, $scope) {
            if (! in_array($key, $protectedKeys, true)) {
                return;
            }

            if ($value === null || $value === '' || is_array($value) || is_object($value) || ! is_numeric($value)) {
                return;
            }

            $scope->assertAccess(
                $user,
                (int) $value,
                'No tienes permiso para operar con la bodega seleccionada.'
            );
        });

        if ($request->isMethod('get') && $request->filled('warehouse_id') && is_numeric($request->query('warehouse_id'))) {
            $scope->assertAccess(
                $user,
                (int) $request->query('warehouse_id'),
                'No tienes permiso para consultar esa bodega.'
            );
        }
    }

    /**
     * Prevent a restricted administrator from using legacy user/organization forms
     * to grant somebody broader warehouse access than the administrator has.
     *
     * Several historical endpoints accept arrays named assigned_to or warehouse_ids,
     * while newer endpoints use scope=all. These values cannot be covered by the
     * scalar selector validation above, so they are checked explicitly here.
     */
    private function validateScopeEscalation(Request $request, $user, WarehouseScopeService $scope): void
    {
        if ($request->isMethod('get') || $request->isMethod('head')) {
            return;
        }

        $wantsAll = $request->input('scope') === 'all'
            || $this->truthy($request->input('is_all_warehouses'));

        if ($wantsAll) {
            throw new AuthorizationException('No puedes conceder acceso global a bodegas desde una cuenta con alcance restringido.');
        }

        $allowed = $scope->allowedWarehouseIds($user);
        foreach (['assigned_to', 'warehouse_ids'] as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $requested = $request->input($key);
            if ($requested === null || $requested === '') {
                continue;
            }

            if (! is_array($requested)) {
                $requested = [$requested];
            }

            foreach ($requested as $warehouseId) {
                if ($warehouseId === null || $warehouseId === '' || ! is_numeric($warehouseId)) {
                    continue;
                }

                if (! in_array((int) $warehouseId, $allowed, true)) {
                    throw new AuthorizationException('No puedes asignar una bodega fuera de tu propio alcance operativo.');
                }
            }
        }
    }

    private function validateTransferRoute(Request $request, $user, WarehouseScopeService $scope): void
    {
        $route = $request->route();
        if (! $route || ! method_exists($route, 'getActionName')) {
            return;
        }

        $action = (string) $route->getActionName();
        if (! str_contains($action, 'TransferController@')) {
            return;
        }

        $method = str_contains($action, '@') ? substr($action, strrpos($action, '@') + 1) : '';
        $id = $request->route('id') ?: $request->route('transfer');

        if (! $id || ! is_numeric($id)) {
            return;
        }

        $transfer = Transfer::whereNull('deleted_at')->find((int) $id);
        if (! $transfer) {
            return;
        }

        $allowed = $scope->allowedWarehouseIds($user);
        $sourceAllowed = in_array((int) $transfer->from_warehouse_id, $allowed, true);
        $destinationAllowed = in_array((int) $transfer->to_warehouse_id, $allowed, true);

        $readMethods = ['show', 'edit', 'get_transfer_detail', 'Print_Transfer'];
        if (in_array($method, $readMethods, true)) {
            if (! $sourceAllowed && ! $destinationAllowed) {
                throw new AuthorizationException('No tienes acceso a esta transferencia.');
            }
            return;
        }

        $sourceMutationMethods = ['update', 'destroy', 'approve', 'reject'];
        if (in_array($method, $sourceMutationMethods, true) && ! $sourceAllowed) {
            throw new AuthorizationException('Solo usuarios con acceso a la bodega de origen pueden modificar o aprobar esta transferencia.');
        }
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function walk(array $data, callable $callback): void
    {
        foreach ($data as $key => $value) {
            $callback((string) $key, $value);
            if (is_array($value)) {
                $this->walk($value, $callback);
            }
        }
    }
}
