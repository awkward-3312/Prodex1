<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves physical stock between InventoryLocations while keeping generic stock,
 * batches and serialized units consistent inside one transaction.
 */
class InternalInventoryMoveService
{
    public function move(
        int $fromLocationId,
        int $toLocationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $options = []
    ): array {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad a mover debe ser mayor que cero.']);
        }

        $product = Product::whereNull('deleted_at')->findOrFail($productId);
        if ($product->type === 'is_service') {
            throw ValidationException::withMessages(['product_id' => 'Los servicios no tienen inventario físico para trasladar.']);
        }

        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? '')) ?: null;
        $context = [
            'user_id' => $options['user_id'] ?? auth()->id(),
            'reference_type' => $options['reference_type'] ?? 'internal_inventory_move',
            'reference_id' => isset($options['reference_id']) ? (string) $options['reference_id'] : null,
            'notes' => $options['notes'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ];

        return DB::transaction(function () use (
            $fromLocationId,
            $toLocationId,
            $product,
            $productId,
            $quantity,
            $variantId,
            $options,
            $context,
            $idempotencyKey
        ) {
            $batchMovements = [];
            $serialIds = [];

            if ((bool) ($product->is_batch_tracked ?? false)) {
                $allocations = $this->resolveBatchAllocations(
                    $fromLocationId,
                    $productId,
                    $variantId,
                    $quantity,
                    (array) ($options['batch_allocations'] ?? [])
                );

                foreach ($allocations as $index => $allocation) {
                    $batchMovements[] = app(BatchLocationService::class)->move(
                        (int) $allocation['product_batch_id'],
                        $fromLocationId,
                        $toLocationId,
                        (float) $allocation['quantity'],
                        $context + [
                            'idempotency_key' => $idempotencyKey
                                ? $idempotencyKey.':batch:'.$index
                                : null,
                        ]
                    );
                }
            }

            if ((bool) ($product->is_imei ?? false)) {
                $serialIds = array_values(array_unique(array_map('intval', (array) ($options['serial_ids'] ?? []))));
                if (abs(count($serialIds) - $quantity) > 0.0005) {
                    throw ValidationException::withMessages([
                        'serial_ids' => 'Para un producto serializado debes seleccionar exactamente un serial por unidad trasladada.',
                    ]);
                }

                app(SerialLocationService::class)->moveSerials(
                    $serialIds,
                    $fromLocationId,
                    $toLocationId,
                    $context
                );
            }

            $movement = app(InventoryService::class)->move(
                $fromLocationId,
                $toLocationId,
                $productId,
                $quantity,
                $variantId,
                $context + ['idempotency_key' => $idempotencyKey]
            );

            return [
                'movement' => $movement,
                'batch_movements' => $batchMovements,
                'serial_ids' => $serialIds,
            ];
        }, 3);
    }

    private function resolveBatchAllocations(
        int $fromLocationId,
        int $productId,
        ?int $variantId,
        float $requiredQuantity,
        array $requested
    ): array {
        $allocations = [];
        $sum = 0.0;

        foreach ($requested as $row) {
            if (! is_array($row)) continue;
            $batchId = (int) ($row['product_batch_id'] ?? $row['batch_id'] ?? 0);
            $qty = round((float) ($row['quantity'] ?? $row['qty'] ?? 0), 3);
            if ($batchId <= 0 || $qty <= 0) continue;
            $allocations[] = ['product_batch_id' => $batchId, 'quantity' => $qty];
            $sum += $qty;
        }

        if ($allocations) {
            if (abs(round($sum, 3) - $requiredQuantity) > 0.0005) {
                throw ValidationException::withMessages([
                    'batch_allocations' => 'La suma de lotes seleccionados debe coincidir exactamente con la cantidad trasladada.',
                ]);
            }
            return $allocations;
        }

        // FEFO automatic allocation when the user did not choose lots manually.
        $available = app(BatchLocationService::class)->availableBatches($fromLocationId, $productId, $variantId);
        $remaining = $requiredQuantity;
        foreach ($available as $batch) {
            if ($remaining <= 0.0005) break;
            $take = min($remaining, (float) $batch['available_quantity']);
            if ($take <= 0) continue;
            $allocations[] = [
                'product_batch_id' => (int) $batch['id'],
                'quantity' => round($take, 3),
            ];
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages([
                'batch_allocations' => 'No hay suficiente existencia por lote en la ubicación de origen.',
            ]);
        }

        return $allocations;
    }
}
