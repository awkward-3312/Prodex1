<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MS7-B2-1 — deterministic fulfillment-location resolution + read for
 * automatic external/store stock flows (online store today; WooCommerce/
 * Shopify/Subscription reuse this same contract in their own milestones).
 *
 * §2 of the MS7-B2 spec: an automatic channel NEVER guesses a location
 * (first location, arbitrary location, quarantine, a location belonging to
 * another warehouse). Precedence:
 *   A. an explicit per-channel fulfillment-location mapping — NOT modeled
 *      anywhere today (no schema for it); this tier is a documented no-op
 *      until a real channel-mapping contract exists. Adding it here without
 *      that contract would be exactly the "build config UI nobody asked for"
 *      the spec bans (§31).
 *   B. Warehouse.default_inventory_location_id — ONLY if it belongs to the
 *      warehouse, is active, and is NOT quarantine.
 *   C. otherwise: FAIL CLOSED (422), never a silent legacy fallback.
 *
 * Callers MUST already know the warehouse is healthy location_primary
 * (WarehouseInventoryModeResolver::isLocationPrimary + the caller's own
 * assertLocationNativePurchaseTransitionSafe-style lock) before resolving a
 * fulfillment location here — this service does not re-check transition
 * mode, it only resolves WHICH location within an already-confirmed-native
 * warehouse.
 */
class ExternalChannelInventoryService
{
    /**
     * @throws ValidationException 422 when no deterministic location exists.
     */
    public function resolveFulfillmentLocation(int $warehouseId): InventoryLocation
    {
        $warehouse = Warehouse::whereNull('deleted_at')->find($warehouseId);
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'El almacén configurado para este canal no existe.',
            ]);
        }

        $defaultId = (int) ($warehouse->default_inventory_location_id ?? 0);
        if ($defaultId <= 0) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Este almacén usa inventario por ubicación pero no tiene una ubicación de cumplimiento configurada (Warehouse.default_inventory_location_id). Configúrala antes de procesar pedidos de este canal.',
            ]);
        }

        $location = InventoryLocation::whereNull('deleted_at')
            ->whereKey($defaultId)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', 1)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de cumplimiento configurada para este almacén no existe o está inactiva.',
            ]);
        }

        if ($location->is_quarantine || $location->type === InventoryLocation::TYPE_QUARANTINE) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de cumplimiento configurada es de cuarentena y no puede usarse para despachar pedidos automáticamente.',
            ]);
        }

        return $location;
    }

    /**
     * Sellable quantity at the EXACT location — quantity - reserved_quantity
     * — never an aggregate across the whole warehouse (§3/§27).
     */
    public function availableQuantity(int $locationId, int $productId, ?int $variantId): float
    {
        $row = DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId),
                fn ($q) => $q->whereNull('product_variant_id'))
            ->first(['quantity', 'reserved_quantity']);

        if (! $row) {
            return 0.0;
        }

        return round((float) $row->quantity - (float) $row->reserved_quantity, 3);
    }

    /**
     * MS7-B2-2C.1 — the ONE canonical warehouse an automatic external
     * channel (Woo order import — SyncService::resolveOrderWarehouseId() —
     * AND Woo stock push, as of this hardening) resolves to: an explicit
     * override if the caller already knows one (e.g. a caller-supplied
     * warehouse_id), else Setting.warehouse_id if it is still a valid,
     * non-deleted warehouse, else the lowest-id non-deleted warehouse.
     *
     * There is deliberately only ONE such rule in the whole codebase — do
     * not add a second warehouse-resolution formula next to this one.
     */
    public function resolveCanonicalWarehouseId(?int $override = null): int
    {
        if ($override !== null && $override > 0) {
            return $override;
        }

        $settings = Setting::whereNull('deleted_at')->first();
        $candidate = $settings ? (int) ($settings->warehouse_id ?? 0) : 0;
        if ($candidate > 0 && Warehouse::where('id', $candidate)->whereNull('deleted_at')->exists()) {
            return $candidate;
        }

        return (int) (Warehouse::whereNull('deleted_at')->min('id') ?? 0);
    }

    /**
     * MS7-B2-2C.1 — sellable quantity for a product/variant from the SINGLE
     * warehouse a Woo connection actually fulfills orders from (never an
     * aggregate across every warehouse in the tenant — B2-2C's original
     * sellableQuantityAcrossWarehouses() could publish a number no single
     * physical warehouse could actually satisfy, e.g. warehouse A=3 +
     * warehouse B=9 published as 12 while a real order only ever draws
     * from ONE of them). The published number must equal exactly what
     * order import (MS7-B2-2B) can fulfill from that same warehouse.
     *
     * - location_primary: quantity - reserved_quantity at that warehouse's
     *   EXACT fulfillment location (batch/serial via their own coverage
     *   check at that same location) — never product_warehouse, never
     *   another warehouse's location.
     * - every other mode: product_warehouse.qte for THIS warehouse only
     *   (not summed with any other warehouse — a tenant with a single
     *   warehouse sees no change at all; a multi-warehouse tenant now
     *   correctly reports only the warehouse Woo can actually draw from).
     *
     * FAILS CLOSED (blocked=true), never a partial/fake number: no
     * resolvable canonical warehouse, an invalid/missing fulfillment
     * location, or a batch/serial coverage mismatch.
     *
     * @return array{quantity: float, blocked: bool, blocked_reason: ?string}
     */
    public function sellableQuantityForFulfillmentWarehouse(
        int $productId,
        ?int $variantId,
        bool $isBatchTracked = false,
        bool $isImei = false,
        ?int $warehouseIdOverride = null
    ): array {
        $warehouseId = $this->resolveCanonicalWarehouseId($warehouseIdOverride);
        if ($warehouseId <= 0) {
            return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'no_canonical_warehouse'];
        }

        if ($isBatchTracked && $isImei) {
            return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'batch_and_serial_conflict'];
        }

        if (! app(WarehouseInventoryModeResolver::class)->isLocationPrimary($warehouseId)) {
            $sum = (float) DB::table('product_warehouse')
                ->whereNull('deleted_at')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->when(
                    $variantId !== null,
                    fn ($q) => $q->where('product_variant_id', $variantId),
                    fn ($q) => $q->whereNull('product_variant_id')
                )
                ->sum('qte');

            return ['quantity' => $sum, 'blocked' => false, 'blocked_reason' => null];
        }

        try {
            $location = $this->resolveFulfillmentLocation($warehouseId);
        } catch (ValidationException $e) {
            return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'missing_or_invalid_fulfillment_location'];
        }

        if ($isBatchTracked) {
            $coverage = app(BatchLocationService::class)->batchCoverageForLocation((int) $location->id, $productId, $variantId);
            if (! $coverage['matches']) {
                return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'batch_coverage_mismatch'];
            }

            return ['quantity' => max(0.0, round((float) $coverage['general_quantity'], 3)), 'blocked' => false, 'blocked_reason' => null];
        }

        if ($isImei) {
            $coverage = app(SerialInventoryCoverageService::class)->coverageForLocation((int) $location->id, $productId, $variantId);
            if (! $coverage['is_ready']) {
                return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'serial_coverage_mismatch'];
            }

            return ['quantity' => (float) $coverage['available_serial_count'], 'blocked' => false, 'blocked_reason' => null];
        }

        return ['quantity' => max(0.0, $this->availableQuantity((int) $location->id, $productId, $variantId)), 'blocked' => false, 'blocked_reason' => null];
    }
}
