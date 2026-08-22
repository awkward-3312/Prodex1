<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferDetailSerial extends Model
{
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_DEFECTIVE = 'defective';
    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'transfer_detail_id',
        'product_serial_id',
        'transfer_receipt_item_id',
        'status',
        'issue_type',
        'received_at',
    ];

    protected $casts = [
        'transfer_detail_id' => 'integer',
        'product_serial_id' => 'integer',
        'transfer_receipt_item_id' => 'integer',
        'received_at' => 'datetime',
    ];

    public function transferDetail()
    {
        return $this->belongsTo(TransferDetail::class, 'transfer_detail_id');
    }

    public function serial()
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
    }

    public function receiptItem()
    {
        return $this->belongsTo(TransferReceiptItem::class, 'transfer_receipt_item_id');
    }
}
