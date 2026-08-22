<?php

namespace App\Models;

use App\Services\TransferDispatchGuardService;
use App\Services\TransferLocationDispatchService;
use App\Services\TransferLogisticsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Transfer extends Model
{
    protected $table = 'transfers';
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'id', 'date', 'user_id', 'from_warehouse_id', 'to_warehouse_id',
        'from_inventory_location_id', 'to_inventory_location_id', 'time',
        'items', 'statut', 'approval_status', 'receiving_token', 'logistics_status',
        'dispatched_at', 'dispatched_by_user_id', 'received_at', 'received_by_user_id',
        'notes', 'GrandTotal', 'discount', 'shipping', 'TaxNet', 'tax_rate',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer', 'from_warehouse_id' => 'integer', 'to_warehouse_id' => 'integer',
        'from_inventory_location_id' => 'integer', 'to_inventory_location_id' => 'integer',
        'items' => 'double', 'GrandTotal' => 'double', 'discount' => 'double', 'shipping' => 'double',
        'TaxNet' => 'double', 'tax_rate' => 'double', 'dispatched_by_user_id' => 'integer',
        'received_by_user_id' => 'integer', 'dispatched_at' => 'datetime', 'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer) {
            static::captureRequestedLocations($transfer);
            static::assertDifferentOrigins($transfer);
            if ($transfer->approval_status === 'pending' && $transfer->statut === 'completed') $transfer->statut = 'sent';
        });

        static::created(function (Transfer $transfer) {
            if (! Schema::hasTable('transfer_events')) return;
            app(TransferLogisticsService::class)->recordEvent(
                $transfer->id, 'created', auth()->id() ?: $transfer->user_id, $transfer->from_warehouse_id,
                [
                    'reference' => $transfer->Ref,
                    'from_warehouse_id' => $transfer->from_warehouse_id ? (int) $transfer->from_warehouse_id : null,
                    'to_warehouse_id' => $transfer->to_warehouse_id ? (int) $transfer->to_warehouse_id : null,
                    'from_inventory_location_id' => $transfer->from_inventory_location_id ? (int) $transfer->from_inventory_location_id : null,
                    'to_inventory_location_id' => $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null,
                    'approval_status' => $transfer->approval_status,
                ], true
            );
        });

        static::updating(function (Transfer $transfer) {
            if (! in_array((string) $transfer->getOriginal('logistics_status'), ['in_transit', 'partially_received', 'received', 'received_with_issues'], true)) {
                static::captureRequestedLocations($transfer);
            }
            static::assertDifferentOrigins($transfer);
            if (! Schema::hasColumn('transfers', 'logistics_status')) return;

            $originalStatus = (string) $transfer->getOriginal('logistics_status');
            $lockedStatuses = ['in_transit', 'partially_received', 'received', 'received_with_issues'];

            if (! in_array($originalStatus, ['received', 'received_with_issues'], true)
                && $transfer->isDirty('statut') && $transfer->statut === 'completed') $transfer->statut = 'sent';
            if (! in_array($originalStatus, $lockedStatuses, true)) return;

            if ($transfer->isDirty('from_warehouse_id') || $transfer->isDirty('to_warehouse_id')
                || $transfer->isDirty('from_inventory_location_id') || $transfer->isDirty('to_inventory_location_id')) {
                throw ValidationException::withMessages(['transfer' => 'No se puede cambiar el origen o destino después de despachar la transferencia.']);
            }
            if ($transfer->isDirty('statut') && $transfer->statut === 'completed') {
                throw ValidationException::withMessages(['transfer' => 'La transferencia solo puede completarse desde la recepción del destino.']);
            }
            if ($transfer->isDirty('deleted_at')) {
                throw ValidationException::withMessages(['transfer' => 'Una transferencia despachada no puede eliminarse; debe resolverse mediante el flujo logístico.']);
            }
        });

        static::saved(function (Transfer $transfer) {
            if (! Schema::hasColumn('transfers', 'logistics_status')) return;
            $actorId = auth()->id() ?: $transfer->user_id;

            if ($transfer->wasChanged('approval_status') && Schema::hasTable('transfer_events')) {
                $approval = (string) $transfer->approval_status;
                if (in_array($approval, ['approved', 'rejected'], true)) {
                    app(TransferLogisticsService::class)->recordEvent(
                        $transfer->id, $approval, $actorId, $transfer->from_warehouse_id,
                        ['reference' => $transfer->Ref, 'approval_status' => $approval]
                    );
                }
            }

            // Backward compatibility: the historical TransferController@approve endpoint
            // approved and dispatched in one action. Keep that behavior only for that
            // legacy endpoint. The new TransferWorkflowController separates approval
            // from physical dispatch so stock moves only when the user clicks Despachar.
            $action = '';
            try {
                $route = request()->route();
                $action = $route ? (string) $route->getActionName() : '';
            } catch (\Throwable $e) {
                $action = '';
            }
            $legacyApproval = str_contains($action, 'TransferController@approve')
                && ! str_contains($action, 'TransferWorkflowController');

            if ($legacyApproval && $transfer->isApproved() && $transfer->statut === 'sent') {
                $fresh = $transfer->fresh();
                if ($fresh->from_inventory_location_id) app(TransferLocationDispatchService::class)->ensureDispatched($fresh);
                app(TransferDispatchGuardService::class)->finalizeDispatch($fresh);
                app(TransferLogisticsService::class)->syncDispatchState($fresh, auth()->user());
            }
        });
    }

    protected static function captureRequestedLocations(Transfer $transfer): void
    {
        if (! Schema::hasColumn('transfers', 'from_inventory_location_id')) return;
        try { $request = request(); } catch (\Throwable $e) { return; }
        if (! $request) return;

        $from = $request->input('transfer.from_inventory_location_id') ?: $request->input('transfer.from_inventory_location');
        $to = $request->input('transfer.to_inventory_location_id') ?: $request->input('transfer.to_inventory_location');
        if ($from) $transfer->from_inventory_location_id = (int) $from;
        if ($to) $transfer->to_inventory_location_id = (int) $to;
    }

    protected static function assertDifferentOrigins(Transfer $transfer): void
    {
        if ($transfer->from_inventory_location_id && $transfer->to_inventory_location_id) {
            if ((int) $transfer->from_inventory_location_id === (int) $transfer->to_inventory_location_id) {
                throw ValidationException::withMessages(['transfer' => 'La ubicación de inventario de origen y destino deben ser diferentes.']);
            }
            return;
        }
        if ($transfer->from_warehouse_id && $transfer->to_warehouse_id
            && (int) $transfer->from_warehouse_id === (int) $transfer->to_warehouse_id) {
            throw ValidationException::withMessages(['transfer' => 'La bodega de origen y la bodega destino deben ser diferentes.']);
        }
    }

    public function user() { return $this->belongsTo('App\Models\User'); }
    public function details() { return $this->hasMany('App\Models\TransferDetail'); }
    public function receipts() { return $this->hasMany(TransferReceipt::class); }
    public function from_warehouse() { return $this->belongsTo('App\Models\Warehouse', 'from_warehouse_id'); }
    public function to_warehouse() { return $this->belongsTo('App\Models\Warehouse', 'to_warehouse_id'); }
    public function fromInventoryLocation() { return $this->belongsTo(InventoryLocation::class, 'from_inventory_location_id'); }
    public function toInventoryLocation() { return $this->belongsTo(InventoryLocation::class, 'to_inventory_location_id'); }
    public function dispatchedBy() { return $this->belongsTo(User::class, 'dispatched_by_user_id'); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by_user_id'); }

    public function getApprovalStatusAttribute($value) { return $value === null ? 'approved' : $value; }
    public function isApproved() { return $this->approval_status === 'approved'; }
}
