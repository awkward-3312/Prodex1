<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SaleReturn extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'GrandTotal', 'time',
        'user_id', 'discount', 'shipping',
        'warehouse_id', 'branch_id', 'inventory_location_id', 'cash_drawer_id',
        'client_id', 'sale_id', 'notes', 'TaxNet', 'tax_rate', 'statut',
        'paid_amount', 'payment_statut', 'refund_mode', 'store_credit_voucher_id', 'store_credit_amount', 'created_at', 'updated_at', 'deleted_at',
        'inventory_effect_snapshot',
    ];

    protected $casts = [
        'GrandTotal' => 'double',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'sale_id' => 'integer',
        'warehouse_id' => 'integer',
        'branch_id' => 'integer',
        'inventory_location_id' => 'integer',
        'cash_drawer_id' => 'integer',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'store_credit_voucher_id' => 'integer',
        'store_credit_amount' => 'double',
        // MS7-B1 — the physical plan (base unit) of a location-native return.
        'inventory_effect_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (SaleReturn $return) {
            if (! $return->sale_id) return;
            if (! Schema::hasColumn('sale_returns', 'inventory_location_id')) return;
            // MS7-B1 — a native SalesReturnController::store already resolved
            // and set the DESTINATION the user explicitly chose (which may
            // legitimately differ from the original sale's location — a
            // customer can return goods to a different valid location).
            // Only fall back to the historical POS "same location as the
            // sale" assumption when nothing has set inventory_location_id yet
            // (e.g. the legacy/POS-only creation path that predates MS7-B1).
            if ($return->inventory_location_id) return;

            $sale = Sale::find($return->sale_id);
            if (! $sale || ! $sale->inventory_location_id) return;

            // A customer return DEFAULTS to the physical location that
            // fulfilled the original POS sale, but this is a fallback only —
            // never overrides an explicit selection.
            $return->branch_id = $sale->branch_id;
            $return->inventory_location_id = $sale->inventory_location_id;
            $return->cash_drawer_id = $sale->cash_drawer_id;
        });
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\SaleReturnDetails');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function inventoryLocation()
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentSaleReturns');
    }

    public function storeCreditVoucher()
    {
        return $this->hasOne(StoreCreditVoucher::class, 'sale_return_id');
    }
}
