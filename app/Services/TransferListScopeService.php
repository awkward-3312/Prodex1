<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TransferListScopeService
{
    public function apply(Builder $query, User $user): Builder
    {
        $warehouseIds = app(WarehouseScopeService::class)->allowedWarehouseIds($user);
        $hasLocations = Schema::hasTable('inventory_locations')
            && Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

        if (! $hasLocations) {
            return $query->where(function ($scope) use ($warehouseIds) {
                $scope->whereIn('from_warehouse_id', $warehouseIds)
                    ->orWhereIn('to_warehouse_id', $warehouseIds);
            });
        }

        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);

        return $query->where(function ($scope) use ($locationIds, $warehouseIds) {
            $hasModern = false;

            if ($locationIds) {
                $scope->where(function ($modern) use ($locationIds) {
                    $modern->where(function ($present) {
                        $present->whereNotNull('from_inventory_location_id')
                            ->orWhereNotNull('to_inventory_location_id');
                    })->where(function ($allowed) use ($locationIds) {
                        $allowed->whereIn('from_inventory_location_id', $locationIds)
                            ->orWhereIn('to_inventory_location_id', $locationIds);
                    });
                });
                $hasModern = true;
            }

            if ($warehouseIds) {
                $method = $hasModern ? 'orWhere' : 'where';
                $scope->{$method}(function ($legacy) use ($warehouseIds) {
                    $legacy->whereNull('from_inventory_location_id')
                        ->whereNull('to_inventory_location_id')
                        ->where(function ($warehouseScope) use ($warehouseIds) {
                            $warehouseScope->whereIn('from_warehouse_id', $warehouseIds)
                                ->orWhereIn('to_warehouse_id', $warehouseIds);
                        });
                });
            }
        });
    }

    public function sourceOptions(User $user): Collection
    {
        if (! Schema::hasTable('inventory_locations')) {
            return app(WarehouseScopeService::class)->visibleWarehouses($user)
                ->map(fn ($warehouse) => [
                    'id' => (int) $warehouse->id,
                    'name' => (string) $warehouse->name,
                ]);
        }

        $ids = app(InventoryLocationScopeService::class)->allowedLocationIds($user);

        return InventoryLocation::with(['branch', 'warehouse'])
            ->active()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryLocation $location) => [
                'id' => (int) $location->id,
                'name' => $this->locationLabel($location),
            ]);
    }

    public function transferLabel(Transfer $transfer, string $side): string
    {
        $location = $side === 'from'
            ? $transfer->fromInventoryLocation
            : $transfer->toInventoryLocation;

        if ($location) return $this->locationLabel($location);

        $warehouse = $side === 'from' ? $transfer->from_warehouse : $transfer->to_warehouse;
        return (string) ($warehouse?->name ?? '');
    }

    public function locationLabel(InventoryLocation $location): string
    {
        $owner = $location->branch_id ? $location->branch : $location->warehouse;
        $ownerName = trim((string) ($owner?->name ?? ''));
        return trim(($ownerName !== '' ? $ownerName.' · ' : '').$location->name);
    }
}
