<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LegacyInventoryReconciliationService
{
    public function auditWarehouse(int $warehouseId): array
    {
        $warehouse = $this->warehouse($warehouseId);
        $legacy = $this->legacyMap($warehouseId);

        // Ubicación destino APTA para reconciliación legacy / dual_write:
        // pertenece al almacén, activa, no borrada, tipo 'storage', NO cuarentena.
        // Si la default del almacén no cumple (p. ej. es QUARANTINE/DAMAGED/
        // RETURNS) => null: sin destino automático.
        $target = $this->eligibleLegacyTargetLocation($warehouse);
        // Verdad de comparación: agregado físico de TODAS las InventoryLocation
        // activas del almacén (MAIN + QUARANTINE + DAMAGED + RETURNS + …), NO
        // sólo la default. Un almacén con MAIN 70 + QUARANTINE 30 contra legacy
        // 100 está reconciliado.
        $warehouseLocations = $this->warehouseLocationMap($warehouseId);

        // Clasificación por PROVENANCE (no por igualdad de snapshot): tras un
        // baseline operativo, legacy_now - location_now puede ser 100% movimientos
        // location-native legítimos y NO stock legacy pendiente.
        $provenance = app(InventoryProvenanceAuditService::class)->auditWarehouse($warehouseId);
        $unknownReview = array_values(array_filter($provenance['keys'], fn ($r) => $r['classification'] === 'UNKNOWN_REVIEW'));
        $legacyOnlyPending = array_values(array_filter($provenance['keys'], fn ($r) => $r['classification'] === 'LEGACY_ONLY_PENDING'));

        // "differences" ahora = lo que realmente requiere atención (revisión o
        // migración pendiente), no cada desalineación de snapshot.
        $differences = array_map(fn ($r) => [
            'product_id' => $r['product_id'],
            'product_variant_id' => $r['product_variant_id'],
            'classification' => $r['classification'],
            'legacy_quantity' => $r['legacy_now'],
            'location_quantity' => $r['current_location'],
            'snapshot_drift' => $r['snapshot_drift'],
            'legacy_only_pending_quantity' => $r['legacy_only_pending_quantity'],
        ], array_merge($unknownReview, $legacyOnlyPending));

        $negative = array_values(array_filter($legacy, fn ($row) => $row['quantity'] < 0));
        $trackedProducts = $this->batchOrSerialTrackedProducts($legacy);
        $warehouseHasLocationStock = ! empty($warehouseLocations);
        // Reconciliado = sin negativos, sin UNKNOWN_REVIEW y sin LEGACY_ONLY_PENDING.
        // El snapshot_drift por operaciones location-native NO cuenta como no
        // reconciliado.
        $isReconciled = empty($negative) && empty($unknownReview) && empty($legacyOnlyPending);
        $stockedLocationCount = $this->stockedLocationCount($warehouseId);

        // SNAPSHOT EQUALITY — señal distinta de provenance_reconciled. Para CADA
        // (product_id, variant_key): legacy_now == inventario por ubicación actual
        // del almacén. Iphone15 (legacy 88 / location 60 por TransferDispatch) es
        // provenance_reconciled PERO NO snapshot_equal. dual_write / el mirror
        // single-target sólo son seguros con paridad EXACTA (no hay reverse-mirror
        // location→legacy).
        $snapshotUnequalKeys = [];
        foreach ($provenance['keys'] as $r) {
            if (abs((float) $r['legacy_now'] - (float) $r['current_location']) > 0.0005) {
                $snapshotUnequalKeys[] = [
                    'product_id' => $r['product_id'],
                    'product_variant_id' => $r['product_variant_id'],
                    'legacy_now' => $r['legacy_now'],
                    'current_location' => $r['current_location'],
                    'post_baseline_location_net' => $r['post_baseline_location_net'],
                    'snapshot_drift' => $r['snapshot_drift'],
                ];
            }
        }
        $snapshotEqual = empty($snapshotUnequalKeys);

        $locationTotal = $this->decimal(array_sum(array_column($warehouseLocations, 'quantity')));
        $targetTotal = $target !== null
            ? $this->decimal((float) InventoryLocationStock::where('inventory_location_id', $target->id)->sum('quantity'))
            : 0.0;
        // Stock location-native que NO vive en la ubicación destino apta.
        $stockOutsideTarget = $this->decimal(max(0, $locationTotal - $targetTotal));
        // dual_write / mirror single-target sólo es seguro si el destino contiene
        // TODO el stock location-native del almacén.
        $targetHoldsAllStock = $target !== null && $stockOutsideTarget <= 0.0005;
        $mainHasStock = $targetTotal > 0.0005;

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'inventory_location_id' => $target?->id,
            'legacy_rows' => count($legacy),
            'location_rows' => count($warehouseLocations),
            'legacy_total' => $this->decimal(array_sum(array_column($legacy, 'quantity'))),
            // Total físico location-native del almacén (todas sus ubicaciones).
            'location_total' => $locationTotal,
            'negative_legacy_rows' => $negative,
            // Products whose stock is only meaningful together with a batch or a
            // serial/IMEI location ledger. The quantity backfill below cannot move
            // product_batch_location_stocks / product_serials, so a plain --apply
            // would leave them half-migrated. They must be reported, never auto-run.
            'batch_or_serial_products' => $trackedProducts,
            'differences' => $differences,
            // Clasificación event-based.
            'provenance_counts' => $provenance['counts'],
            'unknown_review_rows' => $unknownReview,
            'legacy_only_pending_rows' => $legacyOnlyPending,
            'legacy_only_pending_total' => $provenance['legacy_only_pending_total'],
            // Métrica DIAGNÓSTICA: NO es cantidad pendiente de reconciliación.
            'snapshot_drift_total' => $provenance['snapshot_drift_total'],
            // provenance_reconciled = sin negativos, sin UNKNOWN_REVIEW ni
            // LEGACY_ONLY_PENDING. NO implica paridad snapshot legacy/location.
            'is_reconciled' => $isReconciled,
            'provenance_reconciled' => $isReconciled,
            // snapshot_equal = legacy_now == inventario por ubicación actual para
            // TODA clave. Requerido para dual_write / mirror single-target.
            'snapshot_equal' => $snapshotEqual,
            'snapshot_unequal_keys' => $snapshotUnequalKeys,
            // has_target_location = existe una ubicación destino APTA (storage,
            // activa, no cuarentena, del almacén). Distinto de is_reconciled.
            'has_target_location' => $target !== null,
            'transition_ready' => $isReconciled && $target !== null,
            // dual_write sólo es seguro con TODAS estas condiciones.
            'dual_write_compatible' => $isReconciled && $target !== null && $targetHoldsAllStock && $snapshotEqual,
            'main_location_has_stock' => $mainHasStock,
            'warehouse_has_location_stock' => $warehouseHasLocationStock,
            // Cuánto stock location-native vive FUERA de la ubicación destino.
            // dual_write exige que esto sea 0 (single-target).
            'stock_outside_target_quantity' => $stockOutsideTarget,
            'target_holds_all_stock' => $targetHoldsAllStock,
            // Informativo.
            'stocked_location_count' => $stockedLocationCount,
            'is_single_location' => $stockedLocationCount <= 1,
            // Whole-warehouse backfillWarehouse() sólo es seguro desde un almacén
            // SIN NINGUNA ubicación con stock (init desde cero). Si ya hay stock
            // location-native en cualquier ubicación, la divergencia se cierra con
            // el plan incremental, nunca con este backfill.
            'is_backfillable' => empty($negative) && empty($trackedProducts) && ! $warehouseHasLocationStock,
            'needs_incremental' => $warehouseHasLocationStock && (! empty($legacyOnlyPending) || ! empty($unknownReview)),
        ];
    }

    /**
     * READ-ONLY. Plan por producto/variante, BASADO EN PROVENANCE.
     *
     * Sólo las claves clasificadas LEGACY_ONLY_PENDING por
     * InventoryProvenanceAuditService (cantidad legacy sin equivalente
     * location-native) son candidatas a ADD. El snapshot_drift por operaciones
     * location-native legítimas NO genera ADD. UNKNOWN_REVIEW => MANUAL_REVIEW.
     *
     * action ADD sólo si: hay destino apto, producto simple, SIN reservado en
     * ninguna ubicación del almacén, SIN tránsito de salida, sin lote/serie.
     * En cualquier otro caso MANUAL_REVIEW. No escribe nada.
     */
    public function planIncremental(int $warehouseId): array
    {
        $warehouse = $this->warehouse($warehouseId);
        $target = $this->eligibleLegacyTargetLocation($warehouse);
        $legacy = $this->legacyMap($warehouseId);
        $warehouseLocations = $this->warehouseLocationMap($warehouseId);
        $mainMap = $target ? $this->locationMap($target->id) : [];
        $tracked = collect($this->batchOrSerialTrackedProducts($legacy))->keyBy('product_id');
        $outbound = $this->outboundInTransitMap($this->warehouseLocationIds($warehouseId));

        $provenance = app(InventoryProvenanceAuditService::class)->auditWarehouse($warehouseId);

        $plan = [];
        foreach ($provenance['keys'] as $row) {
            $cls = $row['classification'];
            if (! in_array($cls, ['LEGACY_ONLY_PENDING', 'UNKNOWN_REVIEW'], true)) continue;

            $productId = (int) $row['product_id'];
            $variantKey = (int) ($row['product_variant_id'] ?: 0);
            $key = $productId.':'.$variantKey;
            $pending = $this->decimal((float) $row['legacy_only_pending_quantity']);
            $mainQty = $this->decimal($mainMap[$key]['quantity'] ?? 0.0);
            $warehouseLocQty = $this->decimal($warehouseLocations[$key]['quantity'] ?? 0.0);
            $reservedWh = $this->decimal($warehouseLocations[$key]['reserved'] ?? 0.0);

            $reasons = [];
            if ($cls === 'UNKNOWN_REVIEW') $reasons[] = 'provenance_desconocida';
            if ($cls === 'LEGACY_ONLY_PENDING' && $pending <= 0.0005) $reasons[] = 'sin_cantidad_pendiente';
            if ($target === null) $reasons[] = 'sin_ubicacion_destino';
            if (isset($tracked[$productId])) $reasons[] = 'lote_o_serie';
            if ($reservedWh > 0.0005) $reasons[] = 'reservado';
            if (($outbound[$key] ?? 0.0) > 0.0005) $reasons[] = 'transito_salida';

            $plan[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantKey > 0 ? $variantKey : null,
                'classification' => $cls,
                'legacy' => $this->decimal((float) $row['legacy_now']),
                'baseline_quantity' => $this->decimal((float) $row['baseline_quantity']),
                'main_quantity' => $mainQty,
                'other_locations_quantity' => $this->decimal($warehouseLocQty - $mainQty),
                'warehouse_location_quantity' => $warehouseLocQty,
                'snapshot_drift' => $this->decimal((float) $row['snapshot_drift']),
                // delta = cantidad que se sumaría (sólo para ADD).
                'delta' => $cls === 'LEGACY_ONLY_PENDING' ? $pending : 0.0,
                'target_inventory_location_id' => $target?->id,
                'action' => ($cls === 'LEGACY_ONLY_PENDING' && empty($reasons)) ? 'ADD' : 'MANUAL_REVIEW',
                'reasons' => $reasons,
            ];
        }

        $addable = array_values(array_filter($plan, fn ($r) => $r['action'] === 'ADD'));
        $review = array_values(array_filter($plan, fn ($r) => $r['action'] === 'MANUAL_REVIEW'));

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'inventory_location_id' => $target?->id,
            'baseline_at' => $provenance['baseline_at'],
            'plan' => $plan,
            'add_count' => count($addable),
            'manual_review_count' => count($review),
            'add_total_delta' => $this->decimal(array_sum(array_column($addable, 'delta'))),
            // Diagnóstico: NO es cantidad a aplicar.
            'snapshot_drift_total' => $provenance['snapshot_drift_total'],
        ];
    }

    public function backfillWarehouse(int $warehouseId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($warehouseId, $userId) {
            $warehouse = Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->lockForUpdate()->firstOrFail();
            $legacy = $this->legacyMap($warehouseId);

            $negative = array_values(array_filter($legacy, fn ($row) => $row['quantity'] < 0));
            if ($negative) {
                throw ValidationException::withMessages([
                    'legacy_stock' => 'No se puede migrar este almacén/CD porque existen cantidades negativas en product_warehouse.',
                ]);
            }

            $tracked = $this->batchOrSerialTrackedProducts($legacy);
            if ($tracked) {
                throw ValidationException::withMessages([
                    'batch_or_serial_stock' => 'Este almacén/CD contiene productos con control de lote o serie/IMEI ('
                        .count($tracked).'). El backfill de cantidades no migra product_batch_location_stocks ni product_serials, '
                        .'así que dejaría esos productos a medio migrar. Requiere una migración asistida específica de lotes/seriales.',
                ]);
            }

            $location = $this->ensureDefaultLocation($warehouse);
            $before = $this->auditWarehouse($warehouseId);
            if ($before['is_reconciled']) {
                return $before + ['backfilled' => false, 'already_reconciled' => true];
            }

            if (! empty($this->warehouseLocationMap($warehouseId))) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'El almacén ya contiene inventario por ubicación (en MAIN o en otra ubicación). El backfill de almacén completo sólo opera desde un almacén sin ninguna ubicación con stock; para cerrar una divergencia existente usa el plan incremental (planIncremental / prodex:inventory-reconcile --plan), nunca este backfill.',
                ]);
            }

            // Aserción explícita antes de escribir: el destino sigue siendo apto.
            $location = $location->fresh() ?? $location;
            if (! $this->locationIsEligibleTarget($location, $warehouseId)) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación destino dejó de ser un destino apto (storage activa, no cuarentena, del almacén) antes de escribir. Backfill cancelado.',
                ]);
            }

            $inventory = app(InventoryService::class);
            foreach ($legacy as $row) {
                if ($row['quantity'] <= 0) continue;

                $variantId = $row['variant_key'] > 0 ? $row['variant_key'] : null;
                $inventory->increase(
                    $location->id,
                    $row['product_id'],
                    $row['quantity'],
                    $variantId,
                    [
                        'user_id' => $userId,
                        'reference_type' => 'legacy_product_warehouse_backfill',
                        'reference_id' => (string) $warehouseId,
                        'idempotency_key' => sprintf(
                            'legacy-backfill:%d:%d:%d',
                            $warehouseId,
                            $row['product_id'],
                            $row['variant_key']
                        ),
                        'notes' => 'Inicialización desde product_warehouse; product_warehouse continúa siendo la fuente productiva durante la transición.',
                        'metadata' => [
                            'legacy_warehouse_id' => $warehouseId,
                            'phase' => 3,
                        ],
                    ]
                );
            }

            $after = $this->auditWarehouse($warehouseId);
            if (! $after['is_reconciled']) {
                throw ValidationException::withMessages([
                    'reconciliation' => 'El backfill no reconcilió exactamente contra product_warehouse. La transacción fue cancelada.',
                ]);
            }

            return $after + ['backfilled' => true, 'already_reconciled' => false];
        }, 3);
    }

    public function auditAllWarehouses(): array
    {
        return Warehouse::whereNull('deleted_at')->orderBy('id')->get(['id'])
            ->map(fn ($warehouse) => $this->auditWarehouse((int) $warehouse->id))
            ->all();
    }

    /**
     * Devuelve/crea la ubicación destino APTA del almacén y la deja como default.
     * Respeta el mismo contrato que eligibleLegacyTargetLocation() — nunca
     * escribe stock legacy en una ubicación de cuarentena / dañados / devoluciones.
     *
     *  1. Si la default actual ya es apta (storage activa, no cuarentena) => esa.
     *  2. Si no, y existe una code=MAIN del almacén APTA => usarla y setearla default.
     *  3. Si no existe ninguna code=MAIN => crear MAIN storage y setearla default.
     *  4. Si existe una code=MAIN pero NO es apta (quarantine/damaged/…) => NO se
     *     recicla ni se modifica: ValidationException (revisión manual).
     */
    private function ensureDefaultLocation(Warehouse $warehouse): InventoryLocation
    {
        $eligible = $this->eligibleLegacyTargetLocation($warehouse);
        if ($eligible) return $eligible;

        $main = InventoryLocation::whereNull('deleted_at')
            ->where('warehouse_id', $warehouse->id)
            ->where('code', 'MAIN')
            ->first();

        if ($main) {
            if (! $this->locationIsEligibleTarget($main, $warehouse->id)) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación MAIN del almacén no es un destino apto para reconciliación legacy '
                        .'(debe ser tipo storage, activa y no cuarentena). No se recicla ni se modifica automáticamente: requiere revisión manual.',
                ]);
            }

            return app(InventoryLocationService::class)->setWarehouseDefault($main);
        }

        $location = app(InventoryLocationService::class)->createForWarehouse($warehouse, [
            'code' => 'MAIN',
            'name' => 'Inventario principal',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_sellable' => false,
            'is_active' => true,
        ]);

        return app(InventoryLocationService::class)->setWarehouseDefault($location);
    }

    private function existingDefaultLocation(Warehouse $warehouse): ?InventoryLocation
    {
        if (! $warehouse->default_inventory_location_id) return null;

        return InventoryLocation::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('warehouse_id', $warehouse->id)
            ->whereKey($warehouse->default_inventory_location_id)
            ->first();
    }

    /**
     * Contrato de ubicación destino APTA para reconciliación legacy genérica y
     * para dual_write. La default del almacén debe además:
     *   - pertenecer al almacén, estar activa y no borrada (ya en existingDefaultLocation);
     *   - ser de tipo 'storage' (NO sales_floor / quarantine / damaged / returns / other);
     *   - no estar marcada como cuarentena.
     * No se exige code=MAIN: cualquier default 'storage' apta sirve. Si la default
     * no cumple (p. ej. es QUARANTINE/DAMAGED/RETURNS) => null: no hay destino
     * automático y el stock legacy NO se envía a cuarentena/dañados/devoluciones.
     */
    private function eligibleLegacyTargetLocation(Warehouse $warehouse): ?InventoryLocation
    {
        $default = $this->existingDefaultLocation($warehouse);

        return $this->locationIsEligibleTarget($default, (int) $warehouse->id) ? $default : null;
    }

    /**
     * Predicado único del contrato de destino apto: del almacén, activa, no
     * borrada, tipo 'storage', no cuarentena. Se usa en auditWarehouse,
     * planIncremental, ensureDefaultLocation y la aserción previa a increase().
     */
    private function locationIsEligibleTarget(?InventoryLocation $location, int $warehouseId): bool
    {
        return $location !== null
            && $location->deleted_at === null
            && (bool) $location->is_active === true
            && (int) $location->warehouse_id === $warehouseId
            && $location->type === InventoryLocation::TYPE_STORAGE
            && ! $location->is_quarantine;
    }

    private function legacyMap(int $warehouseId): array
    {
        $rows = DB::table('product_warehouse')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->selectRaw('product_id, COALESCE(product_variant_id, 0) as variant_key, SUM(qte) as quantity')
            ->groupBy('product_id', DB::raw('COALESCE(product_variant_id, 0)'))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $variantKey = (int) $row->variant_key;
            $map[$this->key($productId, $variantKey)] = [
                'product_id' => $productId,
                'variant_key' => $variantKey,
                'quantity' => $this->decimal((float) $row->quantity),
            ];
        }
        return $map;
    }

    /**
     * Products in the legacy map that carry a batch or serial/IMEI ledger. The
     * quantity-only backfill cannot move product_batch_location_stocks nor
     * product_serials, so these must block an automatic --apply.
     */
    private function batchOrSerialTrackedProducts(array $legacy): array
    {
        if (! Schema::hasTable('products')) return [];

        $hasBatch = Schema::hasColumn('products', 'is_batch_tracked');
        $hasImei = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatch && ! $hasImei) return [];

        $productIds = array_values(array_unique(array_map(
            fn ($row) => (int) $row['product_id'],
            $legacy
        )));
        if (! $productIds) return [];

        $rows = DB::table('products')
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($hasBatch, $hasImei) {
                if ($hasBatch) $query->orWhere('is_batch_tracked', 1);
                if ($hasImei) $query->orWhere('is_imei', 1);
            })
            ->get(['id', ...($hasBatch ? ['is_batch_tracked'] : []), ...($hasImei ? ['is_imei'] : [])]);

        return $rows->map(fn ($row) => [
            'product_id' => (int) $row->id,
            'is_batch_tracked' => $hasBatch ? (bool) $row->is_batch_tracked : false,
            'is_imei' => $hasImei ? (bool) $row->is_imei : false,
        ])->all();
    }

    private function locationMap(int $locationId): array
    {
        $map = [];
        foreach (InventoryLocationStock::where('inventory_location_id', $locationId)->get() as $row) {
            $map[$this->key((int) $row->product_id, (int) $row->variant_key)] = [
                'product_id' => (int) $row->product_id,
                'variant_key' => (int) $row->variant_key,
                'quantity' => $this->decimal((float) $row->quantity),
            ];
        }
        return $map;
    }

    /** Active, non-deleted InventoryLocation ids that belong to this warehouse. */
    private function warehouseLocationIds(int $warehouseId): array
    {
        return InventoryLocation::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('warehouse_id', $warehouseId)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Nº de ubicaciones activas del almacén con quantity > 0. */
    private function stockedLocationCount(int $warehouseId): int
    {
        $ids = $this->warehouseLocationIds($warehouseId);
        if (! $ids) return 0;

        return (int) InventoryLocationStock::whereIn('inventory_location_id', $ids)
            ->where('quantity', '>', 0)
            ->distinct()
            ->count('inventory_location_id');
    }

    /**
     * product:variant_key => ['product_id','variant_key','quantity','reserved']
     * aggregated across ALL active locations of the warehouse. This is the truth
     * used to decide whether a warehouse is reconciled — never a single location.
     */
    private function warehouseLocationMap(int $warehouseId): array
    {
        $ids = $this->warehouseLocationIds($warehouseId);
        if (! $ids) return [];

        $map = [];
        foreach (InventoryLocationStock::whereIn('inventory_location_id', $ids)->get() as $row) {
            $k = $this->key((int) $row->product_id, (int) $row->variant_key);
            if (! isset($map[$k])) {
                $map[$k] = [
                    'product_id' => (int) $row->product_id,
                    'variant_key' => (int) $row->variant_key,
                    'quantity' => 0.0,
                    'reserved' => 0.0,
                ];
            }
            $map[$k]['quantity'] = $this->decimal($map[$k]['quantity'] + (float) $row->quantity);
            $map[$k]['reserved'] = $this->decimal($map[$k]['reserved'] + (float) $row->reserved_quantity);
        }
        return $map;
    }

    /**
     * Outbound not-yet-received transfer quantity leaving ANY of the given
     * locations, keyed the same way as legacyMap (product:variant_key).
     */
    private function outboundInTransitMap(array $locationIds): array
    {
        $locationIds = array_values(array_filter(array_map('intval', $locationIds), fn ($id) => $id > 0));
        if (! $locationIds || ! Schema::hasTable('transfers') || ! Schema::hasTable('transfer_details')) return [];

        $accounted = Schema::hasTable('transfer_receipt_items')
            ? DB::table('transfer_receipt_items')
                ->select('transfer_detail_id', DB::raw('SUM(quantity_good + quantity_defective + quantity_missing) as acc'))
                ->groupBy('transfer_detail_id')
            : null;

        $q = DB::table('transfer_details as td')
            ->join('transfers as t', 't.id', '=', 'td.transfer_id')
            ->whereIn('t.from_inventory_location_id', $locationIds)
            ->whereIn('t.logistics_status', ['in_transit', 'partially_received'])
            ->whereNull('t.deleted_at');

        if ($accounted) {
            $q->leftJoinSub($accounted, 'ri', fn ($j) => $j->on('ri.transfer_detail_id', '=', 'td.id'))
                ->selectRaw('td.product_id, COALESCE(td.product_variant_id,0) as variant_key, SUM(td.quantity - COALESCE(ri.acc,0)) as qty');
        } else {
            $q->selectRaw('td.product_id, COALESCE(td.product_variant_id,0) as variant_key, SUM(td.quantity) as qty');
        }

        $map = [];
        foreach ($q->groupBy('td.product_id', DB::raw('COALESCE(td.product_variant_id,0)'))->get() as $row) {
            $map[$this->key((int) $row->product_id, (int) $row->variant_key)] = $this->decimal((float) $row->qty);
        }
        return $map;
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        return Warehouse::whereNull('deleted_at')->findOrFail($warehouseId);
    }

    private function key(int $productId, int $variantKey): string
    {
        return $productId.':'.$variantKey;
    }

    private function same(float $a, float $b): bool
    {
        return abs($this->decimal($a) - $this->decimal($b)) < 0.0005;
    }

    private function decimal(float $value): float
    {
        return round($value, 3);
    }
}
