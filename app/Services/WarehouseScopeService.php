<?php

namespace App\Services;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class WarehouseScopeService
{
    public function allowedWarehouseIds(User $user): array
    {
        if ((int) $user->is_all_warehouses === 1) {
            return Warehouse::whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $assignmentService = app(UserOperationalAssignmentService::class);
        $ids = $assignmentService->allowedWarehouseIds($user);

        $temporary = $assignmentService->activeTemporaryAssignment($user);
        if ($temporary && $temporary->temporary_warehouse_id) {
            $ids[] = (int) $temporary->temporary_warehouse_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function canAccess(User $user, ?int $warehouseId): bool
    {
        if (! $warehouseId) {
            return false;
        }

        if ((int) $user->is_all_warehouses === 1) {
            return Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->exists();
        }

        return in_array((int) $warehouseId, $this->allowedWarehouseIds($user), true);
    }

    public function assertAccess(User $user, ?int $warehouseId, string $message = 'No tienes acceso a esta bodega.'): void
    {
        if (! $this->canAccess($user, $warehouseId)) {
            throw new AuthorizationException($message);
        }
    }

    public function scopeQuery(Builder $query, User $user, string $column = 'warehouse_id'): Builder
    {
        if ((int) $user->is_all_warehouses === 1) {
            return $query;
        }

        return $query->whereIn($column, $this->allowedWarehouseIds($user));
    }

    public function visibleWarehouses(User $user)
    {
        $query = Warehouse::whereNull('deleted_at')->orderBy('name');

        if ((int) $user->is_all_warehouses !== 1) {
            $query->whereIn('id', $this->allowedWarehouseIds($user));
        }

        return $query->get();
    }
}
