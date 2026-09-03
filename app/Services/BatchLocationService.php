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
    // MS5-B0 / B0.1 / B0.2 — external batch ARTIFACT PRIMITIVES (INACTIVE: no
    // productive flow calls these yet). `move()` above is INTERNAL
    // (location -> location). The external boundary is:
    //
    //   receive  : supplier / outside  ->  location   (from = NULL, to = loc)
    //   issue    : location  ->  supplier / outside    (from = loc, to = NULL)
    //
    // ALL quantities are BASE UNIT. The caller converts a purchase-unit input to
    // base BEFORE calling. Every external mutation goes through ONE physical
    // implementation, applyExternalBatchSet(), as an ATOMIC SET:
    //
    //   A. normalize the whole set
    //   B. resolve idempotency against the PRE-STATE (all-new / full-replay /
    //      partial => FAIL CLOSED / fingerprint clash => 422)
    //   C. lock the whole set deterministically (ProductBatch id ASC, then
    //      ProductBatchLocationStock by (product_batch_id, id) ASC)
    //   D. validate the whole set against the PRE-STATE:
    //        - per unique batch: status / warehouse / expected identity /
    //          non-negative qty+slices / reconcileBatch().matches
    //        - per unique (product, variant): batchCoverageForLocation().matches
    //          — exactly ONCE, on the PRE-STATE (so a set of many batches of the
    //          same product never re-checks coverage after a partial mutation)
    //        - ISSUE: aggregated sufficiency per batch (SUM of its allocations),
    //          NO clamp
    //   E. mutate: ProductBatch.qty and the slice by the AGGREGATED delta per
    //      batch
    //   F. one immutable ProductBatchLocationMovement PER allocation, with the
    //      individual quantity + idempotency_key
    //
    // NOT a complete inventory operation. It ONLY moves the batch artifact and
    // validates the PREVIOUS state. It MUST run inside an OUTER business
    // transaction that also performs the matching InventoryService mutation
    // (increase for receive, decrease for issue) — this is now enforced
    // (LogicException). Canonical order:  BATCH / ARTIFACT  ->  GENERAL INVENTORY.
    // Do NOT call these from an endpoint directly. MS5 controllers compose the
    // two layers through LocationAwarePurchaseStockService.
    //
    // These primitives do NOT resolve batch identity — the caller passes
    // already-resolved, active batch ids. A soft-deleted batch is NEVER
    // auto-restored here. BatchLocationService NEVER touches InventoryService.
    // =====================================================================

    private const DIR_RECEIVE = 'receive';

    private const DIR_ISSUE = 'issue';

    /**
     * External inbound (single). Delegates to receiveMany() — ONE physical
     * implementation for every external mutation.
     */
    public function receive(int $batchId, int $toLocationId, float $quantityBase, array $context = []): ProductBatchLocationMovement
    {
        return $this->receiveMany($toLocationId, [$this->singleAllocation($batchId, $quantityBase, $context)], $context)[0];
    }

    /**
     * External outbound (single). Delegates to issueMany().
     */
    public function issue(int $batchId, int $fromLocationId, float $quantityBase, array $context = []): ProductBatchLocationMovement
    {
        return $this->issueMany($fromLocationId, [$this->singleAllocation($batchId, $quantityBase, $context)], $context)[0];
    }

    /**
     * External inbound (SET). Credit a set of BASE-UNIT allocations into
     * $toLocationId as ONE atomic operation. Coverage / reconciliation are
     * validated ONCE against the PRE-STATE, before the first mutation.
     *
     * @param  array<int, array{product_batch_id:int, quantity:float,
     *   idempotency_key?:?string, expected_product_id?:int,
     *   expected_variant_id?:?int, reference_type?:string, reference_id?:string,
     *   notes?:?string, metadata?:?array}>  $allocations
     * @return ProductBatchLocationMovement[]  one per allocation, in order
     *
     * @throws \LogicException          no outer transaction
     * @throws ValidationException      set invalid / not native-ready / partial replay
     */
    public function receiveMany(int $toLocationId, array $allocations, array $context = []): array
    {
        return $this->applyExternalBatchSet(self::DIR_RECEIVE, $toLocationId, $allocations, $context);
    }

    /**
     * External outbound (SET). Debit a set of BASE-UNIT allocations out of
     * $fromLocationId as ONE atomic operation. Sufficiency is checked on the
     * AGGREGATED requested quantity per batch (a batch may appear more than
     * once). NO clamp.
     *
     * @param  array<int, array{product_batch_id:int, quantity:float, ...}>  $allocations
     * @return ProductBatchLocationMovement[]
     *
     * @throws \LogicException
     * @throws ValidationException
     */
    public function issueMany(int $fromLocationId, array $allocations, array $context = []): array
    {
        return $this->applyExternalBatchSet(self::DIR_ISSUE, $fromLocationId, $allocations, $context);
    }

    private function singleAllocation(int $batchId, float $quantityBase, array $context): array
    {
        $a = ['product_batch_id' => $batchId, 'quantity' => $quantityBase];
        foreach (['idempotency_key', 'expected_product_id', 'expected_variant_id', 'reference_type', 'reference_id', 'notes', 'metadata'] as $k) {
            if (array_key_exists($k, $context)) {
                $a[$k] = $context[$k];
            }
        }

        return $a;
    }

    /**
     * The ONE physical implementation for every external batch mutation.
     * NEVER calls receive()/issue() — a set is not a loop of singles (that would
     * re-run coverage on a partially mutated state). NEVER calls InventoryService.
     */
    private function applyExternalBatchSet(string $direction, int $externalLocationId, array $allocations, array $context): array
    {
        if (DB::transactionLevel() <= 0) {
            throw new \LogicException(
                'BatchLocationService::'.$direction.'Many() must run inside an outer business transaction '
                .'(the same one that performs the matching InventoryService mutation).'
            );
        }

        $isReceive = $direction === self::DIR_RECEIVE;
        $fromLoc = $isReceive ? null : $externalLocationId;
        $toLoc = $isReceive ? $externalLocationId : null;

        // ---- A. normalize the whole set --------------------------------
        $rows = [];
        foreach (array_values($allocations) as $i => $a) {
            $batchId = (int) ($a['product_batch_id'] ?? 0);
            $qty = round((float) ($a['quantity'] ?? 0), 3);
            if ($batchId <= 0) {
                throw ValidationException::withMessages(["allocations.$i" => 'Asignación de lote sin identificador válido.']);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages(["allocations.$i" => 'La cantidad base del lote debe ser mayor que cero.']);
            }
            $rows[] = [
                'product_batch_id' => $batchId,
                'quantity' => $qty,
                'idempotency_key' => isset($a['idempotency_key']) && trim((string) $a['idempotency_key']) !== ''
                    ? trim((string) $a['idempotency_key']) : null,
                'expected_product_id' => array_key_exists('expected_product_id', $a) ? $a['expected_product_id'] : null,
                'has_expected_variant' => array_key_exists('expected_variant_id', $a),
                'expected_variant_id' => $a['expected_variant_id'] ?? null,
                'reference_type' => $a['reference_type'] ?? ($context['reference_type'] ?? null),
                'reference_id' => array_key_exists('reference_id', $a) ? $a['reference_id'] : ($context['reference_id'] ?? null),
                'notes' => $a['notes'] ?? ($context['notes'] ?? null),
                'metadata' => $a['metadata'] ?? ($context['metadata'] ?? null),
            ];
        }
        if (empty($rows)) {
            throw ValidationException::withMessages(['allocations' => 'El conjunto de asignaciones de lote está vacío.']);
        }

        // ---- B. idempotency resolution against the PRE-STATE ----------
        $keyed = array_values(array_filter($rows, fn ($r) => $r['idempotency_key'] !== null));
        $existingByKey = $keyed
            ? ProductBatchLocationMovement::whereIn('idempotency_key', array_map(fn ($r) => $r['idempotency_key'], $keyed))
                ->get()->keyBy('idempotency_key')
            : collect();

        $replayed = 0;
        foreach ($keyed as $r) {
            $existing = $existingByKey->get($r['idempotency_key']);
            if (! $existing) {
                continue;
            }
            // D — key reused for a physically different request.
            $this->assertSameMovementRequest($existing, $r['product_batch_id'], $fromLoc, $toLoc, $r['quantity']);
            $replayed++;
        }
        if ($replayed > 0) {
            // B — clean full replay: EVERY allocation keyed and matched.
            if (count($keyed) === count($rows) && $replayed === count($rows)) {
                return array_map(fn ($r) => $existingByKey->get($r['idempotency_key']), $rows);
            }
            // C — the set is partially applied; never silently complete it.
            throw ValidationException::withMessages([
                'batch_transition' => 'El conjunto de movimientos de lote está parcialmente aplicado ('
                    .$replayed.'/'.count($rows).'); no se completará automáticamente. Requiere revisión.',
            ]);
        }

        // ---- C. lock the whole set deterministically -----------------
        $batchIds = array_values(array_unique(array_map(fn ($r) => $r['product_batch_id'], $rows)));
        sort($batchIds, SORT_NUMERIC);

        $batches = ProductBatch::whereIn('id', $batchIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($batchIds as $bid) {
            if (! $batches->has($bid)) {
                throw ValidationException::withMessages(['product_batch_id' => 'El lote '.$bid.' no existe o fue eliminado.']);
            }
        }

        $location = $this->activeLocation($externalLocationId);

        if ($isReceive) {
            // resolve/create the zero slices we will credit (no quantity mutation).
            foreach ($batchIds as $bid) {
                ProductBatchLocationStock::firstOrCreate(
                    ['product_batch_id' => $bid, 'inventory_location_id' => $externalLocationId],
                    ['quantity' => 0, 'reserved_quantity' => 0]
                );
            }
        }

        $slices = ProductBatchLocationStock::whereIn('product_batch_id', $batchIds)
            ->where('inventory_location_id', $externalLocationId)
            ->orderBy('product_batch_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('product_batch_id');

        // ---- D. validate the whole set against the PRE-STATE ---------
        $requestedByBatch = [];
        foreach ($rows as $r) {
            $requestedByBatch[$r['product_batch_id']] = round(
                ($requestedByBatch[$r['product_batch_id']] ?? 0) + $r['quantity'], 3
            );
        }

        $coverageChecked = [];
        foreach ($batchIds as $bid) {
            $batch = $batches->get($bid);

            $this->assertOperableStatus($batch);                     // F
            $this->assertBatchLocationWarehouse($batch, $location);  // D (warehouse)

            // E — expected product / variant, from any allocation for this batch.
            foreach ($rows as $r) {
                if ($r['product_batch_id'] !== $bid) {
                    continue;
                }
                $idCtx = [];
                if ($r['expected_product_id'] !== null) {
                    $idCtx['expected_product_id'] = $r['expected_product_id'];
                }
                if ($r['has_expected_variant']) {
                    $idCtx['expected_variant_id'] = $r['expected_variant_id'];
                }
                if ($idCtx) {
                    $this->assertExpectedIdentity($batch, $idCtx);
                }
            }

            // B/C + (1) reconcile — all on the PRE-STATE.
            $this->assertBatchAggregateReady($batch);

            // (2) coverage — ONCE per unique (product, variant), PRE-STATE.
            $variantId = $batch->product_variant_id !== null ? (int) $batch->product_variant_id : null;
            $covKey = $batch->product_id.'|'.(int) ($variantId ?: 0);
            if (! isset($coverageChecked[$covKey])) {
                $this->assertCoverageMatches($externalLocationId, (int) $batch->product_id, $variantId);
                $coverageChecked[$covKey] = true;
            }

            // ISSUE sufficiency — AGGREGATED per batch, no clamp.
            if (! $isReceive) {
                $need = $requestedByBatch[$bid];
                $slice = $slices->get($bid);
                if (! $slice || $slice->available_quantity + self::EPS < $need) {
                    throw ValidationException::withMessages([
                        'quantity' => 'El lote '.$bid.' no tiene suficiente existencia disponible en la ubicación de origen.',
                    ]);
                }
                if (round((float) $batch->qty, 3) + self::EPS < $need) {
                    throw ValidationException::withMessages([
                        'quantity' => 'La existencia agregada del lote '.$bid.' es insuficiente para la salida.',
                    ]);
                }
            }
        }

        // ---- E. mutate the whole set (aggregated per batch) ----------
        $sign = $isReceive ? 1 : -1;
        foreach ($batchIds as $bid) {
            $batch = $batches->get($bid);
            $delta = $sign * $requestedByBatch[$bid];

            $batch->qty = round((float) $batch->qty + $delta, 3);
            $batch->save();

            $slice = $slices->get($bid);
            $slice->quantity = round((float) $slice->quantity + $delta, 3);
            $slice->save();
        }

        // ---- F. one immutable ledger row PER allocation --------------
        $userId = function_exists('auth') ? auth()->id() : null;
        $movements = [];
        foreach ($rows as $r) {
            $movements[] = ProductBatchLocationMovement::create([
                'product_batch_id' => $r['product_batch_id'],
                'from_inventory_location_id' => $fromLoc,
                'to_inventory_location_id' => $toLoc,
                'quantity' => $r['quantity'],
                'user_id' => $context['user_id'] ?? $userId,
                'reference_type' => $r['reference_type'],
                'reference_id' => $r['reference_id'] !== null ? (string) $r['reference_id'] : null,
                'idempotency_key' => $r['idempotency_key'],
                'notes' => $r['notes'],
                'metadata' => $r['metadata'],
            ]);
        }

        return $movements;
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
     * PER-BATCH PRE-STATE checks for an external set (MS5-B0 + B0.1 + B0.2):
     *   B  ProductBatch.qty is not negative
     *   C  no slice of this batch has a negative quantity / reserved_quantity
     *   1  reconcileBatch().matches  (aggregate == SUM of this batch's slices)
     *
     * Status (F), warehouse (D) and expected product/variant (E) are asserted
     * by applyExternalBatchSet() alongside this. Coverage (2) is asserted ONCE
     * per (product, variant) — see assertCoverageMatches(). Everything runs on
     * the PRE-STATE, before the first mutation. FAIL CLOSED => `batch_transition`.
     */
    private function assertBatchAggregateReady(ProductBatch $batch): void
    {
        if (round((float) $batch->qty, 3) < -self::EPS) {    // B
            throw ValidationException::withMessages([
                'batch_transition' => 'La existencia agregada del lote es negativa; requiere conciliación manual antes de una operación de lote por ubicación.',
            ]);
        }

        // NOTE: CAST(... AS DECIMAL(20,6)) is required — MySQL 8.4 evaluates
        // `decimal(12,3)_column < -0.0005` (literal scale 4 > column scale 3)
        // as TRUE for a 0.000 row. The cast normalizes both sides; harmless on
        // SQLite. Same reason for the negative-aggregate raw compare below.
        $hasNegativeSlice = ProductBatchLocationStock::where('product_batch_id', $batch->id)
            ->whereRaw('CAST(quantity AS DECIMAL(20,6)) < ? OR CAST(reserved_quantity AS DECIMAL(20,6)) < ?', [-self::EPS, -self::EPS])
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
     * (2) GENERAL COVERAGE — the general location stock for a (product, variant)
     * must equal the sum of ALL its batch slices in that location. Checked ONCE
     * per (product, variant) per set, on the PRE-STATE — so a set of many
     * batches of the same product never re-checks coverage after a partial
     * mutation. A legacy-drifted product (general in base unit, batches in a
     * pack unit, partial backfill, …) FAILS CLOSED here, even for a brand-new
     * batch, so a native slice is never laid on top of legacy drift.
     */
    private function assertCoverageMatches(int $inventoryLocationId, int $productId, ?int $variantId): void
    {
        $coverage = $this->batchCoverageForLocation($inventoryLocationId, $productId, $variantId);

        if (! $coverage['matches']) {
            throw ValidationException::withMessages([
                'batch_transition' => 'La cobertura de lotes del producto '.$productId.' en la ubicación no está conciliada: '
                    .'existencia general '.$coverage['general_quantity']
                    .' vs suma de existencias por lote '.$coverage['batch_quantity']
                    .'. El producto arrastra un descuadre legacy en esta ubicación y no admite una operación de lote por ubicación hasta conciliarse.',
            ]);
        }
    }
}
