<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Applies the aggregate stock side of a location-aware POS sale.
 *
 * This service runs as soon as the Sale header has been created, before the
 * historical PosController reaches its product_warehouse loop. The old loop is
 * then neutralized by PosLocationStockBridge, which lets us cut over safely
 * without requiring every branch-only product to have a fake CD stock row.
 */
class PosLocationSaleStockService
{
    public function apply(Sale $sale, Request $request): void
    {
        if (! $sale->inventory_location_id || ! $sale->branch_id) return;

        $details = array_values((array) $request->input('details', []));
        if (! $details) return;

        $inventory = app(InventoryService::class);

        foreach ($details as $index => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) continue;

            $product = Product::with('unitSale')->whereNull('deleted_at')->find($productId);
            if (! $product) {
                throw ValidationException::withMessages([
                    'details' => "El producto #{$productId} ya no existe.",
                ]);
            }

            $isService = ($row['product_type'] ?? $product->type) === 'is_service';
            if ($isService) continue;

            $variantId = ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;
            $baseQuantity = $this->baseQuantity($row, $product);
            if ($baseQuantity <= 0) {
                throw ValidationException::withMessages([
                    'details' => 'La cantidad física vendida debe ser mayor que cero.',
                ]);
            }

            $inventory->decrease(
                (int) $sale->inventory_location_id,
                $productId,
                $baseQuantity,
                $variantId,
                [
                    'user_id' => $sale->user_id ?: auth()->id(),
                    'reference_type' => 'pos_sale',
                    'reference_id' => (string) $sale->id,
                    'idempotency_key' => 'pos-sale:'.$sale->id.':line:'.$index,
                    'notes' => 'Venta POS descontada directamente desde la ubicación operativa.',
                    'metadata' => [
                        'sale_id' => (int) $sale->id,
                        'sale_uuid' => $sale->sale_uuid,
                        'branch_id' => (int) $sale->branch_id,
                        'inventory_location_id' => (int) $sale->inventory_location_id,
                        'cash_drawer_id' => $sale->cash_drawer_id ? (int) $sale->cash_drawer_id : null,
                        'legacy_warehouse_id' => $sale->warehouse_id ? (int) $sale->warehouse_id : null,
                        'line_index' => $index,
                    ],
                ]
            );
        }

        // The historical controller will still calculate a qte mutation later in
        // the same transaction. product_warehouse observes this request flag and
        // restores its raw CD value instead of applying another location decrease.
        $request->attributes->set('prodex_location_stock_preapplied', true);
    }

    public function baseQuantity(array $row, Product $product): float
    {
        $quantity = (float) ($row['quantity'] ?? 0);
        $packMultiplier = isset($row['pack_multiplier']) && (float) $row['pack_multiplier'] > 0
            ? (float) $row['pack_multiplier']
            : 1.0;
        $packQuantity = $quantity * $packMultiplier;

        $unit = null;
        if (! empty($row['sale_unit_id'])) {
            $unit = Unit::find((int) $row['sale_unit_id']);
        }
        if (! $unit) $unit = $product->unitSale;
        if (! $unit) return round($packQuantity, 3);

        $value = (float) ($unit->operator_value ?: 1);
        if ($value <= 0) $value = 1;

        return round(
            $unit->operator === '/' ? $packQuantity / $value : $packQuantity * $value,
            3
        );
    }
}
