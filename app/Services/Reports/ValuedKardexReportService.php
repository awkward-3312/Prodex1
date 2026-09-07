<?php

namespace App\Services\Reports;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryReadService;
use App\utils\helpers;
use Illuminate\Support\Facades\DB;

/**
 * Kardex valorizado — libro de movimientos de inventario por producto con saldo
 * de unidades y saldo valorizado corridos.
 *
 * ALCANCE branch-first / legacy-fallback
 * ------------------------------------------------------------------------------
 * Cada fuente se filtra por la MISMA regla que {@see \App\Services\SalesReportingScopeService}
 * (ver {@see InventoryReportScope}):
 *   · ventas / dev. de venta        → `branch_id` (moderno) | `warehouse_id` (legacy)
 *   · compras / dev. compra /
 *     ajustes / daños / traslados   → `inventory_location_id` (moderno) | `warehouse_id` (legacy)
 * `warehouse_id` nunca es la fuente primaria de un registro moderno, así que las
 * ventas POS modernas (`branch_id != NULL`, `warehouse_id NULL`) SÍ aparecen.
 *
 * COSTO
 * ------------------------------------------------------------------------------
 * Entradas con costo documental real → ese costo:
 *   · compras                 `purchase_details.cost`
 *   · traslado de entrada     costo del traslado pareado (WAC del origen si su
 *                             pierna de salida está en el mismo libro; si no,
 *                             `transfer_details.cost`)
 * Salidas con costo documental real → ese costo:
 *   · devolución a proveedor  `purchase_return_details.cost`
 * El resto (ventas, dev. de venta, ajustes, daños, traslado de salida) se valora
 * con COSTO PROMEDIO PONDERADO (WAC) corrido reconstruido de los costos
 * documentales reales. NUNCA `products.cost` actual para revalorizar historia;
 * `products.cost` sólo participa como "costo de referencia" del saldo inicial
 * reconstruido de un producto sin compras (marcado `sin_historial`).
 *
 * SALDO INICIAL RECONSTRUIDO
 * ------------------------------------------------------------------------------
 * Si la existencia actual real no se explica sólo con los documentos, la
 * diferencia se emite como fila `opening` (`is_reconstructed = true`). El saldo
 * de UNIDADES reconcilia siempre; la VALORIZACIÓN previa a `exact_from` es
 * aproximada — ver `valuation_quality`.
 */
class ValuedKardexReportService
{
    public function __construct(private InventoryReadService $inventoryRead)
    {
    }

