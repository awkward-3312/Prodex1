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

    /**
     * READ-ONLY readiness gate for promoting a warehouse to location_primary.
     * Reuses the existing provenance/reconciliation/coverage services — no
     * parallel engine. Unlike dual_write (single-target mirror), location_primary
     * DOES allow batch/serial-tracked inventory and multi-location stock; it
     * requires instead that batch/serial coverage is internally consistent and
     * that no transfer leaves the cut physically unsafe.
     *
     * @return array{
     *   warehouse_id:int, ready:bool, reasons:string[], inventory_location_id:?int,
     *   provenance_reconciled:bool, has_target_location:bool,
     *   batch_mismatches:array, serial_mismatches:array,
     *   unmigrated_legacy_serials:int, pending_transfers:int
     * }
     */
    public function readinessForLocationPrimary(int $warehouseId): array
    {
        $audit = $this->audit($warehouseId);
        $reasons = [];

        // GENERAL — provenance-based reconciliation, no pending ambiguity.
        $reconciled = $audit['provenance_reconciled'] ?? $audit['is_reconciled'] ?? false;
        if (! $reconciled) {
            $reasons[] = 'General: existen inconsistencias sin reconciliar entre legacy y location-native '
                .'(revisión pendiente, migración legacy pendiente o existencias negativas).';
        }

        // LOCATION — default_inventory_location_id válida, del almacén, activa,
        // no cuarentena (el único contrato "apto" existente, reutilizado tal cual).
        $hasTarget = $audit['has_target_location'] ?? false;
        if (! $hasTarget) {
            $reasons[] = 'Location: el almacén no tiene una ubicación destino apta (perteneciente al almacén, '
                .'activa, tipo storage, no cuarentena) configurada como default_inventory_location_id.';
        }

        // BATCH / SERIAL — coverage mismatch por ubicación del almacén.
        $artifacts = $this->artifactCoverageMismatches($warehouseId);
        if (! empty($artifacts['batch'])) {
            $reasons[] = 'Batch: '.count($artifacts['batch']).' clave(s) producto/ubicación cuya existencia general '
                .'no cuadra con la suma de sus lotes (coverage mismatch).';
        }
        if (! empty($artifacts['serial'])) {
            $reasons[] = 'Serial: '.count($artifacts['serial']).' clave(s) producto/ubicación cuya existencia general '
                .'no cuadra con el conteo de seriales disponibles, o no es entera.';
        }

        $unmigratedSerials = app(SerialInventoryCoverageService::class)->unmigratedLegacySerialCount($warehouseId);
        if ($unmigratedSerials > 0) {
            $reasons[] = "Serial: {$unmigratedSerials} serial(es) disponible(s) sin ubicación asignada "
                .'(inventory_location_id NULL) en este almacén.';
        }

        // TRANSFERS — ningún movimiento/issue pendiente que haga inseguro el corte.
        $pendingTransfers = $this->pendingTransfersCount($warehouseId);
        if ($pendingTransfers > 0) {
            $reasons[] = "Transfers: {$pendingTransfers} transferencia(s) con movimiento pendiente "
                .'(in_transit / partially_received / received_with_issues) que hacen inseguro el corte.';
        }

        return [
            'warehouse_id' => $warehouseId,
            'ready' => empty($reasons),
            'reasons' => $reasons,
            'inventory_location_id' => $audit['inventory_location_id'],
            'provenance_reconciled' => $reconciled,
            'has_target_location' => $hasTarget,
            'batch_mismatches' => $artifacts['batch'],
            'serial_mismatches' => $artifacts['serial'],
            'unmigrated_legacy_serials' => $unmigratedSerials,
            'pending_transfers' => $pendingTransfers,
        ];
    }

    /**
     * FAIL CLOSED promotion to location_primary. Throws with the concrete
     * reasons (readinessForLocationPrimary) instead of promoting a warehouse
     * that is not actually safe to cut over.
     *
     * @throws ValidationException
     */
    public function promoteToLocationPrimary(int $warehouseId): InventoryTransitionState
    {
        $readiness = $this->readinessForLocationPrimary($warehouseId);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'inventory_transition' => "FAIL CLOSED: el almacén {$warehouseId} no cumple los requisitos de readiness para location_primary. Motivos: "
                    .implode(' | ', $readiness['reasons']),
            ]);
        }

        $state = $this->state($warehouseId);
        // last_reconciled_at (baseline provenance) no se toca aquí, por la misma
        // razón documentada en enableMode(): el baseline real es el momento del
        // backfill físico, no la activación de un modo de transición.
        $state->forceFill([
            'inventory_location_id' => $readiness['inventory_location_id'],
            'mode' => InventoryTransitionState::MODE_LOCATION_PRIMARY,
            'status' => 'healthy',
            'mismatch_count' => 0,
            'shadow_enabled_at' => $state->shadow_enabled_at ?: now(),
        ])->save();

        return $state->fresh();
    }

    /**
     * Batch/serial coverage mismatch scan across every active location of the
     * warehouse, reusing BatchLocationService::batchCoverageForLocation() and
     * SerialInventoryCoverageService::coverageForLocation() — no second engine.
     *
     * @return array{batch: array<int,array>, serial: array<int,array>}
     */
    private function artifactCoverageMismatches(int $warehouseId): array
    {
        $locationIds = $this->warehouseLocationIds($warehouseId);
        $batchMismatches = [];
        $serialMismatches = [];

        if (! $locationIds) {
            return ['batch' => $batchMismatches, 'serial' => $serialMismatches];
        }

        $keys = DB::table('inventory_location_stocks')
            ->whereIn('inventory_location_id', $locationIds)
            ->select('inventory_location_id', 'product_id', 'variant_key')
            ->distinct()
            ->get();

        if (Schema::hasTable('product_batch_location_stocks') && Schema::hasTable('product_batches')) {
            $batchKeys = DB::table('product_batch_location_stocks as pbls')
                ->join('product_batches as pb', 'pb.id', '=', 'pbls.product_batch_id')
                ->whereIn('pbls.inventory_location_id', $locationIds)
                ->select('pbls.inventory_location_id', 'pb.product_id', DB::raw('COALESCE(pb.product_variant_id, 0) as variant_key'))
                ->distinct()
                ->get();
            $keys = $keys->concat($batchKeys);
        }

        $batchService = app(BatchLocationService::class);
        $serialService = app(SerialInventoryCoverageService::class);
        $seenBatch = [];
        $seenSerial = [];

        foreach ($keys as $row) {
            $locationId = (int) $row->inventory_location_id;
            $productId = (int) $row->product_id;
            $variantKey = (int) $row->variant_key;
            $variantId = $variantKey > 0 ? $variantKey : null;

            $flags = $this->productArtifactFlags($productId);

            if ($flags['is_batch']) {
                $dedupe = $locationId.':'.$productId.':'.$variantKey;
                if (! isset($seenBatch[$dedupe])) {
                    $seenBatch[$dedupe] = true;
                    $coverage = $batchService->batchCoverageForLocation($locationId, $productId, $variantId);
                    if (! $coverage['matches']) {
                        $batchMismatches[] = $coverage;
                    }
                }
            }

            if ($flags['is_imei']) {
                $dedupe = $locationId.':'.$productId.':'.$variantKey;
                if (! isset($seenSerial[$dedupe])) {
                    $seenSerial[$dedupe] = true;
                    $coverage = $serialService->coverageForLocation($locationId, $productId, $variantId);
                    if (! $coverage['is_ready']) {
                        $serialMismatches[] = array_merge([
                            'inventory_location_id' => $locationId,
                            'product_id' => $productId,
                            'product_variant_id' => $variantId,
                        ], $coverage);
                    }
                }
            }
        }

        return ['batch' => $batchMismatches, 'serial' => $serialMismatches];
    }

    /** Per-product batch/IMEI flags — a plural-friendly sibling of productIsArtifactTracked(). */
    private function productArtifactFlags(int $productId): array
    {
        $none = ['is_batch' => false, 'is_imei' => false];
        if (! Schema::hasTable('products')) return $none;

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatch && ! $hasImei) return $none;

        $row = DB::table('products')->where('id', $productId)->whereNull('deleted_at')
            ->first(array_merge($hasBatch ? ['is_batch_tracked'] : [], $hasImei ? ['is_imei'] : []));
        if (! $row) return $none;

        return [
            'is_batch' => $hasBatch && (int) ($row->is_batch_tracked ?? 0) === 1,
            'is_imei' => $hasImei && (int) ($row->is_imei ?? 0) === 1,
        ];
    }

    /**
     * Transfers touching this warehouse (origin or destination) with a
     * movement/issue still pending — unsafe to cut over while any exist.
     * received_with_issues counts: an unresolved discrepancy is still pending.
     */
    private function pendingTransfersCount(int $warehouseId): int
    {
        if (! Schema::hasTable('transfers')) return 0;

        return (int) DB::table('transfers')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($warehouseId) {
                $q->where('from_warehouse_id', $warehouseId)->orWhere('to_warehouse_id', $warehouseId);
            })
            ->whereIn('logistics_status', ['in_transit', 'partially_received', 'received_with_issues'])
            ->count();
    }

    /**
     * FAIL CLOSED demotion guard. Demoting OUT of location_primary makes
     * product_warehouse (legacy) the productive source again — that is only
     * safe if legacy can already represent the current physical state exactly
     * (snapshot_equal) and there is no unreconciled ambiguity. This never
     * performs a copy-back/write: it only gates the mode flip. A caller that
     * needs product_warehouse to actually match first must run the existing
     * reconciliation tooling (LegacyInventoryReconciliationService) — never a
     * destructive shortcut here.
     *
     * @throws ValidationException
     */
    public function returnToLegacyOnly(int $warehouseId): InventoryTransitionState
    {
        $state = $this->state($warehouseId);

        if ($state->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY) {
            $audit = $this->audit($warehouseId);
            $reasons = [];

            if (! ($audit['provenance_reconciled'] ?? $audit['is_reconciled'] ?? false)) {
                $reasons[] = 'quedan inconsistencias sin reconciliar (revisión pendiente, migración legacy pendiente o existencias negativas)';
            }
            if (! ($audit['snapshot_equal'] ?? false)) {
                $reasons[] = 'product_warehouse (legacy) está stale: no representa el estado físico actual para '
                    .count($audit['snapshot_unequal_keys'] ?? []).' clave(s) producto/variante';
            }

            if ($reasons) {
                throw ValidationException::withMessages([
                    'inventory_transition' => "FAIL CLOSED: el almacén {$warehouseId} no puede demoverse de location_primary a legacy_only de forma segura ("
                        .implode('; ', $reasons).'). No se realiza ningún copy-back destructivo; reconcilia primero con las herramientas existentes.',
                ]);
            }
        }

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

    /** ¿El producto lleva control de lote o serie/IMEI? */
    private function productIsArtifactTracked(int $productId): bool
    {
        if (! Schema::hasTable('products')) return false;

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatch && ! $hasImei) return false;

        $row = DB::table('products')->where('id', $productId)->whereNull('deleted_at')
            ->first(array_merge($hasBatch ? ['is_batch_tracked'] : [], $hasImei ? ['is_imei'] : []));
        if (! $row) return false;

        return ($hasBatch && (int) ($row->is_batch_tracked ?? 0) === 1)
            || ($hasImei && (int) ($row->is_imei ?? 0) === 1);
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

                // ARTIFACT SAFETY runtime: adjustTo() no mantiene
                // product_batch_location_stocks ni product_serials. Si ESTE
                // producto es batch-tracked / IMEI, se rehúsa (markMismatch, 0
                // adjustTo) aunque el almacén haya quedado en dual_write por un
                // estado antiguo.
                if ($this->productIsArtifactTracked($productId)) {
                    throw ValidationException::withMessages([
                        'inventory_transition' => 'Dual-write detenido: el producto es batch-tracked o IMEI; el mirror single-target sólo ajusta '
                            .'inventory_location_stocks.quantity y NO mantiene product_batch_location_stocks / product_serials. '
                            .'Requiere un mirror artifact-aware (pendiente).',
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
            // 3) ARTIFACT SAFETY: el mirror single-target no mantiene
            //    product_batch_location_stocks ni product_serials. Se rechaza si
            //    hay productos batch-tracked / IMEI con inventario real.
            if (($audit['has_tracked_inventory'] ?? false)) {
                throw ValidationException::withMessages([
                    'inventory_transition' => 'dual_write no está soportado mientras el almacén tenga inventario de productos batch-tracked o IMEI ('
                        .count($audit['tracked_inventory_product_ids'] ?? []).'). El mirror single-target no mantiene lotes/seriales. '
                        .'Requiere un mirror artifact-aware (PR posterior).',
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
