<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseCustomField extends Model
{
    protected $table = 'purchase_custom_fields';

    protected $fillable = [
        'purchase_id', 'name', 'value',
    ];

    protected $dates = ['deleted_at', 'created_at', 'updated_at'];

    protected $casts = [
        'purchase_id' => 'integer',
    ];

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }
}
