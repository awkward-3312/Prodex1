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
}
