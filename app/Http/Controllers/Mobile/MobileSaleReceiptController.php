<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\Mobile\MobileSaleReceiptService;
use Illuminate\Http\Request;

/**
 * Read-only: returns the sale's already-issued invoice HTML. Never mutates the
 * sale, never re-emits SAR/fiscal numbering, never touches stock or payments.
 */
class MobileSaleReceiptController extends Controller
{
    public function __invoke(Request $request, MobileSaleReceiptService $receipts, $id)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'view', Sale::class);

        $sale = $receipts->findVisibleSale($user, (int) $id);
        abort_unless($sale, 404);

        return response()->json([
            'data' => [
                'sale_id' => $sale->id,
                'reference' => $sale->Ref,
                'html' => $receipts->receiptHtml($sale->id),
            ],
        ]);
    }
}
