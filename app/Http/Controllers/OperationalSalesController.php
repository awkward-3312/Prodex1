<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\PaymentSetting;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Warehouse;
use App\Services\SalesReportingScopeService;
use App\Services\UserOperationalAssignmentService;
use App\utils\helpers;
use Illuminate\Http\Request;

/**
 * Read-only modern index for sales. Write/update/delete methods remain on the
 * historical SalesController; this controller only prevents location-native POS
 * sales from disappearing from the sales list.
 */
class OperationalSalesController extends SalesController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Sale::class);

        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));
        $offset = $perPage === -1 ? 0 : ($page - 1) * $perPage;
        $sortField = (string) ($request->SortField ?: 'id');
        $sortDir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'Ref', 'statut', 'GrandTotal', 'paid_amount', 'payment_statut', 'shipping_status'];
        if (! in_array($sortField, $allowedSorts, true)) $sortField = 'id';

        $sales = Sale::query()
            ->with(['facture', 'client', 'warehouse', 'branch', 'inventoryLocation', 'cashDrawer', 'user', 'sarFiscalDocument'])
            ->whereNull('sales.deleted_at');

        $scope->applyRecordVisibility($sales, $user, 'sales');
        $scope->apply(
            $sales,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        $sales
            ->when($request->filled('Ref'), fn ($q) => $q->where('sales.Ref', 'like', '%'.$request->Ref.'%'))
            ->when($request->filled('statut'), fn ($q) => $q->where('sales.statut', $request->statut))
            ->when($request->filled('client_id'), fn ($q) => $q->where('sales.client_id', $request->client_id))
            ->when($request->filled('payment_statut'), fn ($q) => $q->where('sales.payment_statut', $request->payment_statut))
            ->when($request->filled('shipping_status'), fn ($q) => $q->where('sales.shipping_status', $request->shipping_status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->search);
                $q->where(function ($qq) use ($search) {
                    $qq->where('sales.Ref', 'like', "%{$search}%")
                        ->orWhere('sales.statut', 'like', "%{$search}%")
                        ->orWhere('sales.payment_statut', 'like', "%{$search}%")
                        ->orWhere('sales.shipping_status', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('branch', fn ($branch) => $branch->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('inventoryLocation', fn ($location) => $location->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('warehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$search}%"));
                });
            });

        $totalRows = (clone $sales)->count();
        if ($perPage === -1) $perPage = max(1, $totalRows);

        $rows = $sales->orderBy("sales.{$sortField}", $sortDir)
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $data = [];
        foreach ($rows as $sale) {
            $grandTotal = (float) $sale->GrandTotal;
            $paid = (float) $sale->paid_amount;
            $return = SaleReturn::where('sale_id', $sale->id)->whereNull('deleted_at')->first();

            $data[] = [
                'id' => $sale->id,
                'date' => trim($sale->date.' '.$sale->time),
                'Ref' => $sale->Ref,
                'created_by' => optional($sale->user)->username ?: '—',
                'statut' => $sale->statut,
                'shipping_status' => $sale->shipping_status,
                'discount' => $sale->discount,
                'shipping' => $sale->shipping,
                // Compatibility key consumed by the existing table. For a modern
                // sale it intentionally displays the branch, never a fake warehouse.
                'warehouse_name' => $scope->displayLocation($sale),
                'branch_id' => $sale->branch_id,
                'branch_name' => optional($sale->branch)->name,
                'inventory_location_id' => $sale->inventory_location_id,
                'inventory_location_name' => optional($sale->inventoryLocation)->name,
                'cash_drawer_id' => $sale->cash_drawer_id,
                'cash_drawer_name' => optional($sale->cashDrawer)->name,
                'client_id' => optional($sale->client)->id,
                'client_name' => optional($sale->client)->name ?: '—',
                'client_email' => optional($sale->client)->email,
                'client_tele' => optional($sale->client)->phone,
                'client_code' => optional($sale->client)->code,
                'client_adr' => optional($sale->client)->adresse,
                'GrandTotal' => number_format($grandTotal, helpers::price_decimals(), '.', ''),
                'paid_amount' => number_format($paid, helpers::price_decimals(), '.', ''),
                'due' => number_format($grandTotal - $paid, helpers::price_decimals(), '.', ''),
                'payment_status' => $sale->payment_statut,
                'fiscal_number' => optional($sale->sarFiscalDocument)->fiscal_number,
                'fiscal_status' => optional($sale->sarFiscalDocument)->status,
                'sale_has_return' => $return ? 'yes' : 'no',
                'salereturn_id' => $return?->id,
                'documents_count' => $sale->documents()->whereNull('deleted_at')->count(),
            ];
        }

        $assignment = app(UserOperationalAssignmentService::class);
        $legacyWarehouseIds = $assignment->allowedWarehouseIds($user);
        $warehouses = Warehouse::whereNull('deleted_at')
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $legacyWarehouseIds))
            ->get(['id', 'name']);

        return response()->json([
            'stripe_key' => PaymentSetting::current()->stripe_key,
            'totalRows' => $totalRows,
            'sales' => $data,
            'customers' => Client::whereNull('deleted_at')->get(['id', 'name']),
            'warehouses' => $warehouses,
            'branches' => $scope->branchesFor($user),
            'accounts' => Account::whereNull('deleted_at')->orderByDesc('id')->get(['id', 'account_name']),
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->get(['id', 'name']),
        ]);
    }
}
