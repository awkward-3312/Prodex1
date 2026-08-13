<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit entry for a ProductSerial lifecycle event.
 */
class ProductSerialMovement extends Model
{
    protected $table = 'product_serial_movements';

    public $timestamps = false;

    const ACTION_PURCHASED = 'purchased';
    const ACTION_SOLD = 'sold';
    const ACTION_SALE_RETURNED = 'sale_returned';
    const ACTION_PURCHASE_RETURNED = 'purchase_returned';
    const ACTION_STATUS_CHANGED = 'status_changed';
    const ACTION_ADJUSTED = 'adjusted';

    protected $fillable = [
        'product_serial_id', 'serial_number',
        'action', 'from_status', 'to_status',
        'warehouse_id',
        'reference_type', 'reference_id',
        'user_id', 'notes',
        'created_at',
    ];

    protected $casts = [
        'product_serial_id' => 'integer',
        'warehouse_id' => 'integer',
        'reference_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }
}
