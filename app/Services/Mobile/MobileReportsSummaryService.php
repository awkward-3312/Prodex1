<?php

namespace App\Services\Mobile;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use App\Services\SalesReportingScopeService;
use App\utils\helpers;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Mobile reports summary. Every aggregate below is scoped through
 * SalesReportingScopeService exactly like mobile/sales - a non-owner user only
 * ever sums sales inside their own allowed branches/warehouses, never tenant-wide
 * figures. There is no separate "mobile" visibility rule for reports.
 */
class MobileReportsSummaryService
{
    public function __construct(private SalesReportingScopeService $scope) {}

    public function summary(User $user, string $from, string $to): array
    {
        $decimals = helpers::price_decimals();

        $totalsQuery = Sale::query()
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereBetween('sales.date', [$from, $to]);
        $this->scope->applyRecordVisibility($totalsQuery, $user, 'sales');
        $this->scope->apply($totalsQuery, $user, 'sales');
        $totals = $totalsQuery->selectRaw('COUNT(*) as count, COALESCE(SUM(GrandTotal),0) as total, COALESCE(SUM(TaxNet),0) as tax, COALESCE(SUM(paid_amount),0) as paid')->first();

        $count = (int) $totals->count;
        $total = (float) $totals->total;
        $tax = (float) $totals->tax;
        $paid = (float) $totals->paid;
        $pending = max(0.0, $total - $paid);
        $average = $count > 0 ? $total / $count : 0.0;

        $topProductsQuery = SaleDetail::query()
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereBetween('sales.date', [$from, $to]);
        $this->scope->applyRecordVisibility($topProductsQuery, $user, 'sales');
        $this->scope->apply($topProductsQuery, $user, 'sales');
        $topProducts = $topProductsQuery
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id as id, products.name as name, COALESCE(SUM(sale_details.quantity),0) as quantity')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get();

        $topCustomersQuery = Sale::query()
            ->join('clients', 'clients.id', '=', 'sales.client_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereBetween('sales.date', [$from, $to]);
        $this->scope->applyRecordVisibility($topCustomersQuery, $user, 'sales');
        $this->scope->apply($topCustomersQuery, $user, 'sales');
        $topCustomers = $topCustomersQuery
            ->groupBy('clients.id', 'clients.name')
            ->selectRaw('clients.id as id, clients.name as name, COALESCE(SUM(sales.GrandTotal),0) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $paymentQuery = DB::table('payment_sales')
            ->join('sales', 'sales.id', '=', 'payment_sales.sale_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
            ->whereNull('sales.deleted_at')
            ->whereNull('payment_sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereBetween('sales.date', [$from, $to]);
        $this->scope->applyRecordVisibility($paymentQuery, $user, 'sales');
        $this->scope->apply($paymentQuery, $user, 'sales');
        $payments = $paymentQuery
            ->groupBy('payment_sales.payment_method_id', 'payment_methods.name')
            ->selectRaw('payment_methods.name as name, COALESCE(SUM(payment_sales.montant),0) as total')
            ->orderByDesc('total')
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'sales_total' => number_format($total, $decimals, '.', ''),
            'sales_count' => $count,
            'average_sale' => number_format($average, $decimals, '.', ''),
            'tax_total' => number_format($tax, $decimals, '.', ''),
            'paid_total' => number_format($paid, $decimals, '.', ''),
            'pending_total' => number_format($pending, $decimals, '.', ''),
            'top_products' => $topProducts->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'quantity' => number_format((float) $row->quantity, 3, '.', ''),
            ])->all(),
            'top_customers' => $topCustomers->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'total' => number_format((float) $row->total, $decimals, '.', ''),
            ])->all(),
            'payment_methods' => $payments->map(fn ($row) => [
                'name' => $row->name ?? 'Otro',
                'total' => number_format((float) $row->total, $decimals, '.', ''),
            ])->all(),
        ];
    }

    /**
     * Real per-day sales totals for [$from, $to], same scoping as summary(). A day
     * with no completed sales simply totals 0.00 - never fabricated, never omitted.
     */
    public function dailyTotals(User $user, string $from, string $to): array
    {
        $decimals = helpers::price_decimals();

        $query = Sale::query()
            ->whereNull('sales.deleted_at')
            ->where('sales.statut', 'completed')
            ->whereBetween('sales.date', [$from, $to]);
        $this->scope->applyRecordVisibility($query, $user, 'sales');
        $this->scope->apply($query, $user, 'sales');
        $rows = $query
            ->groupBy('sales.date')
            ->selectRaw('sales.date as date, COALESCE(SUM(sales.GrandTotal),0) as total')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $totals = [];
        $cursor = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $totals[] = ['date' => $date, 'total' => number_format((float) ($rows[$date]->total ?? 0), $decimals, '.', '')];
            $cursor = $cursor->modify('+1 day');
        }

        return $totals;
    }
}
