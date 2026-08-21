<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchLocationService
{
    public function availableBatches(
        int $inventoryLocationId,
        int $productId,
        ?int $variantId = null
    ): array {
        $this->activeLocation($inventoryLocationId);

        $query = ProductBatch::active()
            ->forProduct($productId)
            ->forInventoryLocation($inventoryLocationId);

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        return $query->fefo()->get()->map(function (ProductBatch $batch) use ($inventoryLocationId) {
            $stock = ProductBatchLocationStock::where('product_batch_id', $batch->id)
                ->where('inventory_location_id', $inventoryLocationId)
                ->first();

            return [
                'id' => (int) $batch->id,
                'batch_no' => (string) $batch->batch_no,
                'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                'mfg_date' => $batch->mfg_date?->format('Y-m-d'),
                'quantity' => round((float) ($stock?->quantity ?? 0), 3),
                'reserved_quantity' => round((float) ($stock?->reserved_quantity ?? 0), 3),
                'available_quantity' => round((float) ($stock?->available_quantity ?? 0), 3),
                'unit_cost' => $batch->unit_cost !== null ? (float) $batch->unit_cost : null,
            ];
        })->all();
    }

    public function move(
        int $batchId,
        int $fromLocationId,
        int $toLocationId,
        float $quantity,
        array $context = []
    ): ProductBatchLocationMovement {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad del lote debe ser mayor que cero.']);
        }
        if ($fromLocationId === $toLocationId) {
            throw ValidationException::withMessages(['inventory_location' => 'El origen y destino del lote deben ser diferentes.']);
        }

        $idempotencyKey = isset($context['idempotency_key']) ? trim((string) $context['idempotency_key']) : null;
        if ($idempotencyKey) {
            $existing = ProductBatchLocationMovement::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->assertSameRequest($existing, $batchId, $fromLocationId, $toLocationId, $quantity);
                return $existing;
            }
        }

        return DB::transaction(function () use ($batchId, $fromLocationId, $toLocationId, $quantity, $context, $idempotencyKey) {
            $batch = ProductBatch::whereNull('deleted_at')->lockForUpdate()->findOrFail($batchId);
            $this->activeLocation($fromLocationId);
            $this->activeLocation($toLocationId);

            $from = ProductBatchLocationStock::where('product_batch_id', $batch->id)
                ->where('inventory_location_id', $fromLocationId)
                ->lockForUpdate()
                ->first();

            if (! $from || $from->available_quantity + 0.0005 < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'El lote no tiene suficiente existencia disponible en la ubicación de origen.',
                ]);
            }

            $to = ProductBatchLocationStock::firstOrCreate(
                ['product_batch_id' => $batch->id, 'inventory_location_id' => $toLocationId],
                ['quantity' => 0, 'reserved_quantity' => 0]
            );
            $to = ProductBatchLocationStock::whereKey($to->id)->lockForUpdate()->firstOrFail();

            $from->quantity = round((float) $from->quantity - $quantity, 3);
            $from->save();

            $to->quantity = round((float) $to->quantity + $quantity, 3);
            $to->save();

            return ProductBatchLocationMovement::create([
                'product_batch_id' => $batch->id,
                'from_inventory_location_id' => $fromLocationId,
                'to_inventory_location_id' => $toLocationId,
                'quantity' => $quantity,
                'user_id' => $context['user_id'] ?? auth()->id(),
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
                'idempotency_key' => $idempotencyKey ?: null,
                'notes' => $context['notes'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);
        }, 3);
    }

    public function totalForBatch(int $batchId): float
    {
        return round((float) ProductBatchLocationStock::where('product_batch_id', $batchId)->sum('quantity'), 3);
    }

    public function reconcileBatch(int $batchId): array
    {
        $batch = ProductBatch::whereNull('deleted_at')->findOrFail($batchId);
        $legacy = round((float) $batch->qty, 3);
        $locations = $this->totalForBatch($batchId);

        return [
            'product_batch_id' => $batch->id,
            'legacy_quantity' => $legacy,
            'location_quantity' => $locations,
            'difference' => round($locations - $legacy, 3),
            'matches' => abs($legacy - $locations) < 0.0005,
        ];
    }

    private function activeLocation(int $id): InventoryLocation
    {
        $location = InventoryLocation::active()->find($id);
        if (! $location) {
            throw ValidationException::withMessages(['inventory_location_id' => 'La ubicación de inventario no existe o está inactiva.']);
        }
        return $location;
    }

    private function assertSameRequest(
        ProductBatchLocationMovement $movement,
        int $batchId,
        int $fromLocationId,
        int $toLocationId,
        float $quantity
    ): void {
        $same = (int) $movement->product_batch_id === $batchId
            && (int) $movement->from_inventory_location_id === $fromLocationId
            && (int) $movement->to_inventory_location_id === $toLocationId
            && abs((float) $movement->quantity - $quantity) < 0.0005;

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue utilizada para un movimiento de lote diferente.',
            ]);
        }
    }
}
