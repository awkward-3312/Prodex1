<?php

namespace App\Services;

use App\Models\InventoryLocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TransferBusinessDestinationService
{
    /**
     * Return user-facing destinations for a physical source location.
     *
     * Business rules:
     * - CD/warehouse -> branch storage only (user selects the branch).
     * - Branch storage -> another branch storage OR the same branch sales floor.
     * - Branch sales floor -> same branch storage (return/rebalancing path).
     */
    public function optionsForSource(InventoryLocation $source): Collection
    {
        $locations = InventoryLocation::with(['branch', 'warehouse'])
            ->active()
            ->orderBy('id')
            ->get();

        if ($source->warehouse_id) {
            return $this->branchStorageOptions($locations);
        }

        if ($source->branch_id && $source->type === InventoryLocation::TYPE_STORAGE) {
            $otherBranches = $this->branchStorageOptions($locations)
                ->reject(fn (array $row) => (int) ($row['branch_id'] ?? 0) === (int) $source->branch_id);

            $floor = $this->defaultSalesFloor($locations, (int) $source->branch_id);
            if ($floor) {
                $otherBranches->push($this->locationOption(
                    $floor,
                    'Piso de venta',
                    'sales_floor'
                ));
            }

            return $otherBranches->values();
        }

        if ($source->branch_id && $source->type === InventoryLocation::TYPE_SALES_FLOOR) {
            $storage = $this->primaryBranchStorage($locations, (int) $source->branch_id);
            return $storage
                ? collect([$this->locationOption($storage, 'Bodega de sucursal', 'branch_storage')])
                : collect();
        }

        return collect();
    }

    public function assertAllowed(int $sourceLocationId, int $destinationLocationId): void
    {
        $source = InventoryLocation::active()->find($sourceLocationId);
        $destination = InventoryLocation::active()->find($destinationLocationId);

        if (! $source || ! $destination) {
            throw ValidationException::withMessages([
                'transfer.to_inventory_location_id' => 'La ubicación de origen o destino no existe o está inactiva.',
            ]);
        }

        $allowed = $this->optionsForSource($source)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! in_array((int) $destination->id, $allowed, true)) {
            throw ValidationException::withMessages([
                'transfer.to_inventory_location_id' => 'Ese recorrido de inventario no está permitido. Los envíos a sucursales ingresan primero a su bodega.',
            ]);
        }
    }

    private function branchStorageOptions(Collection $locations): Collection
    {
        return $locations
            ->filter(fn (InventoryLocation $location) => $location->branch_id && $location->type === InventoryLocation::TYPE_STORAGE)
            ->groupBy('branch_id')
            ->map(function (Collection $group) {
                $storage = $this->preferredStorage($group);
                $branchName = optional($storage->branch)->name ?: 'Sucursal '.$storage->branch_id;
                return $this->locationOption($storage, $branchName, 'branch');
            })
            ->values();
    }

    private function primaryBranchStorage(Collection $locations, int $branchId): ?InventoryLocation
    {
        return $this->preferredStorage(
            $locations->filter(fn (InventoryLocation $location) => (int) $location->branch_id === $branchId
                && $location->type === InventoryLocation::TYPE_STORAGE)
        );
    }

    private function preferredStorage(Collection $group): ?InventoryLocation
    {
        if ($group->isEmpty()) return null;

        return $group->first(fn (InventoryLocation $location) => strtoupper((string) $location->code) === 'BODEGA')
            ?: $group->sortBy('id')->first();
    }

    private function defaultSalesFloor(Collection $locations, int $branchId): ?InventoryLocation
    {
        $floors = $locations->filter(fn (InventoryLocation $location) => (int) $location->branch_id === $branchId
            && $location->type === InventoryLocation::TYPE_SALES_FLOOR);

        return $floors->first(fn (InventoryLocation $location) => (bool) $location->is_default_sales)
            ?: $floors->sortBy('id')->first();
    }

    private function locationOption(InventoryLocation $location, string $label, string $destinationType): array
    {
        return [
            'id' => (int) $location->id,
            'name' => $label,
            'code' => (string) $location->code,
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'warehouse_id' => $location->warehouse_id ? (int) $location->warehouse_id : null,
            'type' => $location->type,
            'destination_type' => $destinationType,
        ];
    }
}
