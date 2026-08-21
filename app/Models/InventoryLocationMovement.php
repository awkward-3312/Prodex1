<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class InventoryLocationMovement extends Model
{
    protected $table = 'inventory_location_movements';

    protected $fillable = [
        'movement_type',
        'product_id',
        'product_variant_id',
        'from_inventory_location_id',
        'to_inventory_location_id',
        'quantity',
        'user_id',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'from_inventory_location_id' => 'integer',
        'to_inventory_location_id' => 'integer',
        'quantity' => 'decimal:3',
        'user_id' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Los movimientos de inventario son inmutables y no pueden editarse.');
        });

        static::deleting(function () {
            throw new LogicException('Los movimientos de inventario son inmutables y no pueden eliminarse.');
        });
    }

    public function fromLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'from_inventory_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'to_inventory_location_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
