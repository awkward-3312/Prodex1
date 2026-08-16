<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreCreditVoucherTransaction extends Model
{
    protected $fillable = [
        'voucher_id',
        'sale_id',
        'sale_return_id',
        'user_id',
        'warehouse_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'notes',
    ];

    protected $casts = [
        'voucher_id' => 'integer',
        'sale_id' => 'integer',
        'sale_return_id' => 'integer',
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        'amount' => 'double',
        'balance_before' => 'double',
        'balance_after' => 'double',
    ];

    public function voucher()
    {
        return $this->belongsTo(StoreCreditVoucher::class, 'voucher_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }
}
