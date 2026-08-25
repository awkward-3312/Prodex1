<?php

namespace App\Models;

use App\Services\PosLocationSaleStockService;
use App\Services\PosLocationStockBridge;
use App\Services\PosOperationalContextService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Sale extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'is_pos', 'client_id', 'GrandTotal', 'qte_retturn', 'TaxNet', 'tax_rate', 'notes', 'fiscal_exemption_data',
        'total_retturn', 'warehouse_id', 'branch_id', 'inventory_location_id', 'cash_drawer_id', 'user_id', 'statut', 'discount', 'discount_Method', 'shipping', 'time', 'used_points', 'earned_points', 'discount_from_points',
        'promotion_discount', 'promotion_code',
        'store_credit_amount',
        'paid_amount', 'payment_statut', 'created_at', 'updated_at', 'deleted_at', 'shipping_status', 'subscription_id', 'sales_agent_id',
        'sale_uuid',
        'woocommerce_order_id',
        'woocommerce_order_number',
        'woocommerce_order_status',
        'quickbooks_invoice_id',
        'quickbooks_realm_id',
        'quickbooks_synced_at',
        'quickbooks_sync_error',
    ];

    protected $casts = [
        'is_pos' => 'integer',
        'GrandTotal' => 'double',
        'qte_retturn' => 'double',
        'total_retturn' => 'double',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'branch_id' => 'integer',
        'inventory_location_id' => 'integer',
        'cash_drawer_id' => 'integer',
        'sales_agent_id' => 'integer',
        'subscription_id' => 'integer',
        'discount' => 'double',
        'promotion_discount' => 'double',
        'store_credit_amount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'used_points' => 'double',
        'earned_points' => 'double',
        'discount_from_points' => 'double',
        'fiscal_exemption_data' => 'array',
        'quickbooks_synced_at' => 'datetime',
        'woocommerce_order_id' => 'integer',
    ];

    public function subscription() { return $this->belongsTo(Subscription::class, 'subscription_id'); }
    public function user() { return $this->belongsTo('App\Models\User'); }
    public function details() { return $this->hasMany('App\Models\SaleDetail'); }
    public function saleDetails() { return $this->hasMany('App\Models\SaleDetail'); }
    public function client() { return $this->belongsTo('App\Models\Client'); }
    public function facture() { return $this->hasMany('App\Models\PaymentSale'); }

    /** Legacy warehouse relation retained during inventory cutover. */
    public function warehouse() { return $this->belongsTo('App\Models\Warehouse'); }
    public function branch() { return $this->belongsTo(Branch::class, 'branch_id'); }
    public function inventoryLocation() { return $this->belongsTo(InventoryLocation::class, 'inventory_location_id'); }
    public function cashDrawer() { return $this->belongsTo(CashDrawer::class, 'cash_drawer_id'); }
    public function salesAgent() { return $this->belongsTo('App\Models\SalesAgent', 'sales_agent_id'); }
    public function saleCommissions() { return $this->hasMany('App\Models\SaleCommission', 'sale_id'); }
    public function documents() { return $this->hasMany('App\Models\SaleDocument', 'sale_id'); }
    public function sarFiscalDocument() { return $this->hasOne(SarFiscalDocument::class, 'sale_id'); }

    protected static function booted()
    {
        static::creating(function (Sale $sale) {
            if ((int) $sale->is_pos !== 1 || ! $sale->user_id) return;
            if (! Schema::hasColumn('sales', 'branch_id')
                || ! Schema::hasColumn('sales', 'inventory_location_id')
                || ! Schema::hasColumn('sales', 'cash_drawer_id')) {
                return;
            }

            $user = User::whereNull('deleted_at')->find($sale->user_id);
            if (! $user) return;

            $request = request();
            $bridge = app(PosLocationStockBridge::class);
            if ($bridge->isLocationPosRequest($request)) {
                $bridge->assertCartSupported($request);
                $context = $bridge->resolveContext($request, $user);
            } else {
                $warehouseId = $sale->warehouse_id ?: ($request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null);
                $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
                $locationId = $request->filled('inventory_location_id') ? (int) $request->input('inventory_location_id') : null;
                $drawerId = $request->filled('cash_drawer_id') ? (int) $request->input('cash_drawer_id') : null;

                if (! $warehouseId && ! $branchId && ! $locationId && ! $drawerId) return;

                $context = app(PosOperationalContextService::class)->resolve(
                    $user,
                    $warehouseId,
                    $branchId,
                    $locationId,
                    $drawerId
                );
            }

            // Snapshot the resolved operational address, including the legacy
            // warehouse compatibility pointer. The request may temporarily use an
            // InventoryLocation id in the historical warehouse_id UI field; never
            // persist that synthetic value as if it were a real warehouse.
            $sale->warehouse_id = $context['warehouse_id'] ?? null;
            $sale->branch_id = $context['branch_id'] ?? null;
            $sale->inventory_location_id = $context['inventory_location_id'] ?? null;
            $sale->cash_drawer_id = $context['cash_drawer_id'] ?? null;
        });

        static::created(function (Sale $sale) {
            if ((int) $sale->is_pos !== 1 || ! $sale->inventory_location_id || ! $sale->branch_id) return;
            if (! app()->bound('request')) return;

            $request = request();
            if (! app(PosLocationStockBridge::class)->isLocationPosRequest($request)) return;

            // This executes inside PosController's surrounding DB transaction.
            // Any later payment, lot or serial validation failure therefore rolls
            // the stock movement back together with the sale header.
            app(PosLocationSaleStockService::class)->apply($sale, $request);
        });

        static::updating(function ($sale) {
            if ($sale->isDirty('quickbooks_invoice_id')) {
                $original = $sale->getOriginal('quickbooks_invoice_id');
                if (! empty($original)) $sale->quickbooks_invoice_id = $original;
            }
        });
    }
}
