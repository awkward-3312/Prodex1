<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Final integrity gate that runs after the legacy transfer approval movement but
 * before a transfer is exposed as "in transit". Throwing here rolls the approval
 * transaction back, including the aggregate warehouse debit.
 */
class TransferDispatchGuardService
{
    public function finalizeDispatch(Transfer $transfer): void
    {
        if (! $transfer->isApproved() || $transfer->statut !== 'sent') {
            return;
        }

        $details = TransferDetail::where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get();

        if ($details->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede despachar una transferencia sin productos.',
            ]);
        }

        foreach ($details as $detail) {
            $this->assertAggregateSourceIntegrity($transfer, $detail);
            $this->ensureTrackedBatchAllocation($transfer, $detail);
        }
    }

    protected function assertAggregateSourceIntegrity(Transfer $transfer, TransferDetail $detail): void
    {
        $query = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $detail->product_id);

        if ($detail->product_variant_id) {
            $query->where('product_variant_id', $detail->product_variant_id);
        } else {
            $query->where(function ($q) {
                $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0);
            });
        }

        $source = $query->lockForUpdate()->first();
        if (! $source) {
            throw ValidationException::withMessages([
                'transfer' => 'La bodega de origen no tiene una existencia válida para uno de los productos del despacho.',
            ]);
        }

        // The legacy approval path has already debited this row. A negative result
        // proves the shipment exceeded available stock; raising here rolls it back.
        if ((float) $source->qte < -0.000001) {
            $product = Product::find($detail->product_id);
            throw ValidationException::withMessages([
                'transfer' => 'Stock insuficiente para despachar '.($product?->name ?: 'uno de los productos').'.',
            ]);
        }
    }

    protected function ensureTrackedBatchAllocation(Transfer $transfer, TransferDetail $detail): void
    {
        if (! Schema::hasTable('product_batches') || ! Schema::hasTable('transfer_detail_batches')) {
            return;
        }

        $product = Product::find($detail->product_id);
        if (! $product || ! (bool) $product->is_batch_tracked) {
            return;
        }

        // Existing pivots mean an older/explicit batch flow already debited source
        // lots. Never duplicate that movement.
        if (TransferDetailBatch::where('transfer_detail_id', $detail->id)->exists()) {
            return;
        }

        $required = (float) $detail->quantity;
        if ($required <= 0) {
            return;
        }

        $query = ProductBatch::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $detail->product_id)
            ->where('qty', '>', 0);

        if ($detail->product_variant_id) {
            $query->where('product_variant_id', $detail->product_variant_id);
        } else {
            $query->whereNull('product_variant_id');
        }

        // FEFO is deterministic and prevents a black hole in the historical
        // pending->approval flow, where the legacy controller did not persist the
        // user's temporary batch picker rows before approval.
        $batches = $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $available = (float) $batches->sum('qty');
        if ($available + 0.000001 < $required) {
            throw ValidationException::withMessages([
                'transfer' => 'No hay suficiente existencia por lote para despachar '.$product->name.'.',
            ]);
        }

        $remaining = $required;
        foreach ($batches as $batch) {
            if ($remaining <= 0.000001) {
                break;
            }

            $take = min((float) $batch->qty, $remaining);
            if ($take <= 0) {
                continue;
            }

            $batch->qty = (float) $batch->qty - $take;
            $batch->save();

            TransferDetailBatch::create([
                'transfer_detail_id' => $detail->id,
                'source_batch_id' => $batch->id,
                'dest_batch_id' => null,
                'qty' => $take,
                'unit_cost' => $batch->unit_cost,
            ]);

            $remaining -= $take;
        }

        if ($remaining > 0.000001) {
            // Defensive invariant: the availability check above means this should
            // never happen, but never expose an in-transit transfer if allocation is
            // incomplete. The surrounding DB transaction will restore all changes.
            throw ValidationException::withMessages([
                'transfer' => 'No se pudo completar la asignación de lotes del despacho.',
            ]);
        }
    }
}
