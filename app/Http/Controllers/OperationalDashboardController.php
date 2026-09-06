<?php

namespace App\Http\Controllers;

use App\Models\PaymentSale;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Support\DashboardScope;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Wrapper del Panel que recalcula cada widget derivado de ventas desde la
 * identidad operativa moderna (branch_id / inventory_location_id), en lugar del
 * puntero heredado warehouse_id que puede ser NULL.
 *
 * El ALCANCE ANALÍTICO lo resuelve DashboardScopeService (heredado del padre);
 * este controlador NO usa record_view como palanca de granularidad:
 *   - BRANCH_OPERATIONAL  => agregado de la(s) sucursal(es) en alcance, sin
 *     filtro por usuario (visible para el cajero).
 *   - PERSONAL            => ventas recientes del propio usuario cuando no tiene
 *     autoridad de equipo.
 *   - TEAM / FINANCIAL    => sólo se recalculan si el padre ya incluyó la clave
 *     (owner / gerente); nunca se AÑADEN aquí para el cajero.
 */
class OperationalDashboardController extends DashboardController
{
    public function dashboard_data(Request $request)
    {
        $response = parent::dashboard_data($request);
        $payload = $response->getData(true);

        $scope = $this->resolveDashboardScope($request);

        // Usuario operativo sin sucursal resoluble: el padre ya devolvió el
        // estado vacío. No recalcular nada.
        if (! $scope->hasBranch) {
            return $response;
        }

        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->subDays(6)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();

        $base = Sale::query()->whereNull('sales.deleted_at')->whereBetween('sales.date', [$from, $to]);
        $this->applySaleScope($base, $scope);

        // --- BRANCH_OPERATIONAL: serie diaria de ventas (sin filtro por usuario)
        $days = [];
        $values = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        $daily = (clone $base)
            ->selectRaw('sales.date as d, COALESCE(SUM(sales.GrandTotal),0) as total')
            ->groupBy('sales.date')
            ->pluck('total', 'd');
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $days[] = $day;
            $values[] = (float) ($daily[$day] ?? 0);
            $cursor->addDay();
        }
        $payload['sales'] = ['original' => ['data' => $values, 'days' => $days]];

