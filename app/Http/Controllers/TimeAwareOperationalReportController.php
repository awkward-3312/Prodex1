<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReportingScopeService;
use App\Services\UserOperationalAssignmentService;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

/**
 * Time-aware overrides for the two report screens that expose a date-time picker.
 * OperationalReportController remains the source for the other sales reports.
 */
class TimeAwareOperationalReportController extends OperationalReportController
{
    private function scopedSales(Request $request, string $from, string $to)
    {
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);
        $query = Sale::query()
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [$from, $to]);
        $scope->applyRecordVisibility($query, $user, 'sales');
        $scope->apply(
            $query,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        return $this->applyTimeRange($query, $request, $from, $to);
    }

    private function applyTimeRange($query, Request $request, string $from, string $to)
    {
        if (! $request->filled('start_time') && ! $request->filled('end_time')) return $query;

        $startTime = $request->filled('start_time') ? Carbon::parse($request->start_time)->format('H:i:s') : '00:00:00';
        $endTime = $request->filled('end_time') ? Carbon::parse($request->end_time)->format('H:i:s') : '23:59:59';

        return $query->whereRaw(
            "TIMESTAMP(sales.date, COALESCE(NULLIF(sales.time, ''), '00:00:00')) BETWEEN ? AND ?",
            [$from.' '.$startTime, $to.' '.$endTime]
        );
    }

    private function normalizedSale(Sale $sale): array
    {
        $scope = app(SalesReportingScopeService::class);
        return [
            'id' => $sale->id,
            'sale_id' => $sale->id,
            'date' => $sale->date,
            'time' => $sale->time,
            'Ref' => $sale->Ref,
            'client_name' => optional($sale->client)->name ?: '—',
            'warehouse_name' => $scope->displayLocation($sale),
            'branch_id' => $sale->branch_id,
            'branch_name' => optional($sale->branch)->name,
            'inventory_location_name' => optional($sale->inventoryLocation)->name,
            'cash_drawer_name' => optional($sale->cashDrawer)->name,
            'user_name' => optional($sale->user)->username ?: '—',
            'username' => optional($sale->user)->username ?: '—',
            'statut' => $sale->statut,
            'GrandTotal' => (float) $sale->GrandTotal,
            'paid_amount' => (float) $sale->paid_amount,
            'due' => (float) $sale->GrandTotal - (float) $sale->paid_amount,
            'payment_status' => $sale->payment_statut,
            'shipping_status' => $sale->shipping_status,
        ];
    }

    public function Report_Sales(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_sales', Sale::class);
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : '2000-01-01';
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();

        $query = $this->scopedSales($request, $from, $to)
            ->with(['client', 'warehouse', 'branch', 'inventoryLocation', 'cashDrawer', 'user'])
            ->when($request->filled('Ref'), fn ($q) => $q->where('sales.Ref', 'like', '%'.$request->Ref.'%'))
            ->when($request->filled('client_id'), fn ($q) => $q->where('sales.client_id', $request->client_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('sales.user_id', $request->user_id))
            ->when($request->filled('statut'), fn ($q) => $q->where('sales.statut', $request->statut))
            ->when($request->filled('payment_statut'), fn ($q) => $q->where('sales.payment_statut', $request->payment_statut))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->search);
                $q->where(function ($inner) use ($search) {
                    $inner->where('sales.Ref', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($x) => $x->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('user', fn ($x) => $x->where('username', 'like', "%{$search}%"))
                        ->orWhereHas('branch', fn ($x) => $x->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('warehouse', fn ($x) => $x->where('name', 'like', "%{$search}%"));
                });
            });

        $totalRows = (clone $query)->count();
        $allowedSorts = ['id', 'date', 'Ref', 'statut', 'GrandTotal', 'paid_amount', 'payment_statut'];
        $sort = in_array($request->SortField, $allowedSorts, true) ? $request->SortField : 'id';
        $direction = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $rowsQuery = $query->orderBy("sales.{$sort}", $direction);
        if ($perPage !== -1) $rowsQuery->offset(($page - 1) * $perPage)->limit($perPage);
        $rows = $rowsQuery->get()->map(fn ($sale) => $this->normalizedSale($sale))->values();

        $user = $request->user('api');
        $legacyIds = app(UserOperationalAssignmentService::class)->allowedWarehouseIds($user);
        $warehouses = Warehouse::whereNull('deleted_at')
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $legacyIds))
            ->get(['id', 'name']);

        return response()->json([
            'sales' => $rows,
            'totalRows' => $totalRows,
            'customers' => Client::whereNull('deleted_at')->get(['id', 'name']),
            'warehouses' => $warehouses,
            'branches' => app(SalesReportingScopeService::class)->branchesFor($user),
            'sellers' => User::whereNull('deleted_at')->get(['id', 'username']),
        ]);
    }

    public function seller_report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'seller_report', User::class);
        $start = $request->filled('start_date') ? Carbon::parse($request->start_date)->toDateString() : '2000-01-01';
        $end = $request->filled('end_date') ? Carbon::parse($request->end_date)->toDateString() : now()->toDateString();
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));

        $usersQuery = User::whereNull('deleted_at')
            ->when($request->filled('search'), fn ($q) => $q->where('username', 'like', '%'.$request->search.'%'));
        $totalRows = (clone $usersQuery)->count();
        $users = $usersQuery->orderBy('id')
            ->when($perPage !== -1, fn ($q) => $q->offset(($page - 1) * $perPage)->limit($perPage))
            ->get();
        $methods = PaymentMethod::whereNull('deleted_at')->pluck('name', 'id');
        $report = [];

        foreach ($users as $seller) {
            $sales = $this->scopedSales($request, $start, $end)->where('sales.user_id', $seller->id);
            $row = [
                'id' => $seller->id,
                'username' => $seller->username,
                'total_sales' => number_format((float) $sales->sum('sales.GrandTotal'), helpers::price_decimals(), '.', ','),
            ];
            foreach ($methods as $name) $row[$name] = 0;

            $payments = DB::table('payment_sales')
                ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
                ->whereNull('payment_sales.deleted_at')
                ->whereNull('sales.deleted_at')
                ->where('sales.user_id', $seller->id)
                ->whereBetween('payment_sales.date', [$start, $end]);
            $scope = app(SalesReportingScopeService::class);
            $scope->applyRecordVisibility($payments, $request->user('api'), 'sales');
            $scope->apply(
                $payments,
                $request->user('api'),
                'sales',
                $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            $this->applyTimeRange($payments, $request, $start, $end);

            foreach ($payments->select('payment_sales.payment_method_id', DB::raw('SUM(payment_sales.montant) total'))->groupBy('payment_sales.payment_method_id')->get() as $payment) {
                $name = $methods[$payment->payment_method_id] ?? 'Unknown';
                $row[$name] = number_format((float) $payment->total, helpers::price_decimals(), '.', ',');
            }
            $report[] = $row;
        }

        $user = $request->user('api');
        $legacyIds = app(UserOperationalAssignmentService::class)->allowedWarehouseIds($user);
        $warehouses = Warehouse::whereNull('deleted_at')
            ->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $legacyIds))
            ->get(['id', 'name']);

        return response()->json([
            'report' => $report,
            'warehouses' => $warehouses,
            'branches' => app(SalesReportingScopeService::class)->branchesFor($user),
            'paymentMethods' => array_values($methods->toArray()),
            'totalRows' => $totalRows,
        ]);
    }
}
