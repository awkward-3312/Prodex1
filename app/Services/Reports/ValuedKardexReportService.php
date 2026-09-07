<?php

namespace App\Services\Reports;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\InventoryReadService;
use App\utils\helpers;
use Illuminate\Support\Facades\DB;

/**
 * Kardex valorizado — libro de movimientos de inventario por producto con saldo
 * de unidades y saldo valorizado corridos.
 *
 * ARQUITECTURA DE DATOS (auditada 2026-09-07)
 * ------------------------------------------------------------------------------
 * Los movimientos se reconstruyen desde los DOCUMENTOS reales (compras, ventas,
 * devoluciones, ajustes, traslados, daños). `inventory_location_movements` es
 * una proyección derivada de esos mismos documentos (hoy vacía en varios
 * tenants) — usar los documentos evita doble conteo y funciona igual en tenants
 * legacy y modernos. La existencia ACTUAL (para reconciliar) viene de
 * {@see InventoryReadService} que ya resuelve modern-first / legacy-fallback por
 * almacén sin sumar las dos fuentes.
 *
 * COSTO — LIMITACIÓN CONOCIDA
 * ------------------------------------------------------------------------------
 * PRODEX SÓLO persiste costo unitario histórico en los documentos de ENTRADA:
 *   · purchase_details.cost
 *   · purchase_return_details.cost
 *   · transfer_details.cost
 * NO existe COGS histórico en sale_details / adjustment_details / damage_details.
 * Por tanto el costo de las SALIDAS (y de entradas sin costo documental: ajuste
 * positivo, devolución de venta) se valora con COSTO PROMEDIO PONDERADO (WAC)
 * corrido, reconstruido cronológicamente desde los costos documentales reales.
 * NUNCA se usa `products.cost` actual para revalorizar un movimiento histórico;
 * `products.cost` sólo interviene como "costo de referencia" del saldo inicial
 * reconstruido de un producto sin ninguna compra registrada, y queda marcado
 * como tal (`cost_basis = 'sin_historial'`).
 *
 * SALDO INICIAL RECONSTRUIDO
 * ------------------------------------------------------------------------------
 * Cuando la existencia actual no se explica sólo con los documentos registrados
 * (típico en catálogos migrados desde product_warehouse), la diferencia se emite
 * como una primera fila explícita `opening` (`is_reconstructed = true`). El
 * kardex es EXACTO a partir de `exact_from` (fecha del primer documento real);
 * antes de esa fecha el saldo es una reconstrucción contra la existencia actual.
 */
class ValuedKardexReportService
{
    public function __construct(private InventoryReadService $inventoryRead)
    {
    }

