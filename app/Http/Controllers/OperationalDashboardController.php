<?php

namespace App\Http\Controllers;

use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\SalesReportingScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Compatibility wrapper around the existing dashboard. Purchase/warehouse stock
 * widgets remain untouched while every directly sales-derived widget is
 * recalculated from the modern operational sale identity.
 */
class OperationalDashboardController extends DashboardController
{
    public function dashboard_data(Request $request)
    {
        $response = parent::dashboard_data($request);
        $payload = $response->getData(true);
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);

        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->subDays(6)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        $base = Sale::query()->whereNull('sales.deleted_at')->whereBetween('sales.date', [$from, $to]);
        $scope->applyRecordVisibility($base, $user, 'sales');
        $scope->apply($base, $user, 'sales', $warehouseId, $branchId);

        $days = [];
        $values = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        $daily = (clone $base)
            ->selectRaw('sales.date as d, COALESCE(SUM(sales.GrandTotal),0) as total')
            ->groupBy('sales.date')
            ->pluck('total', 'd');
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $days[] = $day;
            $values[] = (float) ($daily[$day] ?? 0);
            $cursor->addDay();
        }
        $payload['sales'] = ['original' => ['data' => $values, 'days' => $days]];

        $customerQuery = Sale::query()
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->join('clients', 'sales.client_id', '=', 'clients.id');
        $scope->applyRecordVisibility($customerQuery, $user, 'sales');
        $scope->apply($customerQuery, $user, 'sales', $warehouseId, $branchId);
        $payload['customers'] = ['original' => $customerQuery
            ->selectRaw('clients.name as name, COUNT(*) as value')
            ->groupBy('clients.name')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (float) $row->value])
            ->values()->all()];

        $productQuery = SaleDetail::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()]);
        $scope->applyRecordVisibility($productQuery, $user, 'sales');
        $scope->apply($productQuery, $user, 'sales', $warehouseId, $branchId);
        $payload['product_report'] = ['original' => $productQuery
            ->selectRaw('products.name as name, COALESCE(SUM(sale_details.quantity),0) as value')
            ->groupBy('products.name')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (float) $row->value])
            ->values()->all()];

        $payments = PaymentSale::query()
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->leftJoin('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->whereBetween('payment_sales.date', [$from, $to]);
        $scope->applyRecordVisibility($payments, $user, 'sales');
        $scope->apply($payments, $user, 'sales', $warehouseId, $branchId);
        $paymentRows = $payments
            ->selectRaw("COALESCE(payment_methods.name, '---') as name, SUM(payment_sales.montant) as amount")
            ->groupBy('name')->orderByDesc('amount')->get();
        $paymentTotal = max(0.0, (float) $paymentRows->sum('amount'));
        $colors = ['orange', 'blue', 'green', 'grey', 'yellow', 'purple', 'cyan'];
        $payload['sales_by_payment'] = $paymentRows->values()->map(function ($row, $index) use ($paymentTotal, $colors) {
            $amount = (float) $row->amount;
            return [
                'name' => $row->name,
                'amount' => $amount,
                'percentage' => $paymentTotal > 0 ? round(($amount / $paymentTotal) * 100, 2) : 0,
                'color' => $colors[$index % count($colors)],
            ];
        })->all();

        $salesAgg = (clone $base)->selectRaw('COALESCE(SUM(sales.GrandTotal),0) total, COALESCE(SUM(sales.paid_amount),0) paid')->first();
        $report = $payload['report_dashboard']['original']['report'] ?? [];
        $report['today_sales'] = (float) ($salesAgg->total ?? 0);
        $report['sales_due'] = (float) ($salesAgg->total ?? 0) - (float) ($salesAgg->paid ?? 0);
        $report['today_invoices'] = (clone $base)->count();

        foreach (['return_purchases', 'today_purchases', 'return_sales', 'purchase_due', 'profit'] as $key) {
            if (array_key_exists($key, $report) && is_string($report[$key])) {
                $report[$key] = (float) str_replace(',', '', $report[$key]);
            }
        }

        $payload['report_dashboard']['original']['report'] = $report;

        $lastSales = Sale::with(['client', 'branch', 'warehouse'])
            ->whereNull('sales.deleted_at');
        $scope->applyRecordVisibility($lastSales, $user, 'sales');
        $scope->apply($lastSales, $user, 'sales', $warehouseId, $branchId);
        $payload['report_dashboard']['original']['last_sales'] = $lastSales
            ->orderByDesc('sales.id')->limit(5)->get()->map(function ($sale) use ($scope) {
                return [
                    'id' => $sale->id,
                    'Ref' => $sale->Ref,
                    'client_name' => optional($sale->client)->name ?: '—',
                    'warehouse_name' => $scope->displayLocation($sale),
                    'GrandTotal' => (float) $sale->GrandTotal,
                    'paid_amount' => (float) $sale->paid_amount,
                    'due' => (float) $sale->GrandTotal - (float) $sale->paid_amount,
                    'payment_status' => $sale->payment_statut,
                    'statut' => $sale->statut,
                ];
            })->values()->all();

        if (isset($payload['payments']['original']['days'])) {
            $received = PaymentSale::query()
                ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
                ->whereNull('payment_sales.deleted_at')->whereNull('sales.deleted_at')
                ->whereBetween('payment_sales.date', [$from, $to]);
            $scope->applyRecordVisibility($received, $user, 'sales');
            $scope->apply($received, $user, 'sales', $warehouseId, $branchId);
            $byDay = $received->selectRaw('payment_sales.date as d, SUM(payment_sales.montant) as total')
                ->groupBy('payment_sales.date')->pluck('total', 'd');
            $payload['payments']['original']['payment_received'] = collect($payload['payments']['original']['days'])
                ->map(fn ($day) => (float) ($byDay[$day] ?? 0))->all();
        }

        // Owner/audit breakdowns. These use the exact same operational scope as the
        // headline totals, so cashier and branch totals reconcile with the dashboard.
        $cashierQuery = clone $base;
        $payload['sales_by_cashier'] = $cashierQuery
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->selectRaw("sales.user_id as user_id, COALESCE(users.username, '—') as cashier_name")
            ->selectRaw('COUNT(sales.id) as invoices')
            ->selectRaw('COALESCE(SUM(sales.GrandTotal),0) as total_sales')
            ->selectRaw('COALESCE(SUM(sales.paid_amount),0) as paid_amount')
            ->groupBy('sales.user_id', 'users.username')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id ? (int) $row->user_id : null,
                'cashier_name' => (string) $row->cashier_name,
                'invoices' => (int) $row->invoices,
                'total_sales' => (float) $row->total_sales,
                'paid_amount' => (float) $row->paid_amount,
                'due' => max(0, (float) $row->total_sales - (float) $row->paid_amount),
            ])->values()->all();

        $branchQuery = clone $base;
        $payload['sales_by_branch'] = $branchQuery
            ->leftJoin('branches', 'sales.branch_id', '=', 'branches.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->selectRaw('sales.branch_id as branch_id')
            ->selectRaw("COALESCE(branches.name, warehouses.name, '—') as branch_name")
            ->selectRaw('COUNT(sales.id) as invoices')
            ->selectRaw('COALESCE(SUM(sales.GrandTotal),0) as total_sales')
            ->groupBy('sales.branch_id', 'branches.name', 'warehouses.name')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn ($row) => [
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'branch_name' => (string) $row->branch_name,
                'invoices' => (int) $row->invoices,
                'total_sales' => (float) $row->total_sales,
            ])->values()->all();

        $payload['branches'] = $scope->branchesFor($user)->toArray();
        return response()->json($payload);
    }
}