    /**
     * @param  array  $filters  user (App\Models\User, requerido para el alcance),
     *                           product_id (int, requerido), product_variant_id (int|null),
     *                           branch_id (int|0), warehouse_id (int|0),
     *                           from (Y-m-d|null), to (Y-m-d|null)
     */
    public function build(array $filters): array
    {
        $productId = (int) ($filters['product_id'] ?? 0);
        $variantId = isset($filters['product_variant_id']) && $filters['product_variant_id'] !== null && $filters['product_variant_id'] !== ''
            ? (int) $filters['product_variant_id']
            : null;

        /** @var User $user */
        $user = $filters['user'];
        $scope = new InventoryReportScope(
            $user,
            (int) ($filters['branch_id'] ?? 0) ?: null,
            (int) ($filters['inventory_location_id'] ?? 0) ?: null,
            (int) ($filters['warehouse_id'] ?? 0) ?: null,
        );

        // Filtro contradictorio (sucursal ≠ sucursal de la ubicación) → 422.
        if ($scope->error() !== null) {
            return ['error' => $scope->error(), 'mode' => 'ledger', 'rows' => [], 'summary' => [], 'reconciliation' => [], 'quantity_quality' => [], 'valuation_quality' => []];
        }

        // Ventana de fechas: sólo se valida si el usuario la envía (el libro
        // completo, sin `from`/`to`, es el caso por defecto).
        $from = $to = null;
        $dateError = null;
        $toClamped = false;
        if (($filters['from'] ?? '') !== '' || ($filters['to'] ?? '') !== '') {
            $range = ReportDateRange::parse($filters['from'] ?? null, $filters['to'] ?? null);
            if (! $range->isValid()) {
                $dateError = $range->error;
            } else {
                $from = $range->from;
                $to = $range->to;
                $toClamped = $range->toClamped;
            }
        }
        if ($dateError !== null) {
            return ['error' => $dateError, 'mode' => 'ledger', 'rows' => [], 'summary' => [], 'reconciliation' => [], 'quantity_quality' => [], 'valuation_quality' => []];
        }

        // Producto inexistente / soft-deleted → payload vacío (no 404): la vista
        // muestra el estado "Sin movimientos", igual que Rotación degrada.
        $product = Product::whereNull('deleted_at')->find($productId);
        if (! $product) {
            return $this->notFoundPayload($productId, $scope);
        }
        $variant = $variantId ? ProductVariant::whereNull('deleted_at')->find($variantId) : null;

        $decimals = helpers::price_decimals();

        // --- 1. Movimientos documentales (historia completa dentro del alcance) ---
        $movements = $scope->isDenied() ? [] : $this->collectMovements($productId, $variantId, $scope);

        // Orden CRONOLÓGICO real: fecha + hora. `sort_bucket` (entradas antes que
        // salidas, y salida de traslado antes que su entrada) sólo desempata
        // cuando el `datetime` es idéntico — NO reordena el día por tipo.
        usort($movements, function ($a, $b) {
            return [$a['datetime'], $a['sort_bucket'], $a['sort_id']]
                <=> [$b['datetime'], $b['sort_bucket'], $b['sort_id']];
        });

        // --- 2. Existencia actual real (reconciliación) ---
        $onHand = $scope->isDenied() ? 0.0 : $this->currentOnHand($productId, $variantId, $scope);

        // --- 3. Costo de referencia del saldo inicial ---
        [$openingUnitCost, $openingCostBasis] = $this->openingUnitCost($movements, $product, $variant);

        // --- 4. Réplica cronológica con WAC corrido ---
        $balQty = 0.0;
        $balVal = 0.0;
        $netDocQty = 0.0;
        foreach ($movements as $m) {
            $netDocQty += $m['in_qty'] - $m['out_qty'];
        }
        $openingQty = round($onHand - $netDocQty, 3);

        $rows = [];
        $earliestDocDate = $movements[0]['date'] ?? null;
        $isReconstructed = abs($openingQty) > 0.0005;
        $usedWac = false;
        $usedReferenceCost = false;
        $wentNegative = false;

        if ($isReconstructed || ! $movements) {
            $balQty = $openingQty;
            $balVal = round($openingQty * $openingUnitCost, $decimals);
            $rows[] = $this->syntheticRow('opening', 'Saldo inicial (reconstruido)', $balQty, $balVal, round($openingUnitCost, $decimals), $openingCostBasis, true);
        }

        // Traslados internos value-neutrales: el costo unitario con que salió cada
        // Ref se reutiliza cuando su pierna de entrada también está en el libro.
        $transferOutUnitCost = [];

        // Costo de referencia (products.cost / variant.cost) para valorar un
        // movimiento cuando el saldo corrido es 0 y NO hay saldo inicial
        // reconstruido: no existe costo histórico real → se usa el de referencia,
        // NUNCA el costo de una compra futura.
        $referenceCost = $variant ? (float) $variant->cost : (float) $product->cost;

        foreach ($movements as $m) {
            if ($balQty > 0.0005) {
                $wac = $balVal / $balQty;
                $costBasis = 'wac';
            } elseif ($isReconstructed) {
                // El saldo inicial reconstruido carga su propio costo.
                $wac = $openingUnitCost;
                $costBasis = $openingCostBasis;
            } else {
                // Sin stock corrido ni saldo inicial → costo de referencia.
                $wac = $referenceCost;
                $costBasis = 'sin_historial';
            }

            if ($m['in_qty'] > 0) {
                if ($m['movement_key'] === 'transfer_in' && isset($transferOutUnitCost[$m['reference']])) {
                    // Traslado interno con ambas piernas visibles → entra al mismo
                    // costo unitario con que salió: no crea ni destruye valor.
                    $unitCost = $transferOutUnitCost[$m['reference']];
                    $value = round($m['in_qty'] * $unitCost, $decimals);
                    $costBasis = 'wac';
                } elseif ($m['doc_value'] !== null) {
                    $value = round($m['doc_value'], $decimals);
                    $unitCost = $m['in_qty'] > 0 ? $value / $m['in_qty'] : 0.0;
                    $costBasis = 'documento';
                } else {
                    $unitCost = $wac;
                    $value = round($m['in_qty'] * $wac, $decimals);
                    $costBasis === 'sin_historial' ? $usedReferenceCost = true : $usedWac = true;
                }
                $balQty = round($balQty + $m['in_qty'], 3);
                $balVal = round($balVal + $value, $decimals);
                $inQty = $m['in_qty'];
                $outQty = null;
                $moveValue = $value;
            } else {
                if ($m['doc_value'] !== null) {
                    // Devolución a proveedor: sale al costo documental registrado.
                    $value = round($m['doc_value'], $decimals);
                    $unitCost = $m['out_qty'] > 0 ? $value / $m['out_qty'] : 0.0;
                    $costBasis = 'documento';
                } else {
                    $unitCost = $wac;
                    $value = round($m['out_qty'] * $wac, $decimals);
                    $costBasis === 'sin_historial' ? $usedReferenceCost = true : $usedWac = true;
                }
                if ($m['movement_key'] === 'transfer_out') {
                    $transferOutUnitCost[$m['reference']] = $unitCost;
                }
                $balQty = round($balQty - $m['out_qty'], 3);
                $balVal = round($balVal - $value, $decimals);
                $inQty = null;
                $outQty = $m['out_qty'];
                $moveValue = -$value;
                if ($balQty < -0.0005) {
                    // Documentos fuera de orden (salida antes de su compra
                    // retro-fechada). NO se acota el valor: cantidad y valor
                    // quedan negativos y consistentes, y se corrigen solos
                    // cuando un documento posterior restaura la existencia.
                    // El WAC ya cae a `openingUnitCost` mientras balQty <= 0.
                    $wentNegative = true;
                }
            }

            $rows[] = [
                'kind' => 'movement',
                'is_reconstructed' => false,
                'date' => $m['date'],
                'datetime' => $m['datetime'],
                'movement_type' => $m['movement_type'],
                'movement_key' => $m['movement_key'],
                'reference' => $m['reference'],
                'branch_name' => $m['branch_name'],
                'location_name' => $m['location_name'],
                'location_basis' => $m['location_basis'],
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
        $reconciled = abs($finalQty - $onHand) < 0.001;

        // --- 5. Ventana de período para la presentación ---
        $displayRows = $this->applyPeriodWindow($rows, $from, $to, $decimals);

        // --- 6. Totales + saldos de PERÍODO (cuadran con el libro mostrado) ---
        $periodOpenQty = $openingQty;
        $periodOpenVal = round($openingQty * $openingUnitCost, $decimals);
        $periodCloseQty = $finalQty;
        $periodCloseVal = $finalVal;
        $totIn = $totOut = $totInVal = $totOutVal = 0.0;

        foreach ($rows as $r) {
            if ($r['kind'] !== 'movement') {
                continue;
            }
            if ($from && $r['date'] < $from) {
                $periodOpenQty = $r['balance_qty'];
                $periodOpenVal = $r['balance_value'];

                continue;
            }
            if ($to && $r['date'] > $to) {
                continue;
            }
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

        // --- 7. Calidad: CANTIDAD y VALORIZACIÓN por separado ---
        $valuationBasis = ($isReconstructed || $openingCostBasis === 'sin_historial' || $usedReferenceCost)
            ? 'approximate'
            : (($usedWac || $wentNegative) ? 'partially_reconstructed' : 'exact');

        $qtyMessage = $reconciled
            ? 'Existencia reconciliada con el stock real del alcance seleccionado.'
            : 'La existencia del libro NO reconcilia: revisa movimientos anulados o registrados fuera del alcance.';
        if ($wentNegative) {
            $qtyMessage .= ' Aviso: hay documentos fuera de orden (una salida antes de su compra); el saldo intermedio quedó negativo.';
        }

        $valMessage = match ($valuationBasis) {
            'exact' => 'Valorización exacta: todos los movimientos tienen costo documental.',
            'partially_reconstructed' => 'Valorización parcialmente reconstruida: el costo de las salidas usa promedio ponderado (PRODEX no conserva COGS histórico por venta/ajuste/daño).'
                .($earliestDocDate ? " Costo histórico disponible desde {$earliestDocDate}." : ''),
            default => $earliestDocDate
                ? "Valorización aproximada: hay saldo inicial reconstruido. El costo histórico no está disponible antes de {$earliestDocDate}."
                : 'Valorización aproximada: no hay documentos de inventario; el costo usa el costo de referencia del producto (sin historial).',
        };

        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'unit' => optional($product->unit)->ShortName ?: '',
            ],
            'scope' => [
                'branch_ids' => $scope->branchIds(),
                'legacy_warehouse_ids' => $scope->legacyWarehouseIds(),
                'has_modern_locations' => $scope->hasModernLocations(),
                'denied' => $scope->isDenied(),
            ],
            'window' => ['from' => $from, 'to' => $to, 'to_clamped' => $toClamped],
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
                'ledger_closing_qty' => $finalQty,
                'ledger_closing_value' => $finalVal,
            ],
            'reconciliation' => [
                'reconciled' => $reconciled,
                'ledger_closing_qty' => $finalQty,
                'stock_on_hand' => round($onHand, 3),
                'difference' => round($finalQty - $onHand, 3),
            ],
            'quantity_quality' => [
                'reconciled' => $reconciled,
                'reconstructed_opening' => $isReconstructed,
                'went_negative' => $wentNegative,
                'opening_qty' => $openingQty,
                'difference' => round($finalQty - $onHand, 3),
                'message' => $qtyMessage,
            ],
            'valuation_quality' => [
                'basis' => $valuationBasis, // exact | partially_reconstructed | approximate
                'historical_document_cost' => ! $usedWac || $openingCostBasis === 'documento',
                'reconstructed_wac' => $usedWac,
                'reference_cost' => $openingCostBasis === 'sin_historial',
                'exact_from' => $valuationBasis === 'exact' ? $earliestDocDate : null,
                'reconstructed_before' => $isReconstructed ? $earliestDocDate : null,
                'message' => $valMessage,
            ],
        ];
    }

