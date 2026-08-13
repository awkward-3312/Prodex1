<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionUsage extends Model
{
    protected $table = 'promotion_usages';

    protected $fillable = [
        'promotion_id',
        'sale_id',
        'client_id',
        'warehouse_id',
        'discount_amount',
        'code',
        'used_at',
    ];

    protected $casts = [
        'promotion_id' => 'integer',
        'sale_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'discount_amount' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
