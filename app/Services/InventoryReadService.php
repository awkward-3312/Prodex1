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

    private function positiveIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn (int $id) => $id > 0);
        return array_values(array_unique($ids));
    }
}
