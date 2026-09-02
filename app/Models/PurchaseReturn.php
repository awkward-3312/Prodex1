<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'GrandTotal', 'time',
        'user_id', 'discount', 'shipping',
        'warehouse_id', 'purchase_id', 'provider_id', 'notes', 'TaxNet', 'tax_rate', 'statut',
        'paid_amount', 'payment_statut', 'created_at', 'updated_at', 'deleted_at',
        // MS1 — location-native (inactivo hasta MS2). NULL => documento legacy.
        'inventory_location_id', 'inventory_effect_snapshot',
    ];

    protected $casts = [
        'GrandTotal' => 'double',
        'user_id' => 'integer',
        'purchase_id' => 'integer',
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'inventory_location_id' => 'integer',
        'inventory_effect_snapshot' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\PurchaseReturnDetails');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentPurchaseReturns');
    }
}
