<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * MS7-B1 — Admin Sale / Sale Return location-native GENERAL engine.
 *
 * Mirrors LocationAwarePurchaseStockService's contract (validateAndLock ->
 * buildSnapshot -> applySnapshot / reverseSnapshot, quantity_base, revision,
 * idempotency keys) for the two remaining manual documents that still write
 * product_warehouse directly for a location_primary warehouse.
 *
 * SCOPE — deliberately narrower than the Purchase engine:
 *   - GENERAL (InventoryService::decrease/increase) is fully owned HERE,
 *     snapshot-driven, exactly like Purchase.
 *   - BATCH is owned by LocationAwareBatchService — its
 *     applyForSaleWithAutoFallback / reverseForSaleDetails and
 *     applyForSaleReturnWithAutoFallback / reverseForSaleReturnDetails
 *     ALREADY self-branch on $sale->inventory_location_id /
 *     $return->inventory_location_id (built for POS, proven, reused as-is —
 *     never reinvented here). Their SaleDetailBatch / SaleReturnDetailBatch
 *     pivots are the physical reverse authority for batch, exactly as they
 *     already are for POS and for the legacy admin flow; buildSnapshot()
 *     below only RECORDS those pivots into the snapshot afterward, for
 *     provenance / audit (§30 of the MS7-B1 spec) — reversing a document
 *     never re-derives batch mutations by replaying the JSON.
 *   - SERIAL is owned by LocationAwareSerialNumberService — sellOnSale /
 *     returnFromSale (apply) and the new reverseForSaleDetails /
 *     reverseForSaleReturn overrides (reverse) are FK-driven
 *     (ProductSerial.sale_id/sale_detail_id), FAIL CLOSED, exactly like the
 *     provenance the ProductSerial ledger already carries for Purchases.
 *     buildSnapshot() records the resolved product_serial_id + serial_number
 *     per line for the SAME provenance reason.
 *
 * This split is a deliberate architecture decision (documented here and in
 * the MS7-B1 delivery report): GENERAL is snapshot-replay-authoritative
 * (nothing else could reconstruct "how much" without it); BATCH/SERIAL are
 * FK/pivot-authoritative (already proven, already used by POS, and those
 * pivots/FKs are written in the SAME transaction as the snapshot so the two
 * can never diverge). Reinventing a batch/serial JSON-replay layer here would
 * duplicate — and risk regressing — machinery POS already depends on.
 */
class LocationAwareSaleStockService
{
    public const DOC_SALE = 'sale';
    public const DOC_SALE_RETURN = 'sale_return';

    private const EPS = 0.0005;

    // =====================================================================
    // 1 · Line resolution / validation (mirrors LocationAwarePurchaseStockService)
    // =====================================================================

