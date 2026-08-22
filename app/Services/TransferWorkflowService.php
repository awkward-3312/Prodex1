<?php

namespace App\Services;

use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferWorkflowService
{
    public function approve(Transfer $transfer, User $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $locked = Transfer::whereNull('deleted_at')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status === 'rejected') {
                throw ValidationException::withMessages(['transfer' => 'Una transferencia rechazada debe editarse antes de volver a aprobarse.']);
            }
            if ($locked->isApproved()) return $locked;
            if (! in_array((string) $locked->logistics_status, ['', 'pending'], true)) {
                throw ValidationException::withMessages(['transfer' => 'La transferencia ya inició su flujo logístico y no puede volver a aprobarse.']);
            }

            $locked->approval_status = 'approved';
            $locked->save();

            return $locked->fresh();
        }, 5);
    }

    public function reject(Transfer $transfer, User $actor, ?string $reason = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor, $reason) {
            $locked = Transfer::whereNull('deleted_at')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->isApproved()) {
                throw ValidationException::withMessages(['transfer' => 'Una transferencia aprobada no puede rechazarse desde este paso.']);
            }
            if (! in_array((string) $locked->logistics_status, ['', 'pending'], true)) {
                throw ValidationException::withMessages(['transfer' => 'La transferencia ya inició su flujo logístico.']);
            }

            $locked->approval_status = 'rejected';
            $locked->save();

            app(TransferLogisticsService::class)->recordEvent(
                $locked->id,
                'rejection_note',
                $actor->id,
                $locked->from_warehouse_id,
                ['reason' => $reason]
            );

            return $locked->fresh();
        }, 5);
    }

    public function dispatch(Transfer $transfer, User $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor) {
            $locked = Transfer::with('details')->whereNull('deleted_at')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isApproved()) {
                throw ValidationException::withMessages(['transfer' => 'Primero debes aprobar la transferencia.']);
            }
            if (in_array((string) $locked->logistics_status, ['in_transit', 'partially_received', 'received', 'received_with_issues'], true)) {
                return $locked;
            }
            if ($locked->statut !== 'sent') {
                $locked->statut = 'sent';
                $locked->save();
            }

            if ($locked->from_inventory_location_id) {
                app(TransferLocationDispatchService::class)->ensureDispatched($locked);
            } else {
                $this->debitLegacySource($locked);
            }

            app(TransferDispatchGuardService::class)->finalizeDispatch($locked);
            app(TransferLogisticsService::class)->syncDispatchState($locked, $actor);

            return Transfer::with(['from_warehouse', 'to_warehouse'])->findOrFail($locked->id);
        }, 5);
    }

    private function debitLegacySource(Transfer $transfer): void
    {
        foreach (TransferDetail::where('transfer_id', $transfer->id)->orderBy('id')->get() as $detail) {
            $product = Product::find($detail->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['transfer' => 'Uno de los productos de la transferencia ya no existe.']);
            }
            $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
            $unit = $unitId ? Unit::find($unitId) : null;
            if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
                throw ValidationException::withMessages(['transfer' => 'La unidad de compra de '.$product->name.' no permite calcular el despacho.']);
            }
            $qty = $unit->operator === '/'
                ? (float) $detail->quantity / (float) $unit->operator_value
                : (float) $detail->quantity * (float) $unit->operator_value;

            $query = product_warehouse::whereNull('deleted_at')
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $detail->product_id);
            $detail->product_variant_id
                ? $query->where('product_variant_id', $detail->product_variant_id)
                : $query->where(function ($q) { $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0); });

            $row = $query->lockForUpdate()->first();
            if (! $row || (float) $row->getRawOriginal('qte') + 0.000001 < $qty) {
                throw ValidationException::withMessages(['transfer' => 'Stock insuficiente para despachar '.$product->name.'.']);
            }
            $row->qte = (float) $row->getRawOriginal('qte') - $qty;
            $row->save();
        }
    }
}
