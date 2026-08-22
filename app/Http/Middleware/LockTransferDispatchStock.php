<?php

namespace App\Http\Middleware;

use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\Unit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Serialize transfer approval against the exact source-stock rows it will debit.
 *
 * Legacy transfers lock product_warehouse. Location-aware transfers lock the
 * authoritative InventoryLocationStock rows instead, so a valid branch-to-branch
 * shipment is never blocked (or falsely approved) by an unrelated CD aggregate.
 */
class LockTransferDispatchStock
{
    private const EPSILON = 0.000001;

    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $action = $route && method_exists($route, 'getActionName') ? (string) $route->getActionName() : '';

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

        ksort($requirements, SORT_STRING);

        if ($transfer->from_inventory_location_id) {
            $this->lockPhysicalLocationStock($transfer, $requirements);
            return;
        }

        $this->lockLegacyWarehouseStock($transfer, $requirements);
    }

    private function lockPhysicalLocationStock(Transfer $transfer, array $requirements): void
    {
        if (! Schema::hasTable('inventory_location_stocks')) {
            throw ValidationException::withMessages([
                'transfer' => 'El inventario por ubicación todavía no está disponible para esta transferencia. Actualiza el esquema del tenant antes de aprobarla.',
            ]);
        }

        foreach ($requirements as $requirement) {
            $query = InventoryLocationStock::where('inventory_location_id', (int) $transfer->from_inventory_location_id)
                ->where('product_id', $requirement['product_id'])
                ->where('variant_key', (int) ($requirement['variant_id'] ?: 0));

            $source = $query->lockForUpdate()->first();
            $available = $source ? (float) $source->quantity - (float) $source->reserved_quantity : 0.0;
            $required = (float) $requirement['required'];

            if ($available + self::EPSILON < $required) {
                throw ValidationException::withMessages([
                    'transfer' => 'Stock insuficiente en la ubicación de origen para '.$requirement['product_name'].'. Disponible: '.$this->number($available).'; requerido: '.$this->number($required).'.',
                ]);
            }
        }
    }

    private function lockLegacyWarehouseStock(Transfer $transfer, array $requirements): void
    {
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

            // getRawOriginal avoids request-aware compatibility accessors: this is
            // explicitly the legacy path, so the lock and the quantity must refer to
            // the same aggregate row.
            $available = (float) $source->getRawOriginal('qte');
            $required = (float) $requirement['required'];

            if ($available + self::EPSILON < $required) {
                throw ValidationException::withMessages([
                    'transfer' => 'Stock insuficiente para despachar '.$requirement['product_name'].'. Disponible: '.$this->number($available).'; requerido: '.$this->number($required).'.',
                ]);
            }
        }
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
