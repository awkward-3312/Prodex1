<?php

namespace App\Services\Mobile;

use App\Models\Sale;
use App\Models\User;
use App\Services\SalesReportingScopeService;
use App\utils\helpers;

/**
 * Read-only sales history for Mobile. Reuses the same visibility rules and
 * money/status semantics as the web sales index (OperationalSalesController):
 * payment_statut is read verbatim (never re-derived), due is always
 * GrandTotal - paid_amount, and visibility is branch/warehouse scoped via
 * SalesReportingScopeService — there is no separate "mobile" business rule.
 */
class MobileSalesHistoryService
{
    public function __construct(private SalesReportingScopeService $scope) {}

    public function history(
        User $user,
        ?string $search = null,
        ?string $paymentStatus = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $page = null,
        ?int $perPage = null
    ): array {
        $page = max(1, (int) ($page ?: 1));
        $perPage = min(50, max(1, (int) ($perPage ?: 30)));

        $sales = Sale::query()
            ->with(['client', 'branch', 'sarFiscalDocument'])
            ->withCount('details')
            ->whereNull('sales.deleted_at');

        $this->scope->applyRecordVisibility($sales, $user, 'sales');
        $this->scope->apply($sales, $user, 'sales');

        $sales
            ->when($search !== null, function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('sales.Ref', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($paymentStatus !== null, fn ($q) => $q->where('sales.payment_statut', $paymentStatus))
            ->when($dateFrom !== null, fn ($q) => $q->where('sales.date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($q) => $q->where('sales.date', '<=', $dateTo));

        $total = (clone $sales)->count();

        $rows = $sales->orderByDesc('sales.id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'items' => $rows->map(fn ($sale) => $this->shapeSale($sale))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    private function shapeSale(Sale $sale): array
    {
        $grandTotal = (float) $sale->GrandTotal;
        $paid = (float) $sale->paid_amount;
        $decimals = helpers::price_decimals();

        return [
            'sale_id' => $sale->id,
            'sale_uuid' => $sale->sale_uuid,
            'reference' => $sale->Ref,
            'date' => trim($sale->date.' '.$sale->time),
            'customer' => $sale->client ? [
                'id' => $sale->client->id,
                'name' => $sale->client->name,
            ] : null,
            'branch' => $sale->branch ? [
                'id' => $sale->branch->id,
                'name' => $sale->branch->name,
            ] : null,
            'items_count' => (int) $sale->details_count,
            'grand_total' => number_format($grandTotal, $decimals, '.', ''),
            'paid_amount' => number_format($paid, $decimals, '.', ''),
            'due_amount' => number_format($grandTotal - $paid, $decimals, '.', ''),
            'payment_status' => $sale->payment_statut,
            'fiscal' => $sale->sarFiscalDocument ? [
                'number' => $sale->sarFiscalDocument->fiscal_number,
                'status' => $sale->sarFiscalDocument->status,
            ] : null,
        ];
    }
}
