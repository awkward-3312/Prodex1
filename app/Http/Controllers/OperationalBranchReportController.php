<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Warehouse;
use App\Services\SalesReportingScopeService;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;

/**
 * Keeps the historical warehouse report UI usable while sales themselves are
 * branch-native. Purchase/return-purchase figures intentionally remain on the
 * warehouse model because those flows have not been migrated to branch stock.
 */
class OperationalBranchReportController extends OperationalReportController
{
    public function Warehouse_Report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);

        $saleQuery = Sale::query()->whereNull('sales.deleted_at');
        $scope->applyRecordVisibility($saleQuery, $user, 'sales');
        $scope->apply(
            $saleQuery,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        // Preserve the legacy purchase figures from the parent response and only
        // replace the fields whose ownership changed with the POS architecture.
        $legacy = parent::Warehouse_Report($request)->getData(true);
        $data = $legacy['data'] ?? [];
        $data['sales'] = (clone $saleQuery)->count();

        // A sale return linked to a modern sale follows that sale's branch scope.
        $returnQuery = SaleReturn::query()
            ->join('sales', 'sale_returns.sale_id', '=', 'sales.id')
            ->whereNull('sale_returns.deleted_at')
            ->whereNull('sales.deleted_at');
        $scope->applyRecordVisibility($returnQuery, $user, 'sales');
        $scope->apply(
            $returnQuery,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );
        $data['ReturnSale'] = $returnQuery->count();

        return response()->json([
            'data' => $data,
            'warehouses' => $legacy['warehouses'] ?? [],
            'branches' => $scope->branchesFor($user),
        ]);
    }

    public function Sales_Warehouse(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'WarehouseStock', Product::class);
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) ($request->page ?? 1));

        $sales = Sale::with(['client','warehouse','branch','inventoryLocation','cashDrawer','user'])
            ->whereNull('sales.deleted_at');
        $scope->applyRecordVisibility($sales, $user, 'sales');
        $scope->apply(
            $sales,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );
        $sales->when($request->filled('search'), function ($q) use ($request) {
            $s = trim((string) $request->search);
            $q->where(function ($qq) use ($s) {
                $qq->where('sales.Ref', 'like', "%{$s}%")
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('branch', fn ($branch) => $branch->where('name', 'like', "%{$s}%"));
            });
        });

        $totalRows = (clone $sales)->count();
        $rows = $sales->orderByDesc('sales.id')
            ->when($perPage !== -1, fn ($q) => $q->offset(($page - 1) * $perPage)->limit($perPage))
            ->get()->map(function ($sale) use ($scope) {
                return [
                    'id' => $sale->id,
                    'date' => $sale->date,
                    'Ref' => $sale->Ref,
                    'client_name' => optional($sale->client)->name ?: '—',
                    'warehouse_name' => $scope->displayLocation($sale),
                    'branch_id' => $sale->branch_id,
                    'branch_name' => optional($sale->branch)->name,
                    'inventory_location_name' => optional($sale->inventoryLocation)->name,
                    'cash_drawer_name' => optional($sale->cashDrawer)->name,
                    'statut' => $sale->statut,
                    'GrandTotal' => (float) $sale->GrandTotal,
                    'paid_amount' => (float) $sale->paid_amount,
                    'due' => (float) $sale->GrandTotal - (float) $sale->paid_amount,
                    'payment_status' => $sale->payment_statut,
                    'shipping_status' => $sale->shipping_status,
                ];
            })->values();

        return response()->json([
            'totalRows' => $totalRows,
            'sales' => $rows,
            'branches' => $scope->branchesFor($user),
        ]);
    }
}