    /** @return list<array> */
    private function collectMovements(int $productId, ?int $variantId, InventoryReportScope $scope): array
    {
        $units = [];
        foreach (Unit::get(['id', 'operator', 'operator_value']) as $u) {
            $units[(int) $u->id] = [$u->operator, (float) $u->operator_value];
        }
        $convert = function ($qty, $unitId) use ($units) {
            $qty = (float) $qty;
            if (! $unitId || ! isset($units[$unitId])) {
                return $qty;
            }
            [$op, $val] = $units[$unitId];
            if (! $val) {
                return $qty;
            }

            return $op === '/' ? $qty / $val : $qty * $val;
        };

        $vWhere = fn ($q) => $variantId ? $q->where('d.product_variant_id', $variantId) : $q;
        $out = [];

        $push = function (array &$out, string $key, string $label, $r, float $inQty, float $outQty, ?float $docValue, int $bucket) use ($scope) {
            $branchId = ((int) ($r->branch_id ?? 0)) ?: null;
            $locId = ((int) ($r->inventory_location_id ?? 0)) ?: null;
            $whId = ((int) ($r->warehouse_id ?? 0)) ?: null;
            $loc = $scope->resolveLocation($locId, $whId);
            $out[] = [
                'movement_type' => $label,
                'movement_key' => $key,
                'reference' => $r->Ref,
                'date' => $r->date,
                // Clave de orden cronológico: fecha + hora normalizada. Documento
                // legacy sin hora fiable → fallback explícito 00:00:00 (queda al
                // inicio de su día; `sort_bucket` lo ubica entre entradas/salidas).
                'datetime' => ($r->date ?? '0000-00-00').' '.$this->normalizeTime($r->time ?? null),
                'branch_name' => $scope->resolveBranchName($branchId, $locId, $whId),
                'location_name' => $loc['name'],
                'location_basis' => $loc['basis'],
                'in_qty' => round($inQty, 3),
                'out_qty' => round($outQty, 3),
                'doc_value' => $docValue,
                'sort_bucket' => $bucket,
                'sort_id' => (int) $r->id,
            ];
        };

        // --- Compras (ENTRADA, costo documental) ---
        $q = DB::table('purchase_details as d')->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'received')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyLocationScope($q, 'h');
        foreach ($q->get(['d.id', 'd.cost', 'd.quantity', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.inventory_location_id']) as $r) {
            $push($out, 'purchase', 'compra', $r, $convert($r->quantity, $r->purchase_unit_id), 0.0, (float) $r->cost * (float) $r->quantity, 0);
        }

        // --- Devoluciones a proveedor (SALIDA, costo documental) ---
        $q = DB::table('purchase_return_details as d')->join('purchase_returns as h', 'h.id', '=', 'd.purchase_return_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyLocationScope($q, 'h');
        foreach ($q->get(['d.id', 'd.cost', 'd.quantity', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.inventory_location_id']) as $r) {
            $push($out, 'purchase_return', 'devolución a proveedor', $r, 0.0, $convert($r->quantity, $r->purchase_unit_id), (float) $r->cost * (float) $r->quantity, 3);
        }

        // --- Ventas (SALIDA, WAC) — branch-first ---
        $q = DB::table('sale_details as d')->join('sales as h', 'h.id', '=', 'd.sale_id')
            ->whereNull('h.deleted_at')->where('h.statut', 'completed')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyBranchScope($q, 'h');
        foreach ($q->get(['d.id', 'd.quantity', 'd.sale_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.branch_id', 'h.inventory_location_id']) as $r) {
            $push($out, 'sale', 'venta', $r, 0.0, $convert($r->quantity, $r->sale_unit_id), null, 2);
        }

        // --- Devoluciones de venta (ENTRADA, WAC) — branch-first ---
        $q = DB::table('sale_return_details as d')->join('sale_returns as h', 'h.id', '=', 'd.sale_return_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyBranchScope($q, 'h');
        foreach ($q->get(['d.id', 'd.quantity', 'd.sale_unit_id', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.branch_id', 'h.inventory_location_id']) as $r) {
            $push($out, 'sale_return', 'devolución de venta', $r, $convert($r->quantity, $r->sale_unit_id), 0.0, null, 1);
        }

        // --- Ajustes (ENTRADA/SALIDA según type, WAC) ---
        $q = DB::table('adjustment_details as d')->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyLocationScope($q, 'h');
        foreach ($q->get(['d.id', 'd.quantity', 'd.type', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.inventory_location_id']) as $r) {
            $isAdd = strtolower((string) $r->type) === 'add';
            $push($out, 'adjustment', $isAdd ? 'ajuste (+)' : 'ajuste (−)', $r, $isAdd ? (float) $r->quantity : 0.0, $isAdd ? 0.0 : (float) $r->quantity, null, $isAdd ? 1 : 3);
        }

        // --- Daños (SALIDA, WAC) ---
        $q = DB::table('damage_details as d')->join('damages as h', 'h.id', '=', 'd.damage_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId);
        $vWhere($q);
        $scope->applyLocationScope($q, 'h');
        foreach ($q->get(['d.id', 'd.quantity', 'h.Ref', 'h.date', 'h.time', 'h.warehouse_id', 'h.inventory_location_id']) as $r) {
            $push($out, 'damage', 'daño', $r, 0.0, (float) $r->quantity, null, 3);
        }

        // --- Traslados: pierna de salida / entrada evaluada por separado ---
        $q = DB::table('transfer_details as d')->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->whereNull('h.deleted_at')->where('d.product_id', $productId);
        $vWhere($q);
        foreach ($q->get(['d.id', 'd.quantity', 'd.cost', 'd.purchase_unit_id', 'h.Ref', 'h.date', 'h.time',
            'h.from_warehouse_id', 'h.to_warehouse_id', 'h.from_inventory_location_id', 'h.to_inventory_location_id']) as $r) {
            $qty = $convert($r->quantity, $r->purchase_unit_id);
            $docValue = (float) $r->cost * (float) $r->quantity;

            if ($scope->rowInScope((int) ($r->from_inventory_location_id ?? 0) ?: null, (int) ($r->from_warehouse_id ?? 0) ?: null)) {
                $leg = (object) ['id' => $r->id, 'Ref' => $r->Ref, 'date' => $r->date, 'time' => $r->time,
                    'warehouse_id' => $r->from_warehouse_id, 'inventory_location_id' => $r->from_inventory_location_id];
                $push($out, 'transfer_out', 'traslado (salida)', $leg, 0.0, $qty, null, 3);
            }
            if ($scope->rowInScope((int) ($r->to_inventory_location_id ?? 0) ?: null, (int) ($r->to_warehouse_id ?? 0) ?: null)) {
                $leg = (object) ['id' => $r->id, 'Ref' => $r->Ref, 'date' => $r->date, 'time' => $r->time,
                    'warehouse_id' => $r->to_warehouse_id, 'inventory_location_id' => $r->to_inventory_location_id];
                // bucket 4: la ENTRADA del traslado se procesa DESPUÉS de su
                // SALIDA (bucket 3) para poder reutilizar el WAC del origen y que
                // el traslado interno sea value-neutral.
                $push($out, 'transfer_in', 'traslado (entrada)', $leg, $qty, 0.0, $docValue, 4);
            }
        }

        return $out;
    }

    /**
     * Existencia actual real del alcance.
     *
     *  · SELECTOR EXPLÍCITO de ubicación → SÓLO `inventory_location_stocks` de esa
     *    `inventory_location_id`, tenga o no `warehouse_id`. NO se lee el
     *    `product_warehouse` del almacén asociado ni `InventoryReadService`: el
     *    usuario pidió físicamente ESA ubicación.
     *  · Alcance general / sucursal → `InventoryReadService` (warehouse-keyed,
     *    legacy XOR moderno por almacén) + el stock de las InventoryLocation del
     *    alcance SIN almacén (fuera del alcance warehouse-keyed). Sin doble
     *    conteo: esas ubicaciones no mapean a ningún almacén.
     */
    private function currentOnHand(int $productId, ?int $variantId, InventoryReportScope $scope): float
    {
        $sum = 0.0;

        if (! $scope->isLocationScoped()) {
            $warehouseIds = $scope->stockWarehouseIds();
            if ($warehouseIds) {
                $totals = $this->inventoryRead->totalsByProductVariant([$productId], $warehouseIds);
                foreach ($totals as $key => $qty) {
                    if ($variantId ? $key === $productId.':'.$variantId : str_starts_with($key, $productId.':')) {
                        $sum += (float) $qty;
                    }
                }
            }
        }

        $locIds = $scope->stockLocationIdsForDirectRead();
        if ($locIds) {
            $q = DB::table('inventory_location_stocks')
                ->where('product_id', $productId)
                ->whereIn('inventory_location_id', $locIds);
            if ($variantId) {
                $q->where('product_variant_id', $variantId);
            }
            $sum += (float) $q->sum('quantity');
        }

        return round($sum, 3);
    }

    /** Normaliza `time` a `HH:MM:SS`; entrada vacía/no fiable → `00:00:00`. */
    private function normalizeTime(?string $time): string
    {
        $t = trim((string) $time);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $t, $m)) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        return '00:00:00';
    }

    /** @return array{0: float, 1: string} */
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

    /** Producto inexistente: payload vacío coherente (HTTP 200), no una excepción. */
    private function notFoundPayload(int $productId, InventoryReportScope $scope): array
    {
        return [
            'product' => ['id' => $productId, 'code' => null, 'name' => null, 'variant_id' => null, 'variant_name' => null, 'unit' => ''],
            'scope' => [
                'branch_ids' => $scope->branchIds(),
                'legacy_warehouse_ids' => $scope->legacyWarehouseIds(),
                'has_modern_locations' => $scope->hasModernLocations(),
                'denied' => $scope->isDenied(),
            ],
            'rows' => [],
            'summary' => [
                'opening_qty' => 0.0, 'opening_value' => 0.0, 'in_qty' => 0.0, 'out_qty' => 0.0,
                'in_value' => 0.0, 'out_value' => 0.0, 'closing_qty' => 0.0, 'closing_value' => 0.0,
                'closing_avg_cost' => 0.0, 'ledger_closing_qty' => 0.0, 'ledger_closing_value' => 0.0,
            ],
            'reconciliation' => ['reconciled' => true, 'ledger_closing_qty' => 0.0, 'stock_on_hand' => 0.0, 'difference' => 0.0],
            'quantity_quality' => ['reconciled' => true, 'reconstructed_opening' => false, 'went_negative' => false, 'opening_qty' => 0.0, 'difference' => 0.0, 'message' => 'El producto no existe o fue eliminado.'],
            'valuation_quality' => ['basis' => 'exact', 'historical_document_cost' => true, 'reconstructed_wac' => false, 'reference_cost' => false, 'exact_from' => null, 'reconstructed_before' => null, 'message' => ''],
            'not_found' => true,
        ];
    }

    private function syntheticRow(string $kind, string $label, float $balQty, float $balVal, float $unitCost, string $costBasis, bool $reconstructed): array
    {
        return [
            'kind' => $kind,
            'is_reconstructed' => $reconstructed,
            'date' => null,
            'movement_type' => $label,
            'movement_key' => $kind,
            'reference' => null,
            'branch_name' => null,
            'location_name' => null,
            'location_basis' => null,
            'in_qty' => null,
            'out_qty' => null,
            'balance_qty' => round($balQty, 3),
            'unit_cost' => $unitCost,
            'cost_basis' => $costBasis,
            'movement_value' => null,
            'balance_value' => round($balVal, 2),
        ];
    }

    /**
     * Colapsa los movimientos anteriores a `from` en "saldo al inicio del
     * período" y descarta los posteriores a `to`.
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
            if ($from && $r['kind'] === 'opening') {
                $before[] = $r;

                continue;
            }
            if ($from && $d !== null && $d < $from) {
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
        $periodOpening = $this->syntheticRow(
            'period_opening',
            'saldo al inicio del período',
            $last['balance_qty'],
            $last['balance_value'],
            $last['balance_qty'] > 0.0005 ? round($last['balance_value'] / $last['balance_qty'], $decimals) : 0.0,
            'wac',
            (bool) collect($before)->contains(fn ($r) => ! empty($r['is_reconstructed']))
        );
        $periodOpening['date'] = $from;

        return array_merge([$periodOpening], $inWindow);
    }
}
