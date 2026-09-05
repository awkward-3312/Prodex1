<?php

namespace App\Services;

use App\Models\InventoryLocation;
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
     * MS7-B2-2C — total sellable quantity for a product/variant, summed
     * across ALL of the tenant's warehouses. This codebase's WooCommerce
     * (and every other external-channel) stock push has never had a
     * per-warehouse concept — every existing computeStockQuantity() site
     * already summed product_warehouse.qte across every warehouse into ONE
     * number. This generalizes that SAME sum: a location_primary
     * warehouse's contribution is its exact fulfillment location's
     * available quantity (quantity - reserved_quantity, clamped >= 0)
     * instead of that warehouse's stale product_warehouse row; every other
     * mode keeps contributing product_warehouse.qte exactly as before.
     *
     * FAILS CLOSED, never a partial/lowball number: if ANY location_primary
     * warehouse cannot be read safely (no valid fulfillment location, or a
     * batch/serial coverage mismatch at that location), the whole result is
     * `blocked` and callers must not publish anything for this product —
     * never substitute 0 for that warehouse and sum the rest.
     *
     * @return array{quantity: float, blocked: bool, blocked_reason: ?string}
     */
    public function sellableQuantityAcrossWarehouses(
        int $productId,
        ?int $variantId,
        bool $isBatchTracked = false,
        bool $isImei = false
    ): array {
        if ($isBatchTracked && $isImei) {
            return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'batch_and_serial_conflict'];
        }

        $resolver = app(WarehouseInventoryModeResolver::class);
        $warehouseIds = Warehouse::whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->values()
            ->all();

        $total = 0.0;

        foreach ($warehouseIds as $warehouseId) {
            if (! $resolver->isLocationPrimary($warehouseId)) {
                $total += (float) DB::table('product_warehouse')
                    ->whereNull('deleted_at')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->when(
                        $variantId !== null,
                        fn ($q) => $q->where('product_variant_id', $variantId),
                        fn ($q) => $q->whereNull('product_variant_id')
                    )
                    ->sum('qte');

                continue;
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
                $total += max(0.0, (float) $coverage['general_quantity']);

                continue;
            }

            if ($isImei) {
                $coverage = app(SerialInventoryCoverageService::class)->coverageForLocation((int) $location->id, $productId, $variantId);
                if (! $coverage['is_ready']) {
                    return ['quantity' => 0.0, 'blocked' => true, 'blocked_reason' => 'serial_coverage_mismatch'];
                }
                $total += max(0.0, (float) $coverage['available_serial_count']);

                continue;
            }

            $total += max(0.0, $this->availableQuantity((int) $location->id, $productId, $variantId));
        }

        return ['quantity' => round($total, 3), 'blocked' => false, 'blocked_reason' => null];
    }
}
