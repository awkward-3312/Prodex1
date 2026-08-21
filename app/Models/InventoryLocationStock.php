<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InventoryLocationStock extends Model
{
    protected $table = 'inventory_location_stocks';

    protected $fillable = [
        'inventory_location_id',
        'product_id',
        'product_variant_id',
        'variant_key',
        'quantity',
        'reserved_quantity',
        'manage_stock',
    ];

    protected $casts = [
        'inventory_location_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'variant_key' => 'integer',
        'quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'manage_stock' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (InventoryLocationStock $stock) {
            $stock->variant_key = (int) ($stock->product_variant_id ?: 0);

            if ((float) $stock->quantity < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'La existencia de una ubicación no puede ser negativa.',
                ]);
            }

            if ((float) $stock->reserved_quantity < 0) {
                throw ValidationException::withMessages([
                    'reserved_quantity' => 'La cantidad reservada no puede ser negativa.',
                ]);
            }

            if ((float) $stock->reserved_quantity > (float) $stock->quantity) {
                throw ValidationException::withMessages([
                    'reserved_quantity' => 'La cantidad reservada no puede superar la existencia física.',
                ]);
            }
        });
    }

    public function location()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getAvailableQuantityAttribute(): float
    {
        return round((float) $this->quantity - (float) $this->reserved_quantity, 3);
    }
}
