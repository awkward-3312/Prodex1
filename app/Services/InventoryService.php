<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationMovement;
use App\Models\InventoryLocationStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public const MOVEMENT_INCREASE = 'increase';
    public const MOVEMENT_DECREASE = 'decrease';
    public const MOVEMENT_TRANSFER = 'transfer';
    public const MOVEMENT_RESERVE = 'reserve';
    public const MOVEMENT_RELEASE = 'release';
    public const MOVEMENT_CONSUME_RESERVED = 'consume_reserved';
    public const MOVEMENT_ADJUSTMENT = 'adjustment';

    public function quantity(int $locationId, int $productId, ?int $variantId = null): float
    {
        $stock = $this->stockQuery($locationId, $productId, $variantId)->first();
        return $stock ? round((float) $stock->quantity, 3) : 0.0;
    }

    public function reserved(int $locationId, int $productId, ?int $variantId = null): float
    {
        $stock = $this->stockQuery($locationId, $productId, $variantId)->first();
        return $stock ? round((float) $stock->reserved_quantity, 3) : 0.0;
    }

    public function available(int $locationId, int $productId, ?int $variantId = null): float
    {
        $stock = $this->stockQuery($locationId, $productId, $variantId)->first();
        return $stock ? $stock->available_quantity : 0.0;
    }

    public function increase(
        int $locationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);
            $stock->quantity = $this->decimal((float) $stock->quantity + $quantity);
            $stock->save();

            return $this->movement(self::MOVEMENT_INCREASE, $productId, $variantId, null, $locationId, $quantity, $context);
        });
    }

    public function decrease(
        int $locationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);
            $available = $this->decimal((float) $stock->quantity - (float) $stock->reserved_quantity);
            $this->assertEnough($available, $quantity, 'No hay existencia disponible suficiente en la ubicación seleccionada.');

            $stock->quantity = $this->decimal((float) $stock->quantity - $quantity);
            $stock->save();

            return $this->movement(self::MOVEMENT_DECREASE, $productId, $variantId, $locationId, null, $quantity, $context);
        });
    }

    public function move(
        int $fromLocationId,
        int $toLocationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);
        if ($fromLocationId === $toLocationId) {
            throw ValidationException::withMessages(['to_inventory_location_id' => 'El origen y el destino deben ser ubicaciones diferentes.']);
        }

        return $this->transactional($context, function () use ($fromLocationId, $toLocationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($fromLocationId);
            $this->activeLocation($toLocationId);

            // Always lock in deterministic location order to reduce deadlock risk.
            $ids = [$fromLocationId, $toLocationId];
            sort($ids, SORT_NUMERIC);
            $locked = [];
            foreach ($ids as $locationId) {
                $locked[$locationId] = $this->lockedStock($locationId, $productId, $variantId);
            }

            $from = $locked[$fromLocationId];
            $to = $locked[$toLocationId];
            $available = $this->decimal((float) $from->quantity - (float) $from->reserved_quantity);
            $this->assertEnough($available, $quantity, 'No hay existencia disponible suficiente para realizar el movimiento.');

            $from->quantity = $this->decimal((float) $from->quantity - $quantity);
            $from->save();

            $to->quantity = $this->decimal((float) $to->quantity + $quantity);
            $to->save();

            return $this->movement(self::MOVEMENT_TRANSFER, $productId, $variantId, $fromLocationId, $toLocationId, $quantity, $context);
        });
    }

    public function reserve(
        int $locationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);
            $available = $this->decimal((float) $stock->quantity - (float) $stock->reserved_quantity);
            $this->assertEnough($available, $quantity, 'No hay existencia disponible suficiente para reservar.');

            $stock->reserved_quantity = $this->decimal((float) $stock->reserved_quantity + $quantity);
            $stock->save();

            return $this->movement(self::MOVEMENT_RESERVE, $productId, $variantId, $locationId, $locationId, $quantity, $context);
        });
    }

    public function release(
        int $locationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);
            $this->assertEnough((float) $stock->reserved_quantity, $quantity, 'No existe suficiente cantidad reservada para liberar.');

            $stock->reserved_quantity = $this->decimal((float) $stock->reserved_quantity - $quantity);
            $stock->save();

            return $this->movement(self::MOVEMENT_RELEASE, $productId, $variantId, $locationId, $locationId, $quantity, $context);
        });
    }

    public function consumeReserved(
        int $locationId,
        int $productId,
        float $quantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $quantity = $this->positiveQuantity($quantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $quantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);
            $this->assertEnough((float) $stock->reserved_quantity, $quantity, 'No existe suficiente cantidad reservada para consumir.');
            $this->assertEnough((float) $stock->quantity, $quantity, 'La existencia física es insuficiente para consumir la reserva.');

            $stock->reserved_quantity = $this->decimal((float) $stock->reserved_quantity - $quantity);
            $stock->quantity = $this->decimal((float) $stock->quantity - $quantity);
            $stock->save();

            return $this->movement(self::MOVEMENT_CONSUME_RESERVED, $productId, $variantId, $locationId, null, $quantity, $context);
        });
    }

    public function adjustTo(
        int $locationId,
        int $productId,
        float $newQuantity,
        ?int $variantId = null,
        array $context = []
    ): InventoryLocationMovement {
        $newQuantity = $this->nonNegativeQuantity($newQuantity);

        return $this->transactional($context, function () use ($locationId, $productId, $variantId, $newQuantity, $context) {
            $this->activeLocation($locationId);
            $stock = $this->lockedStock($locationId, $productId, $variantId);

            if ($newQuantity < (float) $stock->reserved_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'El ajuste no puede dejar la existencia física por debajo de la cantidad reservada.',
                ]);
            }

            $oldQuantity = $this->decimal((float) $stock->quantity);
            $delta = $this->decimal(abs($newQuantity - $oldQuantity));
            if ($delta <= 0) {
                throw ValidationException::withMessages(['quantity' => 'El ajuste no produce ningún cambio de inventario.']);
            }

            $stock->quantity = $newQuantity;
            $stock->save();

            $context['metadata'] = array_merge($context['metadata'] ?? [], [
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'direction' => $newQuantity > $oldQuantity ? 'increase' : 'decrease',
            ]);

            return $this->movement(
                self::MOVEMENT_ADJUSTMENT,
                $productId,
                $variantId,
                $newQuantity < $oldQuantity ? $locationId : null,
                $newQuantity > $oldQuantity ? $locationId : null,
                $delta,
                $context
            );
        });
    }

    private function transactional(array $context, callable $callback): InventoryLocationMovement
    {
        return DB::transaction(function () use ($context, $callback) {
            $key = isset($context['idempotency_key']) ? trim((string) $context['idempotency_key']) : '';
            if ($key !== '') {
                $existing = InventoryLocationMovement::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) return $existing;
            }

            return $callback();
        }, 3);
    }

    private function lockedStock(int $locationId, int $productId, ?int $variantId): InventoryLocationStock
    {
        $variantKey = (int) ($variantId ?: 0);

        InventoryLocationStock::firstOrCreate([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'variant_key' => $variantKey,
        ], [
            'product_variant_id' => $variantId,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'manage_stock' => true,
        ]);

        return InventoryLocationStock::where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function stockQuery(int $locationId, int $productId, ?int $variantId)
    {
        return InventoryLocationStock::where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0));
    }

    private function activeLocation(int $locationId): InventoryLocation
    {
        $location = InventoryLocation::active()->find($locationId);
        if (! $location) {
            throw ValidationException::withMessages(['inventory_location_id' => 'La ubicación de inventario no existe o está inactiva.']);
        }
        return $location;
    }

    private function movement(
        string $type,
        int $productId,
        ?int $variantId,
        ?int $fromLocationId,
        ?int $toLocationId,
        float $quantity,
        array $context
    ): InventoryLocationMovement {
        return InventoryLocationMovement::create([
            'movement_type' => $type,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'from_inventory_location_id' => $fromLocationId,
            'to_inventory_location_id' => $toLocationId,
            'quantity' => $this->decimal($quantity),
            'user_id' => $context['user_id'] ?? null,
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
            'idempotency_key' => isset($context['idempotency_key']) && trim((string) $context['idempotency_key']) !== ''
                ? trim((string) $context['idempotency_key'])
                : null,
            'notes' => $context['notes'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ]);
    }

    private function assertEnough(float $available, float $requested, string $message): void
    {
        if ($this->decimal($available) + 0.000001 < $this->decimal($requested)) {
            throw ValidationException::withMessages(['quantity' => $message]);
        }
    }

    private function positiveQuantity(float $quantity): float
    {
        $quantity = $this->decimal($quantity);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor que cero.']);
        }
        return $quantity;
    }

    private function nonNegativeQuantity(float $quantity): float
    {
        $quantity = $this->decimal($quantity);
        if ($quantity < 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad no puede ser negativa.']);
        }
        return $quantity;
    }

    private function decimal(float $quantity): float
    {
        return round($quantity, 3);
    }
}
