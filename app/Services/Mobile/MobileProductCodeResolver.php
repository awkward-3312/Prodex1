<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobileProductResolveException;
use App\Models\InventoryLocationStock;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\ProductVariant;

class MobileProductCodeResolver
{
    public function resolve(string $value, int $locationId, ?string $scannerType = null): array
    {
        $value = trim($value);
        if ($value === '') {
            throw MobileProductResolveException::productNotFound();
        }

        $exact = $this->resolveExact($value);
        if ($exact) {
            return $this->response($exact, $locationId, [
                'field' => $exact['field'],
                'source_field' => $exact['field'],
                'type' => $exact['type'],
                'scanned_value' => $value,
                'scanner_type' => $scannerType,
                'weighted' => false,
            ], 1.0);
        }

        $weighted = $this->resolveWeighted($value);
        if ($weighted) {
            return $this->response($weighted, $locationId, [
                'field' => 'weighted_code',
                'source_field' => 'code',
                'type' => $weighted['type'],
                'scanned_value' => $value,
                'scanner_type' => $scannerType,
                'weighted' => true,
                'base_code' => $weighted['base_code'],
            ], $weighted['scan_quantity']);
        }

        throw MobileProductResolveException::productNotFound();
    }

    private function resolveExact(string $value): ?array
    {
        foreach ([
            ['kind' => 'variant', 'field' => 'code'],
            ['kind' => 'product', 'field' => 'code'],
            ['kind' => 'variant', 'field' => 'gtin'],
            ['kind' => 'product', 'field' => 'gtin'],
        ] as $stage) {
            $match = $stage['kind'] === 'variant'
                ? $this->variantMatch($stage['field'], $value)
                : $this->productMatch($stage['field'], $value);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    private function resolveWeighted(string $value): ?array
    {
        if (! preg_match('/^\d{13}$/', $value)) {
            return null;
        }

        $baseCode = substr($value, 0, 7);
        $scanQuantity = round(((float) substr($value, 7, 5)) / 1000, 3);

        $match = $this->variantMatch('code', $baseCode)
            ?? $this->productMatch('code', $baseCode);

        if (! $match) {
            return null;
        }

        $match['base_code'] = $baseCode;
        $match['scan_quantity'] = $scanQuantity;

        return $match;
    }

    private function variantMatch(string $field, string $value): ?array
    {
        $rows = ProductVariant::query()
            ->with(['product.unitSale'])
            ->whereNull('product_variants.deleted_at')
            ->where("product_variants.$field", $value)
            ->whereHas('product', fn ($query) => $this->sellableProductScope($query))
            ->limit(2)
            ->get();

        if ($rows->count() > 1) {
            throw MobileProductResolveException::ambiguousCode();
        }

        if ($rows->isEmpty()) {
            return null;
        }

        $variant = $rows->first();

        return [
            'type' => 'product_variant',
            'field' => $field,
            'product' => $variant->product,
            'variant' => $variant,
        ];
    }

    private function productMatch(string $field, string $value): ?array
    {
        $rows = Product::query()
            ->with('unitSale')
            ->where($field, $value)
            ->where(fn ($query) => $this->sellableProductScope($query))
            ->limit(2)
            ->get();

        if ($rows->count() > 1) {
            throw MobileProductResolveException::ambiguousCode();
        }

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'type' => 'product',
            'field' => $field,
            'product' => $rows->first(),
            'variant' => null,
        ];
    }

    private function sellableProductScope($query)
    {
        return $query
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('not_selling', 0);
    }

    private function response(array $resolved, int $locationId, array $match, float $scanQuantity): array
    {
        /** @var \App\Models\Product $product */
        $product = $resolved['product'];
        /** @var \App\Models\ProductVariant|null $variant */
        $variant = $resolved['variant'];

        $stock = InventoryLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->where('product_id', (int) $product->id)
            ->where('variant_key', $variant ? (int) $variant->id : 0)
            ->first();

        $isService = $product->type === 'is_service';
        $manageStock = $isService ? false : (bool) ($stock->manage_stock ?? true);
        $quantity = $isService ? 0.0 : round((float) ($stock->quantity ?? 0), 3);
        $reserved = $isService ? 0.0 : round((float) ($stock->reserved_quantity ?? 0), 3);
        $available = $isService ? 0.0 : round(max(0, $quantity - $reserved), 3);
        $allowOverselling = (bool) optional(PosSetting::whereNull('deleted_at')->first())->allow_overselling;
        $outOfStock = $manageStock && $available <= 0;
        $lowStock = $manageStock
            && (float) ($product->stock_alert ?? 0) > 0
            && $available <= (float) $product->stock_alert;
        $canSell = ! $manageStock || $available > 0 || $allowOverselling;

        return [
            'match' => $match,
            'product' => [
                'id' => (int) $product->id,
                'variant_id' => $variant ? (int) $variant->id : null,
                'product_id' => (int) $product->id,
                'product_variant_id' => $variant ? (int) $variant->id : null,
                'name' => (string) $product->name,
                'product_name' => (string) $product->name,
                'variant_name' => $variant ? (string) $variant->name : null,
                'code' => (string) ($variant?->code ?? $product->code),
                'gtin' => $variant ? ($variant->gtin ?: null) : ($product->gtin ?: null),
                'barcode_symbology' => (string) $product->Type_barcode,
                'type' => (string) $product->type,
                'unit' => $product->unitSale ? (string) $product->unitSale->ShortName : null,
                'unit_id' => $product->unitSale ? (int) $product->unitSale->id : null,
            ],
            'pricing' => [
                'price' => number_format((float) ($variant?->price ?? $product->price ?? 0), 2, '.', ''),
                'source' => 'base_pos_catalog',
            ],
            'inventory' => [
                'location_id' => $locationId,
                'inventory_location_id' => $locationId,
                'quantity' => $quantity,
                'reserved_quantity' => $reserved,
                'available_quantity' => $available,
                'manage_stock' => $manageStock,
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
                'overselling_allowed' => $allowOverselling,
            ],
            'sellability' => [
                'can_sell' => $canSell,
                'reason' => $canSell ? null : 'out_of_stock',
            ],
            'scan_quantity' => $scanQuantity,
        ];
    }
}
