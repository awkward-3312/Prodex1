<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
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

        return [
            'warehouse_id' => $warehouse->id,
            'warehouse_name' => $warehouse->name,
            'inventory_location_id' => $location?->id,
            'legacy_rows' => count($legacy),
            'location_rows' => count($locationMap),
            'legacy_total' => $this->decimal(array_sum(array_column($legacy, 'quantity'))),
            'location_total' => $this->decimal(array_sum(array_column($locationMap, 'quantity'))),
            'negative_legacy_rows' => $negative,
            'differences' => $differences,
            'is_reconciled' => empty($negative) && empty($differences) && $location !== null,
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

            $location = $this->ensureDefaultLocation($warehouse);
            $before = $this->auditWarehouse($warehouseId);
            if ($before['is_reconciled']) {
                return $before + ['backfilled' => false, 'already_reconciled' => true];
            }

            if (InventoryLocationStock::where('inventory_location_id', $location->id)->exists()) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación destino ya contiene inventario y no coincide con el legado. Se requiere revisión antes de continuar.',
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
