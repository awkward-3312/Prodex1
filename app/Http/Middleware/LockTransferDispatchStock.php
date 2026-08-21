<?php

namespace App\Http\Middleware;

use App\Models\product_warehouse;
use App\Models\Transfer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serialize transfer approval against the exact source-stock rows it will debit.
 *
 * The historical controller reads qte and writes an absolute value. Without a
 * row lock, two managers approving different transfers for the same product at
 * the same time could both read the old quantity and overwrite one another. The
 * whole downstream controller call runs inside this outer transaction, so its
 * nested transaction and model-event dispatch guard share these locks.
 */
class LockTransferDispatchStock
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $action = $route && method_exists($route, 'getActionName') ? (string) $route->getActionName() : '';

        // Registered in the tenant API group so the protection cannot accidentally
        // be omitted from the approval route later. All unrelated API calls are a
        // constant-time no-op.
        if (! str_ends_with($action, 'TransferController@approve')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            $transferId = (int) $request->route('id');

            $transfer = Transfer::with('details')
                ->whereNull('deleted_at')
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            // If another approval won the race while this request waited for the
            // transfer row, let the controller return its normal idempotent no-op.
            if (! $transfer->isApproved()) {
                foreach ($transfer->details as $detail) {
                    $query = product_warehouse::whereNull('deleted_at')
                        ->where('warehouse_id', $transfer->from_warehouse_id)
                        ->where('product_id', $detail->product_id);

                    if ($detail->product_variant_id) {
                        $query->where('product_variant_id', $detail->product_variant_id);
                    } else {
                        $query->where(function ($q) {
                            $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0);
                        });
                    }

                    $source = $query->lockForUpdate()->first();
                    if (! $source) {
                        throw ValidationException::withMessages([
                            'transfer' => 'La bodega de origen no tiene una existencia válida para uno de los productos del despacho.',
                        ]);
                    }
                }
            }

            return $next($request);
        }, 5);
    }
}
