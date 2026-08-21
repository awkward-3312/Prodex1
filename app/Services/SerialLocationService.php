<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SerialLocationService
{
    public function availableSerials(
        int $inventoryLocationId,
        int $productId,
        ?int $variantId = null
    ): array {
        $this->activeLocation($inventoryLocationId);
        if (! Schema::hasColumn('product_serials', 'inventory_location_id')) return [];

        $query = ProductSerial::available()
            ->forProduct($productId)
            ->forInventoryLocation($inventoryLocationId)
            ->orderBy('serial_number');

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        return $query->get(['id', 'serial_number', 'product_id', 'product_variant_id', 'inventory_location_id', 'status'])
            ->map(fn (ProductSerial $serial) => [
                'id' => (int) $serial->id,
                'serial_number' => (string) $serial->serial_number,
                'product_id' => (int) $serial->product_id,
                'product_variant_id' => $serial->product_variant_id ? (int) $serial->product_variant_id : null,
                'inventory_location_id' => (int) $serial->inventory_location_id,
                'status' => (string) $serial->status,
            ])->all();
    }

    public function moveSerials(
        array $serialIds,
        int $fromLocationId,
        int $toLocationId,
        array $context = []
    ): array {
        $serialIds = array_values(array_unique(array_filter(array_map('intval', $serialIds), fn ($id) => $id > 0)));
        if (! $serialIds) {
            throw ValidationException::withMessages(['serials' => 'Selecciona al menos un número de serie.']);
        }
        if ($fromLocationId === $toLocationId) {
            throw ValidationException::withMessages(['inventory_location' => 'El origen y destino deben ser diferentes.']);
        }

        $this->activeLocation($fromLocationId);
        $this->activeLocation($toLocationId);

        return DB::transaction(function () use ($serialIds, $fromLocationId, $toLocationId, $context) {
            $serials = ProductSerial::whereIn('id', $serialIds)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($serials->count() !== count($serialIds)) {
                throw ValidationException::withMessages(['serials' => 'Uno de los números de serie seleccionados no existe.']);
            }

            $moved = [];
            foreach ($serials as $serial) {
                if ((int) $serial->inventory_location_id !== $fromLocationId) {
                    throw ValidationException::withMessages([
                        'serials' => "El serial {$serial->serial_number} no se encuentra en la ubicación de origen.",
                    ]);
                }
                if (in_array($serial->status, [ProductSerial::STATUS_SOLD, ProductSerial::STATUS_RETURNED_SUPPLIER], true)) {
                    throw ValidationException::withMessages([
                        'serials' => "El serial {$serial->serial_number} no puede trasladarse con estado {$serial->status}.",
                    ]);
                }

                $serial->inventory_location_id = $toLocationId;
                $serial->save();

                ProductSerialMovement::create([
                    'product_serial_id' => $serial->id,
                    'serial_number' => $serial->serial_number,
                    'action' => ProductSerialMovement::ACTION_LOCATION_MOVED,
                    'from_status' => $serial->status,
                    'to_status' => $serial->status,
                    'warehouse_id' => $serial->warehouse_id,
                    'from_inventory_location_id' => $fromLocationId,
                    'to_inventory_location_id' => $toLocationId,
                    'reference_type' => $context['reference_type'] ?? null,
                    'reference_id' => isset($context['reference_id']) ? (int) $context['reference_id'] : null,
                    'user_id' => $context['user_id'] ?? auth()->id(),
                    'notes' => $context['notes'] ?? null,
                    'created_at' => now(),
                ]);

                $moved[] = $serial->id;
            }

            return $moved;
        }, 3);
    }

    public function assertSerialsBelongToLocation(array $serialNumbers, int $locationId): void
    {
        $serialNumbers = array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $serialNumbers))));
        if (! $serialNumbers) return;

        $count = ProductSerial::whereIn('serial_number', $serialNumbers)
            ->where('inventory_location_id', $locationId)
            ->count();

        if ($count !== count($serialNumbers)) {
            throw ValidationException::withMessages([
                'serial_numbers' => 'Uno o más seriales no pertenecen a la ubicación de inventario seleccionada.',
            ]);
        }
    }

    private function activeLocation(int $id): InventoryLocation
    {
        $location = InventoryLocation::active()->find($id);
        if (! $location) {
            throw ValidationException::withMessages(['inventory_location_id' => 'La ubicación de inventario no existe o está inactiva.']);
        }
        return $location;
    }
}
