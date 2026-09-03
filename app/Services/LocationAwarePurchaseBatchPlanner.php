<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\ProductBatchLocationStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * MS5-B2 — Purchase / PurchaseReturn / Import BATCH PLANNER (INACTIVE: no
 * productive controller calls it yet).
 *
 * RESPONSIBILITY (only this):
 *   - interpret the batch input of a location-native purchase document
 *   - resolve ProductBatch identity (product_id, product_variant_id,
 *     warehouse_id, batch_no)
 *   - convert every quantity to BASE UNIT with the SAME purchase unit as the
 *     line (operator '/' => qty / value ; otherwise qty * value)
 *   - validate the allocations
 *   - FEFO for a return without an explicit batch selection
 *   - create a NEW ProductBatch with qty = 0 when the identity does not exist
 *   - freeze a deterministic batch_allocation per line (bidx 0..N-1)
 *
 * It NEVER mutates physical batch quantity — BatchLocationService::receiveMany /
 * issueMany do that later, composed by LocationAwarePurchaseStockService. It
 * never parses commercial batch_no semantics inside BatchLocationService.
 *
 * MUST run inside the caller's business transaction.
 */
class LocationAwarePurchaseBatchPlanner
{
    private const EPS = 0.0005;

    public const OPERABLE_STATUSES = BatchLocationService::OPERABLE_STATUSES;

    /**
     * Freeze the RECEIPT batch plan. Returns $validatedLines with a
     * deterministic `batch_allocation` on every requires_batch line ([] on the
     * rest). Each allocation: {bidx, product_batch_id, batch_no, expiry_date,
     * mfg_date, quantity_input, quantity_base, unit_cost}.
     *
     * @throws \LogicException     no outer transaction
     * @throws ValidationException invalid input / identity / conversion
     */
    public function planPurchaseReceipt(int $warehouseId, int $inventoryLocationId, array $validatedLines, array $rawLines, array $context = []): array
    {
        $this->assertInTransaction();
        $rawLines = array_values($rawLines);
        $out = [];

        foreach (array_values($validatedLines) as $i => $line) {
            if (empty($line['requires_batch'])) {
                $out[] = ['batch_allocation' => []] + $line;

                continue;
            }

            $raw = $this->rawBatches($rawLines[$i] ?? []);
            $this->assertLineBatchInput($raw, $i);

            $lineBase = round((float) $line['quantity_base'], 3);
            $productId = (int) $line['product_id'];
            $variantId = $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null;

            $entries = [];
            $sum = 0.0;
            foreach ($raw as $b) {
                $batchNo = trim((string) $b['batch_no']);
                $qtyInput = round((float) $b['qty'], 3);
                $expiry = $this->parseDate($b['expiry_date'] ?? null, 'expiry_date');
                $mfg = $this->parseDate($b['mfg_date'] ?? null, 'mfg_date');
                $unitCost = isset($b['unit_cost']) && $b['unit_cost'] !== '' && $b['unit_cost'] !== null ? (float) $b['unit_cost'] : null;
                $qtyBase = $this->toBase($qtyInput, $line);
                $sum = round($sum + $qtyBase, 3);

                $batchId = $this->resolveReceiptBatchId($productId, $variantId, $warehouseId, $batchNo, $expiry, $mfg, $unitCost, $context);

                $entries[] = [
                    'product_batch_id' => $batchId,
                    'batch_no' => $batchNo,
                    'expiry_date' => $expiry,
                    'mfg_date' => $mfg,
                    'quantity_input' => $qtyInput,
                    'quantity_base' => $qtyBase,
                    'unit_cost' => $unitCost,
                ];
            }

            if (abs($sum - $lineBase) > self::EPS) {
                throw ValidationException::withMessages([
                    "details.$i.batches" => 'La suma de las cantidades por lote ('.$sum.') no coincide con la cantidad física de la línea ('.$lineBase.').',
                ]);
            }

            $out[] = ['batch_allocation' => $this->assignBidx($this->sortReceipt($entries))] + $line;
        }

        return $out;
    }

