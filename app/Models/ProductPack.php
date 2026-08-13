<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single sellable pack for a product (Multi-Pack Selling module).
 *
 * `multiplier` converts a pack quantity into the product's base unit for stock
 * movement; `price` is the selling price for one pack. Each product that uses
 * packs has exactly one default pack (is_default = true) with multiplier = 1.
 */
class ProductPack extends Model
{
    use SoftDeletes;

    protected $table = 'product_packs';

    protected $fillable = [
        'product_id',
        'name',
        'multiplier',
        'price',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'multiplier' => 'double',
        'price' => 'double',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeForProduct($q, $productId)
    {
        return $q->where('product_id', $productId);
    }
}
