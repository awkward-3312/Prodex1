<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BatchLocationService
{
    /**
     * Batch statuses that admit an external physical receive / issue.
     *
     * ONLY `active`. `quarantined` has its own inbound/outbound path in the
     * transfer quarantine services (allocateGood / allocateIssue) and is NOT
     * wired for generic external receive/issue yet — FAIL CLOSED here.
     * `expired` / `written_off` are terminal: the legacy behaviour that silently
     * re-activates them on a new receipt is deliberately NOT reproduced.
     */
    public const OPERABLE_STATUSES = ['active'];

    private const EPS = 0.0005;

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

    // =====================================================================
    // MS5-B0 — external batch ARTIFACT PRIMITIVES (INACTIVE: no productive flow
    // calls these yet). `move()` above is INTERNAL (location -> location).
    // receive() and issue() model an EXTERNAL boundary:
    //
    //   receive  : supplier / outside  ->  location   (from = NULL, to = loc)
    //   issue    : location  ->  supplier / outside    (from = loc, to = NULL)
    //
    // ALL quantities are in BASE UNIT. The caller converts a purchase-unit
    // input to base BEFORE calling. Both mutate the aggregate ProductBatch.qty
    // AND the per-location slice, write an immutable ledger row, and are
    // idempotent by key + fingerprint (same philosophy as move()).
    //
    // NOT a complete inventory operation. receive()/issue() ONLY move the batch
    // artifact and validate the PREVIOUS state. They MUST be executed in the
    // SAME business transaction as the matching InventoryService mutation
    // (increase for receive, decrease for issue). Canonical order:
    //     BATCH / ARTIFACT  ->  GENERAL INVENTORY
    // Do NOT call these from an endpoint directly. MS5 controllers compose the
    // two layers through LocationAwarePurchaseStockService.
    //
    // These primitives do NOT resolve batch identity — the caller passes an
    // already-resolved, active $batchId (see MS5-B1 batch planner). A
    // soft-deleted batch is NEVER auto-restored here.
    //
    // Lock order inside a primitive: ProductBatch -> ProductBatchLocationStock.
    // =====================================================================

    /**
     * External inbound: credit $quantityBase (BASE UNIT) of an existing, active
     * batch into $toLocationId. Increments ProductBatch.qty and the slice.
     *
     * @throws ValidationException  quantity<=0, missing/soft-deleted batch,
     *   inactive location, warehouse mismatch, non-operable status, or a batch /
     *   product+location that is not "native-ready" (see assertBatchNativeReady():
     *   BOTH the batch-aggregate reconciliation AND the product/location general
     *   coverage must hold — even for a brand-new batch).
     */
    public function receive(int $batchId, int $toLocationId, float $quantityBase, array $context = []): ProductBatchLocationMovement
    {
        $quantityBase = round($quantityBase, 3);
        if ($quantityBase <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad base del lote debe ser mayor que cero.']);
        }

        $idempotencyKey = isset($context['idempotency_key']) ? trim((string) $context['idempotency_key']) : null;
        if ($idempotencyKey) {
            $existing = ProductBatchLocationMovement::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->assertSameMovementRequest($existing, $batchId, null, $toLocationId, $quantityBase);

                return $existing;
            }
        }

        return DB::transaction(function () use ($batchId, $toLocationId, $quantityBase, $context, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = ProductBatchLocationMovement::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSameMovementRequest($existing, $batchId, null, $toLocationId, $quantityBase);

                    return $existing;
                }
            }

            $batch = ProductBatch::whereNull('deleted_at')->lockForUpdate()->find($batchId);
            if (! $batch) {
                throw ValidationException::withMessages(['product_batch_id' => 'El lote no existe o fue eliminado.']);
            }

            $location = $this->activeLocation($toLocationId);
            $this->assertBatchLocationWarehouse($batch, $location);

            // ALWAYS — even a brand-new batch (qty 0, no slices): the aggregate
            // reconciliation is trivially 0 == 0, but the product/location
            // general coverage is still enforced, so a legacy-drifted product
            // in this location can NOT take a native receipt.
            $this->assertBatchNativeReady($batch, $toLocationId, $context);

            $slice = ProductBatchLocationStock::firstOrCreate(
                ['product_batch_id' => $batch->id, 'inventory_location_id' => $toLocationId],
                ['quantity' => 0, 'reserved_quantity' => 0]
            );
            $slice = ProductBatchLocationStock::whereKey($slice->id)->lockForUpdate()->firstOrFail();

            $batch->qty = round((float) $batch->qty + $quantityBase, 3);
            $batch->save();

            $slice->quantity = round((float) $slice->quantity + $quantityBase, 3);
            $slice->save();

            return ProductBatchLocationMovement::create([
                'product_batch_id' => $batch->id,
                'from_inventory_location_id' => null,
                'to_inventory_location_id' => $toLocationId,
                'quantity' => $quantityBase,
                'user_id' => $context['user_id'] ?? auth()->id(),
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
                'idempotency_key' => $idempotencyKey ?: null,
                'notes' => $context['notes'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);
        }, 3);
    }

    /**
     * External outbound: debit $quantityBase (BASE UNIT) of an existing, active,
     * native-ready batch out of $fromLocationId. Decrements the slice and
     * ProductBatch.qty. Always requires BOTH the batch-aggregate reconciliation
     * AND the product/location general coverage to hold first. NO clamp, NO
     * max(0): if the slice available quantity or the aggregate is short, FAIL
     * CLOSED with zero mutation.
     *
     * @throws ValidationException
     */
    public function issue(int $batchId, int $fromLocationId, float $quantityBase, array $context = []): ProductBatchLocationMovement
    {
        $quantityBase = round($quantityBase, 3);
        if ($quantityBase <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad base del lote debe ser mayor que cero.']);
        }

        $idempotencyKey = isset($context['idempotency_key']) ? trim((string) $context['idempotency_key']) : null;
        if ($idempotencyKey) {
            $existing = ProductBatchLocationMovement::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                $this->assertSameMovementRequest($existing, $batchId, $fromLocationId, null, $quantityBase);

                return $existing;
            }
        }

        return DB::transaction(function () use ($batchId, $fromLocationId, $quantityBase, $context, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = ProductBatchLocationMovement::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    $this->assertSameMovementRequest($existing, $batchId, $fromLocationId, null, $quantityBase);

                    return $existing;
                }
            }

            $batch = ProductBatch::whereNull('deleted_at')->lockForUpdate()->find($batchId);
            if (! $batch) {
                throw ValidationException::withMessages(['product_batch_id' => 'El lote no existe o fue eliminado.']);
            }

            $location = $this->activeLocation($fromLocationId);
            $this->assertBatchLocationWarehouse($batch, $location);
            // ALWAYS: aggregate reconciliation AND product/location coverage
            // must hold before any decrement.
            $this->assertBatchNativeReady($batch, $fromLocationId, $context);

            $slice = ProductBatchLocationStock::where('product_batch_id', $batch->id)
                ->where('inventory_location_id', $fromLocationId)
                ->lockForUpdate()
                ->first();

            if (! $slice || $slice->available_quantity + self::EPS < $quantityBase) {
                throw ValidationException::withMessages([
                    'quantity' => 'El lote no tiene suficiente existencia disponible en la ubicación de origen.',
                ]);
            }
            if (round((float) $batch->qty, 3) + self::EPS < $quantityBase) {
                throw ValidationException::withMessages([
                    'quantity' => 'La existencia agregada del lote es insuficiente para la salida.',
                ]);
            }

            $batch->qty = round((float) $batch->qty - $quantityBase, 3);
            $batch->save();

            $slice->quantity = round((float) $slice->quantity - $quantityBase, 3);
            $slice->save();

            return ProductBatchLocationMovement::create([
                'product_batch_id' => $batch->id,
                'from_inventory_location_id' => $fromLocationId,
                'to_inventory_location_id' => null,
                'quantity' => $quantityBase,
                'user_id' => $context['user_id'] ?? auth()->id(),
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
                'idempotency_key' => $idempotencyKey ?: null,
                'notes' => $context['notes'] ?? null,
                'metadata' => $context['metadata'] ?? null,
            ]);
        }, 3);
    }

    /**
     * READ-ONLY coherence probe for a product (+ variant) in ONE location:
     * the general location stock vs the sum of that product's batch slices.
     *
     * This is NOT a global invariant. A legacy-drift location (batch received
     * before the location engine, or unit mismatch) returns matches=false; a
     * native-ready location returns matches=true. A caller may require
     * matches=true before an ISSUE. A RECEIVE of a brand-new batch does NOT
     * require prior equality.
     *
     * @return array{inventory_location_id:int, product_id:int,
     *   product_variant_id:?int, general_quantity:float, batch_quantity:float,
     *   difference:float, matches:bool}
     */
    public function batchCoverageForLocation(int $inventoryLocationId, int $productId, ?int $variantId = null): array
    {
        $variantKey = (int) ($variantId ?: 0);

        $general = round((float) InventoryLocationStock::query()
            ->where('inventory_location_id', $inventoryLocationId)
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->sum('quantity'), 3);

        $batch = round((float) ProductBatchLocationStock::query()
            ->where('inventory_location_id', $inventoryLocationId)
            ->whereHas('batch', function ($q) use ($productId, $variantId) {
                $q->where('product_id', $productId);
                $variantId === null
                    ? $q->whereNull('product_variant_id')
                    : $q->where('product_variant_id', $variantId);
            })
            ->sum('quantity'), 3);

        return [
            'inventory_location_id' => $inventoryLocationId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'general_quantity' => $general,
            'batch_quantity' => $batch,
            'difference' => round($batch - $general, 3),
            'matches' => abs($batch - $general) < self::EPS,
        ];
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
        // move() never passes a NULL endpoint — delegate to the null-aware
        // comparator shared with receive() / issue().
        $this->assertSameMovementRequest($movement, $batchId, $fromLocationId, $toLocationId, $quantity);
    }

    /**
     * Fingerprint check for an idempotency-key replay. NULL endpoints are
     * significant: a receipt (from = NULL) and an issue (to = NULL) that reuse
     * the same key with a different batch / location / quantity are rejected.
     */
    private function assertSameMovementRequest(
        ProductBatchLocationMovement $movement,
        int $batchId,
        ?int $fromLocationId,
        ?int $toLocationId,
        float $quantity
    ): void {
        $norm = static fn ($v) => $v === null ? null : (int) $v;

        $same = (int) $movement->product_batch_id === $batchId
            && $norm($movement->from_inventory_location_id) === $norm($fromLocationId)
            && $norm($movement->to_inventory_location_id) === $norm($toLocationId)
            && abs((float) $movement->quantity - $quantity) < self::EPS;

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue utilizada para un movimiento de lote diferente.',
            ]);
        }
    }

    private function assertOperableStatus(ProductBatch $batch): void
    {
        if (! in_array((string) $batch->status, self::OPERABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'batch_transition' => "El lote está en estado '".$batch->status."' y no admite un movimiento físico externo. Estados operables: "
                    .implode(', ', self::OPERABLE_STATUSES).'.',
            ]);
        }
    }

    /**
     * A batch is warehouse-scoped by identity; a location belongs to exactly
     * one warehouse. Both must be present and equal — no guessing.
     */
    private function assertBatchLocationWarehouse(ProductBatch $batch, InventoryLocation $location): void
    {
        $batchWh = $batch->warehouse_id !== null ? (int) $batch->warehouse_id : null;
        $locWh = $location->warehouse_id !== null ? (int) $location->warehouse_id : null;

        if ($batchWh === null) {
            throw ValidationException::withMessages([
                'batch_transition' => 'El lote no tiene almacén asignado; no es apto para una operación de lote por ubicación.',
            ]);
        }
        if ($locWh === null) {
            throw ValidationException::withMessages([
                'batch_transition' => 'La ubicación de inventario no tiene un almacén asignado.',
            ]);
        }
        if ($batchWh !== $locWh) {
            throw ValidationException::withMessages([
                'batch_transition' => 'El lote pertenece al almacén '.$batchWh.', distinto al de la ubicación de inventario ('.$locWh.').',
            ]);
        }
    }

    /**
     * Optional identity assertion. receive()/issue() do NOT resolve batch
     * identity, but a caller that already knows the product/variant can pin it
     * via context['expected_product_id'] / context['expected_variant_id'].
     */
    private function assertExpectedIdentity(ProductBatch $batch, array $context): void
    {
        if (array_key_exists('expected_product_id', $context) && $context['expected_product_id'] !== null
            && (int) $batch->product_id !== (int) $context['expected_product_id']) {
            throw ValidationException::withMessages([
                'batch_transition' => 'El lote no corresponde al producto esperado por la operación.',
            ]);
        }

        if (array_key_exists('expected_variant_id', $context)) {
            $expected = $context['expected_variant_id'] !== null ? (int) $context['expected_variant_id'] : null;
            $actual = $batch->product_variant_id !== null ? (int) $batch->product_variant_id : null;
            if ($expected !== $actual) {
                throw ValidationException::withMessages([
                    'batch_transition' => 'El lote no corresponde a la variante esperada por la operación.',
                ]);
            }
        }
    }

    /**
     * BATCH NATIVE READY (MS5-B0 + hardened in B0.1).
     *
     * An external receive()/issue() may only proceed when TWO INDEPENDENT
     * reconciliations hold, plus the identity / status / sign checks:
     *
     *   1. BATCH AGGREGATE   — ProductBatch.qty == SUM(this batch's slices)
     *                          (reconcileBatch().matches)
     *   2. GENERAL COVERAGE  — inventory_location_stocks.quantity ==
     *                          SUM(all slices of product+variant in THIS location)
     *                          (batchCoverageForLocation().matches)
     *
     * (1) alone is NOT sufficient: the legacy "10 boxes x 12" case can leave
     * ProductBatch.qty == SUM(slices) == 10 while the general location stock is
     * 120 — both batch numbers are in the wrong unit relative to physical
     * stock. (2) catches that.
     *
     * Plus: B non-negative aggregate, C non-negative slices, D warehouse match
     * (asserted separately by the caller before this), E optional expected
     * product/variant, F operable status.
     *
     * Any failure => FAIL CLOSED (`batch_transition`), zero mutation. No
     * auto-reconcile, no backfill, no adjustTo, no unit guessing.
     */
    private function assertBatchNativeReady(ProductBatch $batch, int $inventoryLocationId, array $context = []): void
    {
        $this->assertBatchAggregateReady($batch, $context);
        $this->assertProductLocationBatchCoverageReady($batch, $inventoryLocationId);
    }

    /**
     * (1) + B + C + E + F. The batch's own aggregate must equal the sum of its
     * per-location slices, its quantities must be non-negative, its status must
     * be operable, and — if the caller pinned them — the product/variant must
     * match.
     */
    private function assertBatchAggregateReady(ProductBatch $batch, array $context = []): void
    {
        $this->assertOperableStatus($batch);                 // F
        $this->assertExpectedIdentity($batch, $context);     // E

        if (round((float) $batch->qty, 3) < -self::EPS) {    // B
            throw ValidationException::withMessages([
                'batch_transition' => 'La existencia agregada del lote es negativa; requiere conciliación manual antes de una operación de lote por ubicación.',
            ]);
        }

        $hasNegativeSlice = ProductBatchLocationStock::where('product_batch_id', $batch->id)
            ->where(function ($q) {
                $q->where('quantity', '<', -self::EPS)->orWhere('reserved_quantity', '<', -self::EPS);
            })
            ->exists();
        if ($hasNegativeSlice) {                              // C
            throw ValidationException::withMessages([
                'batch_transition' => 'El lote tiene existencias por ubicación negativas; requiere conciliación manual.',
            ]);
        }

        $reconcile = $this->reconcileBatch($batch->id);       // (1)
        if (! $reconcile['matches']) {
            throw ValidationException::withMessages([
                'batch_transition' => 'El lote no está conciliado: total agregado '.$reconcile['legacy_quantity']
                    .' vs suma de existencias por ubicación '.$reconcile['location_quantity']
                    .'. No puede participar en una operación de lote por ubicación hasta conciliarse.',
            ]);
        }
    }

    /**
     * (2) The general location stock for this batch's product+variant must
     * equal the sum of ALL batch slices of that product+variant in the same
     * location. A legacy-drifted product (e.g. general in base unit, batches in
     * a pack unit, or a partial backfill) FAILS CLOSED here — even for a
     * brand-new batch, so we never mix a native slice on top of legacy drift.
     */
    private function assertProductLocationBatchCoverageReady(ProductBatch $batch, int $inventoryLocationId): void
    {
        $variantId = $batch->product_variant_id !== null ? (int) $batch->product_variant_id : null;
        $coverage = $this->batchCoverageForLocation($inventoryLocationId, (int) $batch->product_id, $variantId);

        if (! $coverage['matches']) {
            throw ValidationException::withMessages([
                'batch_transition' => 'La cobertura de lotes del producto en la ubicación no está conciliada: '
                    .'existencia general '.$coverage['general_quantity']
                    .' vs suma de existencias por lote '.$coverage['batch_quantity']
                    .'. El producto arrastra un descuadre legacy en esta ubicación y no admite una operación de lote por ubicación hasta conciliarse.',
            ]);
        }
    }
}
