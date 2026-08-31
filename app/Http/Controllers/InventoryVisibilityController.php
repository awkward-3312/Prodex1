<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryVisibilityController extends Controller
{
    public function search(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        if (! Schema::hasTable('inventory_locations') || ! Schema::hasTable('inventory_location_stocks')) {
            return response()->json([
                'products' => [],
                'current_branch_id' => $user->default_branch_id ? (int) $user->default_branch_id : null,
                'message' => 'El inventario por ubicación todavía no está disponible para este tenant.',
            ]);
        }

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([
                'products' => [],
                'current_branch_id' => $user->default_branch_id ? (int) $user->default_branch_id : null,
            ]);
        }

        $products = DB::table('products')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($term) {
                $like = '%'.$term.'%';
                $query->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'code']);

        if ($products->isEmpty()) {
            return response()->json([
                'products' => [],
                'current_branch_id' => $user->default_branch_id ? (int) $user->default_branch_id : null,
            ]);
        }

        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Divergencia legado ↔ motor por ubicación, calculada POR ALMACÉN y
        // sumada sólo por el lado positivo — nunca se compensa un almacén con
        // exceso legado contra otro con exceso por ubicación, ni se mezcla stock
        // branch-native (inventory_locations.warehouse_id NULL) con el
        // product_warehouse de un CD.
        //
        //   pending(producto) = Σ_almacén  max( legacyWarehouse − locationWarehouse , 0 )
        //
        // donde locationWarehouse = SUM(inventory_location_stocks.quantity) de
        // TODAS las inventory_locations activas de ese almacén (MAIN + cuarentena
        // + devoluciones + …), no sólo la default.
        $legacyByWh = [];      // [product_id][warehouse_id] => qty
        $legacyTotals = [];    // [product_id] => Σ qty (para mostrar)
        if (Schema::hasTable('product_warehouse')) {
            foreach (DB::table('product_warehouse')
                ->whereIn('product_id', $productIds)
                ->whereNull('deleted_at')
                ->groupBy('product_id', 'warehouse_id')
                ->selectRaw('product_id, warehouse_id, SUM(qte) as quantity')
                ->get() as $row) {
                $pid = (int) $row->product_id;
                $legacyByWh[$pid][(int) $row->warehouse_id] = round((float) $row->quantity, 3);
                $legacyTotals[$pid] = round(($legacyTotals[$pid] ?? 0) + (float) $row->quantity, 3);
            }
        }

        $locationByWh = []; // [product_id][warehouse_id] => physical qty (warehouse-owned locations only)
        foreach (DB::table('inventory_location_stocks as s')
            ->join('inventory_locations as il', 'il.id', '=', 's.inventory_location_id')
            ->whereIn('s.product_id', $productIds)
            ->whereNotNull('il.warehouse_id')
            ->whereNull('il.deleted_at')
            ->where('il.is_active', 1)
            ->groupBy('s.product_id', 'il.warehouse_id')
            ->selectRaw('s.product_id, il.warehouse_id, SUM(s.quantity) as quantity')
            ->get() as $row) {
            $locationByWh[(int) $row->product_id][(int) $row->warehouse_id] = round((float) $row->quantity, 3);
        }

        $legacyPendingByProduct = [];
        foreach ($legacyByWh as $pid => $byWh) {
            $pending = 0.0;
            foreach ($byWh as $whId => $legacyQty) {
                $locQty = $locationByWh[$pid][$whId] ?? 0.0;
                $pending += max(0, round($legacyQty - $locQty, 3));
            }
            $legacyPendingByProduct[$pid] = round($pending, 3);
        }

        $stocks = DB::table('inventory_location_stocks as s')
            ->join('inventory_locations as il', 'il.id', '=', 's.inventory_location_id')
            ->leftJoin('branches as b', 'b.id', '=', 'il.branch_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'il.warehouse_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 's.product_variant_id')
            ->whereIn('s.product_id', $productIds)
            ->whereNull('il.deleted_at')
            ->where('il.is_active', 1)
            ->orderByRaw('CASE WHEN il.branch_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('b.name')
            ->orderBy('w.name')
            ->orderBy('il.name')
            ->get([
                's.product_id',
                's.product_variant_id',
                'pv.name as variant_name',
                's.inventory_location_id',
                's.quantity',
                's.reserved_quantity',
                'il.name as location_name',
                'il.type as location_type',
                'il.is_sellable',
                'il.is_quarantine',
                'il.branch_id',
                'il.warehouse_id',
                'b.name as branch_name',
                'b.code as branch_code',
                'w.name as warehouse_name',
            ]);

        $transit = collect();
        if (Schema::hasTable('transfers') && Schema::hasTable('transfer_details') && Schema::hasTable('transfer_receipt_items')) {
            $received = DB::table('transfer_receipt_items')
                ->select('transfer_detail_id', DB::raw('SUM(quantity_good + quantity_defective + quantity_missing) as accounted'))
                ->groupBy('transfer_detail_id');

            $transit = DB::table('transfer_details as td')
                ->join('transfers as t', 't.id', '=', 'td.transfer_id')
                ->leftJoinSub($received, 'ri', function ($join) {
                    $join->on('ri.transfer_detail_id', '=', 'td.id');
                })
                ->leftJoin('inventory_locations as til', 'til.id', '=', 't.to_inventory_location_id')
                ->leftJoin('branches as tb', 'tb.id', '=', 'til.branch_id')
                ->leftJoin('warehouses as tw', 'tw.id', '=', 'til.warehouse_id')
                ->whereIn('td.product_id', $productIds)
                ->whereIn('t.logistics_status', ['in_transit', 'partially_received'])
                ->whereNull('t.deleted_at')
                ->whereRaw('(td.quantity - COALESCE(ri.accounted, 0)) > 0')
                ->groupBy(
                    'td.product_id', 'td.product_variant_id', 't.to_inventory_location_id',
                    'til.name', 'til.branch_id', 'til.warehouse_id', 'tb.name', 'tw.name'
                )
                ->get([
                    'td.product_id',
                    'td.product_variant_id',
                    't.to_inventory_location_id',
                    'til.name as location_name',
                    'til.branch_id',
                    'til.warehouse_id',
                    'tb.name as branch_name',
                    'tw.name as warehouse_name',
                    DB::raw('SUM(td.quantity - COALESCE(ri.accounted, 0)) as quantity_in_transit'),
                ]);
        }

        $currentBranchId = $user->default_branch_id ? (int) $user->default_branch_id : null;

        $payload = $products->map(function ($product) use ($stocks, $transit, $currentBranchId, $legacyTotals, $legacyPendingByProduct) {
            $rows = $stocks->where('product_id', $product->id)->values()->map(function ($row) use ($currentBranchId) {
                $physical = round((float) $row->quantity, 3);
                $reserved = round((float) $row->reserved_quantity, 3);
                $available = max(0, round($physical - $reserved, 3));
                return [
                    'inventory_location_id' => (int) $row->inventory_location_id,
                    'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                    'variant_name' => $row->variant_name,
                    'location_name' => $row->location_name,
                    'location_type' => $row->location_type,
                    'owner_type' => $row->branch_id ? 'branch' : 'warehouse',
                    'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                    'branch_name' => $row->branch_name,
                    'branch_code' => $row->branch_code,
                    'warehouse_id' => $row->warehouse_id ? (int) $row->warehouse_id : null,
                    'warehouse_name' => $row->warehouse_name,
                    'physical' => $physical,
                    'reserved' => $reserved,
                    'available' => $available,
                    'is_sellable' => (bool) $row->is_sellable,
                    'is_quarantine' => (bool) $row->is_quarantine,
                    'is_current_branch' => $currentBranchId && (int) $row->branch_id === $currentBranchId,
                ];
            });

            $inTransit = $transit->where('product_id', $product->id)->values()->map(function ($row) use ($currentBranchId) {
                return [
                    'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                    'inventory_location_id' => $row->to_inventory_location_id ? (int) $row->to_inventory_location_id : null,
                    'location_name' => $row->location_name,
                    'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                    'branch_name' => $row->branch_name,
                    'warehouse_id' => $row->warehouse_id ? (int) $row->warehouse_id : null,
                    'warehouse_name' => $row->warehouse_name,
                    'quantity' => round((float) $row->quantity_in_transit, 3),
                    'is_current_branch' => $currentBranchId && (int) $row->branch_id === $currentBranchId,
                ];
            });

            // pending = Σ_almacén max(legacyWh − locationWh, 0). NO se compensa
            // entre almacenes ni con stock branch-native. NO entra en
            // company_available.
            $legacyTotal = round((float) ($legacyTotals[$product->id] ?? 0.0), 3);
            $warehouseLocationPhysical = round((float) $rows->whereNotNull('warehouse_id')->sum('physical'), 3);
            $legacyPendingQty = round((float) ($legacyPendingByProduct[$product->id] ?? 0.0), 3);

            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'locations' => $rows,
                'in_transit' => $inTransit,
                'company_available' => round($rows->where('is_quarantine', false)->sum('available'), 3),
                'legacy_total' => $legacyTotal,
                // Total físico location-native en ubicaciones de ALMACÉN (no branch).
                'warehouse_location_physical_total' => $warehouseLocationPhysical,
                // Informational only: legado sin reconciliar (suma por almacén,
                // sólo lado positivo). NO es stock location-native.
                'legacy_pending' => $legacyPendingQty > 0.0005,
                'legacy_pending_quantity' => $legacyPendingQty,
            ];
        })->values();

        return response()->json([
            'products' => $payload,
            'current_branch_id' => $currentBranchId,
        ]);
    }
}
