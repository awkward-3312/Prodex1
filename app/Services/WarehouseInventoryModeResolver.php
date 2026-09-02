<?php

namespace App\Services;

use App\Models\InventoryTransitionState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Read-only resolver for a warehouse's inventory transition mode.
 *
 * Deliberately does NOT use InventoryCompatibilityService::state(), which
 * materialises a row as a side effect. This only ever reads:
 *   - a row present  => its `mode`
 *   - no row         => legacy_only
 *
 * Transition-boundary vocabulary (MS2 hardening):
 *   isPrimary  = mode === location_primary               (regardless of health)
 *   ready      = isPrimary && status === healthy && a valid inventory_location_id
 *   blocked    = isPrimary && ! ready                     (corrupt / not reconciled)
 *
 * Routing:
 *   ! isPrimary                      => legacy purchase flow
 *   ready                            => location-native flow
 *   isPrimary && ! ready             => FAIL CLOSED (never a legacy fallback)
 *
 * ABSOLUTE INVARIANT enforced by the controller guards that use this:
 *   - a location_primary warehouse NEVER receives a legacy-only stock mutation;
 *   - a non-primary warehouse NEVER receives a location-native-only mutation.
 */
class WarehouseInventoryModeResolver
{
    /** Read-only lookup — never creates a row. No table => legacy_only. */
    public function state(int $warehouseId): ?InventoryTransitionState
    {
        if ($warehouseId <= 0 || ! Schema::hasTable('inventory_transition_states')) {
            return null;
        }

        return InventoryTransitionState::where('warehouse_id', $warehouseId)->first();
    }

    public function mode(int $warehouseId): string
    {
        return $this->state($warehouseId)?->mode ?? InventoryTransitionState::MODE_LEGACY_ONLY;
    }

    // ---- predicates on a warehouse id (read, no lock) -------------------

    /** isPrimary — the warehouse has declared location_primary (health aside). */
    public function isLocationPrimary(int $warehouseId): bool
    {
        return $this->stateIsLocationPrimary($this->state($warehouseId));
    }

    /** ready — a location-native purchase can actually be written here. */
    public function isLocationPrimaryHealthy(int $warehouseId): bool
    {
        return $this->stateIsHealthyLocationPrimary($this->state($warehouseId));
    }

    /** Endpoint alias — kept for the purchase form contract. */
    public function requiresInventoryLocation(int $warehouseId): bool
    {
        return $this->isLocationPrimaryHealthy($warehouseId);
    }

    /** blocked — location_primary but corrupt / not reconciled. */
    public function isLocationPrimaryButBlocked(int $warehouseId): bool
    {
        return $this->isLocationPrimary($warehouseId) && ! $this->isLocationPrimaryHealthy($warehouseId);
    }

    // ---- predicates on an already-fetched (possibly locked) state ------

    public function stateIsLocationPrimary(?InventoryTransitionState $s): bool
    {
        return $s !== null && $s->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY;
    }

    public function stateIsHealthyLocationPrimary(?InventoryTransitionState $s): bool
    {
        return $this->stateIsLocationPrimary($s)
            && $s->status === 'healthy'
            && ! empty($s->inventory_location_id);
    }

    // ---- transaction-time locking -------------------------------------

    /**
     * Lock the inventory_transition_states row(s) for the given warehouse ids
     * (ascending — deterministic to avoid deadlocks). Call FIRST inside a
     * purchase transaction that will mutate stock, so the mode cannot change
     * between the guard and the write.
     *
     * Lock order for the purchase flow:
     *   inventory_transition_states(asc)  ->  [validateAndLock: warehouses(asc)
     *   -> inventory_locations -> products(asc) -> product_variants(asc)
     *   -> units(asc)]
     *
     * @param  int[]  $warehouseIds
     * @return array<int,?InventoryTransitionState>  keyed by warehouse id (null = no row)
     */
    public function lockStates(array $warehouseIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $warehouseIds),
            fn ($i) => $i > 0
        )));
        sort($ids);

        $out = array_fill_keys($ids, null);
        if (! $ids || ! Schema::hasTable('inventory_transition_states')) {
            return $out;
        }

        $rows = InventoryTransitionState::whereIn('warehouse_id', $ids)
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $s) {
            $out[(int) $s->warehouse_id] = $s;
        }

        return $out;
    }

    // ---- assertions --------------------------------------------------

    /**
     * FAIL CLOSED gate for the location-native write path (single warehouse).
     * Returns the healthy state or throws 422 `inventory_transition`.
     *
     * @throws ValidationException
     */
    public function assertHealthyLocationPrimary(int $warehouseId): InventoryTransitionState
    {
        $s = $this->state($warehouseId);
        $this->assertStateHealthyLocationPrimary($warehouseId, $s);

        return $s;
    }

    /**
     * Same check, but against an already-fetched (locked) state.
     *
     * @throws ValidationException
     */
    public function assertStateHealthyLocationPrimary(int $warehouseId, ?InventoryTransitionState $s): void
    {
        if (! $this->stateIsLocationPrimary($s)) {
            throw ValidationException::withMessages([
                'inventory_transition' => "El almacén {$warehouseId} no está en modo de inventario por ubicación (location_primary). "
                    .'Una compra por ubicación sólo puede operar en almacenes location_primary reconciliados.',
            ]);
        }
        if (! $this->stateIsHealthyLocationPrimary($s)) {
            throw ValidationException::withMessages([
                'inventory_transition' => "El inventario por ubicación del almacén {$warehouseId} no está reconciliado "
                    .'(estado: '.($s->status ?: 'desconocido').(empty($s->inventory_location_id) ? ', sin ubicación destino' : '').'). '
                    .'FAIL CLOSED: no hay respaldo legacy en este modo.',
            ]);
        }
    }

    /**
     * FAIL CLOSED gate for the LEGACY write path: a legacy (inventory_location_id
     * NULL) purchase must never be edited/deleted while the given warehouse is
     * location_primary. We cannot convert a historical legacy purchase to
     * location-native (its physical location is unknown) and must not run the
     * product_warehouse writer for a location_primary warehouse.
     *
     * @throws ValidationException
     */
    public function assertStateNotLocationPrimary(int $warehouseId, ?InventoryTransitionState $s): void
    {
        if ($this->stateIsLocationPrimary($s)) {
            throw ValidationException::withMessages([
                'inventory_transition' => "El almacén {$warehouseId} usa inventario por ubicación (location_primary). "
                    .'Una compra legacy no puede editarse ni eliminarse por la ruta legacy en ese almacén, y no puede '
                    .'convertirse a location-native porque se desconoce su ubicación física histórica. FAIL CLOSED.',
            ]);
        }
    }
}
