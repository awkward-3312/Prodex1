<?php

namespace App\Services\Reports;

use App\Models\Unit;
use App\Services\InventoryReadService;
use App\utils\helpers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de inventario — reporte OPERATIVO por producto.
 *
 * MÉTRICA PRIMARIA (unidades), documentada:
 *   rotación = unidades vendidas netas del período / inventario promedio (unid.)
 *   inventario promedio = (stock_inicial + stock_final) / 2
 *   stock_final  = existencia actual real ({@see InventoryReadService})
 *   stock_inicial = stock_final − (Σ entradas − Σ salidas del período)
 *                   reconstruido desde los DOCUMENTOS reales del período
 *   días de inventario = días del período / rotación   (sólo si rotación > 0)
 *
 * MÉTRICA FINANCIERA (secundaria, columnas aparte, NUNCA mezclada con la
 * operativa): rotación financiera ≈ costo de ventas / inventario promedio
 * valorizado, con costo unitario = WAC de compras del producto (o products.cost
 * marcado `sin_historial` si no hay compras). Se rotula "aprox. WAC".
 *
 * CASOS SIN DATO — se devuelven como null (la UI muestra "N/A / datos
 * insuficientes"); NUNCA infinito, 0 engañoso ni valores fabricados:
 *   · inventario promedio = 0
 *   · sin ventas en el período
 *   · stock_inicial reconstruido < 0 (historial documental insuficiente)
 */
class InventoryTurnoverReportService
{
    /** Umbrales de clasificación (días de inventario). Explícitos y documentados. */
    public const FAST_MAX_DAYS = 30;

    public const MEDIUM_MAX_DAYS = 90;

    /** Cota de productos evaluados por corrida (ordenación por métrica derivada). */
    public const MAX_PRODUCTS = 5000;

    public function __construct(private InventoryReadService $inventoryRead)
    {
    }

    /**
     * @param  array  $filters  warehouse_ids (int[]), from (Y-m-d), to (Y-m-d),
     *                           category_id (int|0), search (string), page, limit,
     *                           sort_field, sort_dir
     */
    public function build(array $filters): array
    {
        $warehouseIds = array_values(array_unique(array_map('intval', $filters['warehouse_ids'] ?? [])));
        $from = $filters['from'] ?? Carbon::now()->subDays(90)->toDateString();
        $to = $filters['to'] ?? Carbon::now()->toDateString();
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['limit'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $sortField = $filters['sort_field'] ?? 'turnover';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $periodDays = (int) max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $decimals = helpers::price_decimals();

        if (! $warehouseIds) {
            return $this->emptyResult($from, $to, $periodDays);
        }

        // --- Universo de productos en alcance ---
        $productsQuery = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search) {
                $qq->where('p.name', 'LIKE', "%{$search}%")->orWhere('p.code', 'LIKE', "%{$search}%");
            }))
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id');

        $matched = (clone $productsQuery)->count('p.id');

        // La rotación se ordena por métricas DERIVADAS (no columnas SQL), así que
        // hay que traer y calcular todo el universo filtrado antes de paginar en
        // PHP. Cota de seguridad para catálogos gigantes: se acota a los primeros
        // MAX_PRODUCTS por nombre (el usuario acota más con categoría / búsqueda).
        // `totalRows` refleja las filas realmente evaluadas — si se aplicó la
        // cota, `meta.capped` lo avisa y la paginación no muestra páginas
        // fantasma más allá de lo calculado.
        $capped = $matched > self::MAX_PRODUCTS;
        $totalRows = $capped ? self::MAX_PRODUCTS : $matched;

        $products = $productsQuery
            ->orderBy('p.name')
            ->limit(self::MAX_PRODUCTS)
            ->get(['p.id', 'p.code', 'p.name', 'p.cost', 'p.category_id', 'c.name as category_name']);

        $productIds = $products->pluck('id')->map('intval')->all();
        if (! $productIds) {
            return $this->emptyResult($from, $to, $periodDays);
        }

        // --- Existencia actual real (final), por producto ---
        $stockByProduct = [];
        foreach ($this->inventoryRead->totalsByProductVariant($productIds, $warehouseIds) as $key => $qty) {
            [$pid] = explode(':', $key);
            $stockByProduct[(int) $pid] = ($stockByProduct[(int) $pid] ?? 0.0) + (float) $qty;
        }

        // --- Movimientos del período (para reconstruir stock_inicial y ventas) ---
        $unitFactor = $this->unitFactorResolver();

        $salesUnits = $this->periodQty('sale_details', 'sales', 'sale_id', 'sale_unit_id', $productIds, $warehouseIds, $from, $to, $unitFactor);
        $saleReturnUnits = $this->periodQty('sale_return_details', 'sale_returns', 'sale_return_id', 'sale_unit_id', $productIds, $warehouseIds, $from, $to, $unitFactor);
        $purchaseUnits = $this->periodQty('purchase_details', 'purchases', 'purchase_id', 'purchase_unit_id', $productIds, $warehouseIds, $from, $to, $unitFactor);
        $purchaseReturnUnits = $this->periodQty('purchase_return_details', 'purchase_returns', 'purchase_return_id', 'purchase_unit_id', $productIds, $warehouseIds, $from, $to, $unitFactor);
        $damageUnits = $this->periodQty('damage_details', 'damages', 'damage_id', null, $productIds, $warehouseIds, $from, $to, $unitFactor);
        [$adjAdd, $adjSub] = $this->periodAdjustments($productIds, $warehouseIds, $from, $to);
        [$transferIn, $transferOut] = $this->periodTransfers($productIds, $warehouseIds, $from, $to, $unitFactor);

        // --- WAC de compras por producto (para métrica financiera) ---
        $wacByProduct = $this->purchaseWac($productIds);

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $stockFinal = round($stockByProduct[$pid] ?? 0.0, 3);

            $unitsSold = round(($salesUnits[$pid] ?? 0.0) - ($saleReturnUnits[$pid] ?? 0.0), 3);

            $netInPeriod = round(
                ($purchaseUnits[$pid] ?? 0.0)
                + ($saleReturnUnits[$pid] ?? 0.0)
                + ($adjAdd[$pid] ?? 0.0)
                + ($transferIn[$pid] ?? 0.0)
                - ($salesUnits[$pid] ?? 0.0)
                - ($purchaseReturnUnits[$pid] ?? 0.0)
                - ($damageUnits[$pid] ?? 0.0)
                - ($adjSub[$pid] ?? 0.0)
                - ($transferOut[$pid] ?? 0.0),
                3
            );

            $stockInitial = round($stockFinal - $netInPeriod, 3);

            $insufficient = $stockInitial < -0.0005;
            $avgStock = $insufficient ? null : round(($stockInitial + $stockFinal) / 2, 3);

            $turnover = null;
            $daysInventory = null;
            $classification = null;
            $reason = null;

            if ($avgStock === null) {
                $reason = 'historial insuficiente';
            } elseif ($avgStock <= 0.0005) {
                $reason = 'inventario promedio 0';
            } elseif ($unitsSold <= 0.0005) {
                $classification = 'baja';
                $reason = 'sin ventas en el período';
                $turnover = 0.0;
            } else {
                $turnover = round($unitsSold / $avgStock, 4);
                if ($turnover > 0) {
                    $daysInventory = round($periodDays / $turnover, 1);
                    $classification = $daysInventory <= self::FAST_MAX_DAYS ? 'alta'
                        : ($daysInventory <= self::MEDIUM_MAX_DAYS ? 'media' : 'baja');
                }
            }

            // --- Financiera (secundaria) ---
            [$unitCost, $costBasis] = $wacByProduct[$pid] ?? [(float) $p->cost, 'sin_historial'];
            $cogs = $unitsSold > 0 ? round($unitsSold * $unitCost, $decimals) : 0.0;
            $avgStockValue = $avgStock !== null ? round($avgStock * $unitCost, $decimals) : null;
            $turnoverFin = ($avgStockValue !== null && $avgStockValue > 0.0005 && $cogs > 0)
                ? round($cogs / $avgStockValue, 4)
                : null;
            $daysInventoryFin = ($turnoverFin !== null && $turnoverFin > 0) ? round($periodDays / $turnoverFin, 1) : null;

            $rows[] = [
                'product_id' => $pid,
                'code' => $p->code,
                'name' => $p->name,
                'category' => $p->category_name,
                'units_sold' => $unitsSold,
                'stock_initial' => $insufficient ? null : $stockInitial,
                'stock_final' => $stockFinal,
                'avg_stock' => $avgStock,
                'turnover' => $turnover,
                'days_inventory' => $daysInventory,
                'classification' => $classification,
                'reason' => $reason,
                'financial' => [
                    'unit_cost' => round($unitCost, $decimals),
                    'cost_basis' => $costBasis,
                    'cogs' => $cogs,
                    'avg_stock_value' => $avgStockValue,
                    'turnover' => $turnoverFin,
                    'days_inventory' => $daysInventoryFin,
                ],
            ];
        }

        // --- Orden + paginación en PHP (métricas derivadas, nulls al final) ---
        usort($rows, function ($a, $b) use ($sortField, $sortDir) {
            $va = $a[$sortField] ?? null;
            $vb = $b[$sortField] ?? null;
            if ($va === $vb) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            $cmp = $va <=> $vb;

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        $paged = $perPage === -1 ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => array_values($paged),
            'totalRows' => $totalRows,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'period_days' => $periodDays,
                'capped' => $capped,
                'max_products' => self::MAX_PRODUCTS,
                'matched_products' => $matched,
                'formula' => 'rotación = unidades vendidas netas / inventario promedio (unidades). inventario promedio = (stock_inicial + stock_final) / 2. días de inventario = días del período / rotación.',
                'thresholds' => ['alta_max_dias' => self::FAST_MAX_DAYS, 'media_max_dias' => self::MEDIUM_MAX_DAYS],
                'financial_note' => 'La rotación financiera usa costo unitario WAC de compras (o products.cost si el producto no tiene compras registradas, marcado "sin historial"). Es una aproximación y NO debe compararse con la rotación operativa en unidades.',
            ],
        ];
    }

    private function unitFactorResolver(): callable
    {
        $val = Unit::pluck('operator_value', 'id')->all();
        $op = Unit::pluck('operator', 'id')->all();

        return function ($qty, $unitId) use ($val, $op) {
            $qty = (float) $qty;
            if (! $unitId || ! isset($val[$unitId])) {
                return $qty;
            }
            $factor = (float) ($val[$unitId] ?: 1);
            if ($factor == 0.0) {
                return $qty;
            }

            return ($op[$unitId] ?? '*') === '/' ? $qty / $factor : $qty * $factor;
        };
    }

    /**
     * Σ cantidad (unidad base) por producto para un par detalle/cabecera en el
     * período y alcance dados. `$unitCol` null → cantidad ya en unidad base.
     *
     * @return array<int,float>
     */
    private function periodQty(string $detailTable, string $headerTable, string $fkCol, ?string $unitCol, array $productIds, array $warehouseIds, string $from, string $to, callable $factor): array
    {
        $rows = DB::table($detailTable.' as d')
            ->join($headerTable.' as h', 'h.id', '=', 'd.'.$fkCol)
            ->whereNull('h.deleted_at')
            ->when($headerTable === 'sales', fn ($q) => $q->where('h.statut', 'completed'))
            ->when($headerTable === 'purchases', fn ($q) => $q->where('h.statut', 'received'))
            ->whereIn('d.product_id', $productIds)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->whereBetween('h.date', [$from, $to])
            ->get(array_filter(['d.product_id', 'd.quantity', $unitCol ? 'd.'.$unitCol : null]));

        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $qty = $unitCol ? $factor($r->quantity, $r->{$unitCol}) : (float) $r->quantity;
            $out[$pid] = ($out[$pid] ?? 0.0) + $qty;
        }

        return $out;
    }

    /** @return array{0: array<int,float>, 1: array<int,float>} [add, sub] */
    private function periodAdjustments(array $productIds, array $warehouseIds, string $from, string $to): array
    {
        $rows = DB::table('adjustment_details as d')
            ->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->whereNull('h.deleted_at')
            ->whereIn('d.product_id', $productIds)
            ->whereIn('h.warehouse_id', $warehouseIds)
            ->whereBetween('h.date', [$from, $to])
            ->get(['d.product_id', 'd.quantity', 'd.type']);

        $add = [];
        $sub = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            if (strtolower((string) $r->type) === 'add') {
                $add[$pid] = ($add[$pid] ?? 0.0) + (float) $r->quantity;
            } else {
                $sub[$pid] = ($sub[$pid] ?? 0.0) + (float) $r->quantity;
            }
        }

        return [$add, $sub];
    }

    /** @return array{0: array<int,float>, 1: array<int,float>} [in, out] */
    private function periodTransfers(array $productIds, array $warehouseIds, string $from, string $to, callable $factor): array
    {
        $rows = DB::table('transfer_details as d')
            ->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->whereNull('h.deleted_at')
            ->whereIn('d.product_id', $productIds)
            ->whereBetween('h.date', [$from, $to])
            ->where(fn ($q) => $q->whereIn('h.from_warehouse_id', $warehouseIds)->orWhereIn('h.to_warehouse_id', $warehouseIds))
            ->get(['d.product_id', 'd.quantity', 'd.purchase_unit_id', 'h.from_warehouse_id', 'h.to_warehouse_id']);

        $in = [];
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $qty = $factor($r->quantity, $r->purchase_unit_id);
            if (in_array((int) $r->from_warehouse_id, $warehouseIds, true)) {
                $out[$pid] = ($out[$pid] ?? 0.0) + $qty;
            }
            if (in_array((int) $r->to_warehouse_id, $warehouseIds, true)) {
                $in[$pid] = ($in[$pid] ?? 0.0) + $qty;
            }
        }

        return [$in, $out];
    }

    /**
     * WAC de compras por producto: Σ(cost*qty) / Σ(qty) sobre TODA la historia
     * de compras. `sin_historial` cuando el producto no tiene compras.
     *
     * @return array<int,array{0: float, 1: string}>
     */
    private function purchaseWac(array $productIds): array
    {
        $rows = DB::table('purchase_details as d')
            ->join('purchases as h', 'h.id', '=', 'd.purchase_id')
            ->whereNull('h.deleted_at')
            ->where('h.statut', 'received')
            ->whereIn('d.product_id', $productIds)
            ->selectRaw('d.product_id, SUM(d.cost * d.quantity) as val, SUM(d.quantity) as qty')
            ->groupBy('d.product_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            if ($qty > 0.0005) {
                $out[(int) $r->product_id] = [(float) $r->val / $qty, 'wac'];
            }
        }

        return $out;
    }

    private function emptyResult(string $from, string $to, int $periodDays): array
    {
        return [
            'rows' => [],
            'totalRows' => 0,
            'meta' => ['from' => $from, 'to' => $to, 'period_days' => $periodDays],
        ];
    }
}
