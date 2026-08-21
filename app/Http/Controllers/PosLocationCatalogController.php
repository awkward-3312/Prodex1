<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\InventoryLocationScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Native catalog for a branch inventory location.
 *
 * Unlike the historical POS catalog, this endpoint does not require a
 * product_warehouse row to make a product visible. Product master data is global
 * to the tenant; physical availability comes exclusively from the requested
 * InventoryLocation.
 */
class PosLocationCatalogController extends BaseController
{
    public function index(Request $request, int $locationId)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        if (! app(InventoryLocationScopeService::class)->canAccess($user, $locationId)) {
            abort(403, 'No tiene acceso a esta ubicación de inventario.');
        }

        $location = InventoryLocation::active()
            ->where('is_sellable', true)
            ->find($locationId);

        if (! $location) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación no existe, está inactiva o no está habilitada para venta.',
            ]);
        }

        $productsQuery = Product::with(['unitSale', 'variants'])
            ->whereNull('deleted_at')
            ->where('not_selling', 0);

        if ($request->filled('category_id')) {
            $productsQuery->where('category_id', (int) $request->category_id);
        }
        if ($request->filled('brand_id')) {
            $productsQuery->where('brand_id', (int) $request->brand_id);
        }

        if ($request->input('product_combo') === '1') {
            $productsQuery->whereIn('type', ['is_combo', 'is_single', 'is_variant', 'is_service']);
        } elseif ($request->input('product_combo') === '0') {
            $productsQuery->where('type', '!=', 'is_combo');
        }

        $products = $productsQuery->orderByDesc('id')->get();
        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

        $stocks = InventoryLocationStock::where('inventory_location_id', $location->id)
            ->whereIn('product_id', $productIds ?: [0])
            ->get()
            ->keyBy(fn ($row) => $this->stockKey((int) $row->product_id, $row->product_variant_id));

        $packsByProduct = $this->packsByProduct($productIds);
        $allowOverselling = (bool) optional(PosSetting::whereNull('deleted_at')->first())->allow_overselling;
        $stockOnly = (string) $request->input('stock', '1') === '1';

        $rows = [];
        foreach ($products as $product) {
            $variants = $this->activeVariants($product);

            if ($variants->isNotEmpty()) {
                foreach ($variants as $variant) {
                    $stock = $stocks->get($this->stockKey((int) $product->id, (int) $variant->id));
                    $row = $this->formatRow($product, $variant, $stock, []);
                    if ($this->includeRow($row, $stockOnly, $allowOverselling)) $rows[] = $row;
                }
                continue;
            }

            $stock = $stocks->get($this->stockKey((int) $product->id, null));
            $row = $this->formatRow($product, null, $stock, $packsByProduct[$product->id] ?? []);
            if ($this->includeRow($row, $stockOnly, $allowOverselling)) $rows[] = $row;
        }

        return response()->json([
            'products' => $rows,
            'totalRows' => count($rows),
            'server_time' => now()->toIso8601String(),
            'branch_id' => $location->branch_id ? (int) $location->branch_id : null,
            'inventory_location_id' => (int) $location->id,
            'stock_source' => 'inventory_location',
        ]);
    }

    private function activeVariants(Product $product)
    {
        if (! $product->relationLoaded('variants')) return collect();

        return $product->variants
            ->filter(function ($variant) {
                return ! isset($variant->deleted_at) || $variant->deleted_at === null;
            })
            ->values();
    }

    private function packsByProduct(array $productIds): array
    {
        if (! $productIds || ! Schema::hasTable('product_packs')) return [];

        $setting = Setting::whereNull('deleted_at')->first();
        if (! (bool) ($setting->enable_multi_pack_selling ?? false)) return [];

        $out = [];
        foreach (ProductPack::whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get() as $pack) {
            $out[$pack->product_id][] = [
                'id' => (int) $pack->id,
                'name' => (string) $pack->name,
                'multiplier' => (float) $pack->multiplier,
                'price' => (float) $pack->price,
                'is_default' => (bool) $pack->is_default,
            ];
        }

        return $out;
    }

    private function includeRow(array $row, bool $stockOnly, bool $allowOverselling): bool
    {
        if ($row['product_type'] === 'is_service') return true;
        if (! $stockOnly || $allowOverselling) return true;
        if (($row['manage_stock'] ?? true) === false) return true;

        return (float) ($row['available_quantity'] ?? 0) > 0;
    }

    private function formatRow(Product $product, ?ProductVariant $variant, $stock, array $packs): array
    {
        $isService = $product->type === 'is_service';
        $quantity = $isService ? 0.0 : round((float) ($stock->quantity ?? 0), 3);
        $reserved = $isService ? 0.0 : round((float) ($stock->reserved_quantity ?? 0), 3);
        $available = $isService ? 0.0 : round(max(0, $quantity - $reserved), 3);
        $manageStock = $isService ? false : (bool) ($stock->manage_stock ?? true);

        $unit = $product->unitSale;
        $saleQuantity = $isService ? '---' : $available;
        $basePrice = (float) ($variant?->price ?? $product->price ?? 0);
        $salePrice = $basePrice;

        if ($unit) {
            $value = (float) ($unit->operator_value ?: 1);
            if ($value <= 0) $value = 1;
            if ($unit->operator === '/') {
                $saleQuantity = $available * $value;
                $salePrice = $basePrice / $value;
            } else {
                $saleQuantity = $available / $value;
                $salePrice = $basePrice * $value;
            }
        }

        $baseWholesale = (float) ($variant && (float) $variant->wholesale > 0
            ? $variant->wholesale
            : ((float) $product->wholesale_price > 0 ? $product->wholesale_price : $basePrice));
        $baseMinPrice = (float) ($variant && (float) $variant->min_price > 0
            ? $variant->min_price
            : ($product->min_price ?? 0));
        $wholesaleUnitPrice = $baseWholesale;

        if ($unit) {
            $value = (float) ($unit->operator_value ?: 1);
            if ($value <= 0) $value = 1;
            $wholesaleUnitPrice = $unit->operator === '/'
                ? $baseWholesale / $value
                : $baseWholesale * $value;
        }

        $discount = (float) ($product->discount ?? 0);
        $discountMethod = (string) ($product->discount_method ?? '2');
        $discountAmount = 0.0;
        if ($discount > 0) {
            $discountAmount = $discountMethod === '1'
                ? $salePrice * $discount / 100
                : $discount;
        }
        $discounted = $salePrice - $discountAmount;

        $taxPercent = (float) ($product->TaxNet ?? 0);
        $taxMethod = (string) ($product->tax_method ?? '1');
        $taxPrice = $taxPercent > 0 ? $discounted * $taxPercent / 100 : 0.0;
        if ($taxMethod === '1') {
            $netPrice = $discounted;
            $totalPrice = $discounted + $taxPrice;
        } else {
            $totalPrice = $discounted;
            $netPrice = $discounted - $taxPrice;
        }

        $wholesaleDiscount = 0.0;
        if ($discount > 0) {
            $wholesaleDiscount = $discountMethod === '1'
                ? $wholesaleUnitPrice * $discount / 100
                : $discount;
        }
        $wholesaleDiscounted = $wholesaleUnitPrice - $wholesaleDiscount;
        $wholesaleTax = $taxPercent > 0 ? $wholesaleDiscounted * $taxPercent / 100 : 0.0;
        $wholesaleNet = $taxMethod === '1'
            ? $wholesaleDiscounted + $wholesaleTax
            : $wholesaleDiscounted;

        $image = $product->primaryProductImageFilename();
        $name = $variant ? '['.$variant->name.']'.$product->name : $product->name;
        $code = $variant ? $variant->code : $product->code;

        return [
            'id' => (int) $product->id,
            'product_id' => (int) $product->id,
            'product_variant_id' => $variant ? (int) $variant->id : null,
            'Variant' => $variant ? '['.$variant->name.']'.$product->name : null,
            'name' => (string) $name,
            'code' => (string) $code,
            'barcode' => (string) $code,
            'image' => $image,
            'product_type' => (string) $product->type,
            'is_imei' => (bool) $product->is_imei,
            'is_batch_tracked' => (bool) ($product->is_batch_tracked ?? false),
            'not_selling' => (int) ($product->not_selling ?? 0),
            'hide_from_online_store' => (int) ($product->hide_from_online_store ?? 0),
            'packs' => $variant ? [] : $packs,
            'tax_method' => $taxMethod,
            'tax_percent' => $taxPercent,
            'discount_method' => $discountMethod,
            'discount_Method' => $discountMethod,
            'discount' => $discount,
            'qte' => $isService ? '---' : $available,
            'physical_quantity' => $isService ? '---' : $quantity,
            'reserved_quantity' => $reserved,
            'available_quantity' => $isService ? '---' : $available,
            'qte_sale' => $isService ? '---' : round((float) $saleQuantity, 3),
            'manage_stock' => $manageStock,
            'unitSale' => $unit ? (string) $unit->ShortName : '',
            'sale_unit_id' => $unit ? (int) $unit->id : null,
            'Unit_price' => $salePrice,
            'fix_price' => $basePrice,
            'Unit_price_wholesale' => $wholesaleUnitPrice,
            'wholesale_Net_price' => $wholesaleNet,
            'min_price' => $baseMinPrice,
            'DiscountNet' => $discountAmount,
            'Net_price' => $netPrice,
            'tax_price' => $taxPrice,
            'Total_price' => $totalPrice,
            'inventory_location_id' => (int) ($stock->inventory_location_id ?? 0),
            'stock_source' => 'inventory_location',
        ];
    }

    private function stockKey(int $productId, $variantId): string
    {
        return $productId.':'.($variantId ? (int) $variantId : 0);
    }
}
