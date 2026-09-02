<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Http\Request;

/**
 * MS5-B1 — POS artifact preflight coordinator.
 *
 * Runs INSIDE PosController::CreatePOS's DB transaction, from
 * PosLocationSaleStockService::apply(), BEFORE the aggregate
 * InventoryService::decrease loop.
 *
 * Order is deliberate and matches InternalInventoryMoveService
 * (BatchLocationService::move -> SerialLocationService::moveSerials ->
 * InventoryService::move):
 *
 *     1. BATCH artifacts   (LocationAwareBatchService::preflightSaleAllocations)
 *     2. SERIAL artifacts  (LocationAwareSerialNumberService::preflightSaleSerials)
 *     3. GENERAL inventory (caller: PosLocationSaleStockService)
 *
 * Each preflight step only runs SELECT ... FOR UPDATE + validation and freezes
 * a deterministic plan into request attributes keyed by Sale id. It mutates
 * NOTHING: no product_batches.qty, no product_batch_location_stocks.quantity,
 * no ProductSerial.status, no SaleDetailBatch, no ProductSerialMovement.
 *
 * The frozen batch plan is later consumed by
 * LocationAwareBatchService::applyForSaleWithAutoFallback (after SaleDetail
 * insert), which re-locks the SAME rows this transaction already holds and
 * never re-runs FEFO. The serial rows locked here are exactly the ones
 * LocationAwareSerialNumberService::sellOnSale re-touches, so no new
 * ProductSerial lock is acquired after the general decrease either.
 */
class PosLocationArtifactPreflightService
{
    public function preflight(Sale $sale, Request $request, array $details): void
    {
        if (! $sale->inventory_location_id || ! $sale->branch_id) {
            return;
        }
        if (empty($details)) {
            return;
        }

        // 1 — BATCH artifacts.
        $batchPlan = app(LocationAwareBatchService::class)->preflightSaleAllocations($sale, $details);

        // 2 — SERIAL artifacts.
        $serialPlan = app(LocationAwareSerialNumberService::class)->preflightSaleSerials($sale, $details);

        // Freeze both, request-scoped, keyed by Sale id.
        $request->attributes->set(
            LocationAwareBatchService::POS_BATCH_PREFLIGHT_ATTR.':'.$sale->id,
            $batchPlan
        );
        $request->attributes->set(
            LocationAwareSerialNumberService::POS_SERIAL_PREFLIGHT_ATTR.':'.$sale->id,
            $serialPlan
        );
    }
}
