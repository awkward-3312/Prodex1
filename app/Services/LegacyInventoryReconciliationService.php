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
        $location = $this->existingDefaultLocation($warehouse);
        $locationMap = $location ? $this->locationMap($location->id) : [];

        $keys = array_values(array_unique(array_merge(array_keys($legacy), array_keys($locationMap))));
        sort($keys);

        $differences = [];
        foreach ($keys as $key) {
            $legacyQty = $legacy[$key]['quantity'] ?? 0.0;
            $locationQty = $locationMap[$key]['quantity'] ?? 0.0;
            if (!$this->same($legacyQty, $locationQty)) {
                [$productId, $variantKey] = array_map('intval', explode(':', $key));
                $differences[] = [
                    'product_id' => $productId,
                    'product_variant_id' => $variantKey > 0 ? $variantKey : null,
                    'legacy_quantity' => $legacyQty,
                    'location_quantity' => $locationQty,
                    'difference' => $this->decimal($locationQty - $legacyQty),
                ];
            }
        }

        $negative = array_values(array_filter($legacy, fn ($row) => $row['quantity'] < 0));
        $trackedProducts = $this->batchOrSerialTrackedProducts($legacy);
        $mainHasStock = $location !== null
            && InventoryLocationStock::where('inventory_location_id', $location->id)->exists();

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'inventory_location_id' => $location?->id,
            'legacy_rows' => count($legacy),
            'location_rows' => count($locationMap),
            'legacy_total' => $this->decimal(array_sum(array_column($legacy, 'quantity'))),
            'location_total' => $this->decimal(array_sum(array_column($locationMap, 'quantity'))),
            'negative_legacy_rows' => $negative,
            // Products whose stock is only meaningful together with a batch or a
            // serial/IMEI location ledger. The quantity backfill below cannot move
            // product_batch_location_stocks / product_serials, so a plain --apply
            // would leave them half-migrated. They must be reported, never auto-run.
            'batch_or_serial_products' => $trackedProducts,
            'differences' => $differences,
            'is_reconciled' => empty($negative) && empty($differences) && $location !== null,
            // Whole-warehouse backfill (backfillWarehouse) only works from an EMPTY
            // MAIN. Once MAIN already holds stock — e.g. a warehouse in
            // shadow_compare that was backfilled once and then diverged via legacy
            // opening stock — the divergence must be closed with the incremental
            // plan (planIncremental), never with backfillWarehouse.
            'main_location_has_stock' => $mainHasStock,
            // Whole-warehouse backfillWarehouse() is safe only from an empty (or
            // not-yet-created) MAIN, with no negatives and no batch/serial rows.
            'is_backfillable' => empty($negative) && empty($trackedProducts) && ! $mainHasStock,
            'needs_incremental' => $mainHasStock && ! empty($differences),
        ];
    }

    /**
     * READ-ONLY. Per-product plan to close an existing divergence on a warehouse
     * whose MAIN already holds stock. delta = legacy_authoritative - location.
     *
     * action:
     *   ADD           delta > 0, producto simple, sin reservado, sin tránsito de
     *                 salida desde MAIN, sin lote/serie  → seguro sumar el delta.
     *   MANUAL_REVIEW delta < 0 (nunca se descuenta en automático), o hay
     *                 reservado / tránsito / lote / serie / negativos legacy.
     *
     * No escribe nada. No decide por sí solo aplicar: es insumo para revisión.
     */
    public function planIncremental(int $warehouseId): array
    {
        $warehouse = $this->warehouse($warehouseId);
        $location = $this->existingDefaultLocation($warehouse);
        $legacy = $this->legacyMap($warehouseId);
        $locationMap = $location ? $this->locationMap($location->id) : [];
        $tracked = collect($this->batchOrSerialTrackedProducts($legacy))->keyBy('product_id');
        $reserved = $location ? $this->reservedMap($location->id) : [];
        $outbound = $location ? $this->outboundInTransitMap($location->id) : [];

        $keys = array_values(array_unique(array_merge(array_keys($legacy), array_keys($locationMap))));
        sort($keys);

        $plan = [];
        foreach ($keys as $key) {
            [$productId, $variantKey] = array_map('intval', explode(':', $key));
            $legacyQty = $this->decimal($legacy[$key]['quantity'] ?? 0.0);
            $locationQty = $this->decimal($locationMap[$key]['quantity'] ?? 0.0);
            $delta = $this->decimal($legacyQty - $locationQty);
            if ($this->same($delta, 0.0)) continue;

            $reasons = [];
            if ($delta < 0) $reasons[] = 'delta_negativo';
            if ($legacyQty < 0) $reasons[] = 'legacy_negativo';
            if (isset($tracked[$productId])) $reasons[] = 'lote_o_serie';
            if (($reserved[$key] ?? 0.0) > 0.0005) $reasons[] = 'reservado';
            if (($outbound[$key] ?? 0.0) > 0.0005) $reasons[] = 'transito_salida';

            $plan[] = [
                'product_id' => $productId,
                'product_variant_id' => $variantKey > 0 ? $variantKey : null,
                'legacy' => $legacyQty,
                'location' => $locationQty,
                'delta' => $delta,
                'action' => empty($reasons) ? 'ADD' : 'MANUAL_REVIEW',
                'reasons' => $reasons,
            ];
        }

        $addable = array_values(array_filter($plan, fn ($r) => $r['action'] === 'ADD'));
        $review = array_values(array_filter($plan, fn ($r) => $r['action'] === 'MANUAL_REVIEW'));

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'inventory_location_id' => $location?->id,
            'plan' => $plan,
            'add_count' => count($addable),
            'manual_review_count' => count($review),
            'add_total_delta' => $this->decimal(array_sum(array_column($addable, 'delta'))),
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

            if (InventoryLocationStock::where('inventory_location_id', $location->id)->exists()) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación MAIN ya contiene inventario por ubicación. El backfill de almacén completo sólo opera desde una MAIN vacía; para cerrar una divergencia existente usa el plan incremental (planIncremental / prodex:inventory-reconcile --plan), nunca este backfill.',
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

    private function ensureDefaultLocation(Warehouse $warehouse): InventoryLocation
    {
        $existing = $this->existingDefaultLocation($warehouse);
        if ($existing) return $existing;

        $location = InventoryLocation::whereNull('deleted_at')
            ->where('warehouse_id', $warehouse->id)
            ->where('code', 'MAIN')
            ->first();

        if (! $location) {
            $location = app(InventoryLocationService::class)->createForWarehouse($warehouse, [
                'code' => 'MAIN',
                'name' => 'Inventario principal',
                'type' => InventoryLocation::TYPE_STORAGE,
                'is_sellable' => false,
                'is_active' => true,
            ]);
        }

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

    private function reservedMap(int $locationId): array
    {
        $map = [];
        foreach (InventoryLocationStock::where('inventory_location_id', $locationId)->get() as $row) {
            $map[$this->key((int) $row->product_id, (int) $row->variant_key)] = $this->decimal((float) $row->reserved_quantity);
        }
        return $map;
    }

    /**
     * Outbound not-yet-received transfer quantity leaving this location, keyed the
     * same way as legacyMap/locationMap (product:variant_key).
     */
    private function outboundInTransitMap(int $locationId): array
    {
        if (! Schema::hasTable('transfers') || ! Schema::hasTable('transfer_details')) return [];

        $accounted = Schema::hasTable('transfer_receipt_items')
            ? DB::table('transfer_receipt_items')
                ->select('transfer_detail_id', DB::raw('SUM(quantity_good + quantity_defective + quantity_missing) as acc'))
                ->groupBy('transfer_detail_id')
            : null;

        $q = DB::table('transfer_details as td')
            ->join('transfers as t', 't.id', '=', 'td.transfer_id')
            ->where('t.from_inventory_location_id', $locationId)
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
