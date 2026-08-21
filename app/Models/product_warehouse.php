<?php

namespace App\Models;

use App\Services\InventoryCompatibilityService;
use App\Services\PosLocationStockBridge;
use App\Services\SaleReturnLocationStockBridge;
use App\Services\TransferLocationStockBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class product_warehouse extends Model
{
    protected $table = 'product_warehouse';

    protected $fillable = [
        'product_id', 'warehouse_id', 'product_variant_id', 'qte', 'manage_stock',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_variant_id' => 'integer',
        'manage_stock' => 'integer',
        'qte' => 'double',
    ];

    public function getQteAttribute($value)
    {
        if (! app()->bound('request') || ! $this->product_id) return (float) $value;

        return app(PosLocationStockBridge::class)->legacyReadableQuantity(
            (int) $this->product_id,
            $this->product_variant_id ? (int) $this->product_variant_id : null,
            (float) $value,
            request()
        );
    }

    protected static function booted(): void
    {
        static::saving(function (product_warehouse $row) {
            if (! $row->exists || ! $row->isDirty('qte') || ! $row->product_id) return;

            $original = round((float) $row->getRawOriginal('qte'), 3);
            $attributes = $row->getAttributes();
            $target = round((float) ($attributes['qte'] ?? $original), 3);
            $variantId = $row->product_variant_id ? (int) $row->product_variant_id : null;

            // 1) POS sale from a branch inventory location.
            $redirected = app(PosLocationStockBridge::class)->redirectLegacyDecrease(
                (int) $row->product_id,
                $variantId,
                $original,
                $target
            );

            // 2) Approved transfer dispatch from a physical inventory location.
            if (! $redirected) {
                $redirected = app(TransferLocationStockBridge::class)->redirectLegacyDecrease(
                    (int) $row->product_id,
                    $variantId,
                    $original,
                    $target
                );
            }

            // 3) Customer return back to the original POS location (or reversal).
            if (! $redirected) {
                $redirected = app(SaleReturnLocationStockBridge::class)->redirectLegacyMutation(
                    (int) $row->product_id,
                    $variantId,
                    $original,
                    $target
                );
            }

            if ($redirected) {
                // Preserve the CD/legacy aggregate: physical branch movements now
                // live exclusively in InventoryLocationStock.
                $row->setAttribute('qte', $original);
            }
        });

        static::saved(function (product_warehouse $row) {
            if (! $row->warehouse_id || ! $row->product_id) return;
            if (! $row->wasChanged(['qte', 'deleted_at', 'manage_stock'])) return;
            if (! Schema::hasTable('inventory_transition_states')) return;

            app(InventoryCompatibilityService::class)->mirrorLegacySnapshot(
                (int) $row->warehouse_id,
                (int) $row->product_id,
                $row->product_variant_id ? (int) $row->product_variant_id : null,
                [
                    'user_id' => auth()->id(),
                    'reference_type' => 'legacy_product_warehouse_model_write',
                    'reference_id' => (string) $row->id,
                    'notes' => 'Escritura legacy replicada al motor por ubicación durante dual-write.',
                    'metadata' => [
                        'product_warehouse_id' => (int) $row->id,
                    ],
                ]
            );
        });
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function productVariant()
    {
        return $this->belongsTo('App\Models\ProductVariant');
    }
}
