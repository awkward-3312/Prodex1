<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationStock;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Final integrity gate before a transfer becomes "in transit".
 *
 * Legacy transfers remain warehouse-based. New transfers use InventoryLocation as
 * the physical source; the approval bridge has already removed aggregate location
 * stock, and this service validates traceability and moves batch slices into transit.
 */
class TransferDispatchGuardService
{
    public function finalizeDispatch(Transfer $transfer): void
    {
        if (! $transfer->isApproved() || $transfer->statut !== 'sent') return;

        $details = TransferDetail::where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get();

        if ($details->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede despachar una transferencia sin productos.',
            ]);
        }

        if ($transfer->from_inventory_location_id) {
            $this->assertLocationTransfer($transfer);
        }

        foreach ($details as $detail) {
            if ($transfer->from_inventory_location_id) {
                $this->assertLocationSourceIntegrity($transfer, $detail);
                $this->ensureTrackedBatchLocationAllocation($transfer, $detail);
            } else {
                $this->assertAggregateSourceIntegrity($transfer, $detail);
                $this->ensureTrackedBatchAllocation($transfer, $detail);
            }
        }
    }

    protected function assertLocationTransfer(Transfer $transfer): void
    {
        if (! $transfer->to_inventory_location_id) {
            throw ValidationException::withMessages([
                'transfer' => 'Una transferencia por ubicación debe indicar una ubicación física de destino.',
            ]);
        }

        $from = InventoryLocation::active()->find($transfer->from_inventory_location_id);
        $to = InventoryLocation::active()->find($transfer->to_inventory_location_id);
        if (! $from || ! $to) {
            throw ValidationException::withMessages([
                'transfer' => 'La ubicación de origen o destino no existe o está inactiva.',
            ]);
        }
        if ((int) $from->id === (int) $to->id) {
            throw ValidationException::withMessages([
                'transfer' => 'El origen y destino físicos de la transferencia deben ser diferentes.',
            ]);
        }
    }

    protected function assertLocationSourceIntegrity(Transfer $transfer, TransferDetail $detail): void
    {
        // InventoryService::decrease has already executed inside the approval DB
        // transaction. It refuses negative or reserved stock, so reaching this gate
        // proves the aggregate debit was valid. We still require the resulting row
        // to exist so a partial schema/data migration cannot dispatch silently.
        $row = InventoryLocationStock::where('inventory_location_id', $transfer->from_inventory_location_id)
            ->where('product_id', $detail->product_id)
            ->where('variant_key', (int) ($detail->product_variant_id ?: 0))
            ->lockForUpdate()
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'transfer' => 'No se encontró el inventario físico del producto en la ubicación de origen.',
            ]);
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

        if ((float) $source->qte < -0.000001) {
            $product = Product::find($detail->product_id);
            throw ValidationException::withMessages([
                'transfer' => 'Stock insuficiente para despachar '.($product?->name ?: 'uno de los productos').'.',
            ]);
        }
    }

    protected function ensureTrackedBatchLocationAllocation(Transfer $transfer, TransferDetail $detail): void
    {
        if (! Schema::hasTable('product_batches')
            || ! Schema::hasTable('product_batch_location_stocks')
            || ! Schema::hasTable('transfer_detail_batches')) {
            return;
        }

        $product = Product::find($detail->product_id);
        if (! $product || ! (bool) $product->is_batch_tracked) return;
        if (TransferDetailBatch::where('transfer_detail_id', $detail->id)->exists()) return;

        $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
        $required = $this->toBaseQuantity((float) $detail->quantity, $unitId, $product->name);
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

        $stocks = ProductBatchLocationStock::with('batch')
            ->where('inventory_location_id', (int) $transfer->from_inventory_location_id)
            ->whereHas('batch', function ($query) use ($detail, $variantId) {
                $query->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->where('product_id', $detail->product_id);
                $variantId
                    ? $query->where('product_variant_id', $variantId)
                    : $query->whereNull('product_variant_id');
            })
            ->lockForUpdate()
            ->get()
            ->filter(fn ($stock) => $stock->available_quantity > 0)
            ->sortBy(function ($stock) {
                $expiry = optional($stock->batch)->expiry_date;
                return ($expiry ? $expiry->format('Y-m-d') : '9999-12-31')
                    .'|'.str_pad((string) $stock->product_batch_id, 12, '0', STR_PAD_LEFT);
            })
            ->values();

        $available = (float) $stocks->sum(fn ($stock) => $stock->available_quantity);
        if ($available + 0.000001 < $required) {
            throw ValidationException::withMessages([
                'transfer' => 'No hay suficiente existencia por lote en la ubicación de origen para despachar '.$product->name.'.',
            ]);
        }

        $remaining = $required;
        foreach ($stocks as $stock) {
            if ($remaining <= 0.000001) break;

            $take = min((float) $stock->available_quantity, $remaining);
            if ($take <= 0) continue;

            $batch = ProductBatch::whereKey($stock->product_batch_id)->lockForUpdate()->first();
            if (! $batch || (float) $batch->qty + 0.000001 < $take) {
                throw ValidationException::withMessages([
                    'transfer' => 'El lote agregado no coincide con su distribución física. Ejecuta reconciliación antes de despachar.',
                ]);
            }

            $stock->quantity = round((float) $stock->quantity - $take, 3);
            $stock->save();

            $batch->qty = round((float) $batch->qty - $take, 3);
            $batch->save();

            TransferDetailBatch::create([
                'transfer_detail_id' => $detail->id,
                'source_batch_id' => $batch->id,
                'dest_batch_id' => null,
                'qty' => $take,
                'unit_cost' => $batch->unit_cost,
            ]);

            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0.000001) {
            throw ValidationException::withMessages([
                'transfer' => 'No se pudo completar la asignación física de lotes del despacho.',
            ]);
        }
    }

    protected function ensureTrackedBatchAllocation(Transfer $transfer, TransferDetail $detail): void
    {
        if (! Schema::hasTable('product_batches') || ! Schema::hasTable('transfer_detail_batches')) return;

        $product = Product::find($detail->product_id);
        if (! $product || ! (bool) $product->is_batch_tracked) return;
        if (TransferDetailBatch::where('transfer_detail_id', $detail->id)->exists()) return;

        $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
        $required = $this->toBaseQuantity((float) $detail->quantity, $unitId, $product->name);

        $query = ProductBatch::query()
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('warehouse_id', $transfer->from_warehouse_id)
            ->where('product_id', $detail->product_id)
            ->where('qty', '>', 0);

        if ($detail->product_variant_id) $query->where('product_variant_id', $detail->product_variant_id);
        else $query->whereNull('product_variant_id');

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
            if ($remaining <= 0.000001) break;
            $take = min((float) $batch->qty, $remaining);
            if ($take <= 0) continue;

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
                'transfer' => 'No se pudo completar la asignación de lotes del despacho.',
            ]);
        }
    }

    protected function toBaseQuantity(float $quantity, ?int $unitId, string $productName): float
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'La cantidad de '.$productName.' debe ser mayor que cero.',
            ]);
        }

        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede despachar '.$productName.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

        return $unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value;
    }
}
