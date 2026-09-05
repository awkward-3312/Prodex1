<?php

namespace App\Services;

use App\Models\InventoryTransitionState;
use Illuminate\Support\Facades\DB;

class InventoryReadService
{
    /**
     * Return product totals across the requested legacy warehouse/CD IDs.
     *
     * During transition every warehouse remains legacy-backed unless it has been
     * explicitly promoted to location_primary in a later phase. This method lets
     * read paths migrate now without changing their current source or result.
     */
    public function totalsByProduct(array $productIds, array $warehouseIds): array
    {
        $productIds = $this->positiveIds($productIds);
        $warehouseIds = $this->positiveIds($warehouseIds);
        if (! $productIds || ! $warehouseIds) {
            return [];
        }

        $states = InventoryTransitionState::whereIn('warehouse_id', $warehouseIds)
            ->get(['warehouse_id', 'inventory_location_id', 'mode', 'status'])
            ->keyBy('warehouse_id');

        $legacyWarehouseIds = [];
        $locationIds = [];

        foreach ($warehouseIds as $warehouseId) {
            $state = $states->get($warehouseId);
            $useLocation = $state
                && $state->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY
                && $state->status === 'healthy'
                && $state->inventory_location_id;

            if ($useLocation) {
                $locationIds[] = (int) $state->inventory_location_id;
            } else {
                $legacyWarehouseIds[] = $warehouseId;
            }
        }

        $totals = [];

        if ($legacyWarehouseIds) {
            $rows = DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $legacyWarehouseIds)
                ->whereNull('deleted_at')
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(qte) as quantity')
                ->get();

            foreach ($rows as $row) {
                $totals[(int) $row->product_id] = round((float) $row->quantity, 3);
            }
        }

