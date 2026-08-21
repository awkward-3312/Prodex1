<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\InventoryLocationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Warehouse::class);

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';

        $warehouses = Warehouse::with('defaultInventoryLocation:id,warehouse_id,code,name,type,is_active')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('mobile', 'LIKE', "%{$request->search}%")
                        ->orWhere('country', 'LIKE', "%{$request->search}%")
                        ->orWhere('city', 'LIKE', "%{$request->search}%")
                        ->orWhere('zip', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $warehouses->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $warehouses = $warehouses->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'warehouses' => $warehouses,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Warehouse::class);

        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        \DB::transaction(function () use ($request) {
            $warehouse = new Warehouse;
            // Warehouses are now standalone logistic facilities / distribution centers.
            // branch_id remains in the schema only as a transitional legacy field.
            $warehouse->branch_id = null;
            $warehouse->name = $request['name'];
            $warehouse->mobile = $request['mobile'];
            $warehouse->country = $request['country'];
            $warehouse->city = $request['city'];
            $warehouse->zip = $request['zip'];
            $warehouse->email = $request['email'];
            $warehouse->save();

            // Every real warehouse/CD owns at least one inventory location. Stock is
            // still initialized in product_warehouse until the controlled cutover.
            $location = app(InventoryLocationService::class)->createForWarehouse($warehouse, [
                'code' => 'MAIN',
                'name' => 'Inventario principal',
                'type' => InventoryLocation::TYPE_STORAGE,
                'is_sellable' => false,
                'is_active' => true,
            ]);
            app(InventoryLocationService::class)->setWarehouseDefault($location);

            $products = Product::where('deleted_at', '=', null)->get(['id', 'type']);

            foreach ($products as $product) {
                $rows = [];
                $variants = ProductVariant::where('product_id', $product->id)
                    ->where('deleted_at', null)
                    ->get();

                if ($variants->isNotEmpty()) {
                    foreach ($variants as $variant) {
                        $rows[] = [
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouse->id,
                            'product_variant_id' => $variant->id,
                            'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                        ];
                    }
                } else {
                    $rows[] = [
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'product_variant_id' => null,
                        'manage_stock' => $product->type == 'is_service' ? 0 : 1,
                    ];
                }

                product_warehouse::insert($rows);
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Warehouse::class);

        $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'zip' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);

        // Do not rewrite branch_id here. Existing values are retained only for
        // backward-compatible scope discovery until every legacy user is migrated.
        Warehouse::whereId($id)->update([
            'name' => $request['name'],
            'mobile' => $request['mobile'],
            'country' => $request['country'],
            'city' => $request['city'],
            'zip' => $request['zip'],
            'email' => $request['email'],
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        \DB::transaction(function () use ($id) {
            Warehouse::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            product_warehouse::where('warehouse_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }, 10);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Warehouse::class);

        \DB::transaction(function () use ($request) {
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $warehouse_id) {
                Warehouse::whereId($warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);

                product_warehouse::where('warehouse_id', $warehouse_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    public function Get_Warehouses()
    {
        $warehouses = Warehouse::with('defaultInventoryLocation:id,warehouse_id,code,name,type,is_active')
            ->where('deleted_at', '=', null)
            ->get();

        return response()->json($warehouses);
    }
}
