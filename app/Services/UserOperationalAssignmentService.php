<?php

namespace App\Services;

use App\Models\CashDrawer;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class UserOperationalAssignmentService
{
    public function effectiveAssignment(User $user, ?Carbon $at = null): array
    {
        $at = $at ?: now();
        $temporary = $this->activeTemporaryAssignment($user, $at);

        if ($temporary) {
            $warehouse = $this->activeWarehouse($temporary->temporary_warehouse_id);
            $drawer = $this->activeDrawerForWarehouse($temporary->temporary_cash_drawer_id, $warehouse?->id);

            return [
                'source' => 'temporary',
                'warehouse_id' => $warehouse ? (int) $warehouse->id : null,
                'cash_drawer_id' => $drawer ? (int) $drawer->id : null,
                'warehouse' => $warehouse,
                'cash_drawer' => $drawer,
                'assignment' => $temporary,
                'can_override' => $this->canOverride($user),
            ];
        }

        $warehouse = $user->defaultWarehouse;
        $drawer = $user->defaultCashDrawer;

        $warehouse = $this->activeWarehouse($warehouse?->id ?: $user->default_warehouse_id);
        $drawer = $this->activeDrawerForWarehouse($drawer?->id ?: $user->default_cash_drawer_id, $warehouse?->id);

        return [
            'source' => 'default',
            'warehouse_id' => $warehouse ? (int) $warehouse->id : null,
            'cash_drawer_id' => $drawer ? (int) $drawer->id : null,
            'warehouse' => $warehouse,
            'cash_drawer' => $drawer,
            'assignment' => null,
            'can_override' => $this->canOverride($user),
        ];
    }

    public function activeTemporaryAssignment(User $user, ?Carbon $at = null): ?UserOperationalAssignment
    {
        if (! Schema::hasTable('user_operational_assignments')) {
            return null;
        }

        $at = $at ?: now();

        return UserOperationalAssignment::with(['temporaryWarehouse', 'temporaryCashDrawer'])
            ->where('user_id', $user->id)
            ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
            ->where('starts_at', '<=', $at)
            ->where(function ($query) use ($at) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }

    public function canOverride(User $user): bool
    {
        return $user->hasPermissionName('cash_register_override_assignment');
    }

    public function allowedWarehouseIds(User $user): array
    {
        if ((int) $user->is_all_warehouses === 1) {
            return Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return UserWarehouse::where('user_id', $user->id)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function validateUserDefaults(User $user, ?int $warehouseId, ?int $cashDrawerId): void
    {
        if (! $warehouseId && ! $cashDrawerId) {
            return;
        }

        if (! $warehouseId) {
            throw ValidationException::withMessages([
                'default_warehouse_id' => 'Seleccione un warehouse habitual.',
            ]);
        }

        if (! in_array((int) $warehouseId, $this->allowedWarehouseIds($user), true)) {
            throw ValidationException::withMessages([
                'default_warehouse_id' => 'El warehouse habitual debe estar dentro de los warehouses permitidos.',
            ]);
        }

        if (! $cashDrawerId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'Seleccione una caja física habitual.',
            ]);
        }

        $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
        if (! $drawer || ! $drawer->is_active || (int) $drawer->warehouse_id !== (int) $warehouseId) {
            throw ValidationException::withMessages([
                'default_cash_drawer_id' => 'La caja física habitual debe estar activa y pertenecer al warehouse habitual.',
            ]);
        }
    }

    public function validateRequestedAssignment(User $user, ?int $warehouseId, ?int $cashDrawerId, bool $requireDrawer = true): void
    {
        if (! $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'Seleccione un warehouse.']);
        }
        if ($requireDrawer && ! $cashDrawerId) {
            throw ValidationException::withMessages(['cash_drawer_id' => 'Seleccione una caja física.']);
        }

        if ($cashDrawerId) {
            $drawer = CashDrawer::whereNull('deleted_at')->find($cashDrawerId);
            if (! $drawer || ! $drawer->is_active || (int) $drawer->warehouse_id !== (int) $warehouseId) {
                throw ValidationException::withMessages([
                    'cash_drawer_id' => 'La caja física debe estar activa y pertenecer al warehouse seleccionado.',
                ]);
            }
        }

        if ($this->canOverride($user)) {
            return;
        }

        $effective = $this->effectiveAssignment($user);
        if ((int) $effective['warehouse_id'] !== (int) $warehouseId || (int) $effective['cash_drawer_id'] !== (int) $cashDrawerId) {
            throw new AuthorizationException('No tiene permiso para operar fuera de su warehouse/caja asignada.');
        }
    }

    private function activeWarehouse($warehouseId): ?Warehouse
    {
        if (! $warehouseId) {
            return null;
        }

        return Warehouse::whereNull('deleted_at')->find($warehouseId);
    }

    private function activeDrawerForWarehouse($cashDrawerId, $warehouseId): ?CashDrawer
    {
        if (! $cashDrawerId || ! $warehouseId) {
            return null;
        }

        return CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('warehouse_id', $warehouseId)
            ->find($cashDrawerId);
    }
}
