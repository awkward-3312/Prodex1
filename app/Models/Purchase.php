<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'provider_id', 'warehouse_id', 'GrandTotal', 'time',
        'discount', 'shipping', 'statut', 'notes', 'TaxNet', 'tax_rate', 'paid_amount',
        'payment_statut', 'created_at', 'updated_at', 'deleted_at',
        // MS1 — location-native (inactivo hasta MS2). NULL => documento legacy.
        'inventory_location_id', 'inventory_effect_snapshot',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'inventory_location_id' => 'integer',
        'inventory_effect_snapshot' => 'array',
    ];

    public function details()
    {
        return $this->hasMany('App\Models\PurchaseDetail');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentPurchase');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function documents()
    {
        return $this->hasMany('App\Models\PurchaseDocument', 'purchase_id');
    }

    public function extra_charges()
    {
        return $this->hasMany('App\Models\PurchaseExtraCharge', 'purchase_id')->whereNull('deleted_at');
    }

    public function custom_fields()
    {
        return $this->hasMany('App\Models\PurchaseCustomField', 'purchase_id')->whereNull('deleted_at');
    }
}
