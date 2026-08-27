<?php

namespace App\Http\Middleware;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class NormalizeModernPosSaleRequest
{
    public function handle(Request $request, Closure $next)
    {
        if (! $this->isModernCreatePos($request)) {
            return $next($request);
        }

        $location = InventoryLocation::active()->with('branch')->find((int) $request->input('inventory_location_id'));
        if (! $location || (int) $location->branch_id !== (int) $request->input('branch_id')) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación de inventario no pertenece a la sucursal seleccionada.',
            ]);
        }

        // Compatibility is resolved only on the server. The browser never needs to
        // send an InventoryLocation id disguised as a warehouse id again.
        $request->merge([
            'warehouse_id' => $this->realCompatibilityWarehouseId($request, $location) ?: 0,
        ]);

        $details = $this->normalizeDetails((array) $request->input('details', []));
        if (empty($details)) {
            throw ValidationException::withMessages(['details' => 'La venta debe contener al menos un producto.']);
        }
        $request->merge(['details' => $details]);

        $linesSubtotal = round(collect($details)->sum(fn ($row) => (float) ($row['subtotal'] ?? 0)), 2);
        $manualDiscount = $this->manualDiscountAmount(
            $linesSubtotal,
            (float) $request->input('discount', 0),
            (string) $request->input('discount_Method', '2')
        );
        $pointsDiscount = min(max(0, (float) $request->input('discount_from_points', 0)), max(0, $linesSubtotal - $manualDiscount));
        $promotionDiscount = min(max(0, (float) $request->input('promotion_discount', 0)), max(0, $linesSubtotal - $manualDiscount - $pointsDiscount));
        $shipping = max(0, (float) $request->input('shipping', 0));

        $request->merge([
            'GrandTotal' => round(max(0, $linesSubtotal - $manualDiscount - $pointsDiscount - $promotionDiscount + $shipping), 2),
            'promotion_subtotal' => $linesSubtotal,
            'promotion_item_count' => (int) collect($details)->sum(fn ($row) => (float) ($row['quantity'] ?? 0)),
            'promotion_product_ids' => collect($details)->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            'promotion_product_subtotals' => collect($details)->mapWithKeys(fn ($row) => [(int) $row['product_id'] => (float) $row['subtotal']])->all(),
        ]);

        return $next($request);
    }

    private function isModernCreatePos(Request $request): bool
    {
        if (! $request->isMethod('post')) return false;
        if (! $request->filled('branch_id') || ! $request->filled('inventory_location_id')) return false;
        $path = trim($request->path(), '/');
        if ($path === 'api/pos/create_pos' || $path === 'pos/create_pos') return true;
        $route = $request->route();
        $action = $route ? $route->getActionName() : null;
        return is_string($action) && str_contains($action, 'PosController@CreatePOS');
    }

    private function realCompatibilityWarehouseId(Request $request, InventoryLocation $location): ?int
    {
        $user = $request->user('api');
        $candidateIds = array_values(array_filter([
            $user ? (int) $user->default_warehouse_id : 0,
            $location->branch ? (int) $location->branch->default_warehouse_id : 0,
        ]));
        if (empty($candidateIds)) return null;
        $warehouse = Warehouse::whereNull('deleted_at')->whereIn('id', $candidateIds)->first();
        return $warehouse ? (int) $warehouse->id : null;
    }

    private function normalizeDetails(array $details): array
    {
        return collect($details)->values()->map(function ($row, $index) {
            if (! is_array($row) || empty($row['product_id'])) {
                throw ValidationException::withMessages(["details.$index.product_id" => 'Producto inválido en la venta.']);
            }

            $product = Product::with('unitSale')->whereNull('deleted_at')->find((int) $row['product_id']);
            if (! $product || (int) ($product->not_selling ?? 0) === 1) {
                throw ValidationException::withMessages(["details.$index.product_id" => 'El producto no está disponible para venta.']);
            }

            $variant = null;
            if (! empty($row['product_variant_id'])) {
                $variant = ProductVariant::whereNull('deleted_at')->where('product_id', $product->id)->find((int) $row['product_variant_id']);
                if (! $variant) {
                    throw ValidationException::withMessages(["details.$index.product_variant_id" => 'La variante seleccionada no pertenece al producto.']);
                }
            }

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["details.$index.quantity" => 'La cantidad debe ser mayor que cero.']);
            }

            $unit = ! empty($row['sale_unit_id']) ? Unit::find((int) $row['sale_unit_id']) : $product->unitSale;
            $basePrice = (float) ($variant?->price ?? $product->price ?? 0);
            $unitPrice = $this->convertUnitPrice($basePrice, $unit);

            $priceType = strtolower((string) ($row['price_type'] ?? 'retail'));
            if ($priceType === 'wholesale') {
                $wholesale = (float) ($variant && (float) ($variant->wholesale ?? 0) > 0
                    ? $variant->wholesale
                    : ((float) ($product->wholesale_price ?? 0) > 0 ? $product->wholesale_price : $basePrice));
                $unitPrice = $this->convertUnitPrice($wholesale, $unit);
            }

            if (! empty($row['product_pack_id'])) {
                if ($variant) {
                    throw ValidationException::withMessages(["details.$index.product_pack_id" => 'Los packs no aplican a productos con variante.']);
                }
                $pack = ProductPack::whereNull('deleted_at')->where('is_active', true)->where('product_id', $product->id)->find((int) $row['product_pack_id']);
                if (! $pack) {
                    throw ValidationException::withMessages(["details.$index.product_pack_id" => 'El pack seleccionado no está disponible.']);
                }
                $unitPrice = (float) $pack->price;
                $row['pack_multiplier'] = (float) $pack->multiplier;
                $row['pack_name'] = (string) $pack->name;
            } else {
                $row['pack_multiplier'] = isset($row['pack_multiplier']) && (float) $row['pack_multiplier'] > 0 ? (float) $row['pack_multiplier'] : 1;
            }

            $discount = max(0, (float) ($product->discount ?? 0));
            $discountMethod = (string) ($product->discount_method ?? '2');
            $discountAmount = $discount > 0 ? ($discountMethod === '1' ? $unitPrice * $discount / 100 : min($discount, $unitPrice)) : 0.0;
            $discounted = max(0, $unitPrice - $discountAmount);
            $taxPercent = max(0, (float) ($product->TaxNet ?? 0));
            $taxMethod = (string) ($product->tax_method ?? '1');
            $taxAmount = $taxPercent > 0 ? $discounted * $taxPercent / 100 : 0.0;
            if ($taxMethod === '1') {
                $netPrice = $discounted;
                $lineUnitTotal = $discounted + $taxAmount;
            } else {
                $lineUnitTotal = $discounted;
                $netPrice = $discounted - $taxAmount;
            }

            $row['sale_unit_id'] = $unit?->id;
            $row['Unit_price'] = round($unitPrice, 6);
            $row['tax_percent'] = $taxPercent;
            $row['tax_method'] = $taxMethod;
            $row['discount'] = $discount;
            $row['discount_Method'] = $discountMethod;
            $row['Net_price'] = round($netPrice, 6);
            $row['taxe'] = round($taxAmount, 6);
            $row['subtotal'] = round($quantity * $lineUnitTotal, 2);
            $row['product_type'] = (string) $product->type;
            return $row;
        })->all();
    }

    private function convertUnitPrice(float $basePrice, ?Unit $unit): float
    {
        if (! $unit) return $basePrice;
        $value = (float) ($unit->operator_value ?: 1);
        if ($value <= 0) $value = 1;
        return $unit->operator === '/' ? $basePrice / $value : $basePrice * $value;
    }

    private function manualDiscountAmount(float $subtotal, float $value, string $method): float
    {
        $value = max(0, $value);
        return $method === '1' ? min($subtotal, $subtotal * $value / 100) : min($subtotal, $value);
    }
}
