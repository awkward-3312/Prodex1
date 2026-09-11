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

/**
 * Read-only physical stock view for Mobile, scoped to one inventory location.
 *
 * Deliberately does NOT reuse MobilePosCatalogService: that service encodes
 * POS sellability (not_selling, sellability payload). Inventory is a stock
 * view, not a sales catalog, so it clones the safe pagination/search/category
 * PATTERN but drops the POS-only filters and fields. Stock math itself is
 * reused as-is from MobilePosProductReadService so there is exactly one
 * out_of_stock/low_stock formula in the codebase.
 */
class MobileInventoryService
{
    private const NON_PHYSICAL_TYPES = ['is_service', 'is_combo'];

    public function __construct(private MobilePosProductReadService $reader) {}

    public function inventory(
        int $locationId,
        ?string $search = null,
        ?int $categoryId = null,
        ?string $stockStatus = null,
        ?int $page = null,
        ?int $perPage = null
    ): array {
        $page = max(1, (int) ($page ?: 1));
        $perPage = min(50, max(1, (int) ($perPage ?: 30)));

        // Summary is always location-wide, independent of search/category/status,
        // so it never jumps around while the user types or filters.
        $summaryRows = $this->orderedRows($this->eligibleRows(null, null))->get();
        $summaryComputed = $this->computeRows($summaryRows, $locationId);
        $summary = $this->summarize($summaryComputed);

        if ($search === null && $categoryId === null) {
            $listComputed = $summaryComputed;
        } else {
            $listRows = $this->orderedRows($this->eligibleRows($search, $categoryId))->get();
            $listComputed = $this->computeRows($listRows, $locationId);
        }

        if ($stockStatus === 'low_stock') {
            $listComputed = $listComputed->filter(fn ($row) => $row['low_stock'])->values();
        } elseif ($stockStatus === 'out_of_stock') {
            $listComputed = $listComputed->filter(fn ($row) => $row['out_of_stock'])->values();
        }

        $total = $listComputed->count();
        $pageItems = $listComputed->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pageItems->map(fn ($row) => $row['response'])->all(),
            'categories' => $this->categories(),
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    /**
     * Wraps the union in a subquery so it can be ordered deterministically
     * (same ordering MobilePosCatalogService uses, so item order is familiar).
     */
    private function orderedRows(Builder $rows): Builder
    {
        return DB::query()->fromSub($rows, 'inventory_rows')
            ->orderBy('sort_name')
            ->orderBy('product_id')
            ->orderBy('product_variant_id');
    }

    private function eligibleRows(?string $search, ?int $categoryId): Builder
    {
        $simple = DB::table('products as p')
            ->selectRaw('p.id as product_id, NULL as product_variant_id, p.name as sort_name')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where(function ($query) {
                $query->where('p.is_variant', 0)
                    ->orWhereNull('p.is_variant');
            })
            ->where(function ($query) {
                $query->where('p.type', '!=', 'is_variant')
                    ->orWhereNull('p.type');
            })
            ->where(function ($query) {
                $query->whereNotIn('p.type', self::NON_PHYSICAL_TYPES)
                    ->orWhereNull('p.type');
            });

        $variants = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->selectRaw('p.id as product_id, v.id as product_variant_id, p.name as sort_name')
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where(function ($query) {
                $query->where('p.is_variant', 1)
                    ->orWhere('p.type', 'is_variant');
            })
            ->where(function ($query) {
                $query->whereNotIn('p.type', self::NON_PHYSICAL_TYPES)
                    ->orWhereNull('p.type');
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

    private function computeRows(Collection $rows, int $locationId): Collection
    {
        $productIds = $rows->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $variantIds = $rows->pluck('product_variant_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $products = $this->products($productIds);
        $variants = $this->variants($variantIds);
        $stocks = $this->stocks($locationId, $productIds);

        return $rows->map(function ($row) use ($products, $variants, $stocks, $locationId) {
            $product = $products->get((int) $row->product_id);
            if (! $product) {
                return null;
            }

            $variantId = $row->product_variant_id ? (int) $row->product_variant_id : null;
            $variant = $variantId ? $variants->get($variantId) : null;
            $variantKey = $variantId ?: 0;
            // MobilePosProductReadService::item() re-queries inventory_location_stocks
            // itself whenever the 4th argument is null (`$stock ?: ...`). Passing a
            // non-null stub for "no row at this location" (same zero defaults that
            // fallback query would have produced anyway) keeps behavior identical
            // while avoiding one query per never-stocked row (N+1).
            $stock = $stocks->get(((int) $product->id).':'.$variantKey) ?? $this->zeroStockStub();

            $read = $this->reader->item($product, $variant, $locationId, $stock);

            // Inventory reports physical stock truthfully: out_of_stock is already
            // overselling-independent in item() (verified by trace — allow_overselling
            // only feeds `sellability.can_sell`, never `inventory.out_of_stock`). But
            // item()'s low_stock and out_of_stock are computed independently of each
            // other, so both can be true at once (available=0, stock_alert>0). For
            // Inventory's own contract these are mutually exclusive states:
            // out_of_stock wins. This exclusivity is resolved HERE, once, so the item
            // response, the stock_status filter, and the summary counts can never
            // disagree with each other.
            $outOfStock = (bool) $read['inventory']['out_of_stock'];
            $lowStock = (bool) $read['inventory']['low_stock'] && ! $outOfStock;

            return [
                'response' => $this->shapeItem($read, $locationId, $lowStock, $outOfStock),
                'low_stock' => $lowStock,
                'out_of_stock' => $outOfStock,
            ];
        })->filter()->values();
    }

    private function zeroStockStub(): InventoryLocationStock
    {
        return new InventoryLocationStock([
            'quantity' => 0,
            'reserved_quantity' => 0,
            'manage_stock' => true,
        ]);
    }

    /**
     * Reshapes MobilePosProductReadService::item() into the Inventory contract.
     * Deliberately drops `pricing`, `sellability` and `overselling_allowed` —
     * Inventory reports physical stock truthfully, not what POS is allowed to
     * sell past it. `$lowStock`/`$outOfStock` are the already-exclusivity-resolved
     * values from computeRows(), not re-derived from `$read` here, so there is
     * exactly one place that decides the pair.
     */
    private function shapeItem(array $read, int $locationId, bool $lowStock, bool $outOfStock): array
    {
        $product = $read['product'];
        $inventory = $read['inventory'];

        return [
            'product_id' => $product['product_id'],
            'product_variant_id' => $product['product_variant_id'],
            'name' => $product['product_name'],
            'variant_name' => $product['variant_name'],
            'display_name' => $read['display_name'],
            'code' => $product['code'],
            'gtin' => $product['gtin'],
            'category' => $read['category'],
            'image_url' => $read['image_url'],
            'inventory' => [
                'inventory_location_id' => $locationId,
                'quantity' => number_format((float) $inventory['quantity'], 3, '.', ''),
                'reserved_quantity' => number_format((float) $inventory['reserved_quantity'], 3, '.', ''),
                'available_quantity' => number_format((float) $inventory['available_quantity'], 3, '.', ''),
                'manage_stock' => $inventory['manage_stock'],
                'out_of_stock' => $outOfStock,
                'low_stock' => $lowStock,
            ],
        ];
    }

    private function summarize(Collection $computed): array
    {
        return [
            'total_items' => $computed->count(),
            'low_stock_count' => $computed->filter(fn ($row) => $row['low_stock'])->count(),
            'out_of_stock_count' => $computed->filter(fn ($row) => $row['out_of_stock'])->count(),
        ];
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
            ->where(function ($query) {
                $query->whereNotIn('p.type', self::NON_PHYSICAL_TYPES)
                    ->orWhereNull('p.type');
            })
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
