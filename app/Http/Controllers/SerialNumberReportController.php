<?php

namespace App\Http\Controllers;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SerialNumberReportController extends BaseController
{
    /**
     * Available Serial Numbers Report — units currently in stock.
     */
    public function available(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'serial_numbers_report', ProductSerial::class);

        [$perPage, $offSet, $order, $dir] = $this->paging($request, ['id', 'serial_number', 'created_at']);
        $allowed = $this->allowedWarehouseIds();

        $query = ProductSerial::with(['product:id,name,code', 'warehouse:id,name', 'provider:id,name'])
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->when($allowed !== null, fn ($q) => $q->whereIn('warehouse_id', $allowed))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('search'), fn ($q) => $q->where('serial_number', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $data = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'serial_number' => $r->serial_number,
            'product_name' => optional($r->product)->name,
            'product_code' => optional($r->product)->code,
            'warehouse_name' => optional($r->warehouse)->name,
            'provider_name' => optional($r->provider)->name,
            'cost' => $r->cost,
            'created_at' => $r->created_at ? $r->created_at->format('Y-m-d') : null,
        ]);

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $this->warehouses($allowed),
        ]);
    }

    /**
     * Sold Serial Numbers Report — units sold, with customer + sale.
     */
    public function sold(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'serial_numbers_report', ProductSerial::class);

        [$perPage, $offSet, $order, $dir] = $this->paging($request, ['id', 'serial_number']);
        $allowed = $this->allowedWarehouseIds();

        $query = ProductSerial::with(['product:id,name,code', 'warehouse:id,name', 'client:id,name', 'sale:id,Ref,date'])
            ->where('status', ProductSerial::STATUS_SOLD)
            ->when($allowed !== null, fn ($q) => $q->whereIn('warehouse_id', $allowed))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->warehouse_id))
            ->when($request->filled('search'), fn ($q) => $q->where('serial_number', 'LIKE', "%{$request->search}%"));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $data = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'serial_number' => $r->serial_number,
            'product_name' => optional($r->product)->name,
            'product_code' => optional($r->product)->code,
            'warehouse_name' => optional($r->warehouse)->name,
            'client_name' => optional($r->client)->name,
            'sale_ref' => optional($r->sale)->Ref,
            'sale_date' => optional($r->sale)->date,
        ]);

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $this->warehouses($allowed),
        ]);
    }

    /**
     * Serial Movement History Report — every lifecycle event.
     */
    public function movements(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'serial_numbers_report', ProductSerial::class);

        $perPage = $request->limit ?? 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $query = ProductSerialMovement::query()
            ->when($request->filled('search'), fn ($q) => $q->where('serial_number', 'LIKE', "%{$request->search}%"))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->to_date));

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        }

        $rows = $query->offset($offSet)->limit($perPage)->orderBy('id', 'desc')->get();

        $data = $rows->map(fn ($m) => [
            'id' => (int) $m->id,
            'serial_number' => $m->serial_number,
            'product_serial_id' => (int) $m->product_serial_id,
            'action' => $m->action,
            'from_status' => $m->from_status,
            'to_status' => $m->to_status,
            'reference_type' => $m->reference_type,
            'reference_id' => $m->reference_id,
            'created_at' => $m->created_at ? $m->created_at->format('Y-m-d H:i') : null,
        ]);

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    /**
     * Product Serial Inventory Report — per-product counts by status.
     */
    public function inventory(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'serial_numbers_report', ProductSerial::class);

        $perPage = (int) ($request->limit ?? 10);
        $pageStart = (int) \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $allowed = $this->allowedWarehouseIds();

        $base = DB::table('product_serials as ps')
            ->join('products as p', 'p.id', '=', 'ps.product_id')
            ->when($allowed !== null, fn ($q) => $q->whereIn('ps.warehouse_id', $allowed))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('ps.warehouse_id', $request->warehouse_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($w) use ($request) {
                    $w->where('p.name', 'LIKE', "%{$request->search}%")
                        ->orWhere('p.code', 'LIKE', "%{$request->search}%");
                });
            })
            ->groupBy('ps.product_id', 'p.name', 'p.code')
            ->select(
                'ps.product_id',
                'p.name as product_name',
                'p.code as product_code',
                DB::raw("SUM(ps.status = 'available') as available"),
                DB::raw("SUM(ps.status = 'sold') as sold"),
                DB::raw("SUM(ps.status = 'returned_customer') as returned_customer"),
                DB::raw("SUM(ps.status = 'returned_supplier') as returned_supplier"),
                DB::raw("SUM(ps.status = 'damaged') as damaged"),
                DB::raw("SUM(ps.status = 'reserved') as reserved"),
                DB::raw('COUNT(*) as total')
            );

        $totalRows = DB::table(DB::raw("({$base->toSql()}) as sub"))
            ->mergeBindings($base)
            ->count();

        if ($perPage == -1) {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        }

        $rows = $base
            ->orderBy('p.name', 'asc')
            ->offset($offSet)
            ->limit($perPage)
            ->get();

        $data = $rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'product_name' => $r->product_name,
            'product_code' => $r->product_code,
            'available' => (int) $r->available,
            'sold' => (int) $r->sold,
            'returned_customer' => (int) $r->returned_customer,
            'returned_supplier' => (int) $r->returned_supplier,
            'damaged' => (int) $r->damaged,
            'reserved' => (int) $r->reserved,
            'total' => (int) $r->total,
        ]);

        return response()->json([
            'report' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $this->warehouses($allowed),
        ]);
    }

    // ---------------- helpers ----------------

    private function paging(Request $request, array $sortable): array
    {
        $perPage = $request->limit ?? 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = in_array($request->SortField, $sortable) ? $request->SortField : 'id';
        $dir = $request->SortType === 'asc' ? 'asc' : 'desc';

        return [$perPage, $offSet, $order, $dir];
    }

    private function allowedWarehouseIds(): ?array
    {
        $user = auth()->user();
        if ($user && ! $user->is_all_warehouses) {
            return UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
        }

        return null;
    }

    private function warehouses(?array $allowed)
    {
        return Warehouse::where('deleted_at', '=', null)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->get(['id', 'name']);
    }
}
