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
    /** Request-attribute prefix for the MS5-B1 POS batch preflight plan. */
    public const POS_BATCH_PREFLIGHT_ATTR = 'prodex_pos_batch_preflight';

    public function applyForSaleWithAutoFallback(Sale $sale, array $inputDetails, $persistedDetails): void
    {
        if (! $sale->inventory_location_id) {
            parent::applyForSaleWithAutoFallback($sale, $inputDetails, $persistedDetails);
            return;
        }

        if (! $this->isSupported()) return;

        // MS5-B1 — if a POS artifact preflight ran for this sale, its frozen plan
        // is AUTHORITATIVE: consume EXACTLY those allocations. Every ProductBatch
        // / slice was resolved and row-locked during preflight, BEFORE the
        // general decrease, so this step re-locks rows the transaction already
        // holds and never re-runs FEFO / discovers a new batch.
        $plan = $this->consumePosArtifactPreflightPlan($sale);

        $persistedDetails = collect($persistedDetails)->values();
        foreach (array_values($inputDetails) as $i => $row) {
            $detail = $persistedDetails->get($i);
            if (! $detail || ! $this->productIsTracked($detail->product_id)) continue;

            if ($plan !== null) {
                foreach (($plan[$i]['allocations'] ?? []) as $alloc) {
                    $this->consumeBatch(
                        $sale,
                        $detail,
                        (int) $alloc['product_batch_id'],
                        (float) $alloc['quantity'],
                        $alloc['unit_price'] ?? null
                    );
                }
                continue;
            }

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

    /**
     * MS5-B1 — resolve, validate and DETERMINISTICALLY row-lock every batch
     * artifact of the whole POS cart WITHOUT mutating anything, and return a
     * frozen per-line-index allocation plan. Runs inside CreatePOS's
     * transaction, from PosLocationSaleStockService::apply(), BEFORE the general
     * decrease.
     *
     * Only for a location-aware POS sale. A non-location sale returns [] and its
     * apply path is unchanged.
     *
     * Locking order (deterministic, whole cart):
     *   1. ProductBatch                by id ASC
     *   2. ProductBatchLocationStock   by (product_batch_id, id) ASC
     * ...then per line: revalidate under lock + build the FEFO / explicit plan.
     *
     * @return array<int, array{product_id:int, product_variant_id:?int,
     *   quantity_base:float, mode:string,
     *   allocations:array<int, array{product_batch_id:int, quantity:float, unit_price:?float}>}>
     *
     * @throws ValidationException  insufficient stock, wrong batch, unit mismatch
     */
    public function preflightSaleAllocations(Sale $sale, array $inputDetails): array
    {
        if (! $sale->inventory_location_id || ! $this->isSupported()) {
            return [];
        }

        $locationId = (int) $sale->inventory_location_id;
        $posStock = app(PosLocationSaleStockService::class);

        // -- Pass 1: describe every batch-tracked line + collect candidate ids.
        $lines = [];
        $candidateBatchIds = [];
        foreach (array_values($inputDetails) as $i => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0 || ! $this->productIsTracked($productId)) {
                continue;
            }
            if ((($row['product_type'] ?? null) === 'is_service')) {
                continue;
            }

            $product = \App\Models\Product::with('unitSale')->whereNull('deleted_at')->find($productId);
            if (! $product) {
                continue;
            }

            $quantityBase = round($posStock->baseQuantity($row, $product), 3);
            if ($quantityBase <= 0) {
                continue;
            }

            $variantId = ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null;
            $explicit = $this->extractSaleBatchesFromRow($row);

            if ($explicit) {
                $selected = round((float) collect($explicit)->sum('qty'), 3);
                if (abs($selected - $quantityBase) > 0.0005) {
                    throw ValidationException::withMessages([
                        'batches' => 'La cantidad seleccionada por lote debe coincidir exactamente con la cantidad física vendida.',
                    ]);
                }
                foreach ($explicit as $e) {
                    $candidateBatchIds[] = (int) $e['product_batch_id'];
                }
            } else {
                foreach ($this->discoverFefoBatchIds($locationId, $productId, $variantId) as $bid) {
                    $candidateBatchIds[] = $bid;
                }
            }

            $lines[$i] = [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'quantity_base' => $quantityBase,
                'mode' => $explicit ? 'explicit' : 'fefo',
                'explicit' => $explicit,
            ];
        }

        if (empty($lines)) {
            return [];
        }

        // -- Deterministic locks: ProductBatch (id ASC) then slices.
        $candidateBatchIds = array_values(array_unique(array_filter($candidateBatchIds)));
        sort($candidateBatchIds, SORT_NUMERIC);

        $batches = $candidateBatchIds
            ? ProductBatch::whereIn('id', $candidateBatchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        $slices = $candidateBatchIds
            ? ProductBatchLocationStock::whereIn('product_batch_id', $candidateBatchIds)
                ->where('inventory_location_id', $locationId)
                ->orderBy('product_batch_id')->orderBy('id')
                ->lockForUpdate()->get()
                ->keyBy('product_batch_id')
            : collect();

        // -- Pass 2: revalidate under lock, freeze allocations.
        $plan = [];
        foreach ($lines as $i => $line) {
            $variantId = $line['product_variant_id'];
            $allocations = [];

            if ($line['mode'] === 'explicit') {
                foreach ($line['explicit'] as $e) {
                    $bid = (int) $e['product_batch_id'];
                    $qty = round((float) $e['qty'], 3);
                    $batch = $batches->get($bid);
                    if (! $batch || ! $this->batchMatchesLine($batch, $line['product_id'], $variantId)) {
                        throw ValidationException::withMessages(['batches' => 'El lote seleccionado no corresponde al producto vendido.']);
                    }
                    $slice = $slices->get($bid);
                    if (! $slice || $slice->available_quantity + 0.0005 < $qty) {
                        throw ValidationException::withMessages(['batches' => 'El lote no tiene suficiente existencia disponible en esta ubicación.']);
                    }
                    if ((float) $batch->qty + 0.0005 < $qty) {
                        throw ValidationException::withMessages(['batches' => 'La existencia agregada del lote es insuficiente.']);
                    }
                    $allocations[] = [
                        'product_batch_id' => $bid,
                        'quantity' => $qty,
                        'unit_price' => $e['unit_price'] ?? null,
                    ];
                }
            } else {
                $remaining = $line['quantity_base'];
                $ordered = $slices->filter(function ($slice) use ($batches, $line, $variantId) {
                    $batch = $batches->get($slice->product_batch_id);

                    return $batch
                        && $this->batchMatchesLine($batch, $line['product_id'], $variantId)
                        && (string) $batch->status === 'active'
                        && $slice->available_quantity > 0;
                })->sortBy(function ($slice) use ($batches) {
                    $batch = $batches->get($slice->product_batch_id);
                    $expiry = optional($batch)->expiry_date;

                    return $expiry
                        ? $expiry->format('Y-m-d').'|'.str_pad((string) $slice->product_batch_id, 12, '0', STR_PAD_LEFT)
                        : '9999-12-31|'.str_pad((string) $slice->product_batch_id, 12, '0', STR_PAD_LEFT);
                });

                foreach ($ordered as $slice) {
                    if ($remaining <= 0.0005) {
                        break;
                    }
                    $take = round(min($remaining, (float) $slice->available_quantity), 3);
                    if ($take <= 0) {
                        continue;
                    }
                    $allocations[] = [
                        'product_batch_id' => (int) $slice->product_batch_id,
                        'quantity' => $take,
                        'unit_price' => null,
                    ];
                    $remaining = round($remaining - $take, 3);
                }

                if ($remaining > 0.0005) {
                    throw ValidationException::withMessages([
                        'batches' => 'No hay suficiente existencia por lote en la ubicación de venta seleccionada.',
                    ]);
                }
            }

            $plan[$i] = [
                'product_id' => $line['product_id'],
                'product_variant_id' => $variantId,
                'quantity_base' => $line['quantity_base'],
                'mode' => $line['mode'],
                'allocations' => $allocations,
            ];
        }

        return $plan;
    }

    /** Candidate active batch ids for a product+variant in a location (FEFO pool). */
    private function discoverFefoBatchIds(int $locationId, int $productId, ?int $variantId): array
    {
        return ProductBatchLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->whereHas('batch', function ($query) use ($productId, $variantId) {
                $query->where('product_id', $productId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at');
                $variantId === null
                    ? $query->whereNull('product_variant_id')
                    : $query->where('product_variant_id', $variantId);
            })
            ->pluck('product_batch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function batchMatchesLine(ProductBatch $batch, int $productId, ?int $variantId): bool
    {
        if ((int) $batch->product_id !== $productId || $batch->deleted_at !== null) {
            return false;
        }
        $batchVariant = $batch->product_variant_id ? (int) $batch->product_variant_id : null;

        return $batchVariant === $variantId;
    }

    /**
     * Read + consume the frozen POS batch plan for this sale. Returns null when
     * no POS preflight ran (admin / legacy sale) — the caller then keeps the
     * historical explicit / FEFO behaviour. Returns an array (possibly empty)
     * when a preflight ran — that plan is authoritative.
     */
    private function consumePosArtifactPreflightPlan(Sale $sale): ?array
    {
        if (! function_exists('app') || ! app()->bound('request') || ! $sale->id) {
            return null;
        }
        $key = self::POS_BATCH_PREFLIGHT_ATTR.':'.$sale->id;
        $request = request();
        if (! $request->attributes->has($key)) {
            return null;
        }
        $plan = $request->attributes->get($key);
        $request->attributes->remove($key);

        return is_array($plan) ? $plan : [];
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
