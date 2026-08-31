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

        // mismatch = SOLO lo que no podemos explicar (UNKNOWN_REVIEW) + negativos.
        // LEGACY_ONLY_PENDING es "migración pendiente", se cuenta aparte y NO
        // marca mismatch. El snapshot_drift por operaciones location-native
        // legítimas NUNCA marca mismatch.
        $mismatchCount = count($result['unknown_review_rows'] ?? []) + count($result['negative_legacy_rows']);

        $state->forceFill([
            'inventory_location_id' => $result['inventory_location_id'],
            'status' => $mismatchCount === 0 ? 'healthy' : 'mismatch',
            'mismatch_count' => $mismatchCount,
            'last_audited_at' => now(),
            // last_reconciled_at (baseline) sólo se mueve por un backfill/promoción
            // explícita, NUNCA por una auditoría — mover el baseline aquí borraría
            // la frontera que separa operaciones location-native legítimas.
            'metadata' => array_merge($state->metadata ?? [], [
                'legacy_total' => $result['legacy_total'],
                'location_total' => $result['location_total'],
                'legacy_only_pending_total' => $result['legacy_only_pending_total'] ?? 0,
                'snapshot_drift_total' => $result['snapshot_drift_total'] ?? 0,
                'provenance_counts' => $result['provenance_counts'] ?? [],
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
     * El destino registrado en el state debe SEGUIR siendo apto en runtime:
     * pertenece al almacén, activo, no borrado, tipo 'storage', no cuarentena, y
     * sigue siendo la default del almacén. Si alguien cambió la configuración
     * después de activar dual_write, el mirror rehúsa y markMismatch lo registra;
     * jamás escribe en una ubicación que dejó de ser apta.
     */
    private function assertTargetStillEligible(int $warehouseId, int $locationId): void
    {
        $row = DB::table('inventory_locations')->where('id', $locationId)->first();
        $warehouseDefault = (int) (DB::table('warehouses')->where('id', $warehouseId)->value('default_inventory_location_id') ?? 0);

        $ok = $row
            && $row->deleted_at === null
            && (int) $row->warehouse_id === $warehouseId
            && (int) $row->is_active === 1
            && $row->type === 'storage'
            && (int) ($row->is_quarantine ?? 0) === 0
            && $warehouseDefault === $locationId;

        if (! $ok) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'Dual-write detenido: la ubicación destino dejó de ser apta '
                    .'(debe seguir siendo la default del almacén, tipo storage, activa y no cuarentena).',
            ]);
        }
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

                // La ubicación destino registrada debe SEGUIR siendo apta en
                // runtime (por si alguien cambió la configuración tras activar
                // dual_write). Si no, rehúsa — markMismatch lo registra.
                $this->assertTargetStillEligible($warehouseId, (int) $lockedState->inventory_location_id);

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

                // GUARD PROVENANCE: adjustTo(MAIN, legacyTotal) sólo es correcto si
                // NO hay movimientos location-native INDEPENDIENTES DEL MIRROR
                // posteriores al baseline para esta clave. Los movimientos del
                // propio mirror dual_write (legacy_shadow_sync) NO cuentan — de lo
                // contrario dual_write se autobloquearía tras la primera escritura.
                // Si hay net NATIVO != 0 (Iphone15: TransferDispatch -28), el
                // mirror recrearía stock ya movido → se REHÚSA. markMismatch lo
                // registra.
                $provKey = $this->provenanceKey($warehouseId, $productId, $variantId);
                $nativeNet = $provKey ? (float) ($provKey['post_baseline_native_net'] ?? $provKey['post_baseline_location_net'] ?? 0.0) : 0.0;
                if (abs($nativeNet) > 0.0005) {
                    throw ValidationException::withMessages([
                        'inventory_transition' => 'Dual-write detenido: existen movimientos location-native independientes del mirror posteriores al baseline para este producto/variante '
                            .'(net nativo '.round($nativeNet, 3).'). El mirror single-target recrearía stock ya movido. '
                            .'Requiere el mirror delta-based (pendiente).',
                    ]);
                }

                if (abs($target - $current) < 0.0005) {
                    return;
                }

                $syncContext = [
                    'user_id' => $context['user_id'] ?? null,
                    // El movimiento del mirror SIEMPRE se marca como
                    // legacy_shadow_sync para que el auditor por provenance lo
                    // reconozca como espejo de una escritura legacy (no como una
                    // operación location-native). El origen real va en metadata.
                    'reference_type' => 'legacy_shadow_sync',
                    'reference_id' => isset($context['reference_id']) ? (string) $context['reference_id'] : null,
                    'idempotency_key' => $context['idempotency_key'] ?? null,
                    'notes' => $context['notes'] ?? 'Sincronización exacta desde product_warehouse durante transición.',
                    'metadata' => array_merge($context['metadata'] ?? [], [
                        'legacy_warehouse_id' => $warehouseId,
                        'transition_mode' => InventoryTransitionState::MODE_DUAL_WRITE,
                        'legacy_target_quantity' => $target,
                        'origin_reference_type' => $context['reference_type'] ?? null,
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

    /**
     * Comparación por PROVENANCE — ÚNICA definición de mismatch, coherente con
     * audit(). `matches` = la clave está RECONCILED o MIRRORED (NO
     * LEGACY_ONLY_PENDING ni UNKNOWN_REVIEW). Un gap legacy/location explicado por
     * TransferDispatch posterior al baseline NO es mismatch.
     */
    public function compareKey(int $warehouseId, int $productId, ?int $variantId = null): array
    {
        $legacy = $this->legacyQuantity($warehouseId, $productId, $variantId);
        $key = $this->provenanceKey($warehouseId, $productId, $variantId);
        $classification = $key['classification'] ?? ($legacy <= 0.0005 ? 'RECONCILED' : 'UNKNOWN_REVIEW');
        $matches = in_array($classification, ['RECONCILED', 'MIRRORED'], true);

        if (! $matches) {
            $this->markMismatch($warehouseId, 'Provenance: clasificación '.$classification.' para producto '.$productId.'.');
        }

        return [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'legacy_quantity' => $legacy,
            'location_quantity' => $key['current_location'] ?? $this->shadowQuantity($warehouseId, $productId, $variantId),
            'classification' => $classification,
            'snapshot_drift' => $key['snapshot_drift'] ?? null,
            'matches' => $matches,
        ];
    }

    /**
     * Comparación de SNAPSHOT crudo — SÓLO diagnóstico, NUNCA marca mismatch.
     * legacy_now vs agregado por ubicación del almacén (sin interpretación).
     */
    public function snapshotCompareKey(int $warehouseId, int $productId, ?int $variantId = null): array
    {
        $legacy = $this->legacyQuantity($warehouseId, $productId, $variantId);
        $location = $this->warehouseAggregateQuantity($warehouseId, $productId, $variantId);

        return [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'legacy_quantity' => $legacy,
            'location_quantity' => $location,
            'snapshot_equal' => abs($legacy - $location) < 0.0005,
        ];
    }

    /** Fila de provenance para un product+variant del almacén (o null). */
    private function provenanceKey(int $warehouseId, int $productId, ?int $variantId = null): ?array
    {
        $vk = (int) ($variantId ?: 0);
        foreach (app(InventoryProvenanceAuditService::class)->auditWarehouse($warehouseId)['keys'] as $row) {
            if ((int) $row['product_id'] === $productId
                && (int) ($row['product_variant_id'] ?: 0) === $vk) {
                return $row;
            }
        }
        return null;
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
        // provenance_reconciled: sin LEGACY_ONLY_PENDING / UNKNOWN_REVIEW / negativos.
        if (! ($audit['provenance_reconciled'] ?? $audit['is_reconciled'] ?? false)) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El almacén debe estar provenance-reconciled (sin LEGACY_ONLY_PENDING ni UNKNOWN_REVIEW) antes de activar el modo solicitado.',
            ]);
        }

        // provenance_reconciled NO basta: un modo de transición necesita además
        // una ubicación destino APTA (storage, activa, no cuarentena, del almacén).
        if (! ($audit['has_target_location'] ?? false)) {
            throw ValidationException::withMessages([
                'inventory_transition' => 'El almacén no tiene una ubicación destino apta (storage, activa, no cuarentena) por defecto; no puede activarse un modo de transición que requiere ubicación destino.',
            ]);
        }

        if ($mode === InventoryTransitionState::MODE_DUAL_WRITE) {
            // 1) single-target: todo el stock por ubicación en la destino.
            if (! ($audit['target_holds_all_stock'] ?? false)) {
                throw ValidationException::withMessages([
                    'inventory_transition' => 'dual_write requiere que TODO el inventario por ubicación del almacén esté en la ubicación destino (single-target). '
                        .'Stock fuera del destino: '.($audit['stock_outside_target_quantity'] ?? 0).'. '
                        .'Requiere el hardening del mirror multi-ubicación (PR posterior).',
                ]);
            }
            // 2) PARIDAD SNAPSHOT EXACTA legacy/location por product+variant. Sin
            //    reverse-mirror (location→legacy), cualquier movimiento
            //    location-native posterior al baseline (p. ej. TransferDispatch)
            //    haría que el mirror single-target recreara stock ya movido.
            if (! ($audit['snapshot_equal'] ?? false)) {
                throw ValidationException::withMessages([
                    'inventory_transition' => 'dual_write requiere paridad actual legacy/location; existen movimientos location-native posteriores al baseline. '
                        .'Claves sin paridad: '.count($audit['snapshot_unequal_keys'] ?? []).'.',
                ]);
            }
        }

        $state = $this->state($warehouseId);
        // last_reconciled_at (baseline provenance) NO se toca al activar un modo:
        // activar shadow_compare / dual_write NO es un rebaseline. El baseline
        // real es el momento del backfill físico (movimientos
        // legacy_product_warehouse_backfill) o un rebaseline explícito futuro.
        // Reescribirlo aquí borraría el historial entre el baseline original y hoy.
        $state->forceFill([
            'inventory_location_id' => $audit['inventory_location_id'],
            'mode' => $mode,
            'status' => 'healthy',
            'mismatch_count' => 0,
            'shadow_enabled_at' => $state->shadow_enabled_at ?: now(),
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
