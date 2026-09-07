<?php

namespace App\Services\Reports;

use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryReadService;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de inventario — reporte OPERATIVO por producto (sólo unidades).
 *
 *   rotación = unidades vendidas netas del período / inventario promedio (unid.)
 *   inventario promedio = (stock_at_from + stock_at_to) / 2
 *
 * RECONSTRUCCIÓN HISTÓRICA (no snapshots inventados)
 * ------------------------------------------------------------------------------
 *   stock_now   = existencia ACTUAL real (InventoryReadService + location stocks)
 *   stock_at_to = stock_now − (Σ entradas − Σ salidas de movimientos DESPUÉS de `to`)
 *   stock_at_from = stock_at_to − (Σ entradas − Σ salidas dentro de [from, to])
 * Así el reporte de un mes histórico refleja el stock de ESE mes, no el de hoy.
 * Si `stock_at_from` o `stock_at_to` resultan negativos → el historial documental
 * no permite reconstruir con seguridad → N/A "historial insuficiente".
 *
 * Fuentes reales, mismo alcance branch/location: ventas, dev. de venta, compras,
 * dev. de compra, ajustes, daños, traslados (por pierna). Legacy + moderno.
 *
 * CASOS SIN DATO → null (UI "N/A"); nunca infinito, 0 engañoso ni fabricado:
 *   · inventario promedio ≈ 0
 *   · sin ventas netas en el período  (unitsSold <= 0)
 *   · devoluciones superan ventas      (unitsSold < 0)
 *   · historial documental insuficiente
 */
class InventoryTurnoverReportService
{
    public const FAST_MAX_DAYS = 30;

    public const MEDIUM_MAX_DAYS = 90;

    public const MAX_PRODUCTS = 5000;

    /** Campos de ordenación permitidos (whitelist estricta). */
    public const SORT_FIELDS = [
        'code', 'name', 'category', 'units_sold', 'stock_initial',
        'stock_final', 'avg_stock', 'turnover', 'days_inventory',
    ];

    public function __construct(private InventoryReadService $inventoryRead)
    {
    }

