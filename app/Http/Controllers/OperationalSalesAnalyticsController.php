<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Warehouse;
use App\Services\SalesReportingScopeService;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Branch-aware sales analytics used by the 3D dashboard and real-time counter.
 *
 * Both screens historically filtered only by sales.warehouse_id. Modern POS sales
 * are branch/location native and may legitimately have a NULL legacy warehouse_id,
 * so every sales query here is routed through SalesReportingScopeService. Historical
 * warehouse-only sales remain visible through the service's compatibility branch.
 */
class OperationalSalesAnalyticsController extends Controller
{
    private const TOP_N_PRODUCTS = 12;
    private const TOP_N_CLIENTS = 10;
    private const CACHE_TTL_SECONDS = 60;

    public function sales3dData(Request $request)
    {
        $user = $request->user('api');
        $this->requireRolePermission($user, 'sales_3d_dashboard');

        [$start, $end] = $this->resolveDateRange($request->input('from'), $request->input('to'));
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $scope = app(SalesReportingScopeService::class);

        $cacheKey = sprintf(
            'sales3d-operational:%d:%s:%s:%d:%d:%s:%s:%d',
            $user->id,
            $start->toDateString(),
            $end->toDateString(),
            (int) ($warehouseId ?: 0),
            (int) ($branchId ?: 0),
            md5(implode(',', $scope->allowedBranchIds($user))),
            md5(implode(',', $scope->allowedWarehouseIds($user))),
            $user->hasRecordView() ? 1 : 0
        );

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($request, $start, $end) {
            return [
                'sales_by_month_warehouse' => $this->salesByMonthLocation($request, $start, $end),
                'top_products_by_month' => $this->topProductsByMonth($request, $start, $end),
                'product_scatter' => $this->productScatter($request, $start, $end),
                'payment_methods' => $this->paymentMethodsBreakdown($request, $start, $end),
                'hour_dow_heatmap' => $this->hourDayOfWeekHeatmap($request, $start, $end),
                'top_clients' => $this->topClients($request, $start, $end),
                'kpis' => $this->kpis($request, $start, $end),
            ];
        });

