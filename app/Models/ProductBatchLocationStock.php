<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatchLocationStock extends Model
{
    protected $table = 'product_batch_location_stocks';

    protected $fillable = [
        'product_batch_id',
        'inventory_location_id',
        'quantity',
        'reserved_quantity',
    ];

    protected $casts = [
        'product_batch_id' => 'integer',
        'inventory_location_id' => 'integer',
        'quantity' => 'double',
        'reserved_quantity' => 'double',
    ];

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function getAvailableQuantityAttribute(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->reserved_quantity, 3));
    }
}
