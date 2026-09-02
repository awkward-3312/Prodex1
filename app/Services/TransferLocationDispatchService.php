<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransferLocationDispatchService
{
    /**
     * @param  array|null  $batchPlan  Explicit per-line batch picks captured at
     *   create time, keyed by "<product_id>:<variant_id|0>", each value a list of
     *   ['product_batch_id' => int, 'qty' => float] in the line's base unit. When a
     *   key is present its picks are honoured EXACTLY (fully validated); when absent
     *   the line falls back to FEFO among ELIGIBLE batches only.
     */
    public function ensureDispatched(Transfer $transfer, ?array $batchPlan = null): void
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
                $explicit = $batchPlan[$detail->product_id.':'.($variantId ?: 0)] ?? null;
                $this->dispatchBatches($transfer, $detail, $product, $base, $variantId, $explicit);
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

    /**
     * Batch statuses that may never be dispatched automatically or manually.
     * (product_batches.status vocabulary: active | quarantined | expired | written_off)
     */
    private const NON_DISPATCHABLE_STATUSES = ['quarantined', 'expired', 'written_off'];

    private function dispatchBatches(
        Transfer $transfer,
        TransferDetail $detail,
        Product $product,
        float $required,
        ?int $variantId,
        ?array $explicitPlan = null
    ): void {
        if (! Schema::hasTable('product_batch_location_stocks')
            || ! Schema::hasTable('product_batch_location_movements')
            || ! Schema::hasTable('transfer_detail_batches')) {
            throw ValidationException::withMessages([
                'transfer' => 'El producto '.$product->name.' usa lotes, pero el esquema de lotes por ubicación aún no está disponible.',
            ]);
        }

        $required = round($required, 3);

        // Idempotency: this detail was already dispatched (rows are only ever written
        // together with their stock debit + ledger movement below).
        $existing = TransferDetailBatch::where('transfer_detail_id', $detail->id)->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            if (abs((float) $existing->sum('qty') - $required) > 0.0005) {
                throw ValidationException::withMessages([
                    'transfer' => 'La asignación de lotes existente no coincide con la cantidad de '.$product->name.'.',
                ]);
            }
            return;
        }

        // Every batch-stock row of this product/variant at the source location,
        // split into eligible (dispatchable) and the rest.
        $sourceRows = ProductBatchLocationStock::with('batch')
            ->where('inventory_location_id', (int) $transfer->from_inventory_location_id)
            ->whereHas('batch', function ($query) use ($detail, $variantId) {
                $query->where('product_id', (int) $detail->product_id)->whereNull('deleted_at');
                $variantId === null
                    ? $query->where(fn ($q) => $q->whereNull('product_variant_id')->orWhere('product_variant_id', 0))
                    : $query->where('product_variant_id', $variantId);
            })
            ->lockForUpdate()
            ->get();

        $eligibleById = $sourceRows
            ->filter(fn ($row) => $this->batchIneligibleReason($row->batch, $product->name) === null && $row->available_quantity > 0.0005)
            ->keyBy('product_batch_id');

        // ----- Build the allocation plan: [ [ProductBatchLocationStock $row, float $take], ... ]
        $plan = [];

        if ($explicitPlan !== null && $explicitPlan !== []) {
            // The user picked batches explicitly — honour them EXACTLY.
            $seen = [];
            $sum = 0.0;
            foreach ($explicitPlan as $pick) {
                $batchId = (int) ($pick['product_batch_id'] ?? 0);
                $qty = round((float) ($pick['qty'] ?? 0), 3);
                if ($batchId <= 0 || $qty <= 0) {
                    throw ValidationException::withMessages([
                        'transfer' => 'Hay una asignación de lote inválida para '.$product->name.'.',
                    ]);
                }
                if (isset($seen[$batchId])) {
                    throw ValidationException::withMessages([
                        'transfer' => 'El mismo lote está asignado dos veces para '.$product->name.'.',
                    ]);
                }
                $seen[$batchId] = true;

                $row = $eligibleById->get($batchId);
                if (! $row) {
                    // Explain precisely why this pick is not dispatchable.
                    $batch = ProductBatch::find($batchId);
                    if (! $batch
                        || (int) $batch->product_id !== (int) $detail->product_id
                        || (int) ($batch->product_variant_id ?? 0) !== (int) ($variantId ?? 0)) {
                        throw ValidationException::withMessages([
                            'transfer' => 'El lote seleccionado no pertenece a '.$product->name.'.',
                        ]);
                    }
                    $reason = $this->batchIneligibleReason($batch, $product->name);
                    if ($reason !== null) {
                        throw ValidationException::withMessages(['transfer' => $reason]);
                    }
                    throw ValidationException::withMessages([
                        'transfer' => 'El lote '.$batch->batch_no.' no tiene existencia disponible en la ubicación origen.',
                    ]);
                }
                if ($row->available_quantity + 0.0005 < $qty) {
                    throw ValidationException::withMessages([
                        'transfer' => 'El lote '.optional($row->batch)->batch_no.' no tiene suficiente existencia disponible ('
                            .$this->fmt($row->available_quantity).') para asignar '.$this->fmt($qty).' de '.$product->name.'.',
                    ]);
                }
                $sum = round($sum + $qty, 3);
                $plan[] = [$row, $qty];
            }
            if (abs($sum - $required) > 0.0005) {
                throw ValidationException::withMessages([
                    'transfer' => 'La suma de los lotes asignados ('.$this->fmt($sum).') no coincide con la cantidad de la línea ('
                        .$this->fmt($required).') para '.$product->name.'.',
                ]);
            }
        } else {
            // No explicit picks — FEFO fallback, but ONLY across eligible batches.
            $sorted = $eligibleById->values()->sortBy(function ($row) {
                $expiry = optional($row->batch)->expiry_date;
                return ($expiry ? $expiry->format('Y-m-d') : '9999-12-31')
                    .'|'.str_pad((string) $row->product_batch_id, 12, '0', STR_PAD_LEFT);
            });

            $eligibleTotal = round((float) $sorted->sum(fn ($row) => $row->available_quantity), 3);
            if ($eligibleTotal + 0.0005 < $required) {
                throw ValidationException::withMessages([
                    'transfer' => 'No hay existencia por lote elegible suficiente en la ubicación origen para '.$product->name
                        .' (disponible '.$this->fmt($eligibleTotal).', requerido '.$this->fmt($required)
                        .'). No se despacha automáticamente desde lotes vencidos, en cuarentena o dados de baja.',
                ]);
            }

            $remaining = $required;
            foreach ($sorted as $row) {
                if ($remaining <= 0.0005) break;
                $take = round(min($remaining, $row->available_quantity), 3);
                if ($take <= 0) continue;
                $plan[] = [$row, $take];
                $remaining = round($remaining - $take, 3);
            }
            if ($remaining > 0.0005) {
                throw ValidationException::withMessages([
                    'transfer' => 'No se pudo completar la asignación FEFO de lotes elegibles para '.$product->name.'.',
                ]);
            }
        }

        // ----- Execute the plan atomically (debit + ledger). Any failure here
        //       aborts the surrounding DB transaction, so no partial dispatch.
        foreach ($plan as [$row, $take]) {
            $stock = ProductBatchLocationStock::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $batch = ProductBatch::whereKey($row->product_batch_id)->lockForUpdate()->firstOrFail();

            // Re-check eligibility + availability under the row lock (TOCTOU guard).
            $reason = $this->batchIneligibleReason($batch, $product->name);
            if ($reason !== null) {
                throw ValidationException::withMessages(['transfer' => $reason]);
            }
            if ($stock->available_quantity + 0.0005 < $take || (float) $batch->qty + 0.0005 < $take) {
                throw ValidationException::withMessages([
                    'transfer' => 'El lote '.$batch->batch_no.' cambió de existencia mientras se despachaba la transferencia.',
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

            ProductBatchLocationMovement::create([
                'product_batch_id' => $batch->id,
                'from_inventory_location_id' => (int) $transfer->from_inventory_location_id,
                'to_inventory_location_id' => null,
                'quantity' => $take,
                'user_id' => auth()->id(),
                'reference_type' => 'TransferDispatchBatch',
                'reference_id' => (string) $detail->id,
                'idempotency_key' => 'transfer:dispatch:detail:'.$detail->id.':batch:'.$batch->id,
                'notes' => 'Lote despachado desde ubicación física y puesto en tránsito.',
                'metadata' => [
                    'transfer_id' => (int) $transfer->id,
                    'transfer_detail_id' => (int) $detail->id,
                ],
            ]);
        }
    }

    /**
     * Why a batch may not be dispatched, or null when it is dispatchable.
     * Covers: soft-deleted, non-active status (quarantined / expired / written_off),
     * and past expiry_date even when the status row still says "active".
     */
    private function batchIneligibleReason(?ProductBatch $batch, string $productName): ?string
    {
        if (! $batch) {
            return 'Uno de los lotes de '.$productName.' ya no existe.';
        }
        if ($batch->deleted_at) {
            return 'El lote '.$batch->batch_no.' fue eliminado y no puede despacharse.';
        }
        $status = (string) $batch->status;
        if (in_array($status, self::NON_DISPATCHABLE_STATUSES, true)) {
            $label = [
                'quarantined' => 'en cuarentena',
                'expired' => 'vencido',
                'written_off' => 'dado de baja',
            ][$status] ?? $status;
            return 'El lote '.$batch->batch_no.' está '.$label.' y no puede despacharse.';
        }
        if ($status !== 'active') {
            return 'El lote '.$batch->batch_no.' no está activo (estado: '.$status.') y no puede despacharse.';
        }
        if ($batch->expiry_date && $batch->expiry_date->startOfDay()->lt(now()->startOfDay())) {
            return 'El lote '.$batch->batch_no.' está vencido ('.$batch->expiry_date->format('Y-m-d').') y no puede despacharse.';
        }
        return null;
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
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