    /**
     * Freeze the RETURN (issue) batch plan. Explicit selection is validated;
     * otherwise FEFO from product_batch_location_stocks of $inventoryLocationId
     * (NEVER the aggregate product_batches.qty). Same output shape.
     *
     * @throws \LogicException
     * @throws ValidationException
     */
    public function planPurchaseReturnIssue(int $warehouseId, int $inventoryLocationId, array $validatedLines, array $rawLines, array $context = []): array
    {
        $this->assertInTransaction();
        $rawLines = array_values($rawLines);
        $lines = array_values($validatedLines);

        // ---- describe every requires_batch line ---------------------------
        $batchLines = [];
        $explicitBatchIds = [];
        foreach ($lines as $i => $line) {
            if (empty($line['requires_batch'])) {
                continue;
            }

            $raw = $this->rawBatches($rawLines[$i] ?? []);
            $explicit = array_values(array_filter($raw, fn ($b) => (int) ($b['product_batch_id'] ?? 0) > 0));

            if ($explicit) {
                $seen = [];
                foreach ($explicit as $e) {
                    $bid = (int) $e['product_batch_id'];
                    if (isset($seen[$bid])) {
                        // §7 — same batch twice in ONE line is rejected (mirrors
                        // the receipt's duplicate-batch_no rule).
                        throw ValidationException::withMessages([
                            "details.$i.batches" => "El lote {$bid} está repetido en la misma línea de devolución.",
                        ]);
                    }
                    $seen[$bid] = true;
                    $explicitBatchIds[] = $bid;
                }
            }

            $batchLines[$i] = [
                'line' => $line,
                'product_id' => (int) $line['product_id'],
                'variant_id' => $line['product_variant_id'] !== null ? (int) $line['product_variant_id'] : null,
                'line_base' => round((float) $line['quantity_base'], 3),
                'mode' => $explicit ? 'explicit' : 'fefo',
                'explicit' => $explicit,
            ];
        }

        if (! $batchLines) {
            return array_map(fn ($l) => ['batch_allocation' => []] + $l, $lines);
        }

        // ---- discover every FEFO candidate across the whole document -----
        $fefoCandidateIds = [];
        foreach ($batchLines as $meta) {
            if ($meta['mode'] !== 'fefo') {
                continue;
            }
            foreach ($this->discoverReturnFefoBatchIds($inventoryLocationId, $meta['product_id'], $meta['variant_id'], $warehouseId) as $bid) {
                $fefoCandidateIds[] = $bid;
            }
        }

        // ---- lock the WHOLE document's batches + slices, deterministically
        $allBatchIds = array_values(array_unique(array_merge($explicitBatchIds, $fefoCandidateIds)));
        sort($allBatchIds, SORT_NUMERIC);

        $batches = $allBatchIds
            ? ProductBatch::whereIn('id', $allBatchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();
        $slices = $allBatchIds
            ? ProductBatchLocationStock::whereIn('product_batch_id', $allBatchIds)
                ->where('inventory_location_id', $inventoryLocationId)
                ->orderBy('product_batch_id')->orderBy('id')
                ->lockForUpdate()->get()->keyBy('product_batch_id')
            : collect();

        // ---- VIRTUAL availability pool — from the LOCKED slice only. ------
        $virtual = [];
        foreach ($allBatchIds as $bid) {
            $s = $slices->get($bid);
            $virtual[$bid] = $s ? round((float) $s->available_quantity, 3) : 0.0;
        }

        $plannedByLine = [];

        // ---- PASS 1 — EXPLICIT (user intent reserves first) --------------
        foreach ($batchLines as $i => $meta) {
            if ($meta['mode'] !== 'explicit') {
                continue;
            }
            $entries = [];
            foreach ($meta['explicit'] as $e) {
                $bid = (int) $e['product_batch_id'];
                $qtyInput = round((float) ($e['qty'] ?? 0), 3);
                if ($qtyInput <= 0) {
                    throw ValidationException::withMessages(["details.$i.batches" => 'Cantidad de lote no positiva en la selección explícita.']);
                }
                $batch = $batches->get($bid);
                $this->assertReturnBatchUsable($batch, $bid, $meta['product_id'], $meta['variant_id'], $warehouseId, $i);
                if (! $slices->get($bid)) {
                    throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} no tiene existencia en la ubicación de inventario seleccionada."]);
                }

                $qtyBase = $this->toBase($qtyInput, $meta['line']);
                $virtual[$bid] = round(($virtual[$bid] ?? 0.0) - $qtyBase, 3);
                if ($virtual[$bid] < -self::EPS) {
                    throw ValidationException::withMessages([
                        "details.$i.batches" => "El lote {$bid} no tiene existencia suficiente para todas las líneas de la devolución (selección explícita del documento).",
                    ]);
                }

                $entries[] = $this->returnEntry($batch, $qtyInput, $qtyBase);
            }
            $plannedByLine[$i] = $entries;
        }

        // ---- PASS 2 — FEFO (document ordinal order, virtual remaining) ---
        foreach ($batchLines as $i => $meta) {
            if ($meta['mode'] !== 'fefo') {
                continue;
            }
            $remaining = $meta['line_base'];
            $entries = [];
            foreach ($this->fefoOrder($batches, $slices, $meta['product_id'], $meta['variant_id']) as $bid) {
                if ($remaining <= self::EPS) {
                    break;
                }
                $avail = round($virtual[$bid] ?? 0.0, 3);
                if ($avail <= self::EPS) {
                    continue;
                }
                $take = round(min($remaining, $avail), 3);
                $virtual[$bid] = round($avail - $take, 3);
                $entries[] = $this->returnEntry($batches->get($bid), null, $take);
                $remaining = round($remaining - $take, 3);
            }
            if ($remaining > self::EPS) {
                throw ValidationException::withMessages([
                    "details.$i.batches" => 'No hay suficiente existencia por lote en la ubicación de inventario seleccionada para todas las líneas de la devolución.',
                ]);
            }
            $plannedByLine[$i] = $entries;
        }

        // ---- assemble in the ORIGINAL line order ------------------------
        $out = [];
        foreach ($lines as $i => $line) {
            if (empty($line['requires_batch'])) {
                $out[] = ['batch_allocation' => []] + $line;

                continue;
            }
            $entries = $plannedByLine[$i] ?? [];
            $sum = round(array_sum(array_column($entries, 'quantity_base')), 3);
            if (abs($sum - $batchLines[$i]['line_base']) > self::EPS) {
                throw ValidationException::withMessages([
                    "details.$i.batches" => 'La suma de las cantidades por lote ('.$sum.') no coincide con la cantidad física de la línea ('.$batchLines[$i]['line_base'].').',
                ]);
            }
            $out[] = ['batch_allocation' => $this->assignBidx($entries)] + $line;
        }

        return $out;
    }

