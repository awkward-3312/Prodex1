<?php

namespace App\Services\Mobile;

use App\Models\Category;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobilePosCatalogService
{
    public function __construct(private MobilePosProductReadService $reader) {}

    public function catalog(
        int $locationId,
        ?string $search = null,
        ?int $categoryId = null,
        ?int $page = null,
        ?int $perPage = null
    ): array {
        $page = max(1, (int) ($page ?: 1));
        $perPage = min(50, max(1, (int) ($perPage ?: 30)));

        $rowsQuery = $this->catalogRows($search, $categoryId);
        $total = (int) DB::query()->fromSub($rowsQuery, 'catalog_count')->count();

        $rows = DB::query()
            ->fromSub($this->catalogRows($search, $categoryId), 'catalog')
            ->orderBy('sort_name')
            ->orderBy('product_id')
            ->orderBy('product_variant_id')
            ->forPage($page, $perPage)
            ->get();

        $productIds = $rows->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $variantIds = $rows->pluck('product_variant_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $products = $this->products($productIds);
        $variants = $this->variants($variantIds);
        $stocks = $this->stocks($locationId, $productIds);

        $items = $rows->map(function ($row) use ($locationId, $products, $variants, $stocks) {
            $product = $products->get((int) $row->product_id);
            if (! $product) {
                return null;
            }

            $variantId = $row->product_variant_id ? (int) $row->product_variant_id : null;
            $variant = $variantId ? $variants->get($variantId) : null;
            $variantKey = $variantId ?: 0;
            $stock = $stocks->get(((int) $product->id).':'.$variantKey);

            return $this->catalogItem($this->reader->item($product, $variant, $locationId, $stock));
        })->filter()->values()->all();

        return [
            'items' => $items,
            'categories' => $this->categories(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    private function catalogRows(?string $search, ?int $categoryId): Builder
    {
        $simple = DB::table('products as p')
            ->selectRaw('p.id as product_id, NULL as product_variant_id, p.name as sort_name')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('p.not_selling', 0)
            ->where(function ($query) {
                $query->where('p.is_variant', 0)
                    ->orWhereNull('p.is_variant');
            })
            ->where(function ($query) {
                $query->where('p.type', '!=', 'is_variant')
                    ->orWhereNull('p.type');
            });

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->selectRaw('p.id as product_id, v.id as product_variant_id, p.name as sort_name')
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('p.not_selling', 0)
            ->where(function ($query) {
                $query->where('p.is_variant', 1)
                    ->orWhere('p.type', 'is_variant');
            });

        if ($categoryId) {
            $simple->where('p.category_id', $categoryId);
            $variants->where('p.category_id', $categoryId);
        }

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $simple->where(function ($query) use ($like) {
                $query->where('p.name', 'like', $like)
                    ->orWhere('p.code', 'like', $like)
                    ->orWhere('p.gtin', 'like', $like);
            });
            $variants->where(function ($query) use ($like) {
                $query->where('p.name', 'like', $like)
                    ->orWhere('p.code', 'like', $like)
                    ->orWhere('p.gtin', 'like', $like)
                    ->orWhere('v.name', 'like', $like)
                    ->orWhere('v.code', 'like', $like)
                    ->orWhere('v.gtin', 'like', $like);
            });
        }

        return $simple->unionAll($variants);
    }

    private function products(Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        $with = ['unitSale'];
        if (Schema::hasTable('categories')) {
            $with[] = 'category';
        }
        if (Schema::hasTable('product_images')) {
            $with[] = 'images';
        }

        return Product::query()
            ->with($with)
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');
    }

    private function variants(Collection $variantIds): Collection
    {
        if ($variantIds->isEmpty()) {
            return collect();
        }

        return ProductVariant::query()
            ->whereIn('id', $variantIds->all())
            ->get()
            ->keyBy('id');
    }

    private function stocks(int $locationId, Collection $productIds): Collection
    {
        if ($productIds->isEmpty()) {
            return collect();
        }

        return InventoryLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->whereIn('product_id', $productIds->all())
            ->get()
            ->keyBy(fn ($stock) => ((int) $stock->product_id).':'.((int) ($stock->variant_key ?? 0)));
    }

    private function catalogItem(array $read): array
    {
        $product = $read['product'];

        return [
            'product_id' => $product['product_id'],
            'product_variant_id' => $product['product_variant_id'],
            'name' => $product['product_name'],
            'variant_name' => $product['variant_name'],
            'display_name' => $read['display_name'],
            'code' => $product['code'],
            'gtin' => $product['gtin'],
            'barcode_symbology' => $product['barcode_symbology'],
            'type' => $product['type'],
            'unit' => $product['unit'],
            'unit_id' => $product['unit_id'],
            'category' => $read['category'],
            'image_url' => $read['image_url'],
            'pricing' => $read['pricing'],
            'inventory' => $read['inventory'],
            'sellability' => $read['sellability'],
        ];
    }

    private function categories(): array
    {
        if (! Schema::hasTable('categories')) {
            return [];
        }

        return Category::query()
            ->select('categories.id', 'categories.name')
            ->join('products as p', 'p.category_id', '=', 'categories.id')
            ->whereNull('categories.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('p.not_selling', 0)
            ->whereNotNull('p.category_id')
            ->distinct()
            ->orderBy('categories.name')
            ->get()
            ->map(fn ($category) => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])
            ->values()
            ->all();
    }
}
