<?php

namespace App\Services\Mobile;

use App\Http\Controllers\SalesController;
use App\Models\Sale;
use App\Models\User;
use App\Services\SalesReportingScopeService;

/**
 * Read-only invoice retrieval for Mobile. Visibility follows the same rules as
 * mobile sales history (SalesReportingScopeService) - there is no separate
 * "mobile" visibility rule. Rendering delegates to SalesController's shared
 * renderer so Mobile shows the exact same invoice as the web A4/print flow,
 * never a second, independently built one.
 */
class MobileSaleReceiptService
{
    public function __construct(
        private SalesReportingScopeService $scope,
        private SalesController $salesController,
    ) {}

    public function findVisibleSale(User $user, int $saleId): ?Sale
    {
        $query = Sale::query()->whereNull('sales.deleted_at')->where('sales.id', $saleId);
        $this->scope->applyRecordVisibility($query, $user, 'sales');
        $this->scope->apply($query, $user, 'sales');

        return $query->first();
    }

    public function receiptHtml(int $saleId): string
    {
        return $this->salesController->renderSaleInvoiceHtml($saleId);
    }
}