    /**
     * Resolve + lock every line's Product/ProductVariant, compute quantity_base
     * (the SAME conversion PosLocationSaleStockService::baseQuantity uses — no
     * duplicated formula), and mark requires_batch / requires_serial. FAIL
     * CLOSED (422) on: unknown/soft-deleted product or variant, qty<=0, a
     * product that is BOTH batch AND IMEI tracked on one line (a line carries
     * only ONE physical artifact tracker — same fence as native Purchase), or
     * a fractional base for a requires_serial line.
     *
     * @param  array<int,array{product_id:int,product_variant_id:?int,quantity:float,sale_unit_id:?int,pack_multiplier:?float,product_type?:?string}>  $lines
     * @return array{lines: array<int,array>}
     *
     * @throws ValidationException
     */
    public function validateAndLock(array $lines): array
    {
        if (empty($lines)) {
            throw ValidationException::withMessages(['details' => 'El documento no tiene líneas.']);
        }

        $productIds = array_values(array_unique(array_filter(array_map(
            fn ($l) => (int) ($l['product_id'] ?? 0), $lines
        ))));
        sort($productIds, SORT_NUMERIC);
        $products = $productIds
            ? Product::whereIn('id', $productIds)->with('unitSale')->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        $variantIds = array_values(array_unique(array_filter(array_map(
            fn ($l) => (int) ($l['product_variant_id'] ?? 0), $lines
        ))));
        sort($variantIds, SORT_NUMERIC);
        $variants = $variantIds
            ? ProductVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        $posStock = app(PosLocationSaleStockService::class);
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $product = $products->get($productId);
            if (! $product) {
                throw ValidationException::withMessages([
                    "details.$i.product_id" => 'El producto de la línea '.($i + 1).' no existe o fue eliminado.',
                ]);
            }
            if ((string) $product->type === 'is_service') {
                continue; // services never carry physical inventory.
            }

            $variantId = ! empty($line['product_variant_id']) ? (int) $line['product_variant_id'] : null;
            if ($variantId !== null) {
                $variant = $variants->get($variantId);
                if (! $variant || (int) $variant->product_id !== $productId) {
                    throw ValidationException::withMessages([
                        "details.$i.product_variant_id" => 'La variante de la línea '.($i + 1).' no existe o no corresponde al producto.',
                    ]);
                }
            }

            $qtyBase = round($posStock->baseQuantity($line, $product), 3);
            if ($qtyBase <= 0) {
                throw ValidationException::withMessages([
                    "details.$i.quantity" => 'La cantidad física de la línea '.($i + 1).' debe ser mayor que cero.',
                ]);
            }

            $isBatch = (bool) ($product->is_batch_tracked ?? false);
            $isImei = (int) ($product->is_imei ?? 0) === 1;
            if ($isBatch && $isImei) {
                throw ValidationException::withMessages([
                    "details.$i" => 'La línea '.($i + 1).' es de lote Y de serie/IMEI a la vez. La combinación lote+serie no está soportada.',
                ]);
            }

            $requiresSerial = false;
            if ($isImei) {
                $requiresSerial = true;
                $rounded = round($qtyBase);
                if (abs($qtyBase - $rounded) > self::EPS) {
                    throw ValidationException::withMessages([
                        "details.$i.quantity" => 'La línea '.($i + 1).' usa serie/IMEI y sólo admite una cantidad base entera (calculada: '.$qtyBase.').',
                    ]);
                }
                $qtyBase = (float) $rounded;
            }

            $out[] = [
                // Service lines are skipped above (no physical inventory), so
                // this array is NOT positionally aligned with the raw request
                // lines any more — every downstream consumer MUST key off
                // _line_index (the original raw-line position), never off its
                // own re-indexed position, to stay lined up with $detailIds /
                // $rawLines. Dropped by buildSnapshot() before persistence.
                '_line_index' => $i,
                'source_detail_id' => null, // filled by withSourceDetailIds() once details are persisted
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity_base' => $qtyBase,
                'requires_batch' => $isBatch,
                'requires_serial' => $requiresSerial,
            ];
        }

