<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\Transfer;
use App\Models\TransferDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Final integrity gate for the legacy transfer approval path.
 *
 * TransferController debits aggregate product_warehouse stock immediately before
 * changing approval_status to approved. This service runs from the Transfer model
 * updating event, inside the same DB transaction, and therefore can still rollback
 * the whole approval if aggregate stock or per-batch stock is inconsistent.
 */
class TransferDispatchIntegrityService
{
    public function guardApproval(Transfer $transfer): void
    {
        if (! $transfer->isDirty('approval_status') || $transfer->approval_status !== 'approved') {
            return;
        }

        if ($transfer->getOriginal('approval_status') === 'approved') {
            return;
        }

        $details = $transfer->details()->lockForUpdate()->get();
        $this->assertAggregateSourceStockNotNegative($transfer, $details);
        $this->allocateTrackedBatches($transfer, $details);
    }

    private function assertAggregateSourceStockNotNegative(Transfer $transfer, $details): void
    {
        foreach ($details as $detail) {
            $query = DB::table('product_warehouse')
                ->whereNull('deleted_at')
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $detail->product_id);

            if ($detail->product_variant_id) {
                $query->where('product_variant_id', $detail->product_variant_id);
            } else {
                $query->whereNull('product_variant_id');
            }

            $row = $query->lockForUpdate()->first();
            if (! $row || (float) $row->qte < -0.000001) {
                throw ValidationException::withMessages([
                    'transfer' => 'No hay stock suficiente en la bodega de origen para despachar la transferencia. La operación fue revertida completa.',
                ]);
            }
        }
    }

    private function allocateTrackedBatches(Transfer $transfer, $details): void
    {
        if (! Schema::hasTable('product_batches')
            || ! Schema::hasTable('transfer_detail_batches')
            || ! Schema::hasColumn('products', 'is_batch_tracked')) {
            return;
        }

        foreach ($details as $detail) {
            $tracked = (bool) DB::table('products')->where('id', $detail->product_id)->value('is_batch_tracked');
            if (! $tracked) {
                continue;
            }

            // Idempotency: if an earlier strict workflow already persisted and
            // debited allocations, never allocate them again.
            if (TransferDetailBatch::where('transfer_detail_id', $detail->id)->exists()) {
                continue;
            }

            $baseQty = $this->toBaseQuantity((float) $detail->quantity, $detail->purchase_unit_id);
            if ($baseQty <= 0) {
                continue;
            }

            $query = ProductBatch::active()
                ->forProduct((int) $detail->product_id)
                ->forWarehouse((int) $transfer->from_warehouse_id)
                ->where('qty', '>', 0);

            if ($detail->product_variant_id) {
                $query->where('product_variant_id', $detail->product_variant_id);
            } else {
                $query->whereNull('product_variant_id');
            }

            $batches = $query->fefo()->lockForUpdate()->get();
            $available = (float) $batches->sum('qty');
            if ($available + 0.000001 < $baseQty) {
                throw ValidationException::withMessages([
                    'transfer' => 'El stock total existe, pero los lotes disponibles no alcanzan para despachar el producto '.$detail->product_id.'. La transferencia no fue aprobada.',
                ]);
            }

            $remaining = $baseQty;
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
                throw ValidationException::withMessages([
                    'transfer' => 'No se pudo completar la asignación FEFO de lotes. La transferencia fue revertida.',
                ]);
            }
        }
    }

    private function toBaseQuantity(float $quantity, ?int $unitId): float
    {
        if (! $unitId) {
            return $quantity;
        }

        $unit = Unit::find($unitId);
        if (! $unit || ! $unit->operator_value) {
            return $quantity;
        }

        return $unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value;
    }
}
