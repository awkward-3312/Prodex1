<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\ProductBatchLocationStock;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleDetailBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetailBatch;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocationAwareBatchService extends BatchService
{
    public function applyForSaleWithAutoFallback(Sale $sale, array $inputDetails, $persistedDetails): void
    {
        if (! $sale->inventory_location_id) {
            parent::applyForSaleWithAutoFallback($sale, $inputDetails, $persistedDetails);
            return;
        }

        if (! $this->isSupported()) return;

        $persistedDetails = collect($persistedDetails)->values();
        foreach (array_values($inputDetails) as $i => $row) {
            $detail = $persistedDetails->get($i);
            if (! $detail || ! $this->productIsTracked($detail->product_id)) continue;

            $needed = $this->baseQuantity($row, $detail);
            if ($needed <= 0) continue;

            $explicit = $this->extractSaleBatchesFromRow($row);
            if ($explicit) {
                $selected = round((float) collect($explicit)->sum('qty'), 3);
                if (abs($selected - $needed) > 0.0005) {
                    throw ValidationException::withMessages([
                        'batches' => 'La cantidad seleccionada por lote debe coincidir exactamente con la cantidad física vendida.',
                    ]);
                }
                foreach ($explicit as $batch) {
                    $this->consumeBatch($sale, $detail, (int) $batch['product_batch_id'], (float) $batch['qty'], $batch['unit_price'] ?? null);
                }
                continue;
            }

            $this->consumeFefo($sale, $detail, $needed);
        }
    }

    public function reverseForSaleDetails($saleDetails): void
    {
        $saleDetails = collect($saleDetails);
        $locationSaleIds = $saleDetails->pluck('sale_id')->filter()->unique();
        if ($locationSaleIds->isEmpty()) {
            parent::reverseForSaleDetails($saleDetails);
            return;
        }

        $sales = Sale::whereIn('id', $locationSaleIds)->get()->keyBy('id');

        DB::transaction(function () use ($saleDetails, $sales) {
            foreach ($saleDetails as $detail) {
                $sale = $sales->get($detail->sale_id);
                if (! $sale || ! $sale->inventory_location_id) {
                    parent::reverseForSaleDetails([$detail]);
                    continue;
                }

                $pivots = SaleDetailBatch::where('sale_detail_id', $detail->id)->lockForUpdate()->get();
                foreach ($pivots as $pivot) {
                    $batch = ProductBatch::whereKey($pivot->product_batch_id)->lockForUpdate()->first();
                    $stock = ProductBatchLocationStock::where('product_batch_id', $pivot->product_batch_id)
                        ->where('inventory_location_id', $sale->inventory_location_id)
                        ->lockForUpdate()
                        ->first();

                    if ($batch) {
                        $batch->qty = round((float) $batch->qty + (float) $pivot->qty, 3);
                        $batch->save();
                    }
                    if ($stock) {
                        $stock->quantity = round((float) $stock->quantity + (float) $pivot->qty, 3);
                        $stock->save();
                    }
                    $pivot->delete();
                }
            }
        }, 3);
    }

    /**
     * A received sale return credits both the aggregate batch and the batch slice
     * in the original POS inventory location. The parent keeps the historical
     * aggregate/pivot behavior; this layer adds the physical-location ledger.
     */
    public function applyForSaleReturnWithAutoFallback(SaleReturn $return, array $inputDetails, $persistedDetails): void
    {
        if (! $return->inventory_location_id) {
            parent::applyForSaleReturnWithAutoFallback($return, $inputDetails, $persistedDetails);
            return;
        }

        DB::transaction(function () use ($return, $inputDetails, $persistedDetails) {
            parent::applyForSaleReturnWithAutoFallback($return, $inputDetails, $persistedDetails);

            $detailIds = collect($persistedDetails)->pluck('id')->filter()->all();
            if (! $detailIds) return;

            $pivots = SaleReturnDetailBatch::whereIn('sale_return_detail_id', $detailIds)
                ->lockForUpdate()
                ->get();

            foreach ($pivots as $pivot) {
                $stock = ProductBatchLocationStock::where('product_batch_id', $pivot->product_batch_id)
                    ->where('inventory_location_id', (int) $return->inventory_location_id)
                    ->lockForUpdate()
                    ->first();

                if (! $stock) {
                    $stock = ProductBatchLocationStock::create([
                        'product_batch_id' => (int) $pivot->product_batch_id,
                        'inventory_location_id' => (int) $return->inventory_location_id,
                        'quantity' => 0,
                        'reserved_quantity' => 0,
                    ]);
                }

                $stock->quantity = round((float) $stock->quantity + (float) $pivot->qty, 3);
                $stock->save();
            }
        }, 3);
    }

    public function reverseForSaleReturnDetails($returnDetails): void
    {
        DB::transaction(function () use ($returnDetails) {
            foreach (collect($returnDetails) as $detail) {
                if (! $detail) continue;

                $return = SaleReturn::find($detail->sale_return_id);
                if (! $return || ! $return->inventory_location_id) {
                    parent::reverseForSaleReturnDetails([$detail]);
                    continue;
                }

                $pivots = SaleReturnDetailBatch::where('sale_return_detail_id', $detail->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($pivots as $pivot) {
                    $stock = ProductBatchLocationStock::where('product_batch_id', $pivot->product_batch_id)
                        ->where('inventory_location_id', (int) $return->inventory_location_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $stock || (float) $stock->quantity + 0.0005 < (float) $pivot->qty) {
                        throw ValidationException::withMessages([
                            'batches' => 'No se puede revertir la devolución porque parte del lote ya no está disponible en la ubicación original.',
                        ]);
                    }

                    $stock->quantity = round((float) $stock->quantity - (float) $pivot->qty, 3);
                    $stock->save();
                }

                parent::reverseForSaleReturnDetails([$detail]);
            }
        }, 3);
    }

    private function consumeFefo(Sale $sale, SaleDetail $detail, float $needed): void
    {
        $remaining = round($needed, 3);
        $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

        $rows = ProductBatchLocationStock::with('batch')
            ->where('inventory_location_id', (int) $sale->inventory_location_id)
            ->whereHas('batch', function ($query) use ($detail, $variantId) {
                $query->where('product_id', (int) $detail->product_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at');
                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);
            })
            ->get()
            ->filter(fn ($row) => $row->available_quantity > 0)
            ->sortBy(function ($row) {
                $expiry = optional($row->batch)->expiry_date;
                return $expiry ? $expiry->format('Y-m-d').'|'.str_pad((string) $row->product_batch_id, 12, '0', STR_PAD_LEFT)
                    : '9999-12-31|'.str_pad((string) $row->product_batch_id, 12, '0', STR_PAD_LEFT);
            });

        foreach ($rows as $row) {
            if ($remaining <= 0.0005) break;
            $take = min($remaining, $row->available_quantity);
            $this->consumeBatch($sale, $detail, (int) $row->product_batch_id, $take, null);
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0.0005) {
            throw ValidationException::withMessages([
                'batches' => 'No hay suficiente existencia por lote en la ubicación de venta seleccionada.',
            ]);
        }
    }

    private function consumeBatch(Sale $sale, SaleDetail $detail, int $batchId, float $quantity, ?float $unitPrice): void
    {
        $quantity = round($quantity, 3);
        if ($quantity <= 0) return;

        DB::transaction(function () use ($sale, $detail, $batchId, $quantity, $unitPrice) {
            $variantId = $detail->product_variant_id ? (int) $detail->product_variant_id : null;

            $batch = ProductBatch::whereKey($batchId)
                ->where('product_id', (int) $detail->product_id)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($variantId) {
                    $variantId === null
                        ? $query->whereNull('product_variant_id')
                        : $query->where('product_variant_id', $variantId);
                })
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                throw ValidationException::withMessages(['batches' => 'El lote seleccionado no corresponde al producto vendido.']);
            }

            $stock = ProductBatchLocationStock::where('product_batch_id', $batchId)
                ->where('inventory_location_id', (int) $sale->inventory_location_id)
                ->lockForUpdate()
                ->first();

            if (! $stock || $stock->available_quantity + 0.0005 < $quantity) {
                throw ValidationException::withMessages(['batches' => 'El lote no tiene suficiente existencia disponible en esta ubicación.']);
            }
            if ((float) $batch->qty + 0.0005 < $quantity) {
                throw ValidationException::withMessages(['batches' => 'La existencia agregada del lote es insuficiente.']);
            }

            $stock->quantity = round((float) $stock->quantity - $quantity, 3);
            $stock->save();

            $batch->qty = round((float) $batch->qty - $quantity, 3);
            $batch->save();

            SaleDetailBatch::create([
                'sale_detail_id' => $detail->id,
                'product_batch_id' => $batch->id,
                'qty' => $quantity,
                'unit_price' => $unitPrice,
            ]);
        }, 3);
    }

    private function baseQuantity(array $row, SaleDetail $detail): float
    {
        $packMultiplier = isset($row['pack_multiplier']) && (float) $row['pack_multiplier'] > 0
            ? (float) $row['pack_multiplier']
            : 1.0;
        $quantity = (float) ($row['quantity'] ?? $detail->quantity ?? 0);
        $packQuantity = $quantity * $packMultiplier;

        $unitId = $row['sale_unit_id'] ?? $detail->sale_unit_id ?? null;
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit) return round($packQuantity, 3);

        if ($unit->operator === '/') {
            $value = (float) $unit->operator_value;
            return round($value > 0 ? $packQuantity / $value : $packQuantity, 3);
        }

        return round($packQuantity * (float) $unit->operator_value, 3);
    }
}
