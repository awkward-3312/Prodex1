<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\PaymentPurchase;
use App\Models\PaymentPurchaseReturns;
use App\Models\PaymentSale;
use App\Models\PaymentSaleReturns;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\DashboardScopeService;
use App\Support\DashboardScope;
use App\Traits\CalculatesCogsAndAverageCost;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    use CalculatesCogsAndAverageCost;
    // ----------------- dashboard_data -----------------------\\

    /**
     * Alcance analítico del Panel — dos dimensiones independientes resueltas en
     * un único lugar (DashboardScopeService):
     *   A. ORGANIZATION SCOPE  — allowedBranchIds / selectedBranchId /
     *      effectiveBranchIds / effectiveWarehouseIds / hasBranch.
     *   B. METRIC VISIBILITY   — canSeeTeam / canSeeFinancialManagement /
     *      personalUserId.
     * El controlador NO vuelve a preguntar por rol, record_view ni manager.
     * `OperationalDashboardController` reutiliza este mismo resolver.
     */
    protected function resolveDashboardScope(Request $request): DashboardScope
    {
        return app(DashboardScopeService::class)->resolve(
            $request->user('api') ?? auth()->user(),
            (int) $request->input('branch_id', 0)
        );
    }

    public function dashboard_data(Request $request)
    {
        $scope = $this->resolveDashboardScope($request);

        // Selector de sucursal: autoridad = alcance resuelto.
        $branches = [];
        if ($scope->allowedBranchIds !== []) {
            $branches = Branch::whereNull('deleted_at')
                ->whereIn('id', $scope->allowedBranchIds)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        // Usuario operativo sin sucursal resoluble => cero datos + estado suave.
        // NUNCA se hace fallback a "todos los almacenes permitidos".
        if (! $scope->hasBranch) {
            return response()->json([
                'branches' => [],
                'active_branch_id' => 0,
                'scope' => $scope->toClientArray(),
                'warehouses' => [],
            ]);
        }

        // Opciones de "Almacén": sólo las del alcance efectivo. El warehouse_id
        // solicitado por el cliente se valida contra ese alcance.
        $warehouses = Warehouse::where('deleted_at', '=', null)
            ->whereIn('id', $scope->warehouseFilterIds())
            ->get(['id', 'name']);

        $warehouse_id = (int) $request->input('warehouse_id', 0);
        if ($warehouse_id !== 0 && ! in_array($warehouse_id, $scope->effectiveWarehouseIds, true)) {
            $warehouse_id = 0;
        }

        $payload = [
            'branches' => $branches,
            'active_branch_id' => $scope->selectedBranchId,
            'scope' => $scope->toClientArray(),
            'warehouses' => $warehouses,
        ];

        // --- BRANCH_OPERATIONAL: agregado de la sucursal en alcance, visible
        //     para TODO usuario del alcance (incluido el cajero). Sin filtro por
        //     usuario: es el total de la sucursal, no la actividad de nadie.
        $payload['sales'] = $this->SalesChart($scope, $warehouse_id, $request->from, $request->to);
        $payload['product_report'] = $this->Top_Products_Year($scope, $warehouse_id);
        $payload['sales_by_payment'] = $this->SalesByPayment($scope, $warehouse_id, $request->from, $request->to);
        $payload['report_dashboard'] = $this->report_dashboard($request, $scope, $warehouse_id);

        // --- PERSONAL: SIEMPRE el usuario autenticado. "Mis ventas" nunca revela
        //     la actividad individual de otro cajero.
        $payload['my_sales'] = $this->MySales($scope, $warehouse_id, $request->from, $request->to);

        // --- TEAM: sólo owner o gerente de la(s) sucursal(es) en alcance. El
        //     backend NO incluye la clave para el cajero (no se oculta en Vue).
        if ($scope->canSeeTeam) {
            $payload['sales_by_cashier'] = $this->SalesByCashier($scope, $warehouse_id, $request->from, $request->to);
        }

        // --- FINANCIAL_MANAGEMENT: nunca para el cajero/operativo. El backend
        //     omite estas claves por completo según contrato.
        if ($scope->canSeeFinancialManagement) {
            $payload['purchases'] = $this->PurchasesChart($scope, $warehouse_id, $request->from, $request->to);
            $payload['payments'] = $this->Payment_chart($scope, $warehouse_id, $request->from, $request->to);
            $payload['customers'] = $this->TopCustomers($scope, $warehouse_id);
            $payload['stock_value'] = $this->StockValue($scope, $warehouse_id);
            $payload['financial'] = $this->FinancialReport($request, $scope, $warehouse_id);
        }

        return response()->json($payload);
    }

    /**
     * Resuelve la lista de `warehouse_id` para un `whereIn`, respetando el
     * selector interno de almacén del Panel dentro del alcance efectivo.
     *
     * @return list<int>
     */
    private function scopedWarehouseIds(DashboardScope $scope, int $warehouseId): array
    {
        return $scope->resolveWarehouseFilter($warehouseId);
    }

    // ----------------- Sales Chart js -----------------------\\

    public function SalesChart(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        // BRANCH_OPERATIONAL: serie de ventas de la sucursal en alcance, sin
        // filtro por usuario (es el total de la sucursal, no la actividad de
        // nadie en particular).
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        // Determine date window: either custom [from, to] or default last 7 days
        if (! empty($from) && ! empty($to)) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
        } else {
            $end = Carbon::today()->endOfDay();
            $start = $end->copy()->subDays(6)->startOfDay();
        }

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->format('Y-m-d');
            $dates->put($date, 0);
            $cursor->addDay();
        }

        // Get the sales counts within the same window used for the dashboard filter
        $sales = Sale::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('deleted_at', '=', null)
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(GrandTotal) AS count'),
            ])
            ->pluck('count', 'date');

        // Merge the two collections;
        $dates = $dates->merge($sales);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);

    }

    // ----------------- Purchases Chart -----------------------\\

    public function PurchasesChart(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        // FINANCIAL_MANAGEMENT: sólo llega aquí owner o gerente. Agregado de la
        // sucursal en alcance, sin filtro por usuario.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        // Determine date window: either custom [from, to] or default last 7 days
        if (! empty($from) && ! empty($to)) {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
        } else {
            $end = Carbon::today()->endOfDay();
            $start = $end->copy()->subDays(6)->startOfDay();
        }

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->format('Y-m-d');
            $dates->put($date, 0);
            $cursor->addDay();
        }

        // Get the purchases counts within the same window used for the dashboard filter
        $purchases = Purchase::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('deleted_at', '=', null)
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(GrandTotal) AS count'),
            ])
            ->pluck('count', 'date');

        // Merge the two collections;
        $dates = $dates->merge($purchases);

        $data = [];
        $days = [];
        foreach ($dates as $key => $value) {
            $data[] = $value;
            $days[] = $key;
        }

        return response()->json(['data' => $data, 'days' => $days]);

    }

    // -------------------- Get Top 5 Customers -------------\\

    public function TopCustomers(DashboardScope $scope, $warehouse_id)
    {
        // FINANCIAL_MANAGEMENT: métrica de gestión (clientes identificables).
        // Sólo llega aquí owner o gerente. Agregado de la sucursal, sin filtro
        // por usuario.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        $data = Sale::whereBetween('date', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->where('sales.deleted_at', '=', null)
            ->whereIn('sales.warehouse_id', $array_warehouses_id)

            ->join('clients', 'sales.client_id', '=', 'clients.id')
            ->select(DB::raw('clients.name'), DB::raw('count(*) as value'))
            ->groupBy('clients.name')
            ->orderBy('value', 'desc')
            ->take(5)
            ->get();

        return response()->json($data);
    }

    // -------------------- Get Top 5 Products This YEAR -------------\\

    public function Top_Products_Year(DashboardScope $scope, $warehouse_id)
    {
        // BRANCH_OPERATIONAL: top de productos de la sucursal en alcance, sin
        // filtro por usuario. Visible para todo usuario del alcance.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        $products = SaleDetail::join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereBetween('sale_details.date', [
                Carbon::now()->startOfYear(),
                Carbon::now()->endOfYear(),
            ])
            ->whereIn('sales.warehouse_id', $array_warehouses_id)
            ->select(
                DB::raw('products.name as name'),
                DB::raw('count(*) as value'),
            )
            ->groupBy('products.name')
            ->orderBy('value', 'desc')
            ->take(5)
            ->get();

        return response()->json($products);
    }

    // -------------------- General Report dashboard -------------\\

    public function report_dashboard($request, DashboardScope $scope, $warehouse_id)
    {
        // BRANCH_OPERATIONAL + PERSONAL/TEAM.
        //   - products / stock_alert / today_sales / today_invoices /
        //     return_sales  => agregado de la sucursal, sin filtro por usuario.
        //   - recent_sales  => PERSONAL para el operativo; equipo/sucursal si el
        //     usuario tiene autoridad de equipo (owner / gerente).
        // Las métricas FINANCIERAS viven en FinancialReport() y sólo se calculan
        // para owner / gerente.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        // top selling product this month
        $products = SaleDetail::join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereBetween('sale_details.date', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->whereIn('sales.warehouse_id', $array_warehouses_id)
            ->select(
                DB::raw('products.name as name'),
                DB::raw('count(*) as total_sales'),
                DB::raw('sum(total) as total'),
            )
            ->groupBy('products.name')
            ->orderBy('total_sales', 'desc')
            ->take(5)
            ->get();

        // Stock Alerts — MS7-B3: a location_primary warehouse's
        // product_warehouse row is stale; read those from
        // inventory_location_stocks instead, merged with the legacy list.
        $alertScopeWarehouseIds = $array_warehouses_id;
        $alertSplit = app(\App\Services\InventoryReadService::class)->splitWarehousesByMode($alertScopeWarehouseIds);

        $product_warehouse_data = collect();
        if (! empty($alertSplit['legacy'])) {
            $product_warehouse_data = product_warehouse::with('warehouse', 'product', 'productVariant')
                ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                ->where('manage_stock', true)
                ->whereRaw('qte <= stock_alert')
                ->where('product_warehouse.deleted_at', null)
                ->whereIn('product_warehouse.warehouse_id', $alertSplit['legacy'])
                ->take(5)->get();
        }

        $stock_alert = [];
        if ($product_warehouse_data->isNotEmpty()) {

            foreach ($product_warehouse_data as $product_warehouse) {
                if ($product_warehouse->qte <= $product_warehouse['product']->stock_alert) {
                    if ($product_warehouse->product_variant_id !== null) {
                        $item['code'] = $product_warehouse['productVariant']->name.'-'.$product_warehouse['product']->code;
                    } else {
                        $item['code'] = $product_warehouse['product']->code;
                    }
                    $item['quantity'] = $product_warehouse->qte;
                    $item['name'] = $product_warehouse['product']->name;
                    $item['warehouse'] = $product_warehouse['warehouse']->name;
                    $item['stock_alert'] = $product_warehouse['product']->stock_alert;
                    $stock_alert[] = $item;
                }
            }

        }

        if (! empty($alertSplit['locationByWarehouse']) && count($stock_alert) < 5) {
            $locationIds = array_values(array_unique($alertSplit['locationByWarehouse']));
            $warehouseIdByLocation = array_flip($alertSplit['locationByWarehouse']);
            $nativeAlertRows = DB::table('inventory_location_stocks')
                ->join('products', 'products.id', '=', 'inventory_location_stocks.product_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_location_stocks.product_variant_id')
                ->where('inventory_location_stocks.manage_stock', true)
                ->whereColumn('inventory_location_stocks.quantity', '<=', 'products.stock_alert')
                ->whereIn('inventory_location_stocks.inventory_location_id', $locationIds)
                ->select(
                    'inventory_location_stocks.quantity',
                    'inventory_location_stocks.inventory_location_id',
                    'products.code as product_code',
                    'products.name as product_name',
                    'products.stock_alert',
                    'product_variants.name as variant_name'
                )
                ->take(5 - count($stock_alert))
                ->get();

            foreach ($nativeAlertRows as $row) {
                $warehouseId = $warehouseIdByLocation[(int) $row->inventory_location_id] ?? null;
                $warehouseName = $warehouseId ? optional(Warehouse::find($warehouseId))->name : null;
                $stock_alert[] = [
                    'code' => $row->variant_name ? ($row->variant_name.'-'.$row->product_code) : $row->product_code,
                    'quantity' => $row->quantity,
                    'name' => $row->product_name,
                    'warehouse' => $warehouseName,
                    'stock_alert' => $row->stock_alert,
                ];
            }
        }

        // ---------------- BRANCH_OPERATIONAL aggregates -------------
        // Total de ventas de la sucursal en alcance (no la actividad de nadie).

        $data = [];

        $salesAgg = Sale::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->select(DB::raw('COALESCE(SUM(GrandTotal),0) AS total'))
            ->first();

        $data['today_sales'] = (float) ($salesAgg->total ?? 0);

        $data['return_sales'] = (float) SaleReturn::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->sum('GrandTotal');

        $data['today_invoices'] = Sale::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->count();

        // ---------------- recent sales (PERSONAL / TEAM) -----------
        // El operativo ve SOLO sus ventas recientes; el owner/gerente ve las de
        // la sucursal en alcance (equipo).
        $recentSalesQuery = Sale::with('details', 'client', 'facture', 'warehouse')
            ->where('deleted_at', '=', null)
            ->whereIn('warehouse_id', $array_warehouses_id);

        if (! $scope->canSeeTeam) {
            $recentSalesQuery->where('user_id', $scope->personalUserId);
        }

        $Sales = $recentSalesQuery->orderBy('id', 'desc')->take(5)->get();

        $recent_sales = [];
        foreach ($Sales as $Sale) {
            $recent_sales[] = [
                'Ref' => $Sale['Ref'],
                'statut' => $Sale['statut'],
                'client_name' => $Sale['client']['name'] ?? null,
                'warehouse_name' => $Sale['warehouse']['name'] ?? null,
                'GrandTotal' => $Sale['GrandTotal'],
                'paid_amount' => $Sale['paid_amount'],
                'due' => $Sale['GrandTotal'] - $Sale['paid_amount'],
                'payment_status' => $Sale['payment_statut'],
            ];
        }

        return response()->json([
            'products' => $products,
            'stock_alert' => $stock_alert,
            'report' => $data,
            // clave nueva; se mantiene `last_sales` como alias para no romper a
            // ningún consumidor que aún lo lea.
            'recent_sales' => $recent_sales,
            'last_sales' => $recent_sales,
        ]);
    }

    // ----------------- Mis ventas (PERSONAL) -----------------------\\

    /**
     * PERSONAL — actividad de ventas del usuario autenticado dentro del rango y
     * la sucursal efectiva del Panel. Nunca revela la actividad de otro cajero.
     * No se mezcla con `cash_register_id`: una métrica estricta de turno/caja
     * abierta sería una métrica separada en el futuro.
     */
    public function MySales(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        [$start, $end] = $this->resolveDateWindow($from, $to);

        $agg = Sale::where('deleted_at', '=', null)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->where('user_id', $scope->personalUserId)
            ->select(
                DB::raw('COALESCE(SUM(GrandTotal),0) AS total'),
                DB::raw('COALESCE(SUM(paid_amount),0) AS paid'),
                DB::raw('COUNT(*) AS invoices')
            )
            ->first();

        $total = (float) ($agg->total ?? 0);
        $paid = (float) ($agg->paid ?? 0);

        return [
            'total' => $total,
            'paid' => $paid,
            'due' => max(0, $total - $paid),
            'invoices' => (int) ($agg->invoices ?? 0),
        ];
    }

    // ----------------- Ventas por cajero (TEAM) -------------------\\

    /**
     * TEAM — desglose por cajero de la sucursal en alcance. El controlador SÓLO
     * llama a este método cuando $scope->canSeeTeam es verdadero (owner o
     * gerente de la sucursal). El cajero nunca recibe esta clave.
     */
    public function SalesByCashier(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        [$start, $end] = $this->resolveDateWindow($from, $to);

        return Sale::query()
            ->where('sales.deleted_at', '=', null)
            ->whereBetween('sales.date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('sales.warehouse_id', $array_warehouses_id)
            ->leftJoin('users', 'sales.user_id', '=', 'users.id')
            ->groupBy('sales.user_id', 'users.firstname', 'users.lastname')
            ->select(
                'sales.user_id',
                DB::raw("TRIM(CONCAT(COALESCE(users.firstname,''),' ',COALESCE(users.lastname,''))) as cashier_name"),
                DB::raw('COUNT(*) as invoices'),
                DB::raw('COALESCE(SUM(sales.GrandTotal),0) as total_sales'),
                DB::raw('COALESCE(SUM(sales.paid_amount),0) as paid_amount')
            )
            ->orderByDesc('total_sales')
            ->get()
            ->map(function ($r) {
                $total = (float) $r->total_sales;
                $paid = (float) $r->paid_amount;

                return [
                    'user_id' => (int) $r->user_id,
                    'cashier_name' => trim((string) $r->cashier_name) !== '' ? $r->cashier_name : '—',
                    'invoices' => (int) $r->invoices,
                    'total_sales' => $total,
                    'paid_amount' => $paid,
                    'due' => max(0, $total - $paid),
                ];
            })
            ->values();
    }

    // ----------------- Reporte financiero (FINANCIAL_MANAGEMENT) --\\

    /**
     * FINANCIAL_MANAGEMENT — por cobrar, compras, por pagar, devoluciones de
     * compra, utilidad (ProfitNet FIFO) y servicio técnico. El controlador SÓLO
     * llama a este método cuando $scope->canSeeFinancialManagement es verdadero
     * (owner o gerente). El cajero nunca recibe estas cifras.
     *
     * Todos los sumandos de la utilidad se calculan sobre el MISMO alcance
     * (sucursal efectiva + rango), corrigiendo el híbrido anterior que mezclaba
     * ventas propias con COGS/servicio de toda la sucursal.
     */
    public function FinancialReport($request, DashboardScope $scope, $warehouse_id)
    {
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        $data = [];

        $salesAgg = Sale::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->select(
                DB::raw('COALESCE(SUM(GrandTotal),0) AS total'),
                DB::raw('COALESCE(SUM(paid_amount),0) AS paid')
            )
            ->first();

        $today_sales_total = (float) ($salesAgg->total ?? 0);
        $today_sales_paid = (float) ($salesAgg->paid ?? 0);
        $data['sales_due'] = $today_sales_total - $today_sales_paid;

        $completedSalesTotal = (float) Sale::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->where('statut', 'completed')
            ->sum('GrandTotal');

        $purchasesAgg = Purchase::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->select(
                DB::raw('COALESCE(SUM(GrandTotal),0) AS total'),
                DB::raw('COALESCE(SUM(paid_amount),0) AS paid')
            )
            ->first();

        $today_purchases_total = (float) ($purchasesAgg->total ?? 0);
        $today_purchases_paid = (float) ($purchasesAgg->paid ?? 0);
        $data['today_purchases'] = $today_purchases_total;
        $data['purchase_due'] = $today_purchases_total - $today_purchases_paid;

        $return_purchases_total = PurchaseReturn::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->sum('GrandTotal');

        $data['return_purchases'] = number_format($return_purchases_total, \App\utils\helpers::price_decimals(), '.', ',');

        // ----- utilidad (ProfitNet FIFO), todo sobre el mismo alcance ----------
        $expenses_total = (float) Expense::where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->sum('amount');

        $cogsPack = $this->calcCogsAndAvgCostFast($request->from, $request->to, (int) $warehouse_id, $array_warehouses_id);
        $cogsFIFO = (float) ($cogsPack['fifo'] ?? 0.0);

        $service = $this->serviceJobTotals($request->from, $request->to, (int) $warehouse_id, $array_warehouses_id);

        $data['today_profit'] = $completedSalesTotal - $cogsFIFO - $expenses_total + $service['profit'];
        $data['today_service_revenue'] = (float) $service['revenue'];
        $data['today_service_parts_cost'] = (float) $service['parts_cost'];
        $data['today_service_profit'] = (float) $service['profit'];
        $data['today_service_jobs'] = (int) $service['count'];

        return $data;
    }

    /**
     * Ventana de fechas del Panel: [from, to] explícitos o los últimos 7 días.
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private function resolveDateWindow($from, $to): array
    {
        if (! empty($from) && ! empty($to)) {
            return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        }

        $end = Carbon::today()->endOfDay();

        return [$end->copy()->subDays(6)->startOfDay(), $end];
    }

    // ----------------- Payment Chart js -----------------------\\

    public function Payment_chart(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        // FINANCIAL_MANAGEMENT: sólo llega aquí owner o gerente. Series de pagos
        // recibidos/enviados de la sucursal en alcance, sin filtro por usuario.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        [$start, $end] = $this->resolveDateWindow($from, $to);

        // Build an array of the dates we want to show, oldest first
        $dates = collect();
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->format('Y-m-d');
            $dates->put($date, 0);
            $cursor->addDay();
        }

        $inScopeSale = fn ($q) => $q->whereIn('warehouse_id', $array_warehouses_id);

        // Get the sales counts
        $Payment_Sale = PaymentSale::with('sale')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('sale', $inScopeSale)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Sale_Returns = PaymentSaleReturns::with('SaleReturn')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('SaleReturn', $inScopeSale)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Purchases = PaymentPurchase::with('purchase')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('purchase', $inScopeSale)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Purchase_Returns = PaymentPurchaseReturns::with('PurchaseReturn')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('PurchaseReturn', $inScopeSale)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(montant) AS count'),
            ])
            ->pluck('count', 'date');

        $Payment_Expense = Expense::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('warehouse_id', $array_warehouses_id)
            ->groupBy(DB::raw("DATE_FORMAT(date,'%Y-%m-%d')"))
            ->orderBy('date', 'asc')
            ->get([
                DB::raw(DB::raw("DATE_FORMAT(date,'%Y-%m-%d') as date")),
                DB::raw('SUM(amount) AS count'),
            ])
            ->pluck('count', 'date');

        $paymen_recieved = $this->array_merge_numeric_values($Payment_Sale, $Payment_Purchase_Returns);
        $payment_sent = $this->array_merge_numeric_values($Payment_Purchases, $Payment_Sale_Returns, $Payment_Expense);

        $dates_recieved = $dates->merge($paymen_recieved);
        $dates_sent = $dates->merge($payment_sent);

        $data_recieved = [];
        $data_sent = [];
        $days = [];
        foreach ($dates_recieved as $key => $value) {
            $data_recieved[] = $value;
            $days[] = $key;
        }

        foreach ($dates_sent as $key => $value) {
            $data_sent[] = $value;
        }

        return response()->json([
            'payment_sent' => $data_sent,
            'payment_received' => $data_recieved,
            'days' => $days,
        ]);

    }

    // ----------------- array merge -----------------------\\

    public function array_merge_numeric_values()
    {
        $arrays = func_get_args();
        $merged = [];
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (! is_numeric($value)) {
                    continue;
                }
                if (! isset($merged[$key])) {
                    $merged[$key] = $value;
                } else {
                    $merged[$key] += $value;
                }
            }
        }

        return $merged;
    }

    // ----------------- Sales by Payment -----------------------\\
    //
    // Return ALL payment methods with their sales amounts and percentages
    // for the selected date range + warehouse filter.
    public function SalesByPayment(DashboardScope $scope, $warehouse_id, $from = null, $to = null)
    {
        // BRANCH_OPERATIONAL: desglose por método de pago de la sucursal en
        // alcance, sin filtro por usuario. Visible para todo usuario del alcance.
        $array_warehouses_id = $this->scopedWarehouseIds($scope, (int) $warehouse_id);

        [$start, $end] = $this->resolveDateWindow($from, $to);

        // Fetch all active payment methods (we will show every one of them)
        $paymentMethods = \App\Models\PaymentMethod::where('deleted_at', '=', null)
            ->get(['id', 'name']);

        // Base result keyed by payment_method_id so that every method is present,
        // even if it has 0 sales in the selected period.
        $result = [];
        foreach ($paymentMethods as $pm) {
            $result[$pm->id] = [
                'name'   => $pm->name,
                'amount' => 0.0,
            ];
        }

        // Get sales payments grouped by payment method
        $payments = PaymentSale::with('sale', 'payment_method')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('sale', function ($q) use ($array_warehouses_id) {
                $q->whereIn('warehouse_id', $array_warehouses_id)
                  ->where('deleted_at', '=', null);
            })
            ->whereNotNull('payment_method_id')
            ->select(
                'payment_method_id',
                DB::raw('SUM(montant) as amount')
            )
            ->groupBy('payment_method_id')
            ->get();

        // Aggregate amounts per payment method id
        foreach ($payments as $payment) {
            $id = $payment->payment_method_id;
            if (isset($result[$id])) {
                $result[$id]['amount'] += (float) $payment->amount;
            }
        }

        // Calculate grand total to derive percentages
        $total = 0.0;
        foreach ($result as $entry) {
            $total += (float) $entry['amount'];
        }

        // Assign colors in a simple repeating palette so the existing
        // frontend CSS dot/progress classes continue to work.
        $colorPalette = ['orange', 'blue', 'green', 'grey'];
        $formattedResult = [];
        $index = 0;

        foreach ($result as $entry) {
            $amount = (float) $entry['amount'];
            $percentage = $total > 0 ? round(($amount / $total) * 100, 0) : 0;
            $color = $colorPalette[$index % count($colorPalette)];

            $formattedResult[] = [
                'name'       => $entry['name'],
                'amount'     => $amount,
                'percentage' => (int) $percentage,
                'color'      => $color,
            ];

            $index++;
        }

        return $formattedResult;
    }

    // ----------------- Stock Value -----------------------\\

    public function StockValue(DashboardScope $scope, $warehouse_id)
    {
        // FINANCIAL_MANAGEMENT: sólo llega aquí owner o gerente. Valorización de
        // inventario de la sucursal en alcance (el stock no tiene eje de usuario).
        $scopeWarehouseIds = $this->scopedWarehouseIds($scope, (int) $warehouse_id);
        // MS7-B3 — a location_primary warehouse's stock lives in
        // inventory_location_stocks, not the stale product_warehouse row.
        $split = app(\App\Services\InventoryReadService::class)->splitWarehousesByMode($scopeWarehouseIds);
        $legacyWarehouseIds = $split['legacy'];
        $nativeLocationIds = array_values(array_unique($split['locationByWarehouse']));

        // Build warehouse filter (legacy-only rows now)
        $warehouseFilter = function ($query) use ($legacyWarehouseIds) {
            return $query->whereIn('product_warehouse.warehouse_id', $legacyWarehouseIds);
        };

        $valueExprs = [
            'by_cost' => [
                'legacy' => 'product_warehouse.qte * COALESCE(product_variants.cost, 0)',
                'legacy_default' => 'product_warehouse.qte * COALESCE(products.cost, 0)',
                'native' => 'inventory_location_stocks.quantity * COALESCE(product_variants.cost, 0)',
                'native_default' => 'inventory_location_stocks.quantity * COALESCE(products.cost, 0)',
            ],
            'by_retail' => [
                'legacy' => 'product_warehouse.qte * COALESCE(product_variants.price, 0)',
                'legacy_default' => 'product_warehouse.qte * COALESCE(products.price, 0)',
                'native' => 'inventory_location_stocks.quantity * COALESCE(product_variants.price, 0)',
                'native_default' => 'inventory_location_stocks.quantity * COALESCE(products.price, 0)',
            ],
            'by_wholesale' => [
                'legacy' => 'product_warehouse.qte * COALESCE(product_variants.wholesale, product_variants.price, 0)',
                'legacy_default' => 'product_warehouse.qte * COALESCE(products.wholesale_price, products.price, 0)',
                'native' => 'inventory_location_stocks.quantity * COALESCE(product_variants.wholesale, product_variants.price, 0)',
                'native_default' => 'inventory_location_stocks.quantity * COALESCE(products.wholesale_price, products.price, 0)',
            ],
        ];

        $totals = ['by_cost' => 0.0, 'by_retail' => 0.0, 'by_wholesale' => 0.0];

        if (! empty($legacyWarehouseIds)) {
            foreach ($valueExprs as $key => $expr) {
                $row = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
                    ->leftJoin('product_variants', function ($join) {
                        $join->on('product_warehouse.product_variant_id', '=', 'product_variants.id')
                             ->where('products.is_variant', '=', 1);
                    })
                    ->where('product_warehouse.deleted_at', '=', null)
                    ->where('products.deleted_at', '=', null)
                    ->where('product_warehouse.qte', '>', 0)
                    ->where(fn ($query) => $warehouseFilter($query))
                    ->select(DB::raw("SUM(
                        CASE
                            WHEN products.is_variant = 1 AND product_variants.id IS NOT NULL
                            THEN {$expr['legacy']}
                            ELSE {$expr['legacy_default']}
                        END
                    ) as total_value"))
                    ->first();
                $totals[$key] += (float) ($row->total_value ?? 0);
            }
        }

        if (! empty($nativeLocationIds)) {
            foreach ($valueExprs as $key => $expr) {
                $row = DB::table('inventory_location_stocks')
                    ->join('products', 'products.id', '=', 'inventory_location_stocks.product_id')
                    ->leftJoin('product_variants', function ($join) {
                        $join->on('inventory_location_stocks.product_variant_id', '=', 'product_variants.id')
                             ->where('products.is_variant', '=', 1);
                    })
                    ->where('products.deleted_at', '=', null)
                    ->where('inventory_location_stocks.quantity', '>', 0)
                    ->whereIn('inventory_location_stocks.inventory_location_id', $nativeLocationIds)
                    ->select(DB::raw("SUM(
                        CASE
                            WHEN products.is_variant = 1 AND product_variants.id IS NOT NULL
                            THEN {$expr['native']}
                            ELSE {$expr['native_default']}
                        END
                    ) as total_value"))
                    ->first();
                $totals[$key] += (float) ($row->total_value ?? 0);
            }
        }

        $stockByCost = (object) ['total_value' => $totals['by_cost']];
        $stockByRetail = (object) ['total_value' => $totals['by_retail']];
        $stockByWholesale = (object) ['total_value' => $totals['by_wholesale']];

        return [
            'by_cost' => (float) ($stockByCost->total_value ?? 0),
            'by_retail' => (float) ($stockByRetail->total_value ?? 0),
            'by_wholesale' => (float) ($stockByWholesale->total_value ?? 0),
        ];
    }

    /**
     * Real-time sales counter: today's count, total, last sale, hourly breakdown,
     * recent sales, top products, payment-status split and yesterday's total for trend.
     */
    public function real_time_sales_counter_data(Request $request)
    {
        $user = Auth::user();
        $role = $user->roles()->first();
        if (!$role || !$role->inRole('real_time_sales_counter')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        $array_warehouses_id = $is_all_warehouses
            ? Warehouse::where('deleted_at', null)->pluck('id')->toArray()
            : UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();

        $warehouse_id = (int) ($request->warehouse_id ?? 0);
        if ($warehouse_id !== 0 && !in_array($warehouse_id, $array_warehouses_id, true)) {
            $warehouse_id = 0;
        }

        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();
        $yesterdayStart = Carbon::yesterday()->startOfDay();
        $yesterdayEnd = Carbon::yesterday()->endOfDay();

        $applyScope = function ($query) use ($view_records, $warehouse_id, $array_warehouses_id) {
            $query->where('sales.deleted_at', null)
                ->where(function ($q) use ($view_records) {
                    if (!$view_records) {
                        $q->where('sales.user_id', Auth::id());
                    }
                })
                ->where(function ($q) use ($warehouse_id, $array_warehouses_id) {
                    if ($warehouse_id !== 0) {
                        $q->where('sales.warehouse_id', $warehouse_id);
                    } else {
                        $q->whereIn('sales.warehouse_id', $array_warehouses_id);
                    }
                });
            return $query;
        };

        $todayBase = Sale::query()->whereBetween('sales.date', [$todayStart, $todayEnd]);
        $applyScope($todayBase);

        $todayCount = (clone $todayBase)->count();
        $todayTotal = (float) ((clone $todayBase)->sum('GrandTotal') ?? 0);

        $paidTotal = (float) ((clone $todayBase)->sum('paid_amount') ?? 0);
        $dueTotal = max(0, $todayTotal - $paidTotal);

        $statusCounts = (clone $todayBase)
            ->select('payment_statut', DB::raw('count(*) as c'))
            ->groupBy('payment_statut')
            ->pluck('c', 'payment_statut');

        $lastSale = (clone $todayBase)
            ->orderBy('sales.date', 'desc')
            ->orderBy('sales.id', 'desc')
            ->first();

        $lastSaleAt = null;
        if ($lastSale) {
            $lastSaleAt = $lastSale->date
                ? Carbon::parse(trim($lastSale->date . ' ' . ($lastSale->time ?? '')))->toIso8601String()
                : ($lastSale->created_at ? $lastSale->created_at->toIso8601String() : null);
        }

        $hourlyRows = (clone $todayBase)
            ->select(
                DB::raw('HOUR(sales.date) as hour'),
                DB::raw('count(*) as count'),
                DB::raw('COALESCE(SUM(GrandTotal),0) as total')
            )
            ->groupBy(DB::raw('HOUR(sales.date)'))
            ->get();

        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = ['hour' => $h, 'count' => 0, 'total' => 0.0];
        }
        foreach ($hourlyRows as $row) {
            $h = (int) $row->hour;
            if ($h >= 0 && $h < 24) {
                $hourly[$h]['count'] = (int) $row->count;
                $hourly[$h]['total'] = (float) $row->total;
            }
        }

        $recentSalesQuery = (clone $todayBase)
            ->leftJoin('clients', 'sales.client_id', '=', 'clients.id')
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->orderBy('sales.date', 'desc')
            ->orderBy('sales.id', 'desc')
            ->limit(10)
            ->get([
                'sales.id',
                'sales.Ref',
                'sales.date',
                'sales.time',
                'sales.GrandTotal',
                'sales.paid_amount',
                'sales.payment_statut',
                'sales.is_pos',
                'clients.name as client_name',
                'warehouses.name as warehouse_name',
            ]);

        $recentSales = $recentSalesQuery->map(function ($s) {
            $grand = (float) $s->GrandTotal;
            $paid = (float) $s->paid_amount;
            $dateTime = $s->date
                ? Carbon::parse(trim($s->date . ' ' . ($s->time ?? '')))->toIso8601String()
                : null;
            return [
                'id' => $s->id,
                'ref' => $s->Ref,
                'date' => $dateTime,
                'grand_total' => $grand,
                'paid_amount' => $paid,
                'due_amount' => max(0, $grand - $paid),
                'payment_status' => $s->payment_statut,
                'is_pos' => (int) $s->is_pos,
                'client_name' => $s->client_name,
                'warehouse_name' => $s->warehouse_name,
            ];
        });

        $todaySaleIds = (clone $todayBase)->pluck('sales.id');
        $topProducts = collect();
        if ($todaySaleIds->isNotEmpty()) {
            $topProducts = SaleDetail::leftJoin('products', 'sale_details.product_id', '=', 'products.id')
                ->whereIn('sale_details.sale_id', $todaySaleIds)
                ->select(
                    'products.id as product_id',
                    'products.name as product_name',
                    DB::raw('SUM(sale_details.quantity) as quantity'),
                    DB::raw('SUM(sale_details.total) as total')
                )
                ->groupBy('products.id', 'products.name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get()
                ->map(function ($r) {
                    return [
                        'product_id' => $r->product_id,
                        'product_name' => $r->product_name ?: '-',
                        'quantity' => (float) $r->quantity,
                        'total' => (float) $r->total,
                    ];
                });
        }

        $yesterdayBase = Sale::query()->whereBetween('sales.date', [$yesterdayStart, $yesterdayEnd]);
        $applyScope($yesterdayBase);
        $yesterdayTotal = (float) ($yesterdayBase->sum('GrandTotal') ?? 0);

        // Per-warehouse breakdown for the "Sales by Location" panel.
        // Reuses $todayBase (already scoped by user permissions and the
        // optional warehouse_id filter) so a cashier with limited access
        // only sees branches they're allowed to view.
        $salesByLocation = (clone $todayBase)
            ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
            ->select(
                'sales.warehouse_id',
                'warehouses.name as warehouse_name',
                DB::raw('COUNT(*) as total_invoice'),
                DB::raw('COALESCE(SUM(sales.GrandTotal),0) as amount'),
                DB::raw("MAX(CONCAT(sales.date, ' ', COALESCE(sales.time, '00:00:00'))) as last_sale")
            )
            ->groupBy('sales.warehouse_id', 'warehouses.name')
            ->orderByDesc('amount')
            ->get()
            ->map(function ($r) {
                return [
                    'warehouse_id' => $r->warehouse_id,
                    'name' => $r->warehouse_name ?: '—',
                    'total_invoice' => (int) $r->total_invoice,
                    'amount' => (float) $r->amount,
                    'last_sale' => $r->last_sale ? Carbon::parse($r->last_sale)->toIso8601String() : null,
                ];
            });

        $warehouses = Warehouse::where('deleted_at', null)
            ->whereIn('id', $array_warehouses_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'today_count' => $todayCount,
            'today_total' => $todayTotal,
            'today_paid' => $paidTotal,
            'today_due' => $dueTotal,
            'last_sale_at' => $lastSaleAt,
            'yesterday_total' => $yesterdayTotal,
            'payment_status_counts' => [
                'paid' => (int) ($statusCounts['paid'] ?? 0),
                'partial' => (int) ($statusCounts['partial'] ?? 0),
                'unpaid' => (int) ($statusCounts['unpaid'] ?? 0),
            ],
            'hourly' => array_values($hourly),
            'recent_sales' => $recentSales,
            'top_products' => $topProducts,
            'sales_by_location' => $salesByLocation,
            'warehouses' => $warehouses,
            'selected_warehouse_id' => $warehouse_id,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }
}

