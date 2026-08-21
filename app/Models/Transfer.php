<?php

namespace App\Models;

use App\Services\TransferDispatchGuardService;
use App\Services\TransferLogisticsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Transfer extends Model
{
    protected $table = 'transfers';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'id', 'date', 'user_id', 'from_warehouse_id', 'to_warehouse_id', 'time',
        'items', 'statut', 'approval_status', 'receiving_token', 'logistics_status',
        'dispatched_at', 'dispatched_by_user_id', 'received_at', 'received_by_user_id',
        'notes', 'GrandTotal', 'discount', 'shipping', 'TaxNet', 'tax_rate',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'items' => 'double',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'dispatched_by_user_id' => 'integer',
        'received_by_user_id' => 'integer',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer) {
            static::assertDifferentWarehouses($transfer);

            // New transfers cannot credit destination stock from the legacy
            // "completed" selector. Completion now belongs to destination receipt.
            if ($transfer->approval_status === 'pending' && $transfer->statut === 'completed') {
                $transfer->statut = 'sent';
            }
        });

        static::updating(function (Transfer $transfer) {
            static::assertDifferentWarehouses($transfer);

            if (! Schema::hasColumn('transfers', 'logistics_status')) {
                return;
            }

            $originalStatus = (string) $transfer->getOriginal('logistics_status');
            $lockedStatuses = ['in_transit', 'partially_received', 'received', 'received_with_issues'];

            // Legacy transfer forms still expose a "completed" option. Before a
            // physical destination receipt exists, normalize that intent to "sent"
            // so approval can only debit the source warehouse.
            if (! in_array($originalStatus, ['received', 'received_with_issues'], true)
                && $transfer->isDirty('statut')
                && $transfer->statut === 'completed') {
                $transfer->statut = 'sent';
            }

            if (! in_array($originalStatus, $lockedStatuses, true)) {
                return;
            }

            if ($transfer->isDirty('from_warehouse_id') || $transfer->isDirty('to_warehouse_id')) {
                throw ValidationException::withMessages([
                    'transfer' => 'No se puede cambiar el origen o destino después de despachar la transferencia.',
                ]);
            }

            if ($transfer->isDirty('statut') && $transfer->statut === 'completed') {
                throw ValidationException::withMessages([
                    'transfer' => 'La transferencia solo puede completarse desde la recepción de la bodega destino.',
                ]);
            }

            if ($transfer->isDirty('deleted_at')) {
                throw ValidationException::withMessages([
                    'transfer' => 'Una transferencia despachada no puede eliminarse; debe resolverse mediante el flujo logístico.',
                ]);
            }
        });

        static::saved(function (Transfer $transfer) {
            if (! Schema::hasColumn('transfers', 'logistics_status')) {
                return;
            }

            if ($transfer->isApproved() && $transfer->statut === 'sent') {
                $fresh = $transfer->fresh();

                // The legacy approval controller performs the source movement before
                // saving approval_status. This integrity gate executes inside the same
                // database transaction: any insufficient aggregate/batch stock throws
                // and rolls the entire approval/dispatch back atomically.
                app(TransferDispatchGuardService::class)->finalizeDispatch($fresh);

                // Logistics metadata is written through the query builder, making this
                // synchronization idempotent and avoiding model-event recursion.
                app(TransferLogisticsService::class)->syncDispatchState($fresh, auth()->user());
            }
        });
    }

    protected static function assertDifferentWarehouses(Transfer $transfer): void
    {
        if ($transfer->from_warehouse_id
            && $transfer->to_warehouse_id
            && (int) $transfer->from_warehouse_id === (int) $transfer->to_warehouse_id) {
            throw ValidationException::withMessages([
                'transfer' => 'La bodega de origen y la bodega destino deben ser diferentes.',
            ]);
        }
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\TransferDetail');
    }

    public function receipts()
    {
        return $this->hasMany(TransferReceipt::class);
    }

    public function from_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'from_warehouse_id');
    }

    public function to_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'to_warehouse_id');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * Accessor to ensure OLD TRANSFERS SAFETY:
     * any existing row with a NULL approval_status is treated as "approved".
     */
    public function getApprovalStatusAttribute($value)
    {
        if ($value === null) {
            return 'approved';
        }

        return $value;
    }

    public function isApproved()
    {
        return $this->approval_status === 'approved';
    }
}