    public function build(array $filters): array
    {
        /** @var User $user */
        $user = $filters['user'];
        $scope = new InventoryReportScope(
            $user,
            (int) ($filters['branch_id'] ?? 0) ?: null,
            (int) ($filters['inventory_location_id'] ?? 0) ?: null,
            (int) ($filters['warehouse_id'] ?? 0) ?: null,
        );

        if ($scope->error() !== null) {
            return ['rows' => [], 'totalRows' => 0, 'meta' => ['capped' => false, 'matched_products' => 0], 'error' => $scope->error()];
        }

        $range = ReportDateRange::parse($filters['from'] ?? null, $filters['to'] ?? null);
        if (! $range->isValid()) {
            return $this->errorResult($range);
        }
        $from = $range->from;
        $to = $range->to;
        $periodDays = $range->days;

        $categoryId = (int) ($filters['category_id'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['limit'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $sortField = in_array($filters['sort_field'] ?? '', self::SORT_FIELDS, true) ? $filters['sort_field'] : 'turnover';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($scope->isDenied()) {
            return $this->emptyResult($range, $scope);
        }

        $productsQuery = DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->where('p.type', '!=', 'is_service')
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($search !== '', fn ($q) => $q->where(function ($qq) use ($search) {
                $qq->where('p.name', 'LIKE', "%{$search}%")->orWhere('p.code', 'LIKE', "%{$search}%");
            }))
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id');

        $matched = (clone $productsQuery)->count('p.id');
        $capped = $matched > self::MAX_PRODUCTS;
        $totalRows = $capped ? self::MAX_PRODUCTS : $matched;

        $products = $productsQuery->orderBy('p.name')->limit(self::MAX_PRODUCTS)
            ->get(['p.id', 'p.code', 'p.name', 'p.category_id', 'c.name as category_name']);

        $productIds = $products->pluck('id')->map('intval')->all();
        if (! $productIds) {
            return $this->emptyResult($range, $scope);
        }

        $factor = $this->unitFactorResolver();

        // Existencia ACTUAL real.
        $stockNow = $this->currentStock($productIds, $scope);

        // Neto (Σin − Σout) DESPUÉS de `to` y DENTRO de [from, to].
        $netAfter = $this->netUnitsByProduct($productIds, $scope, $factor, fn ($q, $a) => $q->where("{$a}.date", '>', $to));
        $netInPeriod = $this->netUnitsByProduct($productIds, $scope, $factor, fn ($q, $a) => $q->whereBetween("{$a}.date", [$from, $to]));

        // Ventas netas del período (venta − dev. de venta), para la rotación.
        $salesUnits = $this->periodQtyBranch('sale_details', 'sales', 'sale_id', 'sale_unit_id', 'completed', $productIds, $scope, $from, $to, $factor);
        $saleReturnUnits = $this->periodQtyBranch('sale_return_details', 'sale_returns', 'sale_return_id', 'sale_unit_id', null, $productIds, $scope, $from, $to, $factor);

        $rows = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $stockNowP = round($stockNow[$pid] ?? 0.0, 3);
            $unitsSold = round(($salesUnits[$pid] ?? 0.0) - ($saleReturnUnits[$pid] ?? 0.0), 3);

            $stockAtTo = round($stockNowP - ($netAfter[$pid] ?? 0.0), 3);
            $stockAtFrom = round($stockAtTo - ($netInPeriod[$pid] ?? 0.0), 3);

            // Historial insuficiente: la reconstrucción da un saldo imposible.
            $insufficient = $stockAtFrom < -0.0005 || $stockAtTo < -0.0005;
            $avgStock = $insufficient ? null : round(($stockAtFrom + $stockAtTo) / 2, 3);

            $turnover = null;
            $daysInventory = null;
            $classification = null;
            $reason = null;

            if ($insufficient) {
                $reason = 'historial insuficiente';
            } elseif ($unitsSold < -0.0005) {
                $reason = 'devoluciones superan ventas';
            } elseif ($unitsSold <= 0.0005) {
                $reason = 'sin ventas en el período';
            } elseif ($avgStock <= 0.0005) {
                $reason = 'inventario promedio 0';
            } else {
                $turnover = round($unitsSold / $avgStock, 4);
                if ($turnover > 0) {
                    $daysInventory = round($periodDays / $turnover, 1);
                    $classification = $daysInventory <= self::FAST_MAX_DAYS ? 'alta'
                        : ($daysInventory <= self::MEDIUM_MAX_DAYS ? 'media' : 'baja');
                }
            }

            $rows[] = [
                'product_id' => $pid,
                'code' => $p->code,
                'name' => $p->name,
                'category' => $p->category_name,
                'units_sold' => $unitsSold,
                'stock_initial' => $insufficient ? null : $stockAtFrom,
                'stock_final' => $insufficient ? null : $stockAtTo,
                'stock_now' => $stockNowP,
                'avg_stock' => $avgStock,
                'turnover' => $turnover,
                'days_inventory' => $daysInventory,
                'classification' => $classification,
                'reason' => $reason,
            ];
        }

        usort($rows, function ($a, $b) use ($sortField, $sortDir) {
            $va = $a[$sortField] ?? null;
            $vb = $b[$sortField] ?? null;
            if ($va === $vb) {
                return $a['product_id'] <=> $b['product_id'];
            }
            if ($va === null) {
                return 1;   // nulls siempre al final
            }
            if ($vb === null) {
                return -1;
            }
            $cmp = is_string($va) || is_string($vb)
                ? strcasecmp((string) $va, (string) $vb)
                : ($va <=> $vb);

            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        $paged = $perPage === -1 ? $rows : array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'rows' => array_values($paged),
            'totalRows' => $totalRows,
            'meta' => $this->meta($range, $scope, $capped, $matched),
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function currentStock(array $productIds, InventoryReportScope $scope): array
    {
        $out = [];
        // Selector explícito de ubicación → sólo lectura directa de
        // `inventory_location_stocks` de esa ubicación (ver stockLocationIdsForDirectRead).
        if (! $scope->isLocationScoped()) {
            $whIds = $scope->stockWarehouseIds();
            if ($whIds) {
                foreach ($this->inventoryRead->totalsByProductVariant($productIds, $whIds) as $key => $qty) {
                    [$pid] = explode(':', $key);
                    $out[(int) $pid] = ($out[(int) $pid] ?? 0.0) + (float) $qty;
                }
            }
        }
        $locIds = $scope->stockLocationIdsForDirectRead();
        if ($locIds) {
            foreach (DB::table('inventory_location_stocks')
                ->whereIn('product_id', $productIds)
                ->whereIn('inventory_location_id', $locIds)
                ->selectRaw('product_id, SUM(quantity) q')->groupBy('product_id')->get() as $r) {
                $out[(int) $r->product_id] = ($out[(int) $r->product_id] ?? 0.0) + (float) $r->q;
            }
        }

        return $out;
    }

    /**
     * Neto (Σ entradas − Σ salidas), en unidades base, por producto, para el
     * conjunto de documentos que cumplan `$dateFilter($query, $alias)`.
     *
     * @return array<int,float>  positivo = el stock aumentó
     */
    private function netUnitsByProduct(array $productIds, InventoryReportScope $scope, callable $factor, callable $dateFilter): array
    {
        $net = array_fill_keys($productIds, 0.0);
        $add = function (array $map, float $sign) use (&$net) {
            foreach ($map as $pid => $q) {
                $net[$pid] = ($net[$pid] ?? 0.0) + $sign * $q;
            }
        };

        $branchSrc = function (string $detail, string $header, string $fk, ?string $unitCol, ?string $statut) use ($productIds, $scope, $factor, $dateFilter) {
            $q = DB::table($detail.' as d')->join($header.' as h', 'h.id', '=', 'd.'.$fk)
                ->whereNull('h.deleted_at')
                ->when($statut, fn ($qq) => $qq->where('h.statut', $statut))
                ->whereIn('d.product_id', $productIds);
            $dateFilter($q, 'h');
            $scope->applyBranchScope($q, 'h');

            return $this->sumByProduct($q->get(array_values(array_filter(['d.product_id', 'd.quantity', $unitCol ? 'd.'.$unitCol : null]))), $unitCol, $factor);
        };
        $locSrc = function (string $detail, string $header, string $fk, ?string $unitCol, ?string $statut) use ($productIds, $scope, $factor, $dateFilter) {
            $q = DB::table($detail.' as d')->join($header.' as h', 'h.id', '=', 'd.'.$fk)
                ->whereNull('h.deleted_at')
                ->when($statut, fn ($qq) => $qq->where('h.statut', $statut))
                ->whereIn('d.product_id', $productIds);
            $dateFilter($q, 'h');
            $scope->applyLocationScope($q, 'h');

            return $this->sumByProduct($q->get(array_values(array_filter(['d.product_id', 'd.quantity', $unitCol ? 'd.'.$unitCol : null]))), $unitCol, $factor);
        };

        $add($locSrc('purchase_details', 'purchases', 'purchase_id', 'purchase_unit_id', 'received'), +1);   // compra → +
        $add($branchSrc('sale_return_details', 'sale_returns', 'sale_return_id', 'sale_unit_id', null), +1);  // dev. venta → +
        $add($branchSrc('sale_details', 'sales', 'sale_id', 'sale_unit_id', 'completed'), -1);                // venta → −
        $add($locSrc('purchase_return_details', 'purchase_returns', 'purchase_return_id', 'purchase_unit_id', null), -1); // dev. compra → −
        $add($locSrc('damage_details', 'damages', 'damage_id', null, null), -1);                             // daño → −

        // Ajustes: +add / −sub.
        $aq = DB::table('adjustment_details as d')->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->whereNull('h.deleted_at')->whereIn('d.product_id', $productIds);
        $dateFilter($aq, 'h');
        $scope->applyLocationScope($aq, 'h');
        foreach ($aq->get(['d.product_id', 'd.quantity', 'd.type']) as $r) {
            $pid = (int) $r->product_id;
            $net[$pid] = ($net[$pid] ?? 0.0) + (strtolower((string) $r->type) === 'add' ? 1 : -1) * (float) $r->quantity;
        }

        // Traslados: por pierna (salida −, entrada +).
        $tq = DB::table('transfer_details as d')->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->whereNull('h.deleted_at')->whereIn('d.product_id', $productIds);
        $dateFilter($tq, 'h');
        foreach ($tq->get(['d.product_id', 'd.quantity', 'd.purchase_unit_id',
            'h.from_warehouse_id', 'h.to_warehouse_id', 'h.from_inventory_location_id', 'h.to_inventory_location_id']) as $r) {
            $pid = (int) $r->product_id;
            $qty = $factor($r->quantity, $r->purchase_unit_id);
            if ($scope->rowInScope(((int) ($r->from_inventory_location_id ?? 0)) ?: null, ((int) ($r->from_warehouse_id ?? 0)) ?: null)) {
                $net[$pid] = ($net[$pid] ?? 0.0) - $qty;
            }
            if ($scope->rowInScope(((int) ($r->to_inventory_location_id ?? 0)) ?: null, ((int) ($r->to_warehouse_id ?? 0)) ?: null)) {
                $net[$pid] = ($net[$pid] ?? 0.0) + $qty;
            }
        }

        return $net;
    }

    /** Σ cantidad (unidad base) por producto — fuente con `branch_id`. @return array<int,float> */
    private function periodQtyBranch(string $detail, string $header, string $fk, ?string $unitCol, ?string $statut, array $productIds, InventoryReportScope $scope, string $from, string $to, callable $factor): array
    {
        $q = DB::table($detail.' as d')->join($header.' as h', 'h.id', '=', 'd.'.$fk)
            ->whereNull('h.deleted_at')
            ->when($statut, fn ($qq) => $qq->where('h.statut', $statut))
            ->whereIn('d.product_id', $productIds)
            ->whereBetween('h.date', [$from, $to]);
        $scope->applyBranchScope($q, 'h');

        return $this->sumByProduct($q->get(array_values(array_filter(['d.product_id', 'd.quantity', $unitCol ? 'd.'.$unitCol : null]))), $unitCol, $factor);
    }

    private function sumByProduct($rows, ?string $unitCol, callable $factor): array
    {
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $qty = $unitCol ? $factor($r->quantity, $r->{$unitCol}) : (float) $r->quantity;
            $out[$pid] = ($out[$pid] ?? 0.0) + $qty;
        }

        return $out;
    }

    private function unitFactorResolver(): callable
    {
        $units = [];
        foreach (Unit::get(['id', 'operator', 'operator_value']) as $u) {
            $units[(int) $u->id] = [$u->operator, (float) $u->operator_value];
        }

        return function ($qty, $unitId) use ($units) {
            $qty = (float) $qty;
            if (! $unitId || ! isset($units[$unitId])) {
                return $qty;
            }
            [$op, $f] = $units[$unitId];

            return $f ? ($op === '/' ? $qty / $f : $qty * $f) : $qty;
        };
    }

    private function meta(ReportDateRange $range, InventoryReportScope $scope, bool $capped, int $matched): array
    {
        return [
            'from' => $range->from,
            'to' => $range->to,
            'period_days' => $range->days,
            'to_clamped' => $range->toClamped,
            'capped' => $capped,
            'max_products' => self::MAX_PRODUCTS,
            'matched_products' => $matched,
            'branch_ids' => $scope->branchIds(),
            'has_modern_locations' => $scope->hasModernLocations(),
            'formula' => 'rotación = unidades vendidas netas / inventario promedio (unidades). inventario promedio = (stock al inicio + stock al fin del período) / 2, ambos reconstruidos desde los movimientos reales. días de inventario = días del período / rotación.',
            'thresholds' => ['alta_max_dias' => self::FAST_MAX_DAYS, 'media_max_dias' => self::MEDIUM_MAX_DAYS],
        ];
    }

    private function emptyResult(ReportDateRange $range, InventoryReportScope $scope): array
    {
        return ['rows' => [], 'totalRows' => 0, 'meta' => $this->meta($range, $scope, false, 0)];
    }

    private function errorResult(ReportDateRange $range): array
    {
        return [
            'rows' => [], 'totalRows' => 0,
            'meta' => ['from' => $range->from, 'to' => $range->to, 'period_days' => $range->days, 'capped' => false, 'matched_products' => 0],
            'error' => $range->error,
        ];
    }
}
