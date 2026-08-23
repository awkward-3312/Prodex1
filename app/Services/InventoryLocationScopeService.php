<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryLocationScopeService
{
    public function allowedLocationIds(User $user): array
    {
        if ((int) $user->role_id === 1) {
            return $this->allActiveLocationIds();
        }

        $receivingStorage = $this->branchReceivingStorageIds($user);
        $explicit = $this->explicitLocationIds($user);
        $temporary = $this->temporaryLocationId($user);
        if ($explicit) {
            if ($temporary) $explicit[] = $temporary;
            return $this->activeUnique(array_merge($explicit, $receivingStorage));
        }

        $fallback = [];
        if ($user->default_inventory_location_id) {
            $fallback[] = (int) $user->default_inventory_location_id;
        }
        if ($temporary) $fallback[] = $temporary;

        if ($fallback || $receivingStorage) {
            return $this->activeUnique(array_merge($fallback, $receivingStorage));
        }

        // Transitional default for branch-scoped users: when no explicit inventory
        // scope exists yet, only the branch's default sales location is operable.
        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);
        if ($branchIds) {
            $branchDefaults = DB::table('branches')
                ->whereIn('id', $branchIds)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->whereNotNull('default_inventory_location_id')
                ->pluck('default_inventory_location_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($branchDefaults) return $this->activeUnique($branchDefaults);
        }

        // Legacy warehouse users continue to operate their CD default locations
        // until their branch/location scope is configured explicitly.
        if (Schema::hasTable('user_warehouse') && Schema::hasColumn('warehouses', 'default_inventory_location_id')) {
            $warehouseIds = DB::table('user_warehouse')->where('user_id', $user->id)->pluck('warehouse_id');
            $legacyDefaults = DB::table('warehouses')
                ->whereIn('id', $warehouseIds)
                ->whereNull('deleted_at')
                ->whereNotNull('default_inventory_location_id')
                ->pluck('default_inventory_location_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($legacyDefaults) return $this->activeUnique($legacyDefaults);
        }

        return [];
    }

    public function canAccess(User $user, int $locationId): bool
    {
        return in_array($locationId, $this->allowedLocationIds($user), true);
    }

    private function branchReceivingStorageIds(User $user): array
    {
        if (! Schema::hasTable('inventory_locations')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_user')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_role')
            || ! $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION)) {
            return [];
        }

        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);
        if (! $branchIds) return [];

        // TransferBusinessDestinationService sends inter-site transfers to the
        // primary storage location of each branch (code BODEGA when present,
        // otherwise the oldest active storage). Mirror that rule here instead
        // of granting every internal storage location in the branch.
        return InventoryLocation::active()
            ->whereIn('branch_id', $branchIds)
            ->where('type', InventoryLocation::TYPE_STORAGE)
            ->orderByRaw("CASE WHEN UPPER(code) = 'BODEGA' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->groupBy('branch_id')
            ->map(fn ($locations) => (int) $locations->first()->id)
            ->values()
            ->all();
    }

    private function explicitLocationIds(User $user): array
    {
        if (! Schema::hasTable('user_inventory_locations')) return [];

        return DB::table('user_inventory_locations')
            ->where('user_id', $user->id)
            ->pluck('inventory_location_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function temporaryLocationId(User $user): ?int
    {
        if (! Schema::hasTable('user_operational_assignments')
            || ! Schema::hasColumn('user_operational_assignments', 'temporary_inventory_location_id')) {
            return null;
        }

        $assignment = UserOperationalAssignment::where('user_id', $user->id)
            ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
            ->whereNotNull('temporary_inventory_location_id')
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first(['temporary_inventory_location_id']);

        return $assignment?->temporary_inventory_location_id ? (int) $assignment->temporary_inventory_location_id : null;
    }

    private function allActiveLocationIds(): array
    {
        return InventoryLocation::active()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function activeUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if (! $ids) return [];

        return InventoryLocation::active()->whereIn('id', $ids)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
