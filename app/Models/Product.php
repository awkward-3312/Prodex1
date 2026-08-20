<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'gtin', 'Type_barcode', 'name', 'cost', 'price', 'unit_id', 'unit_sale_id', 'unit_purchase_id',
        'stock_alert', 'weight', 'length', 'width', 'height', 'category_id', 'sub_category_id', 'is_variant', 'is_imei',
        'tax_method', 'fiscal_tax_category', 'image', 'brand_id', 'is_active', 'note', 'type',
        'warranty_period', 'warranty_unit', 'warranty_terms', 'wholesale_price', 'min_price',
        'has_guarantee', 'guarantee_period', 'guarantee_unit', 'points', 'discount', 'discount_method',
        'is_featured', 'hide_from_online_store',
        'is_preorder', 'preorder_available_date', 'preorder_limit', 'preorder_note',
        'is_batch_tracked', 'shelf_life_days', 'generic_name', 'strength', 'dosage_form',
        'pack_size', 'manufacturer', 'prescription_required', 'drug_schedule',
    ];

    protected $casts = [
        'wholesale_price' => 'double',
        'min_price' => 'double',
        'category_id' => 'integer',
        'is_featured' => 'integer',
        'sub_category_id' => 'integer',
        'unit_id' => 'integer',
        'unit_sale_id' => 'integer',
        'unit_purchase_id' => 'integer',
        'is_variant' => 'integer',
        'is_imei' => 'integer',
        'brand_id' => 'integer',
        'is_active' => 'integer',
        'cost' => 'double',
        'price' => 'double',
        'stock_alert' => 'double',
        'weight' => 'double',
        'length' => 'double',
        'width' => 'double',
        'height' => 'double',
        'TaxNet' => 'double',
        'points' => 'double',
        'has_guarantee' => 'boolean',
        'hide_from_online_store' => 'boolean',
        'is_preorder' => 'boolean',
        'preorder_available_date' => 'date',
        'preorder_limit' => 'integer',
        'discount' => 'double',
        'woocommerce_missing_sku' => 'boolean',
        'is_batch_tracked' => 'boolean',
        'prescription_required' => 'boolean',
        'shelf_life_days' => 'integer',
    ];

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function batches()
    {
        return $this->hasMany(ProductBatch::class, 'product_id');
    }

    public function packs()
    {
        return $this->hasMany(ProductPack::class, 'product_id');
    }

    public function ProductVariant()
    {
        return $this->belongsTo('App\Models\ProductVariant');
    }

    public function PurchaseDetail()
    {
        return $this->belongsTo('App\Models\PurchaseDetail');
    }

    public function SaleDetail()
    {
        return $this->belongsTo('App\Models\SaleDetail');
    }

    public function QuotationDetail()
    {
        return $this->belongsTo('App\Models\QuotationDetail');
    }

    public function category()
    {
        return $this->belongsTo('App\Models\Category');
    }

    public function subCategory()
    {
        return $this->belongsTo(SubCategory::class, 'sub_category_id');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withTimestamps();
    }

    public function subcategories()
    {
        return $this->belongsToMany(SubCategory::class, 'product_subcategory', 'product_id', 'sub_category_id')
            ->withTimestamps();
    }

    public function unit()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_id');
    }

    public function unitPurchase()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_purchase_id');
    }

    public function unitSale()
    {
        return $this->belongsTo('App\Models\Unit', 'unit_sale_id');
    }

    public function brand()
    {
        return $this->belongsTo('App\Models\Brand');
    }

    public function combinedProducts()
    {
        return $this->belongsToMany(Product::class, 'combined_products', 'product_id', 'combined_product_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function collections()
    {
        return $this->belongsToMany(Collection::class, 'collection_product')
            ->withPivot(['sort_order', 'pinned'])
            ->withTimestamps();
    }

    public function warehouseLocationHints()
    {
        return $this->hasMany(ProductWarehouseLocation::class, 'product_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function productGalleryFilenames(): array
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            return $this->images
                ->sortBy('sort_order')
                ->values()
                ->pluck('image_path')
                ->map(fn ($f) => trim((string) $f))
                ->filter()
                ->values()
                ->all();
        }

        $raw = trim((string) ($this->attributes['image'] ?? ''));
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();
    }

    public function primaryProductImageFilename(): string
    {
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            $main = $this->images->firstWhere('is_main', true);
            if ($main && trim((string) $main->image_path) !== '') {
                return trim((string) $main->image_path);
            }
            $first = $this->images->sortBy('sort_order')->first();
            return $first ? trim((string) $first->image_path) : '';
        }

        $gallery = $this->productGalleryFilenames();
        return ! empty($gallery) ? (string) $gallery[0] : '';
    }

    public function computeFinalPrice(?float $taxRate = null, ?float $overrideBase = null): array
    {
        $base = (float) ($overrideBase ?? $this->price ?? 0);
        $dmRaw = $this->discount_method ?? null;
        $dm = null;

        if (is_string($dmRaw)) {
            $dmRaw = trim(strtolower($dmRaw));
            if ($dmRaw === '1' || in_array($dmRaw, ['percent', 'percentage'], true)) {
                $dm = 'percent';
            } elseif ($dmRaw === '2' || $dmRaw === 'fixed') {
                $dm = 'fixed';
            }
        } elseif (is_numeric($dmRaw)) {
            $dm = ((int) $dmRaw === 1) ? 'percent' : (((int) $dmRaw === 2) ? 'fixed' : null);
        }

        $discVal = (float) ($this->discount ?? 0);
        if ($dm === 'percent') {
            $discountAmount = round($base * ($discVal / 100), 2);
        } elseif ($dm === 'fixed') {
            $discountAmount = round(min($discVal, $base), 2);
        } else {
            $discountAmount = 0.0;
        }

        $afterDiscount = max(0.0, round($base - $discountAmount, 2));

        if ($taxRate === null) {
            if (isset($this->tax_rate)) {
                $taxRate = (float) $this->tax_rate;
            } elseif (isset($this->TaxNet)) {
                $taxRate = (float) $this->TaxNet;
            } else {
                $taxRate = 0.0;
            }
        } else {
            $taxRate = (float) $taxRate;
        }

        $tmRaw = $this->tax_method ?? '1';
        $taxMode = 'exclusive';
        if (is_string($tmRaw)) {
            $tm = trim(strtolower($tmRaw));
            $taxMode = ($tm === '2' || $tm === 'inclusive') ? 'inclusive' : 'exclusive';
        } elseif (is_numeric($tmRaw)) {
            $taxMode = ((int) $tmRaw === 2) ? 'inclusive' : 'exclusive';
        }

        if ($taxRate <= 0) {
            $taxAmount = 0.0;
            $final = $afterDiscount;
        } elseif ($taxMode === 'inclusive') {
            $taxAmount = round($afterDiscount - ($afterDiscount / (1 + $taxRate / 100)), 2);
            $final = $afterDiscount;
        } else {
            $taxAmount = round($afterDiscount * ($taxRate / 100), 2);
            $final = round($afterDiscount + $taxAmount, 2);
        }

        return [
            'base' => round($base, 2),
            'discount' => $discountAmount,
            'after_discount' => $afterDiscount,
            'tax' => $taxAmount,
            'final' => $final,
        ];
    }

    public function minDisplayPrice(?float $taxRate = null): float
    {
        if (! empty($this->is_variant) && $this->relationLoaded('variants') && $this->variants->count()) {
            $min = null;
            foreach ($this->variants as $v) {
                $calc = $this->computeFinalPrice($taxRate, (float) ($v->price ?? 0));
                $min = is_null($min) ? $calc['final'] : min($min, $calc['final']);
            }
            if ($min !== null) {
                return round($min, 2);
            }
        }

        return round($this->computeFinalPrice($taxRate)['final'], 2);
    }

    public function apiCategoriesList(): array
    {
        if ($this->relationLoaded('categories') && $this->categories->isNotEmpty()) {
            return $this->categories
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
                ->values()
                ->all();
        }

        if ($this->category_id) {
            $c = $this->relationLoaded('category') ? $this->category : $this->category()->first();
            if ($c) {
                return [['id' => (int) $c->id, 'name' => (string) $c->name]];
            }
        }

        return [];
    }

    public function apiSubcategoriesList(): array
    {
        if ($this->relationLoaded('subcategories') && $this->subcategories->isNotEmpty()) {
            return $this->subcategories
                ->map(fn ($s) => [
                    'id' => (int) $s->id,
                    'name' => (string) $s->name,
                    'category_id' => (int) $s->category_id,
                ])
                ->values()
                ->all();
        }

        if ($this->sub_category_id) {
            $s = $this->relationLoaded('subCategory') ? $this->subCategory : $this->subCategory()->first();
            if ($s) {
                return [[
                    'id' => (int) $s->id,
                    'name' => (string) $s->name,
                    'category_id' => (int) $s->category_id,
                ]];
            }
        }

        return [];
    }
}
