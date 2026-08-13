<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single serialized physical unit (serial number / IMEI).
 *
 * Lifecycle is driven by `status`. Rows are not deleted on a return — the
 * status changes and a ProductSerialMovement row records the event.
 */
class ProductSerial extends Model
{
    protected $table = 'product_serials';

    const STATUS_AVAILABLE = 'available';
    const STATUS_SOLD = 'sold';
    const STATUS_RETURNED_CUSTOMER = 'returned_customer';
    const STATUS_RETURNED_SUPPLIER = 'returned_supplier';
    const STATUS_DAMAGED = 'damaged';
    const STATUS_RESERVED = 'reserved';

    protected $fillable = [
        'serial_number',
        'product_id', 'product_variant_id', 'warehouse_id',
        'status',
        'purchase_id', 'purchase_detail_id', 'provider_id', 'cost',
        'sale_id', 'sale_detail_id', 'client_id',
        'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'warehouse_id' => 'integer',
        'purchase_id' => 'integer',
        'purchase_detail_id' => 'integer',
        'provider_id' => 'integer',
        'cost' => 'double',
        'sale_id' => 'integer',
        'sale_detail_id' => 'integer',
        'client_id' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function movements()
    {
        return $this->hasMany(ProductSerialMovement::class, 'product_serial_id')->orderBy('id', 'asc');
    }

    public function scopeAvailable($q)
    {
        return $q->where('status', self::STATUS_AVAILABLE);
    }

    public function scopeForProduct($q, $productId)
    {
        return $q->where('product_id', $productId);
    }

    public function scopeForWarehouse($q, $warehouseId)
    {
        return $q->where('warehouse_id', $warehouseId);
    }
}
