<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'client_id', 'GrandTotal', 'warehouse_id', 'user_id', 'statut', 'time',
        'notes', 'discount', 'shipping', 'TaxNet', 'tax_rate', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'GrandTotal' => 'double',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',

    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\QuotationDetail');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    /**
     * Cotización -> Venta POS traceability (Option A).
     *
     * The sale produced by processing this quotation through the POS.
     * sales.quotation_id is UNIQUE, so this is at most one row and is also
     * the source of truth for "this quotation has been converted".
     */
    public function sale()
    {
        return $this->hasOne('App\Models\Sale', 'quotation_id')->whereNull('deleted_at');
    }
}
