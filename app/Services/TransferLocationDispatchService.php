<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferLocationDispatchService
{
    public function ensureDispatched(Transfer $transfer): void
    {
        if (! $transfer->from_inventory_location_id || ! $transfer->to_inventory_location_id) return;
        if (! $transfer->isApproved() || $transfer->statut !== 'sent') return;

        $details = TransferDetail::where('transfer_id', $transfer->id)->orderBy('id')->get();
        if ($details->isEmpty()) {
            throw ValidationException::withMessages(['transfer' => 'No se puede despachar una transferencia sin productos.']);
        }

        $groups = [];
        foreach ($details as $detail) {
            $product = Product::find($detail->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['transfer' => 'Uno de los productos de la transferencia ya no existe.']);
            }

            $unitId = $detail->purchase_unit_id ?: $product->unit_purchase_id;
            $unit = $unitId ? Unit::find($unitId) : null;
            $base = $this->toBaseQuantity((float) $detail->quantity, $unit, $product->name);
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            if ((bool) ($product->is_batch_tracked ?? false)) {
                $this->dispatchBatches($transfer, $detail, $product, $base, $variantId);
            }
            app(TransferSerialLocationService::class)->dispatchDetail($transfer, $detail, $product, $base);

            $key = (int) $detail->product_id.':'.($variantId ?: 0);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'product_id' => (int) $detail->product_id,
                    'variant_id' => $variantId,
                    'quantity' => 0.0,
                ];
            }
            $groups[$key]['quantity'] = round($groups[$key]['quantity'] + $base, 3);
        }

        foreach ($groups as $group) {
            app(InventoryService::class)->decrease(
                (int) $transfer->from_inventory_location_id,
                $group['product_id'],
                $group['quantity'],
                $group['variant_id'],
                [
                    'user_id' => auth()->id(),
                    'reference_type' => 'TransferDispatch',
                    'reference_id' => (string) $transfer->id,
                    'idempotency_key' => 'transfer:dispatch:'.$transfer->id.':product:'.$group['product_id'].':variant:'.($group['variant_id'] ?: 0),
                    'notes' => 'Mercancía despachada y puesta en tránsito.',
                    'metadata' => [
                        'transfer_id' => (int) $transfer->id,
                        'from_inventory_location_id' => (int) $transfer->from_inventory_location_id,
                        'to_inventory_location_id' => (int) $transfer->to_inventory_location_id,
                    ],
                ]
            );
        }
    }

    private function dispatchBatches(
        Transfer $transfer,
        TransferDetail $detail,
        Product $product,
        float $required,
        ?int $variantId
    ): void {
        if (! Schema::hasTable('product_batch_location_stocks') || ! Schema::hasTable('transfer_detail_batches')) {
            throw ValidationException::withMessages([
                'transfer' => 'El producto '.$product->name.' usa lotes, pero el esquema de lotes por ubicación aún no está disponible.',
            ]);
        }

        $existing = TransferDetailBatch::where('transfer_detail_id', $detail->id)->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            if (abs((float) $existing->sum('qty') - $required) > 0.0005) {
                throw ValidationException::withMessages([
                    'transfer' => 'La asignación de lotes existente no coincide con la cantidad de '.$product->name.'.',
                ]);
            }
            return;
        }

        $rows = ProductBatchLocationStock::with('batch')
            ->where('inventory_location_id', (int) $transfer->from_inventory_location_id)
            ->whereHas('batch', function ($query) use ($detail, $variantId) {
                $query->where('product_id', (int) $detail->product_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at');
                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);
            })
            ->lockForUpdate()
            ->get()
            ->filter(fn ($row) => $row->available_quantity > 0)
            ->sortBy(function ($row) {
                $expiry = optional($row->batch)->expiry_date;
                return ($expiry ? $expiry->format('Y-m-d') : '9999-12-31')
                    .'|'.str_pad((string) $row->product_batch_id, 12, '0', STR_PAD_LEFT);
            });

        $available = round((float) $rows->sum(fn ($row) => $row->available_quantity), 3);
        if ($available + 0.0005 < $required) {
            throw ValidationException::withMessages([
                'transfer' => 'No hay suficiente existencia por lote en la ubicación origen para '.$product->name.'.',
            ]);
        }

        $remaining = round($required, 3);
        foreach ($rows as $row) {
            if ($remaining <= 0.0005) break;
            $take = min($remaining, $row->available_quantity);
            if ($take <= 0) continue;

            $stock = ProductBatchLocationStock::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $batch = ProductBatch::whereKey($row->product_batch_id)->lockForUpdate()->firstOrFail();
            if ($stock->available_quantity + 0.0005 < $take || (float) $batch->qty + 0.0005 < $take) {
                throw ValidationException::withMessages([
                    'transfer' => 'El lote '.$batch->batch_no.' cambió de existencia mientras se aprobaba la transferencia.',
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

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages([
                'transfer' => 'No se pudo completar la asignación FEFO de lotes para '.$product->name.'.',
            ]);
        }
    }

    private function toBaseQuantity(float $quantity, ?Unit $unit, string $productName): float
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['transfer' => 'La cantidad de '.$productName.' debe ser mayor que cero.']);
        }
        if (! $unit || ! in_array($unit->operator, ['*', '/'], true) || (float) $unit->operator_value <= 0) {
            throw ValidationException::withMessages([
                'transfer' => 'No se puede despachar '.$productName.' porque su unidad de compra no tiene una conversión válida.',
            ]);
        }

        return round($unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value, 3);
    }
}
