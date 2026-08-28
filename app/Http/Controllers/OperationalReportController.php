<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesReportingScopeService;
use App\Services\UserOperationalAssignmentService;
use App\utils\helpers;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

/**
 * Sale-facing reports backed by the operational sale address. Methods not
 * overridden here continue to use ReportController unchanged.
 */
class OperationalReportController extends ReportController
{
    private function salesBase(Request $request, ?string $from = null, ?string $to = null)
    {
        $user = $request->user('api');
        $scope = app(SalesReportingScopeService::class);
        $q = Sale::query()->whereNull('sales.deleted_at');
        $scope->applyRecordVisibility($q, $user, 'sales');
        $scope->apply(
            $q,
            $user,
            'sales',
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('branch_id') ? (int) $request->branch_id : null
        );
        if ($from && $to) $q->whereBetween('sales.date', [$from, $to]);
        return $q;
    }

    private function saleRow(Sale $sale): array
    {
        $scope = app(SalesReportingScopeService::class);
        return [
            'id' => $sale->id,
            'sale_id' => $sale->id,
            'date' => $sale->date,
            'time' => $sale->time,
            'Ref' => $sale->Ref,
            'client_name' => optional($sale->client)->name ?: '—',
            'warehouse_name' => $scope->displayLocation($sale),
            'branch_id' => $sale->branch_id,
            'branch_name' => optional($sale->branch)->name,
            'inventory_location_name' => optional($sale->inventoryLocation)->name,
            'cash_drawer_name' => optional($sale->cashDrawer)->name,
            'user_name' => optional($sale->user)->username ?: '—',
            'username' => optional($sale->user)->username ?: '—',
            'statut' => $sale->statut,
            'GrandTotal' => (float) $sale->GrandTotal,
            'paid_amount' => (float) $sale->paid_amount,
            'due' => (float) $sale->GrandTotal - (float) $sale->paid_amount,
            'payment_status' => $sale->payment_statut,
            'shipping_status' => $sale->shipping_status,
        ];
    }

