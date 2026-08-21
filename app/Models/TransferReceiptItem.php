<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferReceiptItem extends Model
{
    protected $fillable = [
        'transfer_receipt_id', 'transfer_detail_id', 'quantity_good', 'quantity_defective', 'quantity_missing', 'notes',
    ];

    protected $casts = [
        'transfer_receipt_id' => 'integer',
        'transfer_detail_id' => 'integer',
        'quantity_good' => 'float',
        'quantity_defective' => 'float',
        'quantity_missing' => 'float',
    ];

    public function receipt() { return $this->belongsTo(TransferReceipt::class, 'transfer_receipt_id'); }
    public function transferDetail() { return $this->belongsTo(TransferDetail::class); }
}
