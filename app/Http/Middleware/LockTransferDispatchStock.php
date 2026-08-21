<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\Unit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serialize transfer approval against the exact source-stock rows it will debit.
 *
 * Besides locking stock rows, this middleware performs the final preflight before
 * the legacy approval controller mutates inventory: every line must have a valid
 * unit definition and the total quantity requested for each product/variant must
 * fit in the source warehouse. Grouping is important because the same SKU can be
 * present on more than one transfer line.
 */
class LockTransferDispatchStock
{
    private const EPSILON = 0.000001;

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
                $this->validateAndLockSourceStock($transfer);
            }

            return $next($request);
        }, 5);
    }

    private function validateAndLockSourceStock(Transfer $transfer): void
    {
        if ($transfer->details->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede aprobar una transferencia sin productos.',
            ]);
        }

        $requirements = [];

        foreach ($transfer->details as $detail) {
            $quantity = (float) $detail->quantity;
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'transfer' => 'Todas las líneas de la transferencia deben tener una cantidad mayor que cero.',
                ]);
            }

            $product = Product::find($detail->product_id);
            if (! $product) {
                throw ValidationException::withMessages([
                    'transfer' => 'Uno de los productos de la transferencia ya no existe.',
                ]);
            }

            $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
            $unit = $unitId ? Unit::find($unitId) : null;

            if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
                throw ValidationException::withMessages([
                    'transfer' => 'No se puede despachar '.$product->name.' porque su unidad de compra no tiene una conversión válida.',
                ]);
            }

            $baseQuantity = $unit->operator === '/'
                ? $quantity / (float) $unit->operator_value
                : $quantity * (float) $unit->operator_value;

            if ($baseQuantity <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'transfer' => 'La cantidad convertida de '.$product->name.' no es válida para despacho.',
                ]);
            }

            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;
            $key = (int) $detail->product_id.'|'.($variantId ?? 'null');

            if (! isset($requirements[$key])) {
                $requirements[$key] = [
                    'product_id' => (int) $detail->product_id,
                    'variant_id' => $variantId,
                    'product_name' => (string) $product->name,
                    'required' => 0.0,
                ];
            }

            $requirements[$key]['required'] += $baseQuantity;
        }

        // Sort keys so concurrent approvals lock shared stock rows in a stable order,
        // reducing deadlock risk when transfers contain several of the same SKUs.
        ksort($requirements, SORT_STRING);

        foreach ($requirements as $requirement) {
            $query = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $requirement['product_id']);

            if ($requirement['variant_id'] !== null) {
                $query->where('product_variant_id', $requirement['variant_id']);
            } else {
                $query->where(function ($q) {
                    $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0);
                });
            }

            $source = $query->lockForUpdate()->first();
            if (! $source) {
                throw ValidationException::withMessages([
                    'transfer' => 'La bodega de origen no tiene existencia registrada para '.$requirement['product_name'].'.',
                ]);
            }

            $available = (float) $source->qte;
            $required = (float) $requirement['required'];

            if ($available + self::EPSILON < $required) {
                throw ValidationException::withMessages([
                    'transfer' => 'Stock insuficiente para despachar '.$requirement['product_name'].'. Disponible: '.rtrim(rtrim(number_format($available, 6, '.', ''), '0'), '.').'; requerido: '.rtrim(rtrim(number_format($required, 6, '.', ''), '0'), '.').'.',
                ]);
            }
        }
    }
}