        return ['lines' => $out];
    }

    public function withSourceDetailIds(array $validatedLines, array $detailIds): array
    {
        $out = [];
        foreach (array_values($validatedLines) as $line) {
            // Key off _line_index (the ORIGINAL raw-line position), never off
            // this loop's own position — validateAndLock() silently drops
            // service-type lines, so the two arrays are not positionally
            // aligned once any line is skipped.
            $rawIndex = $line['_line_index'] ?? null;
            $out[] = ['source_detail_id' => $rawIndex !== null ? ($detailIds[$rawIndex] ?? null) : null] + $line;
        }

        return $out;
    }

    // =====================================================================
    // 2 · Snapshot build / normalize
    // =====================================================================

    /**
     * @param  array<int,array>  $effects  each: source_detail_id, product_id,
     *   product_variant_id, quantity_base, batch_allocation?, serial_allocation?
     */
    public function buildSnapshot(string $documentType, int $warehouseId, int $locationId, array $effects, int $revision): array
    {
        $this->assertDocumentType($documentType);
        if ($revision < 1) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Revisión de snapshot inválida.']);
        }
        if (empty($effects)) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot sin efectos.']);
        }

        $sign = $documentType === self::DOC_SALE ? -1 : 1; // Sale removes stock, SaleReturn restores it.
        $out = [];
        foreach ($effects as $e) {
            $qtyBase = round((float) $e['quantity_base'], 3);
            if ($qtyBase <= 0) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Efecto con cantidad base no positiva.']);
            }
            $out[] = [
                'source_detail_id' => $e['source_detail_id'] ?? null,
                'product_id' => (int) $e['product_id'],
                'product_variant_id' => isset($e['product_variant_id']) && $e['product_variant_id'] !== null ? (int) $e['product_variant_id'] : null,
                'quantity_base' => $qtyBase,
                'delta' => round($sign * $qtyBase, 3),
                'batch_allocation' => $e['batch_allocation'] ?? [],
                'serial_allocation' => $e['serial_allocation'] ?? [],
            ];
        }

        return [
            'version' => 1,
            'document_type' => $documentType,
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => $locationId,
            'revision' => $revision,
            'effects' => $out,
        ];
    }

    public function normalizeSnapshot($raw): array
    {
        $snapshot = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($snapshot)) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot corrupto o ausente. FAIL CLOSED.']);
        }
        $this->assertDocumentType((string) ($snapshot['document_type'] ?? ''));
        if (! is_int($snapshot['revision'] ?? null) || $snapshot['revision'] < 1) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Revisión de snapshot inválida.']);
        }
        $warehouseId = (int) ($snapshot['warehouse_id'] ?? 0);
        $locationId = (int) ($snapshot['inventory_location_id'] ?? 0);
        if ($warehouseId <= 0 || $locationId <= 0) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot sin almacén/ubicación válidos.']);
        }
        $effects = $snapshot['effects'] ?? [];
        if (! is_array($effects) || empty($effects)) {
            throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot sin efectos.']);
        }
        foreach ($effects as $e) {
            if (! is_array($e) || ! isset($e['product_id']) || ! array_key_exists('delta', $e) || ! array_key_exists('quantity_base', $e)) {
                throw ValidationException::withMessages(['inventory_effect_snapshot' => 'Snapshot de efectos corrupto.']);
            }
        }

        return [
            'version' => 1,
            'document_type' => $snapshot['document_type'],
            'warehouse_id' => $warehouseId,
            'inventory_location_id' => $locationId,
            'revision' => (int) $snapshot['revision'],
            'effects' => $effects,
        ];
    }

    /**
     * MS7-B1 — shared by SalesController AND SalesReturnController: build the
     * GENERAL snapshot from ALREADY-PERSISTED details (called AFTER the
     * batch/serial artifacts for them have already been applied by the
     * caller's own — unchanged — legacy code, which self-branches to native
     * automatically via the LocationAware* singleton bindings).
     *
     * $byRawIndex is validateAndLock()'s `lines`, keyed by _line_index (the
     * position in the raw request `details` array) — NOT every raw line has
     * an entry (service-type lines are dropped, matching legacy's own
     * "no physical effect for a service line" behaviour).
     *
     * $persistedDetails must be positionally aligned to the raw `details`
     * array (index 0..n-1 in request order) — a Collection keyed 0..n-1
     * (`...::where(...)->orderBy('id')->get()`, relying on the same
     * insert-order-equals-id-order assumption the existing legacy batch/
     * serial code already makes) or a plain array keyed the same way.
     */
    public function buildSnapshotFromPersistedDetails(string $documentType, int $warehouseId, int $locationId, array $rawLines, array $byRawIndex, $persistedDetails, int $revision): array
    {
        $isSale = $documentType === self::DOC_SALE;
        $effects = [];

        foreach (array_values($rawLines) as $i => $row) {
            if (! isset($byRawIndex[$i])) {
                continue; // service line or otherwise skipped by validateAndLock() — no physical effect.
            }
            $detail = is_array($persistedDetails) ? ($persistedDetails[$i] ?? null) : $persistedDetails->get($i);
            if (! $detail) {
                continue;
            }

            $line = $byRawIndex[$i];
            $line['source_detail_id'] = $detail->id;

            $line['batch_allocation'] = [];
            if ($line['requires_batch'] ?? false) {
                $line['batch_allocation'] = ($isSale
                    ? \App\Models\SaleDetailBatch::query()->where('sale_detail_id', $detail->id)
                    : \App\Models\SaleReturnDetailBatch::query()->where('sale_return_detail_id', $detail->id)
                    )->get()
                    ->map(fn ($p) => ['product_batch_id' => (int) $p->product_batch_id, 'quantity_base' => (float) $p->qty])
                    ->all();
            }

            $line['serial_allocation'] = [];
            if ($line['requires_serial'] ?? false) {
                // ProductSerial carries NO sale_return_detail_id FK — a
                // returned serial keeps its ORIGINAL sale_id/sale_detail_id
                // as history (see LocationAwareSerialNumberService), so the
                // only precise way to attribute serials to THIS line (Sale
                // or SaleReturn alike) is the exact input list that was just
                // applied for it — never a detail-id-keyed re-query.
                $requested = app(SerialNumberService::class)->normalizeSerials($row['serial_numbers'] ?? null);
                if (! empty($requested)) {
                    $line['serial_allocation'] = \App\Models\ProductSerial::query()
                        ->whereIn('serial_number', $requested)
                        ->where('product_id', $line['product_id'])
                        ->get(['id', 'serial_number'])
                        ->map(fn ($s) => ['product_serial_id' => (int) $s->id, 'serial_number' => (string) $s->serial_number])
                        ->all();
                }
            }

            $effects[] = $line;
        }

        return $this->buildSnapshot($documentType, $warehouseId, $locationId, $effects, $revision);
    }

    // =====================================================================
    // 3 · GENERAL apply / reverse (the ONLY physical writer this service owns)
    // =====================================================================

    public function applySnapshot(array $snapshot, int $documentId): void
    {
        $this->runGeneral($snapshot, $documentId, apply: true);
    }

    public function reverseSnapshot(array $snapshot, int $documentId): void
    {
        $this->runGeneral($snapshot, $documentId, apply: false);
    }

    private function runGeneral(array $snapshot, int $documentId, bool $apply): void
    {
        $this->assertInTransaction();
        $snapshot = $this->normalizeSnapshot($snapshot);
        $documentType = $snapshot['document_type'];
        $locationId = $snapshot['inventory_location_id'];
        $revision = $snapshot['revision'];
        $operation = $apply ? 'apply' : 'reverse';
        $referenceType = $this->referenceType($documentType, $apply);
        $userId = function_exists('auth') ? auth()->id() : null;

        // Sale effects are negative (outflow); SaleReturn effects are positive
        // (inflow). apply() runs the delta as-is, reverse() negates it.
        $inventory = app(InventoryService::class);
        foreach ($snapshot['effects'] as $n => $effect) {
            $delta = $apply ? $effect['delta'] : -$effect['delta'];
            $qty = round(abs($delta), 3);
            if ($qty <= self::EPS) {
                continue;
            }
            $decreasing = $delta < 0;
            $variantId = $effect['product_variant_id'] !== null ? (int) $effect['product_variant_id'] : null;

            $context = [
                'user_id' => $userId,
                'reference_type' => $referenceType,
                'reference_id' => (string) $documentId,
                'idempotency_key' => $this->idempotencyKey($documentType, $documentId, $revision, (int) $n, $operation),
                'notes' => $referenceType.' '.$operation.' (rev '.$revision.', efecto '.$n.')',
                'metadata' => [
                    'document_type' => $documentType,
                    'document_id' => $documentId,
                    'revision' => $revision,
                    'source_detail_id' => $effect['source_detail_id'] ?? null,
                ],
            ];

            $decreasing
                ? $inventory->decrease($locationId, (int) $effect['product_id'], $qty, $variantId, $context)
                : $inventory->increase($locationId, (int) $effect['product_id'], $qty, $variantId, $context);
        }
    }

    public function idempotencyKey(string $documentType, int $documentId, int $revision, int $effectIndex, string $operation): string
    {
        return $documentType.':'.$documentId.':rev:'.$revision.':effect:'.$effectIndex.':'.$operation;
    }

    private function referenceType(string $documentType, bool $apply): string
    {
        if ($documentType === self::DOC_SALE) {
            return $apply ? 'Sale' : 'SaleReversal';
        }

        return $apply ? 'SaleReturn' : 'SaleReturnReversal';
    }

    private function assertDocumentType(string $documentType): string
    {
        if (! in_array($documentType, [self::DOC_SALE, self::DOC_SALE_RETURN], true)) {
            throw ValidationException::withMessages([
                'document_type' => "Tipo de documento no soportado: '{$documentType}'.",
            ]);
        }

        return $documentType;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw ValidationException::withMessages([
                'inventory' => 'LocationAwareSaleStockService debe ejecutarse dentro de la transacción del documento.',
            ]);
        }
    }
}
