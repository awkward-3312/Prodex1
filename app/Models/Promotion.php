<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'kind',
        'discount_type',
        'discount_value',
        'is_active',
        'starts_at',
        'ends_at',
        'time_of_day_start',
        'time_of_day_end',
        'min_cart_total',
        'min_item_count',
        'product_scope',
        'priority',
        'stackable',
        'usage_limit_total',
        'usage_limit_per_customer',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stackable' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'discount_value' => 'decimal:2',
        'min_cart_total' => 'decimal:2',
        'min_item_count' => 'integer',
        'priority' => 'integer',
        'usage_limit_total' => 'integer',
        'usage_limit_per_customer' => 'integer',
    ];

    public function warehouses()
    {
        return $this->belongsToMany(
            Warehouse::class,
            'promotion_warehouse',
            'promotion_id',
            'warehouse_id'
        )->withTimestamps();
    }

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'promotion_products',
            'promotion_id',
            'product_id'
        )->withTimestamps();
    }

    public function usages()
    {
        return $this->hasMany(PromotionUsage::class, 'promotion_id');
    }

    /**
     * Compute the monetary discount this promotion would deduct from the given
     * cart subtotal. Returns 0 when the promotion does not qualify on amount alone
     * (qualification is decided in PromotionEngine).
     */
    public function computeDiscount(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($this->discount_type === 'percentage') {
            return round($subtotal * ((float) $this->discount_value / 100), 2);
        }

        return round(min((float) $this->discount_value, $subtotal), 2);
    }
}
