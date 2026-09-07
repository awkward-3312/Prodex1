<?php

namespace App\Services\Reports;

use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de inventario — reporte OPERATIVO por producto (sólo unidades).
 *
 *   rotación = unidades vendidas netas del período / inventario promedio (unid.)
 *   inventario promedio = (stock_inicial + stock_final) / 2
 *   stock_final   = existencia actual real ({@see InventoryReadService})
 *   stock_inicial = stock_final − (Σ entradas − Σ salidas del período),
 *                   reconstruido de los DOCUMENTOS reales del período
 *   días de inventario = días del período / rotación   (sólo si rotación > 0)
 *
 * NO se ofrece rotación financiera: PRODEX no conserva COGS histórico por
 * venta/ajuste/daño y el WAC de compras no puede acotarse de forma fiable al
 * período/sucursal, así que una cifra financiera aquí sería contablemente
 * ambigua. Para análisis de costo, usar los reportes contables canónicos.
 *
 * ALCANCE branch-first / legacy-fallback: ver {@see InventoryReportScope}.
 * Ventas / dev. de venta → `branch_id`; compras / dev. compra / ajustes / daños
 * / traslados → `inventory_location_id`; `warehouse_id` sólo como fallback legacy.
 *
 * CASOS SIN DATO → null (UI: "N/A"); nunca infinito, 0 engañoso ni fabricado:
 *   · inventario promedio = 0
 *   · sin ventas en el período
 *   · stock_inicial reconstruido < 0 (historial documental insuficiente)
 */
class InventoryTurnoverReportService
{
    public const FAST_MAX_DAYS = 30;

    public const MEDIUM_MAX_DAYS = 90;

    /** Cota de productos evaluados por corrida (orden por métrica derivada). */
    public const MAX_PRODUCTS = 5000;

    public function __construct(private InventoryReadService $inventoryRead)
    {
    }

