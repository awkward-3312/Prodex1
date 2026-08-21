<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferReceipt extends Model
{
    protected $fillable = [
        'transfer_id', 'warehouse_id', 'received_by_user_id', 'status', 'notes', 'received_at',
    ];

    protected $casts = [
        'transfer_id' => 'integer',
        'warehouse_id' => 'integer',
        'received_by_user_id' => 'integer',
        'received_at' => 'datetime',
    ];

    public function transfer() { return $this->belongsTo(Transfer::class); }
    public function items() { return $this->hasMany(TransferReceiptItem::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by_user_id'); }
}
