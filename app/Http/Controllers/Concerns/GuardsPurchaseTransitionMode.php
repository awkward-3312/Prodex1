<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InventoryLocation;
use App\Models\InventoryTransitionState;
use App\Models\Warehouse;
use App\Services\WarehouseInventoryModeResolver;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Shared transition-mode boundary for Purchases (MS2) and Purchase Returns (MS3).
 *
 * ABSOLUTE INVARIANT: a location_primary warehouse NEVER takes a legacy-only
 * stock mutation, and a non-primary / unhealthy warehouse NEVER takes a
 * location-native mutation.
 *
 * Both guards lock inventory_transition_states FIRST (ascending by warehouse_id
 * — deterministic) so the mode cannot change between the check and the write.
 *
 * GLOBAL LOCK ORDER (Purchases + Purchase Returns, identical):
 *   inventory_transition_states(asc)
 *     -> [LocationAwarePurchaseStockService::validateAndLock:
 *          warehouses(asc) -> inventory_locations -> products(asc)
 *          -> product_variants(asc) -> units(asc)]
 *     -> inventory_location_stocks (InventoryService, per key)
 * This is a strict prefix-extension of the engine order that already exists for
 * Adjustments / Damages / Opening stock, so no cycle is introduced.
 *
 * BULK DELETE: delete_by_selection() locks ALL involved transition states ONCE
 * up front (lockStates() sorts warehouse_id ascending) before the per-row loop,
 * so two concurrent bulk deletes with overlapping warehouses in opposite
 * selection order cannot deadlock on the transition-state rows.
 */
trait GuardsPurchaseTransitionMode
{
    /**
     * LEGACY document boundary. A legacy (inventory_location_id NULL) document
     * may only be created/edited/deleted while NEITHER its stored warehouse NOR
     * the requested warehouse is location_primary. A historical legacy document
     * is never converted to location-native (its physical location is unknown).
     *
     * @throws ValidationException  422 inventory_transition
     */
    protected function assertLegacyPurchaseTransitionSafe(?int $storedWarehouseId, ?int $requestWarehouseId): void
    {
        $resolver = app(WarehouseInventoryModeResolver::class);
        $ids = array_values(array_filter([(int) $storedWarehouseId, (int) $requestWarehouseId], fn ($i) => $i > 0));
        $states = $resolver->lockStates($ids);
        foreach ($states as $whId => $state) {
            $resolver->assertStateNotLocationPrimary((int) $whId, $state);
        }
    }

    /**
     * LOCATION-NATIVE document boundary. BOTH the stored warehouse and the
     * target warehouse must still be healthy location_primary — a snapshot can
     * only be reversed/applied inside the architecture that created it.
     *
     * @throws ValidationException  422 inventory_transition
     */
    protected function assertLocationNativePurchaseTransitionSafe(int $storedWarehouseId, ?int $targetWarehouseId): void
    {
        $resolver = app(WarehouseInventoryModeResolver::class);
        $ids = array_values(array_filter([$storedWarehouseId, (int) $targetWarehouseId], fn ($i) => $i > 0));
        $states = $resolver->lockStates($ids);
        foreach ($ids as $whId) {
            $resolver->assertStateHealthyLocationPrimary($whId, $states[$whId] ?? null);
        }
    }

    /**
     * Payload for a "which inventory location for this warehouse" endpoint,
     * shared by the Purchase form (receiving scope) and the Purchase Return
     * form (operating/outbound scope). The CALLER performs authorization and
     * resolves the user's allowed location ids ($allowedLocationIds === null =>
     * no scope filter, e.g. owner / role 1).
     *
     * @return array{
     *   transition_mode:string, transition_status:string,
     *   requires_inventory_location:bool, blocked:bool,
     *   locations:array, default_inventory_location_id:?int
     * }
     */
    protected function inventoryLocationContextPayload(int $warehouseId, ?array $allowedLocationIds): array
    {
        $resolver = app(WarehouseInventoryModeResolver::class);
        $state = $resolver->state($warehouseId);
        $mode = $state?->mode ?? InventoryTransitionState::MODE_LEGACY_ONLY;
        $status = $state?->status ?? 'pending';

        $base = [
            'transition_mode' => $mode,
            'transition_status' => $status,
            'requires_inventory_location' => false,
            'blocked' => false,
            'locations' => [],
            'default_inventory_location_id' => null,
        ];

        // Tenant without the location engine yet — nothing to select, stay legacy.
        if (! Schema::hasTable('inventory_locations')) {
            return $base;
        }

        $query = InventoryLocation::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', 1);

        if ($allowedLocationIds !== null) {
            $query->whereIn('id', $allowedLocationIds ?: [0]);
        }

        $locations = $query->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_quarantine']);

        $default = Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->value('default_inventory_location_id');
        $defaultEligible = $default && $locations->firstWhere(fn ($l) => (int) $l->id === (int) $default);

        return [
            'transition_mode' => $mode,
            'transition_status' => $status,
            'requires_inventory_location' => $resolver->requiresInventoryLocation($warehouseId),
            'blocked' => $resolver->isLocationPrimaryButBlocked($warehouseId),
            'locations' => $locations,
            'default_inventory_location_id' => $defaultEligible ? (int) $default : null,
        ];
    }
}
