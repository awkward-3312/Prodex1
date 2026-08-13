<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseExtraCharge extends Model
{
    protected $table = 'purchase_extra_charges';

    protected $fillable = [
        'purchase_id', 'name', 'amount',
    ];

    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    protected $casts = [
        'purchase_id' => 'integer',
        'amount' => 'double',
    ];

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }
}
