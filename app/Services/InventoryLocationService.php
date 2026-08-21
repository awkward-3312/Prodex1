<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryLocationService
{
    public function createForBranch(Branch $branch, array $attributes): InventoryLocation
    {
        return $this->create('branch', $branch->id, $attributes);
    }

    public function createForWarehouse(Warehouse $warehouse, array $attributes): InventoryLocation
    {
        return $this->create('warehouse', $warehouse->id, $attributes);
    }

    public function create(string $ownerType, int $ownerId, array $attributes): InventoryLocation
    {
        if (! in_array($ownerType, ['branch', 'warehouse'], true)) {
            throw ValidationException::withMessages(['owner_type' => 'El propietario de la ubicación debe ser sucursal o almacén/CD.']);
        }

        $this->assertOwnerExists($ownerType, $ownerId);

        $code = strtoupper(trim((string) ($attributes['code'] ?? '')));
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'El código de la ubicación es obligatorio.']);
        }

        $query = InventoryLocation::whereNull('deleted_at')->where('code', $code);
        $ownerType === 'branch'
            ? $query->where('branch_id', $ownerId)
            : $query->where('warehouse_id', $ownerId);

        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe una ubicación con este código dentro del mismo propietario.']);
        }

        return DB::transaction(function () use ($ownerType, $ownerId, $attributes, $code) {
            $location = InventoryLocation::create([
                'branch_id' => $ownerType === 'branch' ? $ownerId : null,
                'warehouse_id' => $ownerType === 'warehouse' ? $ownerId : null,
                'code' => $code,
                'name' => trim((string) ($attributes['name'] ?? $code)),
                'type' => $attributes['type'] ?? InventoryLocation::TYPE_STORAGE,
                'is_sellable' => (bool) ($attributes['is_sellable'] ?? false),
                'is_default_sales' => (bool) ($attributes['is_default_sales'] ?? false),
                'is_quarantine' => (bool) ($attributes['is_quarantine'] ?? false),
                'is_active' => array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : true,
            ]);

            if ($location->is_default_sales) {
                $this->makeDefaultSalesLocation($location);
            }

            return $location->fresh();
        });
    }

    public function makeDefaultSalesLocation(InventoryLocation $location): InventoryLocation
    {
        if (! $location->branch_id) {
            throw ValidationException::withMessages([
                'is_default_sales' => 'Solo una ubicación perteneciente a una sucursal puede ser el piso de venta predeterminado.',
            ]);
        }

        return DB::transaction(function () use ($location) {
            InventoryLocation::whereNull('deleted_at')
                ->where('branch_id', $location->branch_id)
                ->where('id', '!=', $location->id)
                ->update(['is_default_sales' => false]);

            $location->forceFill([
                'is_default_sales' => true,
                'is_sellable' => true,
                'is_active' => true,
            ])->save();

            Branch::whereKey($location->branch_id)->update([
                'default_inventory_location_id' => $location->id,
            ]);

            return $location->fresh();
        });
    }

    public function setWarehouseDefault(InventoryLocation $location): InventoryLocation
    {
        if (! $location->warehouse_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'La ubicación debe pertenecer a un almacén/CD.',
            ]);
        }

        Warehouse::whereKey($location->warehouse_id)->update([
            'default_inventory_location_id' => $location->id,
        ]);

        return $location->fresh();
    }

    private function assertOwnerExists(string $ownerType, int $ownerId): void
    {
        $exists = $ownerType === 'branch'
            ? Branch::whereNull('deleted_at')->where('is_active', true)->whereKey($ownerId)->exists()
            : Warehouse::whereNull('deleted_at')->whereKey($ownerId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'owner_id' => 'La sucursal o almacén/CD seleccionado no existe o no está activo.',
            ]);
        }
    }
}
