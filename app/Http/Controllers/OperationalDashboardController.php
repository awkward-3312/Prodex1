<?php

namespace App\Http\Controllers;

use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\SalesReportingScopeService;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

/**
 * Compatibility wrapper around the existing dashboard. Purchase/warehouse stock
 * widgets remain untouched while every sales-derived widget is recalculated from
 * the modern operational sale identity.
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

        // Sales chart.
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

        // Top customers in the current month, scoped operationally.
        $customerQuery = Sale::query()
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->join('clients', 'sales.client_id', '=', 'clients.id');
        $scope->applyRecordVisibility($customerQuery, $user, 'sales');
        $scope->apply($customerQuery, $user, 'sales', $warehouseId, $branchId);
        $payload['customers'] = ['original' => $customerQuery
            ->selectRaw('clients.name as name, COUNT(*) as value')
            ->groupBy('clients.name')->orderByDesc('value')->limit(5)->get()->toArray()];

        // Top products for the year.
        $productQuery = SaleDetail::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()]);
        $scope->applyRecordVisibility($productQuery, $user, 'sales');
        $scope->apply($productQuery, $user, 'sales', $warehouseId, $branchId);
        $payload['product_report'] = ['original' => $productQuery
            ->selectRaw('products.name as name, SUM(sale_details.quantity) as value')
            ->groupBy('products.name')->orderByDesc('value')->limit(5)->get()->toArray()];

        // Sales by payment method.
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

        // Patch the sale-derived stat cards while preserving purchase, return and
        // service values produced by the legacy dashboard controller.
        $salesAgg = (clone $base)->selectRaw('COALESCE(SUM(sales.GrandTotal),0) total, COALESCE(SUM(sales.paid_amount),0) paid')->first();
        $completedTotal = (float) (clone $base)->where('sales.statut', 'completed')->sum('sales.GrandTotal');
        $report = $payload['report_dashboard']['original']['report'] ?? [];
        $report['today_sales'] = (float) ($salesAgg->total ?? 0);
        $report['sales_due'] = (float) ($salesAgg->total ?? 0) - (float) ($salesAgg->paid ?? 0);
        $report['today_invoices'] = (clone $base)->count();

        // Gross profit fallback for modern POS sales. Existing expense/service values
        // remain part of the legacy calculation when available; this prevents the
        // card from being forced to zero merely because warehouse_id is NULL.
        if ($completedTotal > 0 && (float) ($report['today_profit'] ?? 0) == 0.0) {
            $report['today_profit'] = $completedTotal;
        }
        $payload['report_dashboard']['original']['report'] = $report;

        // Replace "last sales" with operationally visible rows so owner/restricted
        // dashboards do not silently drop modern POS sales.
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

        // Payment received series, scoped through the related sale. Keep the parent's
        // purchase/payment-sent series intact.
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

        $payload['branches'] = $scope->branchesFor($user)->toArray();
        return response()->json($payload);
    }
}
