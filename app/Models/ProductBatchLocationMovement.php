<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatchLocationMovement extends Model
{
    protected $table = 'product_batch_location_movements';

    protected $fillable = [
        'product_batch_id',
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
        'product_batch_id' => 'integer',
        'from_inventory_location_id' => 'integer',
        'to_inventory_location_id' => 'integer',
        'quantity' => 'double',
        'user_id' => 'integer',
        'metadata' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function fromInventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'from_inventory_location_id');
    }

    public function toInventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'to_inventory_location_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('El ledger de movimientos de lotes es inmutable.'));
        static::deleting(fn () => throw new \LogicException('El ledger de movimientos de lotes es inmutable.'));
    }
}
