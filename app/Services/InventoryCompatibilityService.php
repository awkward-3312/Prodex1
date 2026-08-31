<?php

namespace App\Services;

use App\Models\InventoryTransitionState;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class InventoryCompatibilityService
{
    public function state(int $warehouseId): InventoryTransitionState
    {
        Warehouse::whereNull('deleted_at')->findOrFail($warehouseId);

        return InventoryTransitionState::firstOrCreate(
            ['warehouse_id' => $warehouseId],
            [
                'mode' => InventoryTransitionState::MODE_LEGACY_ONLY,
                'status' => 'pending',
                'mismatch_count' => 0,
            ]
        );
    }

    public function audit(int $warehouseId): array
    {
        $result = app(LegacyInventoryReconciliationService::class)->auditWarehouse($warehouseId);
        $state = $this->state($warehouseId);

        $state->forceFill([
            'inventory_location_id' => $result['inventory_location_id'],
            'status' => $result['is_reconciled'] ? 'healthy' : 'mismatch',
            'mismatch_count' => count($result['differences']) + count($result['negative_legacy_rows']),
            'last_audited_at' => now(),
            'last_reconciled_at' => $result['is_reconciled'] ? now() : $state->last_reconciled_at,
            'metadata' => array_merge($state->metadata ?? [], [
                'legacy_total' => $result['legacy_total'],
                'location_total' => $result['location_total'],
                'legacy_rows' => $result['legacy_rows'],
                'location_rows' => $result['location_rows'],
            ]),
        ])->save();

        return $result + ['transition_mode' => $state->mode];
    }

    public function enableShadowCompare(int $warehouseId): InventoryTransitionState
    {
        return $this->enableMode($warehouseId, InventoryTransitionState::MODE_SHADOW_COMPARE);
    }

    public function enableDualWrite(int $warehouseId): InventoryTransitionState
    {
        return $this->enableMode($warehouseId, InventoryTransitionState::MODE_DUAL_WRITE);
    }

    public function returnToLegacyOnly(int $warehouseId): InventoryTransitionState
    {
        $state = $this->state($warehouseId);
        $state->forceFill([
            'mode' => InventoryTransitionState::MODE_LEGACY_ONLY,
            'status' => $state->status === 'mismatch' ? 'mismatch' : 'pending',
        ])->save();

        return $state->fresh();
    }

    public function readQuantity(int $warehouseId, int $productId, ?int $variantId = null): float
    {
        $state = $this->state($warehouseId);

        if ($state->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY) {
            if ($state->status !== 'healthy' || ! $state->inventory_location_id) {
                throw ValidationException::withMessages([
                    'inventory_transition' => 'El inventario por ubicación no puede ser fuente primaria mientras el almacén no esté reconciliado.',
                ]);
            }

            // Equivalente al antiguo total product_warehouse del ALMACÉN: agregado
            // de TODAS sus ubicaciones activas, no sólo la MAIN.
            return $this->warehouseAggregateQuantity($warehouseId, $productId, $variantId);
        }

        return $this->legacyQuantity($warehouseId, $productId, $variantId);
    }

    public function legacyQuantity(int $warehouseId, int $productId, ?int $variantId = null): float
    {
        $query = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at');

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        return round((float) $query->sum('qte'), 3);
    }

    public function shadowQuantity(int $warehouseId, int $productId, ?int $variantId = null): ?float
    {
        $state = $this->state($warehouseId);
        if (! $state->inventory_location_id) {
            return null;
        }

        // Comparación shadow a nivel ALMACÉN: agregado de todas sus ubicaciones
        // activas por product+variant, NO sólo la MAIN. Así legacy 100 vs
        // (MAIN 70 + QUARANTINE 30) coincide.
        return $this->warehouseAggregateQuantity($warehouseId, $productId, $variantId);
    }

    /**
     * SUM(inventory_location_stocks.quantity) sobre TODAS las inventory_locations
     * activas del almacén, para el product+variant dado (variant_key 0 = simple).
     */
    public function warehouseAggregateQuantity(int $warehouseId, int $productId, ?int $variantId = null): float
    {
        $ids = $this->warehouseLocationIds($warehouseId);
        if (! $ids) return 0.0;

        return round((float) DB::table('inventory_location_stocks')
            ->whereIn('inventory_location_id', $ids)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0))
            ->sum('quantity'), 3);
    }

    private function warehouseLocationIds(int $warehouseId): array
    {
        if (! Schema::hasTable('inventory_locations')) return [];

        return DB::table('inventory_locations')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('warehouse_id', $warehouseId)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function stockedLocationCount(int $warehouseId): int
    {
        $ids = $this->warehouseLocationIds($warehouseId);
        if (! $ids) return 0;

        return (int) DB::table('inventory_location_stocks')
            ->whereIn('inventory_location_id', $ids)
            ->where('quantity', '>', 0)
            ->distinct()
            ->count('inventory_location_id');
    }

    /**
     * Called only from legacy write paths that have already committed their
     * product_warehouse mutation inside the current DB transaction.
     *
     * In dual_write mode the new engine is set to the resulting legacy snapshot,
     * rather than repeating the legacy arithmetic. This makes gradual migration
     * safer across old controllers that use different increment/decrement styles.
     *
     * LÍMITE CONOCIDO (multi-ubicación): este mirror escribe el total legacy del
     * ALMACÉN en la MAIN con adjustTo(). Sólo es correcto cuando la MAIN es la
     * única ubicación con stock de ese product+variant. Si otra ubicación activa
     * también tiene stock de esa clave, adjustTo(MAIN, total) inflaría el
     * agregado del almacén — en ese caso se REHÚSA y se marca mismatch, sin
     * escribir. El hardening del mirror multi-ubicación es un PR posterior.
     */
    public function mirrorLegacySnapshot(
        int $warehouseId,
        int $productId,
        ?int $variantId = null,
        array $context = []
    ): void {
        $state = InventoryTransitionState::where('warehouse_id', $warehouseId)->first();
        if (! $state || $state->mode !== InventoryTransitionState::MODE_DUAL_WRITE) {
            return;
        }

        try {
            DB::transaction(function () use ($state, $warehouseId, $productId, $variantId, $context) {
                $lockedState = InventoryTransitionState::whereKey($state->id)->lockForUpdate()->firstOrFail();
                if ($lockedState->mode !== InventoryTransitionState::MODE_DUAL_WRITE) {
                    return;
                }

                if ($lockedState->status !== 'healthy' || ! $lockedState->inventory_location_id) {
                    throw ValidationException::withMessages([
                        'inventory_transition' => 'Dual-write detenido: el almacén no está reconciliado o no tiene ubicación destino.',
                    ]);
                }

                $target = $this->legacyQuantity($warehouseId, $productId, $variantId);
                if ($target < 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Dual-write detenido: product_warehouse contiene existencia negativa.',
                    ]);
                }

                $inventory = app(InventoryService::class);
                $current = $inventory->quantity((int) $lockedState->inventory_location_id, $productId, $variantId);

                // Rehúsa si el product+variant tiene stock fuera de la MAIN: el
                // adjustTo(MAIN, target) de abajo sólo mantiene el agregado
                // correcto cuando la MAIN es el único contenedor de esa clave.
                $warehouseAggregate = $this->warehouseAggregateQuantity($warehouseId, $productId, $variantId);
                if (abs($warehouseAggregate - $current) > 0.0005) {
                    throw ValidationException::withMessages([
                        'inventory_transition' => 'Dual-write detenido: el producto/variante tiene inventario por ubicación fuera de MAIN. '
                            .'El mirror single-MAIN no puede sincronizarlo sin inflar el agregado del almacén. '
                            .'Requiere el hardening del mirror multi-ubicación (PR posterior).',
                    ]);
                }

                if (abs($target - $current) < 0.0005) {
                    return;
                }

                $syncContext = [
                    'user_id' => $context['user_id'] ?? null,
                    'reference_type' => $context['reference_type'] ?? 'legacy_shadow_sync',
                    'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
                    'idempotency_key' => $context['idempotency_key'] ?? null,
                    'notes' => $context['notes'] ?? 'Sincronización exacta desde product_warehouse durante transición.',
                    'metadata' => array_merge($context['metadata'] ?? [], [
                        'legacy_warehouse_id' => $warehouseId,
                        'transition_mode' => InventoryTransitionState::MODE_DUAL_WRITE,
                        'legacy_target_quantity' => $target,
                    ]),
                ];

                $inventory->adjustTo(
                    (int) $lockedState->inventory_location_id,
                    $productId,
                    $target,
                    $variantId,
                    $syncContext
                );
            }, 3);
        } catch (Throwable $e) {
            $this->markMismatch($warehouseId, $e->getMessage());
            throw $e;
        }
    }

    public function compareKey(int $warehouseId, int $productId, ?int $variantId = null): array
    {
        $legacy = $this->legacyQuantity($warehouseId, $productId, $variantId);
        $shadow = $this->shadowQuantity($warehouseId, $productId, $variantId);
        $matches = $shadow !== null && abs($legacy - $shadow) < 0.0005;

        if (! $matches) {
            $this->markMismatch($warehouseId, 'Diferencia detectada entre product_warehouse e inventario por ubicación.');
        }

        return [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'legacy_quantity' => $legacy,
            'location_quantity' => $shadow,
            'matches' => $matches,
        ];
    }

    private function enableMode(int $warehouseId, string $mode): InventoryTransitionState
    {
        if (! in_array($mode, [
            InventoryTransitionState::MODE_SHADOW_COMPARE,
            InventoryTransitionState::MODE_DUAL_WRITE,
        ], true)) {
            throw ValidationException::withMessages(['mode' => 'Modo de transición no permitido en esta etapa.']);
        }

        $audit = $this->audit($warehouseId);
        if (! $audit['is_reconciled']) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El almacén debe reconciliar exactamente antes de activar el modo de transición solicitado.',
            ]);
        }

        // is_reconciled es sólo paridad cuantitativa warehouse-wide. Un modo de
        // transición necesita además una MAIN activa por defecto como destino.
        if (! ($audit['has_target_location'] ?? false)) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El almacén no tiene una ubicación MAIN activa por defecto; no puede activarse un modo de transición que requiere ubicación destino.',
            ]);
        }

        // dual_write ESCRIBE en la MAIN vía mirror single-MAIN. No es seguro
        // mientras el almacén tenga inventario en más de una ubicación activa:
        // bloqueado hasta el hardening del mirror multi-ubicación (PR posterior).
        if ($mode === InventoryTransitionState::MODE_DUAL_WRITE && (int) ($audit['stocked_location_count'] ?? 0) > 1) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'dual_write no está soportado para almacenes con inventario en múltiples ubicaciones. '
                    .'Requiere el hardening del mirror multi-ubicación (PR posterior).',
            ]);
        }

        $state = $this->state($warehouseId);
        $state->forceFill([
            'inventory_location_id' => $audit['inventory_location_id'],
            'mode' => $mode,
            'status' => 'healthy',
            'mismatch_count' => 0,
            'shadow_enabled_at' => $state->shadow_enabled_at ?: now(),
            'last_reconciled_at' => now(),
        ])->save();

        return $state->fresh();
    }

    private function markMismatch(int $warehouseId, string $reason): void
    {
        $state = $this->state($warehouseId);
        $metadata = $state->metadata ?? [];
        $metadata['last_mismatch_reason'] = $reason;
        $metadata['last_mismatch_at'] = now()->toIso8601String();

        $state->forceFill([
            'status' => 'mismatch',
            'mismatch_count' => ((int) $state->mismatch_count) + 1,
            'metadata' => $metadata,
        ])->save();
    }
}
