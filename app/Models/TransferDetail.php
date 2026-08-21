<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferDetail extends Model
{
    protected $table = 'transfer_details';

    protected $fillable = [
        'id', 'transfer_id', 'quantity', 'purchase_unit_id', 'product_id', 'total', 'product_variant_id',
        'cost', 'TaxNet', 'discount', 'discount_method', 'tax_method',
    ];

    protected $casts = [
        'total' => 'double',
        'cost' => 'double',
        'TaxNet' => 'double',
        'discount' => 'double',
        'quantity' => 'double',
        'transfer_id' => 'integer',
        'purchase_unit_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
    ];

    protected static function booted(): void
    {
        $guard = function (TransferDetail $detail) {
            if ((float) $detail->quantity <= 0) {
                throw ValidationException::withMessages([
                    'transfer' => 'Todas las líneas de una transferencia deben tener una cantidad mayor que cero.',
                ]);
            }

            if (! Schema::hasColumn('transfers', 'logistics_status') || ! $detail->transfer_id) {
                return;
            }

            $status = Transfer::whereKey($detail->transfer_id)->value('logistics_status');
            if (in_array($status, ['in_transit', 'partially_received', 'received', 'received_with_issues'], true)) {
                throw ValidationException::withMessages([
                    'transfer' => 'Las líneas de una transferencia despachada ya no pueden modificarse ni eliminarse.',
                ]);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting(function (TransferDetail $detail) {
            if (! Schema::hasColumn('transfers', 'logistics_status') || ! $detail->transfer_id) {
                return;
            }

            $status = Transfer::whereKey($detail->transfer_id)->value('logistics_status');
            if (in_array($status, ['in_transit', 'partially_received', 'received', 'received_with_issues'], true)) {
                throw ValidationException::withMessages([
                    'transfer' => 'Las líneas de una transferencia despachada ya no pueden modificarse ni eliminarse.',
                ]);
            }
        });
    }

    public function transfer()
    {
        return $this->belongsTo('App\Models\Transfer');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