    /**
     * @param  array  $filters  user (App\Models\User), branch_id (int|0),
     *                           warehouse_id (int|0), from, to, category_id,
     *                           search, page, limit, sort_field, sort_dir
     */
    public function build(array $filters): array
    {
        /** @var User $user */
        $user = $filters['user'];
        $scope = new InventoryReportScope($user, (int) ($filters['branch_id'] ?? 0) ?: null, (int) ($filters['warehouse_id'] ?? 0) ?: null);

        $from = $filters['from'] ?? Carbon::now()->subDays(90)->toDateString();
        $to = $filters['to'] ?? Carbon::now()->toDateString();
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = (int) ($filters['limit'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $sortField = $filters['sort_field'] ?? 'turnover';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $periodDays = (int) max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);

        if ($scope->isDenied()) {
            return $this->emptyResult($from, $to, $periodDays);
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

        $products = $productsQuery
            ->orderBy('p.name')
            ->limit(self::MAX_PRODUCTS)
            ->get(['p.id', 'p.code', 'p.name', 'p.category_id', 'c.name as category_name']);

        $productIds = $products->pluck('id')->map('intval')->all();
        if (! $productIds) {
            return $this->emptyResult($from, $to, $periodDays);
        }

        // --- Existencia actual real (final) por producto ---
        $stockByProduct = [];
        foreach ($this->inventoryRead->totalsByProductVariant($productIds, $scope->stockWarehouseIds()) as $key => $qty) {
            [$pid] = explode(':', $key);
            $stockByProduct[(int) $pid] = ($stockByProduct[(int) $pid] ?? 0.0) + (float) $qty;
        }

        // --- Movimientos del período (branch-first) ---
        $factor = $this->unitFactorResolver();

        $salesUnits = $this->periodQtyBranch('sale_details', 'sales', 'sale_id', 'sale_unit_id', 'completed', $productIds, $scope, $from, $to, $factor);
        $saleReturnUnits = $this->periodQtyBranch('sale_return_details', 'sale_returns', 'sale_return_id', 'sale_unit_id', null, $productIds, $scope, $from, $to, $factor);
        $purchaseUnits = $this->periodQtyLocation('purchase_details', 'purchases', 'purchase_id', 'purchase_unit_id', 'received', $productIds, $scope, $from, $to, $factor);
        $purchaseReturnUnits = $this->periodQtyLocation('purchase_return_details', 'purchase_returns', 'purchase_return_id', 'purchase_unit_id', null, $productIds, $scope, $from, $to, $factor);
        $damageUnits = $this->periodQtyLocation('damage_details', 'damages', 'damage_id', null, null, $productIds, $scope, $from, $to, $factor);
        [$adjAdd, $adjSub] = $this->periodAdjustments($productIds, $scope, $from, $to);
        [$transferIn, $transferOut] = $this->periodTransfers($productIds, $scope, $from, $to, $factor);

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
            ];
        }

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
                'branch_ids' => $scope->branchIds(),
                'has_modern_locations' => $scope->hasModernLocations(),
                'formula' => 'rotación = unidades vendidas netas / inventario promedio (unidades). inventario promedio = (stock_inicial + stock_final) / 2. días de inventario = días del período / rotación.',
                'thresholds' => ['alta_max_dias' => self::FAST_MAX_DAYS, 'media_max_dias' => self::MEDIUM_MAX_DAYS],
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

    /** Σ cantidad (unidad base) por producto — fuente con `inventory_location_id`. @return array<int,float> */
    private function periodQtyLocation(string $detail, string $header, string $fk, ?string $unitCol, ?string $statut, array $productIds, InventoryReportScope $scope, string $from, string $to, callable $factor): array
    {
        $q = DB::table($detail.' as d')->join($header.' as h', 'h.id', '=', 'd.'.$fk)
            ->whereNull('h.deleted_at')
            ->when($statut, fn ($qq) => $qq->where('h.statut', $statut))
            ->whereIn('d.product_id', $productIds)
            ->whereBetween('h.date', [$from, $to]);
        $scope->applyLocationScope($q, 'h');

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

    /** @return array{0: array<int,float>, 1: array<int,float>} [add, sub] */
    private function periodAdjustments(array $productIds, InventoryReportScope $scope, string $from, string $to): array
    {
        $q = DB::table('adjustment_details as d')->join('adjustments as h', 'h.id', '=', 'd.adjustment_id')
            ->whereNull('h.deleted_at')
            ->whereIn('d.product_id', $productIds)
            ->whereBetween('h.date', [$from, $to]);
        $scope->applyLocationScope($q, 'h');

        $add = [];
        $sub = [];
        foreach ($q->get(['d.product_id', 'd.quantity', 'd.type']) as $r) {
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
    private function periodTransfers(array $productIds, InventoryReportScope $scope, string $from, string $to, callable $factor): array
    {
        $rows = DB::table('transfer_details as d')->join('transfers as h', 'h.id', '=', 'd.transfer_id')
            ->whereNull('h.deleted_at')
            ->whereIn('d.product_id', $productIds)
            ->whereBetween('h.date', [$from, $to])
            ->get(['d.product_id', 'd.quantity', 'd.purchase_unit_id',
                'h.from_warehouse_id', 'h.to_warehouse_id', 'h.from_inventory_location_id', 'h.to_inventory_location_id']);

        $in = [];
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r->product_id;
            $qty = $factor($r->quantity, $r->purchase_unit_id);
            if ($scope->rowInScope(((int) ($r->from_inventory_location_id ?? 0)) ?: null, ((int) ($r->from_warehouse_id ?? 0)) ?: null)) {
                $out[$pid] = ($out[$pid] ?? 0.0) + $qty;
            }
            if ($scope->rowInScope(((int) ($r->to_inventory_location_id ?? 0)) ?: null, ((int) ($r->to_warehouse_id ?? 0)) ?: null)) {
                $in[$pid] = ($in[$pid] ?? 0.0) + $qty;
            }
        }

        return [$in, $out];
    }

    private function emptyResult(string $from, string $to, int $periodDays): array
    {
        return [
            'rows' => [],
            'totalRows' => 0,
            'meta' => ['from' => $from, 'to' => $to, 'period_days' => $periodDays, 'capped' => false, 'matched_products' => 0],
        ];
    }
}