    // -----------------------------------------------------------------------

    /** Active batch ids of a product (+ variant) that have a slice in $locationId. */
    private function discoverReturnFefoBatchIds(int $locationId, int $productId, ?int $variantId, int $warehouseId): array
    {
        return ProductBatchLocationStock::query()
            ->where('inventory_location_id', $locationId)
            ->whereHas('batch', function ($q) use ($productId, $variantId, $warehouseId) {
                $q->where('product_id', $productId)->where('status', 'active')->whereNull('deleted_at');
                $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId);
                $q->where(function ($w) use ($warehouseId) {
                    $w->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
                });
            })
            ->pluck('product_batch_id')->map(fn ($x) => (int) $x)->unique()->values()->all();
    }

    /** Locked batch ids for this product+variant, FEFO-ordered (NULL expiry last, then id ASC). */
    private function fefoOrder($batches, $slices, int $productId, ?int $variantId): array
    {
        return $batches
            ->filter(function ($b) use ($slices, $productId, $variantId) {
                if ((int) $b->product_id !== $productId || $b->deleted_at !== null) {
                    return false;
                }
                if ((string) $b->status !== 'active') {
                    return false;
                }
                $bVariant = $b->product_variant_id !== null ? (int) $b->product_variant_id : null;

                return $bVariant === $variantId && $slices->has($b->id);
            })
            ->sortBy(function ($b) {
                return ($b->expiry_date ? $b->expiry_date->format('Y-m-d') : '9999-12-31')
                    .'|'.str_pad((string) $b->id, 12, '0', STR_PAD_LEFT);
            })
            ->map(fn ($b) => (int) $b->id)
            ->values()
            ->all();
    }

    private function assertReturnBatchUsable($batch, int $bid, int $productId, ?int $variantId, int $warehouseId, int $i): void
    {
        if (! $batch || $batch->deleted_at !== null) {
            throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} no existe o fue eliminado."]);
        }
        if ((int) $batch->product_id !== $productId) {
            throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} no corresponde al producto de la línea."]);
        }
        $bVariant = $batch->product_variant_id !== null ? (int) $batch->product_variant_id : null;
        if ($bVariant !== $variantId) {
            throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} no corresponde a la variante de la línea."]);
        }
        if ($batch->warehouse_id !== null && (int) $batch->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} pertenece a otro almacén."]);
        }
        if (! in_array((string) $batch->status, self::OPERABLE_STATUSES, true)) {
            throw ValidationException::withMessages(["details.$i.batches" => "El lote {$bid} está en un estado no operable ({$batch->status})."]);
        }
    }

    private function returnEntry($batch, ?float $qtyInput, float $qtyBase): array
    {
        return [
            'product_batch_id' => (int) $batch->id,
            'batch_no' => (string) $batch->batch_no,
            'expiry_date' => $batch->expiry_date ? $batch->expiry_date->toDateString() : null,
            'mfg_date' => $batch->mfg_date ? $batch->mfg_date->toDateString() : null,
            'quantity_input' => $qtyInput,
            'quantity_base' => $qtyBase,
            'unit_cost' => $batch->unit_cost !== null ? (float) $batch->unit_cost : null,
        ];
    }

    // -----------------------------------------------------------------------

    private function resolveReceiptBatchId(int $productId, ?int $variantId, int $warehouseId, string $batchNo, ?string $expiry, ?string $mfg, ?float $unitCost, array $context): int
    {
        if (! Schema::hasTable('product_batches')) {
            throw ValidationException::withMessages(['batches' => 'El esquema de lotes no está disponible en este tenant.']);
        }

        $trashed = ProductBatch::withTrashed()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('batch_no', $batchNo)
            ->where(fn ($q) => $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId))
            ->first();

        if ($trashed && $trashed->deleted_at !== null) {
            throw ValidationException::withMessages([
                'batch_transition' => "El lote '{$batchNo}' del producto {$productId} existe con estado eliminado. La reconciliación de identidades eliminadas es una herramienta aparte. FAIL CLOSED.",
            ]);
        }

        $batch = $trashed ?: ProductBatch::firstOrCreate(
            ['product_id' => $productId, 'product_variant_id' => $variantId, 'warehouse_id' => $warehouseId, 'batch_no' => $batchNo],
            [
                'qty' => 0,
                'status' => 'active',
                'expiry_date' => $expiry,
                'mfg_date' => $mfg,
                'unit_cost' => $unitCost,
                'provider_id' => $context['provider_id'] ?? null,
                'source_purchase_id' => $context['source_purchase_id'] ?? null,
            ]
        );

        $locked = ProductBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

        if (! in_array((string) $locked->status, self::OPERABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['batches' => "El lote '{$batchNo}' está en un estado no operable ({$locked->status}). No se reactiva."]);
        }

        // METADATA — complete a NULL date, never overwrite a differing one.
        $locked->expiry_date = $this->reconcileDate('caducidad', $locked->expiry_date, $expiry, $batchNo);
        $locked->mfg_date = $this->reconcileDate('fabricación', $locked->mfg_date, $mfg, $batchNo);
        // unit_cost / provider_id / source_purchase_id: NOT touched on a top-up.
        if ($locked->isDirty()) {
            $locked->save();
        }

        return (int) $locked->id;
    }

    private function reconcileDate(string $kind, $existing, ?string $incoming, string $batchNo): ?string
    {
        $existingStr = $existing instanceof \DateTimeInterface
            ? $existing->format('Y-m-d')
            : ($existing !== null && $existing !== '' ? Carbon::parse((string) $existing)->toDateString() : null);

        if ($incoming === null) {
            return $existingStr;
        }
        if ($existingStr === null) {
            return $incoming;                    // complete it
        }
        if ($existingStr !== $incoming) {
            throw ValidationException::withMessages([
                'batches' => "La fecha de {$kind} del lote '{$batchNo}' ({$existingStr}) difiere de la recepción ({$incoming}). FAIL CLOSED.",
            ]);
        }

        return $existingStr;
    }

    private function assertLineBatchInput(array $raw, int $i): void
    {
        if (empty($raw)) {
            throw ValidationException::withMessages(["details.$i.batches" => 'La línea es de lote y no trae ningún lote.']);
        }
        $seen = [];
        foreach ($raw as $b) {
            $batchNo = trim((string) ($b['batch_no'] ?? ''));
            $qty = round((float) ($b['qty'] ?? 0), 3);
            if ($batchNo === '') {
                throw ValidationException::withMessages(["details.$i.batches" => 'Cada lote necesita un número de lote.']);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages(["details.$i.batches" => "La cantidad del lote '{$batchNo}' debe ser mayor que cero."]);
            }
            $key = mb_strtolower($batchNo);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["details.$i.batches" => "Número de lote duplicado en la misma línea: '{$batchNo}'."]);
            }
            $seen[$key] = true;
        }
    }

    private function rawBatches($rawLine): array
    {
        $raw = is_array($rawLine) ? ($rawLine['batches'] ?? null) : null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    private function parseDate($raw, string $field): ?string
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }
        try {
            return Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['batches' => "Fecha de lote inválida en '{$field}': {$raw}."]);
        }
    }

    private function toBase(float $qtyInput, array $line): float
    {
        $op = (string) ($line['unit_operator'] ?? '*');
        $val = (float) ($line['unit_operator_value'] ?? 1);
        if ($val <= 0) {
            $val = 1;
        }

        return round($op === '/' ? $qtyInput / $val : $qtyInput * $val, 3);
    }

    /** RECEIPT canonical order: batch_no, expiry, mfg, product_batch_id. */
    private function sortReceipt(array $entries): array
    {
        usort($entries, function ($a, $b) {
            return [$a['batch_no'], $a['expiry_date'] ?? '', $a['mfg_date'] ?? '', $a['product_batch_id']]
                <=> [$b['batch_no'], $b['expiry_date'] ?? '', $b['mfg_date'] ?? '', $b['product_batch_id']];
        });

        return $entries;
    }

    private function assignBidx(array $entries): array
    {
        $out = [];
        foreach (array_values($entries) as $n => $e) {
            $out[] = ['bidx' => $n] + $e;
        }

        return $out;
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() <= 0) {
            throw new \LogicException(
                'LocationAwarePurchaseBatchPlanner must run inside the caller\'s business transaction.'
            );
        }
    }
}
