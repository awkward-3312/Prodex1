<?php

namespace App\Http\Controllers;

use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\SerialNumberService;
use Illuminate\Http\Request;

class SerialNumberController extends BaseController
{
    /**
     * Paginated, filterable list of serial / IMEI units.
     * Query params (vue-good-table remote): limit, page, SortField, SortType,
     * plus filters: search, product_id, warehouse_id, status.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ProductSerial::class);

        $perPage = $request->limit ?? 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = in_array($request->SortField, ['id', 'serial_number', 'status', 'created_at'])
            ? $request->SortField : 'id';
        $dir = $request->SortType === 'asc' ? 'asc' : 'desc';

        // Warehouse scoping: respect the user's assigned warehouses.
        $allowedWarehouses = $this->allowedWarehouseIds();

        $query = ProductSerial::with(['product:id,name,code', 'variant:id,name', 'warehouse:id,name', 'provider:id,name', 'client:id,name'])
            ->when($allowedWarehouses !== null, function ($q) use ($allowedWarehouses) {
                return $q->whereIn('warehouse_id', $allowedWarehouses);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('serial_number', 'LIKE', "%{$request->search}%");
            })
            ->when($request->filled('product_id'), function ($q) use ($request) {
                return $q->where('product_id', $request->product_id);
            })
            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                return $q->where('warehouse_id', $request->warehouse_id);
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                return $q->where('status', $request->status);
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows > 0 ? $totalRows : 1;
        }

        $rows = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => (int) $r->id,
                'serial_number' => $r->serial_number,
                'product_id' => (int) $r->product_id,
                'product_name' => optional($r->product)->name,
                'product_code' => optional($r->product)->code,
                'variant_name' => optional($r->variant)->name,
                'warehouse_id' => (int) $r->warehouse_id,
                'warehouse_name' => optional($r->warehouse)->name,
                'status' => $r->status,
                'provider_name' => optional($r->provider)->name,
                'client_name' => optional($r->client)->name,
                'cost' => $r->cost,
                'created_at' => $r->created_at ? $r->created_at->format('Y-m-d H:i') : null,
            ];
        }

        $warehouses = Warehouse::where('deleted_at', '=', null)
            ->when($allowedWarehouses !== null, fn ($q) => $q->whereIn('id', $allowedWarehouses))
            ->get(['id', 'name']);

        return response()->json([
            'serials' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * Full lifecycle of one serial: the record plus its ordered movement log.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ProductSerial::class);

        $serial = ProductSerial::with([
            'product:id,name,code', 'variant:id,name', 'warehouse:id,name',
            'provider:id,name', 'client:id,name',
        ])->findOrFail($id);

        $movements = \App\Models\ProductSerialMovement::where('product_serial_id', $serial->id)
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'action' => $m->action,
                'from_status' => $m->from_status,
                'to_status' => $m->to_status,
                'warehouse_id' => $m->warehouse_id,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
                'reference_ref' => $this->referenceRef($m->reference_type, $m->reference_id),
                'user_name' => $m->user_id ? optional(\App\Models\User::find($m->user_id))->username : null,
                'created_at' => $m->created_at ? $m->created_at->format('Y-m-d H:i') : null,
            ]);

        return response()->json([
            'serial' => [
                'id' => (int) $serial->id,
                'serial_number' => $serial->serial_number,
                'status' => $serial->status,
                'product_name' => optional($serial->product)->name,
                'product_code' => optional($serial->product)->code,
                'variant_name' => optional($serial->variant)->name,
                'warehouse_name' => optional($serial->warehouse)->name,
                'provider_name' => optional($serial->provider)->name,
                'client_name' => optional($serial->client)->name,
                'cost' => $serial->cost,
                'created_at' => $serial->created_at ? $serial->created_at->format('Y-m-d H:i') : null,
            ],
            'movements' => $movements,
        ]);
    }

    /**
     * Manually set a serial's status (available / damaged / reserved).
     */
    public function changeStatus(Request $request, $id, SerialNumberService $service)
    {
        $this->authorizeForUser($request->user('api'), 'update', ProductSerial::class);

        $request->validate(['status' => 'required|string']);

        $serial = ProductSerial::findOrFail($id);
        $service->changeStatus($serial, $request->status, $request->notes);

        return response()->json(['success' => true]);
    }

    /**
     * Resolve a movement reference to the source document's Ref for display.
     */
    private function referenceRef($type, $id)
    {
        if (! $type || ! $id) {
            return null;
        }
        $map = [
            'Sale' => \App\Models\Sale::class,
            'Purchase' => \App\Models\Purchase::class,
            'SaleReturn' => \App\Models\SaleReturn::class,
            'PurchaseReturn' => \App\Models\PurchaseReturn::class,
        ];
        if (! isset($map[$type])) {
            return null;
        }

        return optional($map[$type]::find($id))->Ref;
    }

    /**
     * Available serials for a product (+ optional variant) in a warehouse.
     * Used by the create-sale / POS serial picker. Query params:
     * product_id (required), warehouse_id (required), product_variant_id (optional).
     */
    public function available(Request $request, SerialNumberService $service)
    {
        // Authorize against Sale creation (not the serial-management module): this
        // lookup is used by the create-sale / POS serial picker, so any user who can
        // make a sale must be able to load available serials — mirrors batches_for_sale.
        $this->authorizeForUser($request->user('api'), 'create', Sale::class);

        $productId = (int) $request->product_id;
        $warehouseId = (int) $request->warehouse_id;
        $variantId = $request->filled('product_variant_id') ? (int) $request->product_variant_id : null;
        $includeSaleId = $request->filled('include_sale_id') ? (int) $request->include_sale_id : null;

        return response()->json([
            'serials' => $service->availableSerialsForSale($productId, $variantId, $warehouseId, $includeSaleId),
        ]);
    }

    /**
     * Serials sold on a given sale (for the sale-return picker).
     * Query params: sale_id (required), product_id, product_variant_id (optional).
     */
    public function forSale(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Sale::class);

        $rows = ProductSerial::where('sale_id', (int) $request->sale_id)
            ->where('status', ProductSerial::STATUS_SOLD)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where('product_variant_id', $request->product_variant_id))
            ->orderBy('id', 'asc')
            ->get(['id', 'serial_number', 'status']);

        return response()->json(['serials' => $this->mapSerials($rows)]);
    }

    /**
     * Available serials originating from a given purchase (for the purchase-return picker).
     * Query params: purchase_id (required), product_id, product_variant_id (optional).
     */
    public function forPurchase(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', \App\Models\PurchaseReturn::class);

        $rows = ProductSerial::where('purchase_id', (int) $request->purchase_id)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where('product_variant_id', $request->product_variant_id))
            ->orderBy('id', 'asc')
            ->get(['id', 'serial_number', 'status']);

        return response()->json(['serials' => $this->mapSerials($rows)]);
    }

    private function mapSerials($rows)
    {
        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'serial_number' => (string) $r->serial_number,
            'status' => (string) $r->status,
        ])->all();
    }

    /**
     * Warehouse ids the current user may see, or null when unrestricted.
     */
    private function allowedWarehouseIds(): ?array
    {
        $user = auth()->user();
        if ($user && ! $user->is_all_warehouses) {
            return \App\Models\UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        return null;
    }
}