    /**
     * @param  array  $filters  product_id (int, requerido para el libro),
     *                           product_variant_id (int|null),
     *                           warehouse_ids (int[] — alcance ya resuelto por el controlador),
     *                           branch_id (int|0), from (Y-m-d|null), to (Y-m-d|null)
     * @return array{
     *   product: array, rows: list<array>, summary: array,
     *   reconciliation: array, data_quality: array
     * }
     */
    public function build(array $filters): array
    {
        $productId = (int) ($filters['product_id'] ?? 0);
        $variantId = isset($filters['product_variant_id']) && $filters['product_variant_id'] !== null && $filters['product_variant_id'] !== ''
            ? (int) $filters['product_variant_id']
            : null;
        $warehouseIds = array_values(array_unique(array_map('intval', $filters['warehouse_ids'] ?? [])));
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $product = Product::whereNull('deleted_at')->findOrFail($productId);
        $variant = $variantId ? ProductVariant::whereNull('deleted_at')->find($variantId) : null;

        $decimals = helpers::price_decimals();

        // --- 1. Reunir TODOS los movimientos documentales (historia completa) ---
        $movements = $this->collectMovements($productId, $variantId, $warehouseIds);

        // Orden cronológico estable: fecha, prioridad de tipo (entradas antes que
        // salidas el mismo día para no forzar saldo negativo espurio), id.
        usort($movements, function ($a, $b) {
            return [$a['date'], $a['sort_bucket'], $a['sort_id']]
                <=> [$b['date'], $b['sort_bucket'], $b['sort_id']];
        });

        // --- 2. Existencia actual real (fuente de reconciliación) ---
        $onHand = $this->currentOnHand($productId, $variantId, $warehouseIds);

        // --- 3. Costo de referencia para el saldo inicial ---
        [$openingUnitCost, $openingCostBasis] = $this->openingUnitCost($movements, $product, $variant);

        // --- 4. Réplica cronológica con WAC corrido ---------------------------
        $balQty = 0.0;
        $balVal = 0.0;

        // Saldo inicial = existencia actual − Σentradas + Σsalidas de TODA la
        // historia documental. Es el "tapón" no registrado (migración legacy).
        $netDocQty = 0.0;
        foreach ($movements as $m) {
            $netDocQty += $m['in_qty'] - $m['out_qty'];
        }
        $openingQty = round($onHand - $netDocQty, 3);

        $rows = [];
        $earliestDocDate = $movements[0]['date'] ?? null;

        $isReconstructed = abs($openingQty) > 0.0005;
        if ($isReconstructed || ! $movements) {
            $openingVal = round($openingQty * $openingUnitCost, $decimals);
            $balQty = $openingQty;
            $balVal = $openingVal;
            $rows[] = [
                'kind' => 'opening',
                'is_reconstructed' => true,
                'date' => null,                       // "Saldo inicial", sin fecha exacta
                'movement_type' => 'Saldo inicial (reconstruido)',
                'reference' => null,
                'branch_name' => null,
                'location_name' => null,
                'in_qty' => null,
                'out_qty' => null,
                'balance_qty' => round($balQty, 3),
                'unit_cost' => round($openingUnitCost, $decimals),
                'cost_basis' => $openingCostBasis,
                'movement_value' => null,
                'balance_value' => $balVal,
            ];
        }

        foreach ($movements as $m) {
            $wac = $balQty > 0.0005 ? ($balVal / $balQty) : $openingUnitCost;
            $costBasis = $balQty > 0.0005 ? 'wac' : $openingCostBasis;

            if ($m['in_qty'] > 0) {
                // Entrada: costo documental si existe; si no, WAC corrido.
                if ($m['doc_value'] !== null) {
                    $value = round($m['doc_value'], $decimals);
                    $unitCost = $m['in_qty'] > 0 ? $value / $m['in_qty'] : 0.0;
                    $costBasis = 'documento';
                } else {
                    $unitCost = $wac;
                    $value = round($m['in_qty'] * $wac, $decimals);
                }
                $balQty = round($balQty + $m['in_qty'], 3);
                $balVal = round($balVal + $value, $decimals);
                $inQty = $m['in_qty'];
                $outQty = null;
                $moveValue = $value;
            } else {
                // Salida: SIEMPRE al WAC corrido (no hay COGS histórico).
                $unitCost = $wac;
                $value = round($m['out_qty'] * $wac, $decimals);
                $balQty = round($balQty - $m['out_qty'], 3);
                $balVal = round($balVal - $value, $decimals);
                $inQty = null;
                $outQty = $m['out_qty'];
                $moveValue = -$value;
                if ($balQty < 0) {
                    // Inconsistencia de datos: existencia insuficiente para la
                    // salida. Se registra pero se acota el saldo valorizado a 0.
                    $balVal = 0.0;
                }
            }

            $rows[] = [
                'kind' => 'movement',
                'is_reconstructed' => false,
                'date' => $m['date'],
                'datetime' => $m['datetime'],
                'movement_type' => $m['movement_type'],
                'reference' => $m['reference'],
                'branch_name' => $m['branch_name'],
                'location_name' => $m['location_name'],
                'in_qty' => $inQty,
                'out_qty' => $outQty,
                'balance_qty' => round($balQty, 3),
                'unit_cost' => round($unitCost, $decimals),
                'cost_basis' => $costBasis,
                'movement_value' => round($moveValue, $decimals),
                'balance_value' => round($balVal, $decimals),
            ];
        }

        $finalQty = round($balQty, 3);
        $finalVal = round($balVal, $decimals);

        // --- 5. Reconciliación: saldo final del libro == existencia real ------
        $reconciled = abs($finalQty - $onHand) < 0.001;

        // --- 6. Filtro de período para la PRESENTACIÓN ----------------------
        // Se replica siempre la historia completa (para un WAC correcto); aquí se
        // recorta la vista. Los movimientos anteriores a `from` se colapsan en
        // una fila "saldo al inicio del período".
        $displayRows = $this->applyPeriodWindow($rows, $from, $to, $decimals);

        // Totales + saldos de PERÍODO. El resumen debe cuadrar con el libro
        // mostrado: saldo inicial del período + entradas − salidas = saldo final
        // del período. Sin filtro de fechas, inicial = saldo inicial del kardex
        // y final = existencia real.
        $periodOpenQty = $openingQty;
        $periodOpenVal = round($openingQty * $openingUnitCost, $decimals);
        $periodCloseQty = $finalQty;
        $periodCloseVal = $finalVal;
        $totIn = 0.0;
        $totOut = 0.0;
        $totInVal = 0.0;
        $totOutVal = 0.0;

        foreach ($rows as $r) {
            if ($r['kind'] !== 'movement') {
                continue;
            }
            // Saldo antes de la ventana: se acarrea hasta el último movimiento
            // anterior a `from`.
            if ($from && $r['date'] < $from) {
                $periodOpenQty = $r['balance_qty'];
                $periodOpenVal = $r['balance_value'];

                continue;
            }
            if ($to && $r['date'] > $to) {
                continue;
            }
            // Último movimiento dentro de la ventana → saldo final del período.
            $periodCloseQty = $r['balance_qty'];
            $periodCloseVal = $r['balance_value'];

            $totIn += (float) ($r['in_qty'] ?? 0);
            $totOut += (float) ($r['out_qty'] ?? 0);
            if (($r['in_qty'] ?? 0) > 0) {
                $totInVal += (float) $r['movement_value'];
            } else {
                $totOutVal += abs((float) $r['movement_value']);
            }
        }

        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'unit' => optional($product->unit)->ShortName ?: '',
            ],
            'rows' => $displayRows,
            'summary' => [
                'opening_qty' => round($periodOpenQty, 3),
                'opening_value' => round($periodOpenVal, $decimals),
                'in_qty' => round($totIn, 3),
                'out_qty' => round($totOut, 3),
                'in_value' => round($totInVal, $decimals),
                'out_value' => round($totOutVal, $decimals),
                'closing_qty' => round($periodCloseQty, 3),
                'closing_value' => round($periodCloseVal, $decimals),
                'closing_avg_cost' => $periodCloseQty > 0.0005 ? round($periodCloseVal / $periodCloseQty, $decimals) : 0.0,
                // Saldo final del libro completo (siempre = existencia real).
                'ledger_closing_qty' => $finalQty,
                'ledger_closing_value' => $finalVal,
            ],
            'reconciliation' => [
                'reconciled' => $reconciled,
                'ledger_closing_qty' => $finalQty,
                'stock_on_hand' => round($onHand, 3),
                'difference' => round($finalQty - $onHand, 3),
            ],
            'data_quality' => [
                'is_reconstructed_opening' => $isReconstructed,
                'exact_from' => $earliestDocDate,
                'opening_cost_basis' => $openingCostBasis,
                'note' => $earliestDocDate
                    ? "El kardex es exacto a partir de {$earliestDocDate}. El saldo previo a esa fecha se reconstruye contra la existencia actual porque PRODEX no conserva el saldo inicial histórico ni el costo de las salidas."
                    : 'No hay documentos de inventario registrados para este producto en el alcance seleccionado; el saldo mostrado es la existencia actual sin historial reconstruible.',
            ],
        ];
    }

    /**
     * Recolecta movimientos de todas las fuentes documentales, normalizados a:
     * date, datetime, movement_type, reference, branch_name, location_name,
     * in_qty (base unit), out_qty (base unit), doc_value (float|null),
     * sort_bucket, sort_id.
     *
     * @return list<array>
     */
    private function collectMovements(int $productId, ?int $variantId, array $warehouseIds): array
    {
        if (! $warehouseIds) {
            return [];
        }

        $units = Unit::pluck('operator_value', 'id')->all();
        $unitOps = Unit::pluck('operator', 'id')->all();
        $convert = function ($qty, $unitId) use ($units, $unitOps) {
            $qty = (float) $qty;
            if (! $unitId || ! isset($units[$unitId])) {
                return $qty;
            }
            $op = $unitOps[$unitId] ?? '*';
            $val = (float) ($units[$unitId] ?: 1);
            if ($val == 0.0) {
                return $qty;
            }

            return $op === '/' ? $qty / $val : $qty * $val;
        };

        $branchByWarehouse = DB::table('warehouses')->whereIn('id', $warehouseIds)
            ->pluck('branch_id', 'id')->all();
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();
        $branchName = fn ($whId) => $branchNames[$branchByWarehouse[$whId] ?? 0] ?? null;
        $warehouseNames = DB::table('warehouses')->whereIn('id', $warehouseIds)->pluck('name', 'id')->all();

        $variantWhere = function ($q, string $col = 'product_variant_id') use ($variantId) {
            if ($variantId) {
                $q->where($col, $variantId);
            }
        };

        $out = [];

        // --- Compras (ENTRADA, costo documental) ---
        $rows = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'received') // igual que CalculatesCogsAndAverageCost
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.cost', 'd.quantity', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $qty = $convert($r->quantity, $r->purchase_unit_id);
            $out[] = $this->row('purchase', 'compra', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: $qty, outQty: 0.0, docValue: (float) $r->cost * (float) $r->quantity, bucket: 0, id: (int) $r->id);
        }

        // --- Devoluciones a proveedor (SALIDA, costo documental) ---
        $rows = DB::table('purchase_return_details as d')
            ->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->whereNull('h.deleted_at')
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.cost', 'd.quantity', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $qty = $convert($r->quantity, $r->purchase_unit_id);
            $out[] = $this->row('purchase_return', 'devolución a proveedor', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: 0.0, outQty: $qty, docValue: null, bucket: 3, id: (int) $r->id);
        }

        // --- Ventas (SALIDA, WAC) ---
        $rows = DB::table('sale_details as d')
            ->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'completed') // igual que CalculatesCogsAndAverageCost
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.quantity', 'd.sale_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $qty = $convert($r->quantity, $r->sale_unit_id);
            $out[] = $this->row('sale', 'venta', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: 0.0, outQty: $qty, docValue: null, bucket: 2, id: (int) $r->id);
        }

        // --- Devoluciones de venta (ENTRADA, WAC) ---
        $rows = DB::table('sale_return_details as d')
            ->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->whereNull('h.deleted_at')
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.quantity', 'd.sale_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $qty = $convert($r->quantity, $r->sale_unit_id);
            $out[] = $this->row('sale_return', 'devolución de venta', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: $qty, outQty: 0.0, docValue: null, bucket: 1, id: (int) $r->id);
        }

        // --- Ajustes (ENTRADA o SALIDA según type, WAC) ---
        $rows = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->whereNull('h.deleted_at')
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.quantity', 'd.type', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $qty = (float) $r->quantity; // los ajustes se registran en unidad base
            $isAdd = strtolower((string) $r->type) === 'add';
            $out[] = $this->row('adjustment', $isAdd ? 'ajuste (+)' : 'ajuste (−)', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: $isAdd ? $qty : 0.0, outQty: $isAdd ? 0.0 : $qty, docValue: null, bucket: $isAdd ? 1 : 3, id: (int) $r->id);
        }

        // --- Daños (SALIDA, WAC) ---
        $rows = DB::table('damage_details as d')
            ->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->whereNull('h.deleted_at')
            ->where('d.product_id', $productId)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->get(['d.id', 'd.quantity', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id']);
        foreach ($rows as $r) {
            $out[] = $this->row('damage', 'daño', $r->Ref, $r->date, $r->time, $branchName($r->warehouse_id), $warehouseNames[$r->warehouse_id] ?? null,
                inQty: 0.0, outQty: (float) $r->quantity, docValue: null, bucket: 3, id: (int) $r->id);
        }

        // --- Traslados (SALIDA del origen / ENTRADA al destino, costo documental) ---
        $rows = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->whereNull('h.deleted_at')
            ->where('d.product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('d.product_variant_id', $variantId))
            ->where(function ($q) use ($warehouseIds) {
                $q->whereIn('h.from_warehouse_id', $warehouseIds)
                    ->orWhereIn('h.to_warehouse_id', $warehouseIds);
            })
            ->get(['d.id', 'd.quantity', 'd.cost', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.from_warehouse_id', 'h.to_warehouse_id']);
        foreach ($rows as $r) {
            $qty = $convert($r->quantity, $r->purchase_unit_id);
            $docValue = (float) $r->cost * (float) $r->quantity;
            if (in_array((int) $r->from_warehouse_id, $warehouseIds, true)) {
                $out[] = $this->row('transfer_out', 'traslado (salida)', $r->Ref, $r->date, $r->time, $branchName($r->from_warehouse_id), $warehouseNames[$r->from_warehouse_id] ?? null,
                    inQty: 0.0, outQty: $qty, docValue: null, bucket: 3, id: (int) $r->id);
            }
            if (in_array((int) $r->to_warehouse_id, $warehouseIds, true)) {
                $out[] = $this->row('transfer_in', 'traslado (entrada)', $r->Ref, $r->date, $r->time, $branchName($r->to_warehouse_id), $warehouseNames[$r->to_warehouse_id] ?? null,
                    inQty: $qty, outQty: 0.0, docValue: $docValue, bucket: 0, id: (int) $r->id);
            }
        }

        return $out;
    }

    private function row(string $type, string $label, ?string $ref, ?string $date, ?string $time, ?string $branch, ?string $location, float $inQty, float $outQty, ?float $docValue, int $bucket, int $id): array
    {
        return [
            'movement_type' => $label,
            'movement_key' => $type,
            'reference' => $ref,
            'date' => $date,
            'datetime' => trim(($date ?? '').' '.($time ?? '')),
            'branch_name' => $branch,
            'location_name' => $location,
            'in_qty' => round($inQty, 3),
            'out_qty' => round($outQty, 3),
            'doc_value' => $docValue,
            'sort_bucket' => $bucket,
            'sort_id' => $id,
        ];
    }

    private function currentOnHand(int $productId, ?int $variantId, array $warehouseIds): float
    {
        if (! $warehouseIds) {
            return 0.0;
        }
        $totals = $this->inventoryRead->totalsByProductVariant([$productId], $warehouseIds);
        if ($variantId) {
            return (float) ($totals[$productId.':'.$variantId] ?? 0.0);
        }
        $sum = 0.0;
        foreach ($totals as $key => $qty) {
            if (str_starts_with($key, $productId.':')) {
                $sum += (float) $qty;
            }
        }

        return $sum;
    }

    /**
     * Costo unitario para valorizar el saldo inicial reconstruido:
     *  1) primer costo documental de ENTRADA disponible (base real);
     *  2) products.cost / variant.cost como "costo de referencia (sin historial)".
     *
     * @return array{0: float, 1: string}
     */
    private function openingUnitCost(array $movements, Product $product, ?ProductVariant $variant): array
    {
        foreach ($movements as $m) {
            if ($m['doc_value'] !== null && $m['in_qty'] > 0) {
                return [$m['doc_value'] / $m['in_qty'], 'documento'];
            }
        }
        $ref = $variant ? (float) $variant->cost : (float) $product->cost;

        return [$ref, 'sin_historial'];
    }

    /**
     * Colapsa los movimientos anteriores a `from` en una fila "saldo al inicio
     * del período" y descarta los posteriores a `to`.
     *
     * @param  list<array>  $rows
     * @return list<array>
     */
    private function applyPeriodWindow(array $rows, ?string $from, ?string $to, int $decimals): array
    {
        if (! $from && ! $to) {
            return $rows;
        }

        $before = [];
        $inWindow = [];
        foreach ($rows as $r) {
            $d = $r['date'];
            if ($from && $d !== null && $d < $from && $r['kind'] !== 'opening') {
                $before[] = $r;

                continue;
            }
            if ($from && $r['kind'] === 'opening') {
                $before[] = $r;

                continue;
            }
            if ($to && $d !== null && $d > $to) {
                continue;
            }
            $inWindow[] = $r;
        }

        if (! $before) {
            return $inWindow;
        }

        $last = end($before);
        $periodOpening = [
            'kind' => 'period_opening',
            'is_reconstructed' => (bool) collect($before)->contains(fn ($r) => ! empty($r['is_reconstructed'])),
            'date' => $from,
            'movement_type' => 'saldo al inicio del período',
            'reference' => null,
            'branch_name' => null,
            'location_name' => null,
            'in_qty' => null,
            'out_qty' => null,
            'balance_qty' => $last['balance_qty'],
            'unit_cost' => $last['balance_qty'] > 0.0005 ? round($last['balance_value'] / $last['balance_qty'], $decimals) : 0.0,
            'cost_basis' => 'wac',
            'movement_value' => null,
            'balance_value' => $last['balance_value'],
        ];

        return array_merge([$periodOpening], $inWindow);
    }
}
