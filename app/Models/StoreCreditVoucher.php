<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StoreCreditVoucher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'tenant_id',
        'client_id',
        'original_sale_id',
        'sale_return_id',
        'warehouse_id',
        'issued_by_user_id',
        'original_amount',
        'remaining_balance',
        'currency',
        'status',
        'issued_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'original_sale_id' => 'integer',
        'sale_return_id' => 'integer',
        'warehouse_id' => 'integer',
        'issued_by_user_id' => 'integer',
        'original_amount' => 'double',
        'remaining_balance' => 'double',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function originalSale()
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function saleReturn()
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function transactions()
    {
        return $this->hasMany(StoreCreditVoucherTransaction::class, 'voucher_id');
    }
}