        return response()->json(array_merge($payload, [
            'warehouses' => $this->warehouseOptions($request),
            'branches' => $scope->branchesFor($user),
            'range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
        ]));
    }

    public function realTimeData(Request $request)
    {
        $user = $request->user('api');
        $this->requireRolePermission($user, 'real_time_sales_counter');

        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();
        $todayBase = $this->salesBase($request, $today, $today);
        $yesterdayBase = $this->salesBase($request, $yesterday, $yesterday);
        $scope = app(SalesReportingScopeService::class);

        $todayCount = (clone $todayBase)->count();
        $todayTotal = (float) ((clone $todayBase)->sum('sales.GrandTotal') ?? 0);
        $paidTotal = (float) ((clone $todayBase)->sum('sales.paid_amount') ?? 0);
        $dueTotal = max(0, $todayTotal - $paidTotal);
        $yesterdayTotal = (float) ((clone $yesterdayBase)->sum('sales.GrandTotal') ?? 0);

        $statusCounts = (clone $todayBase)
            ->select('sales.payment_statut', DB::raw('COUNT(*) as c'))
            ->groupBy('sales.payment_statut')
            ->pluck('c', 'sales.payment_statut');

        $lastSale = (clone $todayBase)
            ->orderByDesc('sales.date')
            ->orderByRaw("COALESCE(sales.time, '00:00:00') DESC")
            ->orderByDesc('sales.id')
            ->first();

        $lastSaleAt = $lastSale ? $this->saleDateTime($lastSale->date, $lastSale->time) : null;

        $hourlyRows = (clone $todayBase)
            ->selectRaw("HOUR(COALESCE(NULLIF(sales.time, ''), '00:00:00')) as hour")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(sales.GrandTotal), 0) as total')
            ->groupBy(DB::raw("HOUR(COALESCE(NULLIF(sales.time, ''), '00:00:00'))"))
            ->get();

        $hourly = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourly[$hour] = ['hour' => $hour, 'count' => 0, 'total' => 0.0];
        }
        foreach ($hourlyRows as $row) {
            $hour = (int) $row->hour;
            if ($hour >= 0 && $hour < 24) {
                $hourly[$hour]['count'] = (int) $row->count;
                $hourly[$hour]['total'] = (float) $row->total;
            }
        }

        $recentSales = (clone $todayBase)
            ->with(['client', 'warehouse', 'branch', 'inventoryLocation'])
            ->orderByDesc('sales.date')
            ->orderByRaw("COALESCE(sales.time, '00:00:00') DESC")
            ->orderByDesc('sales.id')
            ->limit(10)
            ->get()
            ->map(function (Sale $sale) use ($scope) {
                $grand = (float) $sale->GrandTotal;
                $paid = (float) $sale->paid_amount;

                return [
                    'id' => $sale->id,
                    'ref' => $sale->Ref,
                    'date' => $this->saleDateTime($sale->date, $sale->time),
                    'grand_total' => $grand,
                    'paid_amount' => $paid,
                    'due_amount' => max(0, $grand - $paid),
                    'payment_status' => $sale->payment_statut,
                    'is_pos' => (int) $sale->is_pos,
                    'client_name' => optional($sale->client)->name,
                    'warehouse_name' => $scope->displayLocation($sale),
                    'branch_id' => $sale->branch_id,
                    'branch_name' => optional($sale->branch)->name,
                    'inventory_location_name' => optional($sale->inventoryLocation)->name,
                ];
            })->values();

        $todaySaleIds = (clone $todayBase)->pluck('sales.id');
        $topProducts = collect();
        if ($todaySaleIds->isNotEmpty()) {
            $topProducts = SaleDetail::query()
                ->leftJoin('products', 'sale_details.product_id', '=', 'products.id')
                ->whereIn('sale_details.sale_id', $todaySaleIds)
                ->select('products.id as product_id', 'products.name as product_name')
                ->selectRaw('SUM(sale_details.quantity) as quantity')
                ->selectRaw('SUM(sale_details.total) as total')
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name ?: '-',
                    'quantity' => (float) $row->quantity,
                    'total' => (float) $row->total,
                ]);
        }

        $salesByLocation = (clone $todayBase)
            ->leftJoin('branches', 'sales.branch_id', '=', 'branches.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->selectRaw('CASE WHEN sales.branch_id IS NOT NULL THEN sales.branch_id ELSE NULL END as branch_id')
            ->selectRaw('CASE WHEN sales.branch_id IS NULL THEN sales.warehouse_id ELSE NULL END as warehouse_id')
            ->selectRaw("COALESCE(branches.name, warehouses.name, '—') as location_name")
            ->selectRaw('COUNT(*) as total_invoice')
            ->selectRaw('COALESCE(SUM(sales.GrandTotal), 0) as amount')
            ->selectRaw("MAX(CONCAT(sales.date, ' ', COALESCE(sales.time, '00:00:00'))) as last_sale")
            ->groupBy(
                DB::raw('CASE WHEN sales.branch_id IS NOT NULL THEN sales.branch_id ELSE NULL END'),
                DB::raw('CASE WHEN sales.branch_id IS NULL THEN sales.warehouse_id ELSE NULL END'),
                DB::raw("COALESCE(branches.name, warehouses.name, '—')")
            )
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'warehouse_id' => $row->warehouse_id ? (int) $row->warehouse_id : null,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'name' => $row->location_name ?: '—',
                'total_invoice' => (int) $row->total_invoice,
                'amount' => (float) $row->amount,
                'last_sale' => $row->last_sale ? Carbon::parse($row->last_sale)->toIso8601String() : null,
            ])->values();

        return response()->json([
            'today_count' => $todayCount,
            'today_total' => $todayTotal,
            'today_paid' => $paidTotal,
            'today_due' => $dueTotal,
            'last_sale_at' => $lastSaleAt,
            'yesterday_total' => $yesterdayTotal,
            'payment_status_counts' => [
                'paid' => (int) ($statusCounts['paid'] ?? 0),
                'partial' => (int) ($statusCounts['partial'] ?? 0),
                'unpaid' => (int) ($statusCounts['unpaid'] ?? 0),
            ],
            'hourly' => array_values($hourly),
            'recent_sales' => $recentSales,
            'top_products' => $topProducts,
            'sales_by_location' => $salesByLocation,
            'warehouses' => $this->warehouseOptions($request),
            'branches' => $scope->branchesFor($user),
            'selected_warehouse_id' => $request->filled('warehouse_id') ? (int) $request->warehouse_id : 0,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function salesBase(Request $request, Carbon $start, Carbon $end, bool $completedOnly = false): Builder
    {
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);
        $query = Sale::query()
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [$start->toDateString(), $end->toDateString()]);

        if ($completedOnly) {
            $query->where('sales.statut', 'completed');
        }

        $scope->applyRecordVisibility($query, $user, 'sales');
        $scope->apply(
            $query,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        return $query;
    }

    private function salesByMonthLocation(Request $request, Carbon $start, Carbon $end): array
    {
        $rows = $this->salesBase($request, $start, $end, true)
            ->leftJoin('branches', 'sales.branch_id', '=', 'branches.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->selectRaw("DATE_FORMAT(sales.date, '%Y-%m') as month")
            ->selectRaw("COALESCE(branches.name, warehouses.name, '—') as location_name")
            ->selectRaw('SUM(sales.GrandTotal) as total')
            ->groupBy(DB::raw("DATE_FORMAT(sales.date, '%Y-%m')"), DB::raw("COALESCE(branches.name, warehouses.name, '—')"))
            ->orderBy('month')
            ->get();

        $months = $rows->pluck('month')->unique()->values();
        $locations = $rows->pluck('location_name')->unique()->values();
        $matrix = [];
        foreach ($rows as $row) {
            $matrix[] = [
                $months->search($row->month),
                $locations->search($row->location_name),
                round((float) $row->total, 2),
            ];
        }

        return ['months' => $months, 'warehouses' => $locations, 'data' => $matrix];
    }

    private function topProductsByMonth(Request $request, Carbon $start, Carbon $end): array
    {
        $saleIds = $this->salesBase($request, $start, $end, true)->select('sales.id');
        $topProductIds = SaleDetail::query()
            ->whereIn('sale_id', $saleIds)
            ->select('product_id', DB::raw('SUM(total) as revenue'))
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->limit(self::TOP_N_PRODUCTS)
            ->pluck('product_id');

        if ($topProductIds->isEmpty()) {
            return ['months' => [], 'products' => [], 'data' => []];
        }

        $rows = SaleDetail::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereIn('sales.id', $this->salesBase($request, $start, $end, true)->select('sales.id'))
            ->whereIn('sale_details.product_id', $topProductIds)
            ->selectRaw("DATE_FORMAT(sales.date, '%Y-%m') as month")
            ->addSelect('sale_details.product_id', 'products.name as product_name')
            ->selectRaw('SUM(sale_details.total) as revenue')
            ->groupBy(DB::raw("DATE_FORMAT(sales.date, '%Y-%m')"), 'sale_details.product_id', 'products.name')
            ->orderBy('month')
            ->get();

        $months = $rows->pluck('month')->unique()->values();
        $productIds = $rows->pluck('product_id')->unique()->values();
        $products = $productIds->map(function ($id) use ($rows) {
            return optional($rows->firstWhere('product_id', $id))->product_name ?: ('#'.$id);
        })->values();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[] = [
                $months->search($row->month),
                $productIds->search($row->product_id),
                round((float) $row->revenue, 2),
            ];
        }

        return ['months' => $months, 'products' => $products, 'data' => $matrix];
    }

    private function productScatter(Request $request, Carbon $start, Carbon $end): array
    {
        return SaleDetail::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereIn('sales.id', $this->salesBase($request, $start, $end, true)->select('sales.id'))
            ->select('sale_details.product_id', 'products.name as product_name')
            ->selectRaw('SUM(sale_details.quantity) as quantity')
            ->selectRaw('AVG(sale_details.price) as avg_price')
            ->selectRaw('SUM(sale_details.total) as revenue')
            ->groupBy('sale_details.product_id', 'products.name')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                (float) $row->quantity,
                round((float) $row->avg_price, 2),
                round((float) $row->revenue, 2),
                $row->product_name,
            ])->values()->all();
    }

    private function paymentMethodsBreakdown(Request $request, Carbon $start, Carbon $end): array
    {
        $query = DB::table('payment_sales')
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->leftJoin('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [$start->toDateString(), $end->toDateString()])
            ->where('sales.statut', 'completed');

        $scope = app(SalesReportingScopeService::class);
        $scope->applyRecordVisibility($query, $request->user('api'), 'sales');
        $scope->apply(
            $query,
            $request->user('api'),
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );

        return $query
            ->selectRaw("COALESCE(payment_methods.name, 'Other') as method")
            ->selectRaw('SUM(payment_sales.montant) as amount')
            ->groupBy('payment_methods.name')
            ->get()
            ->map(fn ($row) => ['name' => $row->method ?: 'Other', 'value' => round((float) $row->amount, 2)])
            ->values()->all();
    }

    private function hourDayOfWeekHeatmap(Request $request, Carbon $start, Carbon $end): array
    {
        return $this->salesBase($request, $start, $end, true)
            ->selectRaw('DAYOFWEEK(sales.date) as dow')
            ->selectRaw("HOUR(COALESCE(NULLIF(sales.time, ''), '00:00:00')) as hour")
            ->selectRaw('SUM(sales.GrandTotal) as total')
            ->groupBy(DB::raw('DAYOFWEEK(sales.date)'), DB::raw("HOUR(COALESCE(NULLIF(sales.time, ''), '00:00:00'))"))
            ->get()
            ->map(fn ($row) => [
                (int) $row->hour,
                ((int) $row->dow) - 1,
                round((float) $row->total, 2),
            ])->values()->all();
    }

    private function topClients(Request $request, Carbon $start, Carbon $end): array
    {
        return $this->salesBase($request, $start, $end, true)
            ->join('clients', 'sales.client_id', '=', 'clients.id')
            ->select('clients.name as client_name')
            ->selectRaw('SUM(sales.GrandTotal) as total')
            ->selectRaw('COUNT(sales.id) as orders')
            ->groupBy('clients.id', 'clients.name')
            ->orderByDesc('total')
            ->limit(self::TOP_N_CLIENTS)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->client_name,
                'value' => round((float) $row->total, 2),
                'orders' => (int) $row->orders,
            ])->values()->all();
    }

    private function kpis(Request $request, Carbon $start, Carbon $end): array
    {
        $row = $this->salesBase($request, $start, $end, true)
            ->selectRaw('COUNT(sales.id) as orders')
            ->selectRaw('SUM(sales.GrandTotal) as revenue')
            ->selectRaw('AVG(sales.GrandTotal) as avg_order')
            ->selectRaw('COUNT(DISTINCT sales.client_id) as customers')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'avg_order' => round((float) ($row->avg_order ?? 0), 2),
            'customers' => (int) ($row->customers ?? 0),
        ];
    }

    private function warehouseOptions(Request $request)
    {
        $user = $request->user('api');
        $ids = app(SalesReportingScopeService::class)->allowedWarehouseIds($user);

        return Warehouse::whereNull('deleted_at')
            ->when((int) $user->role_id !== 1, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function resolveDateRange(?string $from, ?string $to): array
    {
        if ($from && $to) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        }

        $end = Carbon::today()->endOfDay();
        return [$end->copy()->subDays(29)->startOfDay(), $end];
    }

    private function saleDateTime($date, $time): ?string
    {
        if (! $date) return null;

        return Carbon::parse(trim($date.' '.($time ?: '00:00:00')))->toIso8601String();
    }

    private function requireRolePermission($user, string $permission): void
    {
        $role = $user?->roles()->first();
        abort_if(! $role || ! $role->inRole($permission), 403, 'Unauthorized');
    }
}
