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
 * Routing rule (MS2):
 *   legacy_only | shadow_compare | dual_write | (no row) => legacy purchase flow
 *   location_primary + healthy + has inventory_location_id => location-native flow
 *   location_primary but not healthy => FAIL CLOSED (no legacy fallback)
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

    /** The warehouse has declared location_primary (regardless of health). */
    public function isLocationPrimary(int $warehouseId): bool
    {
        return $this->mode($warehouseId) === InventoryTransitionState::MODE_LOCATION_PRIMARY;
    }

    /**
     * True ONLY when a location-native purchase can actually be written:
     * location_primary + status healthy + a configured destination location.
     */
    public function requiresInventoryLocation(int $warehouseId): bool
    {
        $s = $this->state($warehouseId);

        return $s !== null
            && $s->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY
            && $s->status === 'healthy'
            && ! empty($s->inventory_location_id);
    }

    /**
     * The warehouse is location_primary but NOT in a usable state — the
     * form/endpoint must hard-block instead of silently falling back to legacy.
     */
    public function isLocationPrimaryButBlocked(int $warehouseId): bool
    {
        return $this->isLocationPrimary($warehouseId) && ! $this->requiresInventoryLocation($warehouseId);
    }

    /**
     * FAIL CLOSED gate for the location-native write path. Returns the healthy
     * state, or throws 422 `inventory_transition` — NEVER falls back to legacy.
     *
     * @throws ValidationException
     */
    public function assertHealthyLocationPrimary(int $warehouseId): InventoryTransitionState
    {
        $s = $this->state($warehouseId);

        if ($s === null || $s->mode !== InventoryTransitionState::MODE_LOCATION_PRIMARY) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El almacén no está en modo de inventario por ubicación (location_primary).',
            ]);
        }

        if ($s->status !== 'healthy' || empty($s->inventory_location_id)) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El inventario por ubicación de este almacén no está reconciliado (estado: '
                    .($s->status ?: 'desconocido').'). No se puede registrar la compra por ubicación y este modo no admite respaldo legacy.',
            ]);
        }

        return $s;
    }
}