        // --- BRANCH_OPERATIONAL: top productos del año
        $productQuery = SaleDetail::query()
            ->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->join('products', 'sale_details.product_id', '=', 'products.id')
            ->whereNull('sales.deleted_at')
            ->whereBetween('sales.date', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()]);
        $this->applySaleScope($productQuery, $scope);
        $payload['product_report'] = ['original' => $productQuery
            ->selectRaw('products.name as name, COALESCE(SUM(sale_details.quantity),0) as value')
            ->groupBy('products.name')->orderByDesc('value')->limit(5)->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (float) $row->value])
            ->values()->all()];

        // --- BRANCH_OPERATIONAL: ventas por método de pago
        $payments = PaymentSale::query()
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->leftJoin('payment_methods', 'payment_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->whereBetween('payment_sales.date', [$from, $to]);
        $this->applySaleScope($payments, $scope);
        $paymentRows = $payments
            ->selectRaw("COALESCE(payment_methods.name, '---') as name, SUM(payment_sales.montant) as amount")
            ->groupBy('name')->orderByDesc('amount')->get();
        $paymentTotal = max(0.0, (float) $paymentRows->sum('amount'));
        $colors = ['orange', 'blue', 'green', 'grey', 'yellow', 'purple', 'cyan'];
        $payload['sales_by_payment'] = $paymentRows->values()->map(function ($row, $index) use ($paymentTotal, $colors) {
            $amount = (float) $row->amount;

            return [
                'name' => $row->name,
                'amount' => $amount,
                'percentage' => $paymentTotal > 0 ? round(($amount / $paymentTotal) * 100, 2) : 0,
                'color' => $colors[$index % count($colors)],
            ];
        })->all();

        // --- BRANCH_OPERATIONAL: cabeceras del reporte (sin filtro por usuario)
        $salesAgg = (clone $base)->selectRaw('COALESCE(SUM(sales.GrandTotal),0) total')->first();
        $report = $payload['report_dashboard']['original']['report'] ?? [];
        $report['today_sales'] = (float) ($salesAgg->total ?? 0);
        $report['today_invoices'] = (clone $base)->count();
        // `sales_due` NO vive aquí: es FINANCIAL_MANAGEMENT y viaja en
        // $payload['financial'] (que el padre sólo incluye para owner / gerente).
        unset($report['sales_due']);
        $payload['report_dashboard']['original']['report'] = $report;

        // --- PERSONAL / TEAM: ventas recientes
        $recent = Sale::with(['client', 'branch', 'warehouse'])->whereNull('sales.deleted_at');
        $this->applySaleScope($recent, $scope);
        if (! $scope->canSeeTeam) {
            $recent->where('sales.user_id', $scope->personalUserId);
        }
        $recentRows = $recent->orderByDesc('sales.id')->limit(5)->get()->map(function ($sale) {
            return [
                'id' => $sale->id,
                'Ref' => $sale->Ref,
                'client_name' => optional($sale->client)->name ?: '—',
                'warehouse_name' => optional($sale->branch)->name
                    ?: optional($sale->warehouse)->name
                    ?: '—',
                'GrandTotal' => (float) $sale->GrandTotal,
                'paid_amount' => (float) $sale->paid_amount,
                'due' => (float) $sale->GrandTotal - (float) $sale->paid_amount,
                'payment_status' => $sale->payment_statut,
                'statut' => $sale->statut,
            ];
        })->values()->all();
        $payload['report_dashboard']['original']['recent_sales'] = $recentRows;
        $payload['report_dashboard']['original']['last_sales'] = $recentRows;

        // --- FINANCIAL_MANAGEMENT: sólo si el padre ya incluyó la clave -------
        if (array_key_exists('customers', $payload)) {
            $customerQuery = Sale::query()
                ->whereNull('sales.deleted_at')
                ->whereBetween('sales.date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->join('clients', 'sales.client_id', '=', 'clients.id');
            $this->applySaleScope($customerQuery, $scope);
            $payload['customers'] = ['original' => $customerQuery
                ->selectRaw('clients.name as name, COUNT(*) as value')
                ->groupBy('clients.name')->orderByDesc('value')->limit(5)->get()
                ->map(fn ($row) => ['name' => (string) $row->name, 'value' => (float) $row->value])
                ->values()->all()];
        }

        if (isset($payload['payments']['original']['days'])) {
            $received = PaymentSale::query()
                ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
                ->whereNull('payment_sales.deleted_at')->whereNull('sales.deleted_at')
                ->whereBetween('payment_sales.date', [$from, $to]);
            $this->applySaleScope($received, $scope);
            $byDay = $received->selectRaw('payment_sales.date as d, SUM(payment_sales.montant) as total')
                ->groupBy('payment_sales.date')->pluck('total', 'd');
            $payload['payments']['original']['payment_received'] = collect($payload['payments']['original']['days'])
                ->map(fn ($day) => (float) ($byDay[$day] ?? 0))->all();
        }

        // --- TEAM: desglose por cajero / sucursal. SÓLO si el usuario tiene
        //     autoridad de equipo. Nunca se entrega al cajero (ni oculto en Vue).
        if ($scope->canSeeTeam) {
            $cashierQuery = clone $base;
            $payload['sales_by_cashier'] = $cashierQuery
                ->leftJoin('users', 'sales.user_id', '=', 'users.id')
                ->selectRaw("sales.user_id as user_id, COALESCE(users.username, '—') as cashier_name")
                ->selectRaw('COUNT(sales.id) as invoices')
                ->selectRaw('COALESCE(SUM(sales.GrandTotal),0) as total_sales')
                ->selectRaw('COALESCE(SUM(sales.paid_amount),0) as paid_amount')
                ->groupBy('sales.user_id', 'users.username')
                ->orderByDesc('total_sales')
                ->get()
                ->map(fn ($row) => [
                    'user_id' => $row->user_id ? (int) $row->user_id : null,
                    'cashier_name' => (string) $row->cashier_name,
                    'invoices' => (int) $row->invoices,
                    'total_sales' => (float) $row->total_sales,
                    'paid_amount' => (float) $row->paid_amount,
                    'due' => max(0, (float) $row->total_sales - (float) $row->paid_amount),
                ])->values()->all();

            $branchQuery = clone $base;
            $payload['sales_by_branch'] = $branchQuery
                ->leftJoin('branches', 'sales.branch_id', '=', 'branches.id')
                ->leftJoin('warehouses', 'sales.warehouse_id', '=', 'warehouses.id')
                ->selectRaw('sales.branch_id as branch_id')
                ->selectRaw("COALESCE(branches.name, warehouses.name, '—') as branch_name")
                ->selectRaw('COUNT(sales.id) as invoices')
                ->selectRaw('COALESCE(SUM(sales.GrandTotal),0) as total_sales')
                ->groupBy('sales.branch_id', 'branches.name', 'warehouses.name')
                ->orderByDesc('total_sales')
                ->get()
                ->map(fn ($row) => [
                    'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                    'branch_name' => (string) $row->branch_name,
                    'invoices' => (int) $row->invoices,
                    'total_sales' => (float) $row->total_sales,
                ])->values()->all();
        } else {
            unset($payload['sales_by_cashier'], $payload['sales_by_branch']);
        }

        return response()->json($payload);
    }

    /**
     * Filtro de ventas consciente de `branch_id`: las ventas modernas viven en
     * `sales.branch_id`; las históricas sólo tienen `sales.warehouse_id`. Ambas
     * se limitan al alcance efectivo resuelto — SIN record_view.
     */
    protected function applySaleScope($query, DashboardScope $scope, string $alias = 'sales')
    {
        return $query->where(function ($w) use ($scope, $alias) {
            $w->whereIn("{$alias}.branch_id", $scope->branchFilterIds())
                ->orWhere(function ($legacy) use ($scope, $alias) {
                    $legacy->whereNull("{$alias}.branch_id")
                        ->whereIn("{$alias}.warehouse_id", $scope->warehouseFilterIds());
                });
        });
    }
}
