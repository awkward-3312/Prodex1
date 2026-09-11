<?php

namespace App\Services\Mobile;

use App\Exceptions\Mobile\MobileProductResolveException;
use App\Models\Product;
use App\Models\ProductVariant;

class MobileProductCodeResolver
{
    public function __construct(private MobilePosProductReadService $reader) {}

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
            ->whereHas('product', fn ($query) => $this->reader->sellableProductScope($query))
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
            ->where(fn ($query) => $this->reader->sellableProductScope($query))
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

    private function response(array $resolved, int $locationId, array $match, float $scanQuantity): array
    {
        /** @var \App\Models\Product $product */
        $product = $resolved['product'];
        /** @var \App\Models\ProductVariant|null $variant */
        $variant = $resolved['variant'];

        return [
            'match' => $match,
            ...$this->reader->item($product, $variant, $locationId),
            'scan_quantity' => $scanQuantity,
        ];
    }
}