        if ($locationIds) {
            $rows = DB::table('inventory_location_stocks')
                ->whereIn('product_id', $productIds)
                ->whereIn('inventory_location_id', array_values(array_unique($locationIds)))
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(quantity) as quantity')
                ->get();

            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $totals[$productId] = round(($totals[$productId] ?? 0.0) + (float) $row->quantity, 3);
            }
        }

        return $totals;
    }

    public function totalForProduct(int $productId, array $warehouseIds): float
    {
        return (float) ($this->totalsByProduct([$productId], $warehouseIds)[$productId] ?? 0.0);
    }

    /**
     * MS7-B3 — split warehouse IDs into legacy vs location_primary (with
     * their resolved location), using the exact same rule totalsByProduct()
     * uses. Report/dashboard call sites that need a per-warehouse or
     * per-variant breakdown (not just a flat total) reuse this instead of
     * re-implementing the mode check.
     *
     * @return array{legacy: int[], locationByWarehouse: array<int,int>}
     */
    public function splitWarehousesByMode(array $warehouseIds): array
    {
        $warehouseIds = $this->positiveIds($warehouseIds);
        $states = InventoryTransitionState::whereIn('warehouse_id', $warehouseIds)
            ->get(['warehouse_id', 'inventory_location_id', 'mode', 'status'])
            ->keyBy('warehouse_id');

        $legacy = [];
        $locationByWarehouse = [];

        foreach ($warehouseIds as $warehouseId) {
            $state = $states->get($warehouseId);
            $useLocation = $state
                && $state->mode === InventoryTransitionState::MODE_LOCATION_PRIMARY
                && $state->status === 'healthy'
                && $state->inventory_location_id;

            if ($useLocation) {
                $locationByWarehouse[$warehouseId] = (int) $state->inventory_location_id;
            } else {
                $legacy[] = $warehouseId;
            }
        }

        return ['legacy' => $legacy, 'locationByWarehouse' => $locationByWarehouse];
    }

    /**
     * Like totalsByProduct() but keyed by "productId:variantId" (variantId
     * 0 for the base product) — for reports with a variant dimension.
     *
     * @return array<string,float>
     */
    public function totalsByProductVariant(array $productIds, array $warehouseIds): array
    {
        $productIds = $this->positiveIds($productIds);
        $split = $this->splitWarehousesByMode($warehouseIds);
        if (! $productIds || (empty($split['legacy']) && empty($split['locationByWarehouse']))) {
            return [];
        }

        $totals = [];
        $add = function ($productId, $variantId, $qty) use (&$totals) {
            $key = (int) $productId.':'.(int) $variantId;
            $totals[$key] = round(($totals[$key] ?? 0.0) + (float) $qty, 3);
        };

        if ($split['legacy']) {
            $rows = DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $split['legacy'])
                ->whereNull('deleted_at')
                ->groupBy('product_id', 'product_variant_id')
                ->selectRaw('product_id, product_variant_id, SUM(qte) as quantity')
                ->get();
            foreach ($rows as $row) {
                $add($row->product_id, $row->product_variant_id, $row->quantity);
            }
        }

        if ($split['locationByWarehouse']) {
            $locationIds = array_values(array_unique($split['locationByWarehouse']));
            $rows = DB::table('inventory_location_stocks')
                ->whereIn('product_id', $productIds)
                ->whereIn('inventory_location_id', $locationIds)
                ->groupBy('product_id', 'product_variant_id')
                ->selectRaw('product_id, product_variant_id, SUM(quantity) as quantity')
                ->get();
            foreach ($rows as $row) {
                $add($row->product_id, $row->product_variant_id, $row->quantity);
            }
        }

        return $totals;
    }

    /**
     * Like totalsByProductVariant() but keeps the warehouse_id dimension —
     * for reports correlating current stock with per-warehouse movement
     * history (sales/transfers/adjustments), which stay scoped by their own
     * tables' warehouse_id regardless of stock source.
     *
     * @return array<string,float> keyed "productId:variantId:warehouseId"
     */
    public function totalsByProductVariantWarehouse(array $productIds, array $warehouseIds): array
    {
        $productIds = $this->positiveIds($productIds);
        $split = $this->splitWarehousesByMode($warehouseIds);
        if (! $productIds || (empty($split['legacy']) && empty($split['locationByWarehouse']))) {
            return [];
        }

        $totals = [];
        $add = function ($productId, $variantId, $warehouseId, $qty) use (&$totals) {
            $key = (int) $productId.':'.(int) $variantId.':'.(int) $warehouseId;
            $totals[$key] = round(($totals[$key] ?? 0.0) + (float) $qty, 3);
        };

        if ($split['legacy']) {
            $rows = DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereIn('warehouse_id', $split['legacy'])
                ->whereNull('deleted_at')
                ->groupBy('product_id', 'product_variant_id', 'warehouse_id')
                ->selectRaw('product_id, product_variant_id, warehouse_id, SUM(qte) as quantity')
                ->get();
            foreach ($rows as $row) {
                $add($row->product_id, $row->product_variant_id, $row->warehouse_id, $row->quantity);
            }
        }

        if ($split['locationByWarehouse']) {
            $warehouseIdByLocation = array_flip($split['locationByWarehouse']);
            $locationIds = array_values(array_unique($split['locationByWarehouse']));
            $rows = DB::table('inventory_location_stocks')
                ->whereIn('product_id', $productIds)
                ->whereIn('inventory_location_id', $locationIds)
                ->groupBy('product_id', 'product_variant_id', 'inventory_location_id')
                ->selectRaw('product_id, product_variant_id, inventory_location_id, SUM(quantity) as quantity')
                ->get();
            foreach ($rows as $row) {
                $warehouseId = $warehouseIdByLocation[(int) $row->inventory_location_id] ?? null;
                if ($warehouseId === null) {
                    continue;
                }
                $add($row->product_id, $row->product_variant_id, $warehouseId, $row->quantity);
            }
        }

        return $totals;
    }

    private function positiveIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn (int $id) => $id > 0);
        return array_values(array_unique($ids));
    }
}
