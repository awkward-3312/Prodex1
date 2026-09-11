<?php

namespace App\Services\Mobile;

use App\Models\InventoryLocationStock;
use App\Models\PosSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Schema;

class MobilePosProductReadService
{
    private ?bool $allowOverselling = null;

    public function item(Product $product, ?ProductVariant $variant, int $locationId, ?InventoryLocationStock $stock = null): array
    {
        $stock = $stock ?: InventoryLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->where('product_id', (int) $product->id)
            ->where('variant_key', $variant ? (int) $variant->id : 0)
            ->first();

        $isService = $product->type === 'is_service';
        $manageStock = $isService ? false : (bool) ($stock->manage_stock ?? true);
        $quantity = $isService ? 0.0 : round((float) ($stock->quantity ?? 0), 3);
        $reserved = $isService ? 0.0 : round((float) ($stock->reserved_quantity ?? 0), 3);
        $available = $isService ? 0.0 : round(max(0, $quantity - $reserved), 3);
        $allowOverselling = $this->allowOverselling();
        $outOfStock = $manageStock && $available <= 0;
        $lowStock = $manageStock
            && (float) ($product->stock_alert ?? 0) > 0
            && $available <= (float) $product->stock_alert;
        $canSell = ! $manageStock || $available > 0 || $allowOverselling;

        $category = $product->relationLoaded('category')
            ? $product->category
            : (Schema::hasTable('categories') ? $product->category()->first() : null);
        $variantName = $variant ? (string) $variant->name : null;

        return [
            'product' => [
                'id' => (int) $product->id,
                'variant_id' => $variant ? (int) $variant->id : null,
                'product_id' => (int) $product->id,
                'product_variant_id' => $variant ? (int) $variant->id : null,
                'name' => (string) $product->name,
                'product_name' => (string) $product->name,
                'variant_name' => $variantName,
                'code' => (string) ($variant?->code ?? $product->code),
                'gtin' => $variant ? ($variant->gtin ?: null) : ($product->gtin ?: null),
                'barcode_symbology' => (string) $product->Type_barcode,
                'type' => (string) $product->type,
                'unit' => $product->unitSale ? (string) $product->unitSale->ShortName : null,
                'unit_id' => $product->unitSale ? (int) $product->unitSale->id : null,
            ],
            'display_name' => $variantName ? ((string) $product->name).' · '.$variantName : (string) $product->name,
            'category' => $category ? [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ] : null,
            'image_url' => $this->imageUrl($product, $variant),
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
        ];
    }

    public function sellableProductScope($query)
    {
        return $query
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('not_selling', 0);
    }

    public function allowOverselling(): bool
    {
        if ($this->allowOverselling === null) {
            $this->allowOverselling = (bool) optional(PosSetting::whereNull('deleted_at')->first())->allow_overselling;
        }

        return $this->allowOverselling;
    }

    private function imageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        $filename = trim((string) ($variant?->image ?: $product->primaryProductImageFilename()));
        if ($filename === '' || $filename === 'no-image.png') {
            return null;
        }

        return global_asset(upload_path('products').'/'.$filename);
    }
}