    public function Report_Sales(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_sales', Sale::class);
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));
        $offset = $perPage === -1 ? 0 : ($page - 1) * $perPage;
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : '2000-01-01';
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();

        $q = $this->salesBase($request, $from, $to)
            ->with(['client', 'warehouse', 'branch', 'inventoryLocation', 'cashDrawer', 'user'])
            ->when($request->filled('Ref'), fn ($x) => $x->where('sales.Ref', 'like', '%'.$request->Ref.'%'))
            ->when($request->filled('client_id'), fn ($x) => $x->where('sales.client_id', $request->client_id))
            ->when($request->filled('user_id'), fn ($x) => $x->where('sales.user_id', $request->user_id))
            ->when($request->filled('statut'), fn ($x) => $x->where('sales.statut', $request->statut))
            ->when($request->filled('payment_statut'), fn ($x) => $x->where('sales.payment_statut', $request->payment_statut))
            ->when($request->filled('search'), function ($x) use ($request) {
                $s = trim((string) $request->search);
                $x->where(function ($qq) use ($s) {
                    $qq->where('sales.Ref', 'like', "%{$s}%")
                        ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                        ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$s}%"))
                        ->orWhereHas('branch', fn ($b) => $b->where('name', 'like', "%{$s}%"))
                        ->orWhereHas('warehouse', fn ($w) => $w->where('name', 'like', "%{$s}%"));
                });
            });

        $totalRows = (clone $q)->count();
        if ($perPage === -1) $perPage = max(1, $totalRows);
        $allowedSorts = ['id','date','Ref','statut','GrandTotal','paid_amount','payment_statut'];
        $sort = in_array($request->SortField, $allowedSorts, true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';
        $rows = $q->orderBy("sales.{$sort}", $dir)->offset($offset)->limit($perPage)->get()->map(fn ($sale) => $this->saleRow($sale))->values();

        $user = $request->user('api');
        $legacyIds = app(UserOperationalAssignmentService::class)->allowedWarehouseIds($user);
        $warehouses = Warehouse::whereNull('deleted_at')->when((int) $user->role_id !== 1, fn ($x) => $x->whereIn('id', $legacyIds))->get(['id','name']);

        return response()->json([
            'sales' => $rows,
            'totalRows' => $totalRows,
            'customers' => Client::whereNull('deleted_at')->get(['id','name']),
            'warehouses' => $warehouses,
            'branches' => app(SalesReportingScopeService::class)->branchesFor($user),
            'sellers' => User::whereNull('deleted_at')->get(['id','username']),
        ]);
    }

    public function get_sales_by_user(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'users_report', User::class);
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));
        $q = $this->salesBase($request)->with(['client','warehouse','branch','inventoryLocation','cashDrawer','user'])
            ->where('sales.user_id', (int) $request->id)
            ->when($request->filled('search'), fn ($x) => $x->where('sales.Ref', 'like', '%'.$request->search.'%'));
        $totalRows = (clone $q)->count();
        $rows = $q->orderByDesc('sales.id')
            ->when($perPage !== -1, fn ($x) => $x->offset(($page - 1) * $perPage)->limit($perPage))
            ->get()->map(fn ($sale) => $this->saleRow($sale))->values();
        return response()->json(['totalRows' => $totalRows, 'sales' => $rows]);
    }

    public function users_Report(Request $request)
    {
        $response = parent::users_Report($request);
        $payload = $response->getData(true);
        foreach ($payload['report'] ?? [] as $index => $row) {
            $fake = $request->duplicate();
            $fake->setUserResolver(fn () => $request->user('api'));
            $q = $this->salesBase($request)->where('sales.user_id', (int) $row['id']);
            $payload['report'][$index]['total_sales'] = $q->count();
        }
        return response()->json($payload);
    }

    public function seller_report(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'seller_report', User::class);
        $start = $request->filled('start_date') ? Carbon::parse($request->start_date)->toDateString() : '2000-01-01';
        $end = $request->filled('end_date') ? Carbon::parse($request->end_date)->toDateString() : now()->toDateString();
        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->input('page', 1));

        $usersQ = User::whereNull('deleted_at')->when($request->filled('search'), fn ($q) => $q->where('username', 'like', '%'.$request->search.'%'));
        $totalRows = (clone $usersQ)->count();
        $users = $usersQ->orderBy('id')->when($perPage !== -1, fn ($q) => $q->offset(($page - 1) * $perPage)->limit($perPage))->get();
        $methods = PaymentMethod::whereNull('deleted_at')->pluck('name', 'id');
        $report = [];

        foreach ($users as $seller) {
            $sales = $this->salesBase($request, $start, $end)->where('sales.user_id', $seller->id);
            $row = ['id' => $seller->id, 'username' => $seller->username, 'total_sales' => number_format((float) $sales->sum('sales.GrandTotal'), helpers::price_decimals(), '.', ',')];
            foreach ($methods as $name) $row[$name] = 0;

            $payments = DB::table('payment_sales')->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
                ->whereNull('payment_sales.deleted_at')->whereNull('sales.deleted_at')
                ->where('sales.user_id', $seller->id)->whereBetween('payment_sales.date', [$start, $end]);
            $scope = app(SalesReportingScopeService::class);
            $scope->applyRecordVisibility($payments, $request->user('api'), 'sales');
            $scope->apply($payments, $request->user('api'), 'sales', $request->filled('warehouse_id') ? (int) $request->warehouse_id : null, $request->filled('branch_id') ? (int) $request->branch_id : null);
            foreach ($payments->select('payment_sales.payment_method_id', DB::raw('SUM(payment_sales.montant) total'))->groupBy('payment_sales.payment_method_id')->get() as $p) {
                $name = $methods[$p->payment_method_id] ?? 'Unknown';
                $row[$name] = number_format((float) $p->total, helpers::price_decimals(), '.', ',');
            }
            $report[] = $row;
        }

        $user = $request->user('api');
        $legacyIds = app(UserOperationalAssignmentService::class)->allowedWarehouseIds($user);
        $warehouses = Warehouse::whereNull('deleted_at')->when((int) $user->role_id !== 1, fn ($q) => $q->whereIn('id', $legacyIds))->get(['id','name']);
        return response()->json([
            'report' => $report,
            'warehouses' => $warehouses,
            'branches' => app(SalesReportingScopeService::class)->branchesFor($user),
            'paymentMethods' => array_values($methods->toArray()),
            'totalRows' => $totalRows,
        ]);
    }

    public function report_top_products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Top_products', Sale::class);
        $q = SaleDetail::query()->join('sales', 'sale_details.sale_id', '=', 'sales.id')->join('products', 'sale_details.product_id', '=', 'products.id')->whereNull('sales.deleted_at');
        $scope = app(SalesReportingScopeService::class);
        $scope->applyRecordVisibility($q, $request->user('api'), 'sales');
        $scope->apply($q, $request->user('api'), 'sales', $request->filled('warehouse_id') ? (int) $request->warehouse_id : null, $request->filled('branch_id') ? (int) $request->branch_id : null);
        if ($request->filled('from') && $request->filled('to')) $q->whereBetween('sales.date', [Carbon::parse($request->from)->toDateString(), Carbon::parse($request->to)->toDateString()]);
        $rows = $q->selectRaw('products.name as name, products.code as code, SUM(sale_details.quantity) as total_sales, SUM(sale_details.total) as total')->groupBy('products.id','products.name','products.code')->orderByDesc('total_sales')->get();
        $perPage = (int) ($request->limit ?? 10); $page = max(1, (int) ($request->page ?? 1)); $totalRows = $rows->count();
        if ($perPage !== -1) $rows = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        return response()->json(['products' => $rows, 'totalRows' => $totalRows]);
    }

    public function report_top_customers(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Top_customers', Client::class);
        $q = $this->salesBase($request)->join('clients', 'sales.client_id', '=', 'clients.id')
            ->selectRaw('clients.name as name, clients.phone as phone, clients.email as email, COUNT(*) as total_sales, SUM(sales.GrandTotal) as total')
            ->groupBy('clients.id','clients.name','clients.phone','clients.email')->orderByDesc('total_sales');
        $all = $q->get(); $totalRows = $all->count(); $perPage = (int) ($request->limit ?? 10); $page = max(1,(int)($request->page ?? 1));
        if ($perPage !== -1) $all = $all->slice(($page - 1) * $perPage, $perPage)->values();
        return response()->json(['customers' => $all, 'totalRows' => $totalRows]);
    }

    public function sales_by_category_report(Request $request)
    {
        return $this->groupedCatalogSales($request, 'category');
    }

    public function sales_by_brand_report(Request $request)
    {
        return $this->groupedCatalogSales($request, 'brand');
    }

    private function groupedCatalogSales(Request $request, string $type)
    {
        $permission = $type === 'category' ? 'report_sales_by_category' : 'report_sales_by_brand';
        $this->authorizeForUser($request->user('api'), $permission, Sale::class);
        $table = $type === 'category' ? 'categories' : 'brands';
        $foreign = $type === 'category' ? 'category_id' : 'brand_id';
        $label = $type === 'category' ? 'category_name' : 'brand_name';
        $from = $request->filled('from') ? Carbon::parse($request->from)->toDateString() : now()->subDays(29)->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->to)->toDateString() : now()->toDateString();
        $q = DB::table($table)->join('products', "{$table}.id", '=', "products.{$foreign}")
            ->join('sale_details', 'products.id', '=', 'sale_details.product_id')->join('sales', 'sale_details.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')->whereBetween('sales.date', [$from,$to]);
        $scope = app(SalesReportingScopeService::class); $scope->applyRecordVisibility($q, $request->user('api'), 'sales');
        $scope->apply($q, $request->user('api'), 'sales', $request->filled('warehouse_id') ? (int)$request->warehouse_id : null, $request->filled('branch_id') ? (int)$request->branch_id : null);
        if ($request->filled('search')) $q->where("{$table}.name", 'like', '%'.$request->search.'%');
        $rows = $q->selectRaw("{$table}.id as id, {$table}.name as {$label}, COALESCE(SUM(sale_details.total),0) as total_sales")->groupBy("{$table}.id", "{$table}.name")->get();
        $totalRows = $rows->count(); $perPage = (int)($request->limit ?? 10); $page=max(1,(int)($request->page ?? 1)); if($perPage!==-1)$rows=$rows->slice(($page-1)*$perPage,$perPage)->values();
        return response()->json(['reports'=>$rows,'totalRows'=>$totalRows,'currency'=>(new helpers)->Get_Currency_Code()]);
    }
}
