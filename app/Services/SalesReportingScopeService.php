<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Canonical read scope for sales during the warehouse -> branch/location cutover.
 *
 * Modern POS sales are owned by branch_id / inventory_location_id / cash_drawer_id.
 * warehouse_id is only a legacy compatibility pointer and may legitimately be NULL.
 * Historical sales with no branch snapshot remain visible through their warehouse.
 */
class SalesReportingScopeService
{
    public function allowedBranchIds(User $user): array
    {
        return app(BranchScopeService::class)->allowedBranchIds($user);
    }

    public function allowedWarehouseIds(User $user): array
    {
        return app(UserOperationalAssignmentService::class)->allowedWarehouseIds($user);
    }

    public function branchesFor(User $user)
    {
        $ids = $this->allowedBranchIds($user);

        return Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Apply access + optional operational selector to a query that contains sales.
     * Works with Eloquent and DB query builders.
     */
    public function apply(
        EloquentBuilder|QueryBuilder $query,
        User $user,
        string $alias = 'sales',
        ?int $requestedWarehouseId = null,
        ?int $requestedBranchId = null
    ) {
        $requestedWarehouseId = $requestedWarehouseId ?: null;
        $requestedBranchId = $requestedBranchId ?: null;

        if ($requestedBranchId) {
            if (! $this->canAccessBranch($user, $requestedBranchId)) {
                return $query->whereRaw('1 = 0');
            }

            $legacyWarehouseIds = Warehouse::whereNull('deleted_at')
                ->where('branch_id', $requestedBranchId)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();

            return $query->where(function ($q) use ($alias, $requestedBranchId, $legacyWarehouseIds) {
                $q->where("{$alias}.branch_id", $requestedBranchId);
                if ($legacyWarehouseIds) {
                    $q->orWhere(function ($legacy) use ($alias, $legacyWarehouseIds) {
                        $legacy->whereNull("{$alias}.branch_id")
                            ->whereIn("{$alias}.warehouse_id", $legacyWarehouseIds);
                    });
                }
            });
        }

        if ($requestedWarehouseId) {
            if (! $this->canAccessWarehouse($user, $requestedWarehouseId)) {
                return $query->whereRaw('1 = 0');
            }

            $warehouse = Warehouse::whereNull('deleted_at')->find($requestedWarehouseId);
            $branchId = $warehouse && $warehouse->branch_id ? (int) $warehouse->branch_id : null;

            return $query->where(function ($q) use ($alias, $requestedWarehouseId, $branchId) {
                // Historical sale: exact legacy warehouse.
                $q->where(function ($legacy) use ($alias, $requestedWarehouseId) {
                    $legacy->whereNull("{$alias}.branch_id")
                        ->where("{$alias}.warehouse_id", $requestedWarehouseId);
                });

                // Modern sale: the warehouse selector is translated to its branch.
                if ($branchId) {
                    $q->orWhere("{$alias}.branch_id", $branchId);
                }
            });
        }

        // Owner is tenant-wide by definition. Do not accidentally hide modern sales
        // just because warehouse_id is NULL.
        if ((int) $user->role_id === 1) {
            return $query;
        }

        $branchIds = $this->allowedBranchIds($user);
        $warehouseIds = $this->allowedWarehouseIds($user);

        return $query->where(function ($q) use ($alias, $branchIds, $warehouseIds) {
            $hasModernScope = ! empty($branchIds);
            $hasLegacyScope = ! empty($warehouseIds);

            if ($hasModernScope) {
                $q->whereIn("{$alias}.branch_id", $branchIds);
            }

            if ($hasLegacyScope) {
                $method = $hasModernScope ? 'orWhere' : 'where';
                $q->{$method}(function ($legacy) use ($alias, $warehouseIds) {
                    $legacy->whereNull("{$alias}.branch_id")
                        ->whereIn("{$alias}.warehouse_id", $warehouseIds);
                });
            }

            if (! $hasModernScope && ! $hasLegacyScope) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function applyRecordVisibility(
        EloquentBuilder|QueryBuilder $query,
        User $user,
        string $alias = 'sales'
    ) {
        // Tenant owners must always be able to audit every sale in their tenant,
        // including sales created by cashiers and other employees. record_view is
        // a staff-level restriction and must never reduce owner visibility.
        if ((int) $user->role_id === 1) {
            return $query;
        }

        if (! $user->hasRecordView()) {
            $query->where("{$alias}.user_id", $user->id);
        }

        return $query;
    }

    public function displayLocation($sale): string
    {
        if ($sale->relationLoaded('branch') && $sale->branch) {
            return (string) $sale->branch->name;
        }
        if ($sale->relationLoaded('warehouse') && $sale->warehouse) {
            return (string) $sale->warehouse->name;
        }
        if ($sale->relationLoaded('inventoryLocation') && $sale->inventoryLocation) {
            return (string) $sale->inventoryLocation->name;
        }

        return '—';
    }

    private function canAccessBranch(User $user, int $branchId): bool
    {
        return (int) $user->role_id === 1
            || in_array($branchId, $this->allowedBranchIds($user), true);
    }

    private function canAccessWarehouse(User $user, int $warehouseId): bool
    {
        return (int) $user->role_id === 1
            || in_array($warehouseId, $this->allowedWarehouseIds($user), true);
    }
}
