<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\CombinedProduct;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\LocationAwareAdjustmentService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDF;

class AdjustmentController extends BaseController
{
    // ------------ Show All Adjustement  -----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Adjustment::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'Ref', 1 => 'warehouse_id', 2 => 'date'];
        $param = [0 => 'like', 1 => '=', 2 => '='];
        $data = [];

        // Check If User Has Permission View  All Records
        $Adjustments = Adjustment::with('warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Adjustments->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Adjustments, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Adjustments = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Adjustments as $Adjustment) {
            $item['id'] = $Adjustment->id;
            $item['date'] = $Adjustment['date'].' '.$Adjustment['time'];
            $item['Ref'] = $Adjustment->Ref;
            $item['warehouse_name'] = $Adjustment['warehouse']->name;
            $item['items'] = $Adjustment->items;
            $data[] = $item;
        }

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'adjustments' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $warehouses,
        ]);

    }

    // ------------ Store New Adjustement -----------\\

    public function store(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Adjustment::class);

        // (#81 · BLOCKER 1) TODO Adjustment NUEVO es location-aware: exige
        // inventory_location_id explícita. Sin ella => 422, 0 escrituras. La
        // ruta legacy de abajo ya NO se usa para crear documentos nuevos
        // (queda sólo para update/destroy de históricos con inventory_location_id NULL).
        if (! $request->filled('inventory_location_id')) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Debes seleccionar una ubicación de inventario: los ajustes nuevos se registran por ubicación.',
            ]);
        }

        return $this->storeLocationAware($request);

    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // --------------- Update Adjustment ----------------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Adjustment::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $current_adjustment = Adjustment::whereNull('deleted_at')->findOrFail($id);

        // (#81 · C6/C7) Registro location-aware => reversa + re-aplicación
        // location-native, PERO respetando la autorización histórica
        // (warehouse scope + check_record) y sólo si no está eliminado.
        if ($current_adjustment->inventory_location_id !== null) {
            if ($denied = $this->assertCanModifyDocument($request, $current_adjustment)) {
                return $denied;
            }

            return $this->updateLocationAware($request, $current_adjustment);
        }

         /**
         * Warehouses restriction
         * Allow if:
         * - user has access to all warehouses (is_all_warehouses = 1)
         * - OR sale warehouse_id is in user's assigned warehouses
        */
        $user_auth = auth()->user();

        if (! $user_auth->is_all_warehouses) {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($current_adjustment->warehouse_id) || ! in_array($current_adjustment->warehouse_id, $warehouses_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this sale (warehouse restriction).',
                ], 403);
            }
        }

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Adjustment->id
            $this->authorizeForUser($request->user('api'), 'check_record', $current_adjustment);
        }

        request()->validate([
            'warehouse_id' => 'required',
        ]);

        \DB::transaction(function () use ($request, $id, $current_adjustment) {

            $old_adjustment_details = AdjustmentDetail::where('adjustment_id', $id)->get();
            $new_adjustment_details = $request['details'];
            $length = count($new_adjustment_details);

            // Get Ids for new Details
            $new_products_id = [];
            foreach ($new_adjustment_details as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // Pharmacy: reverse old batch movements before re-touching warehouse stock
            // so the per-batch ledger ends up consistent.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForAdjustmentDetails($old_adjustment_details);
            }

            $old_products_id = [];
            // Init Data with old Parametre
            foreach ($old_adjustment_details as $key => $value) {
                $old_products_id[] = $value->id;
                if ($value['type'] == 'add') {

                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_adjustment->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte -= $value['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail = Product::where('deleted_at', '=', null)
                            ->where('id', $value['product_id'])
                            ->first();

                        if ($product_detail->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $value['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $value['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_adjustment->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte += $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $value['quantity'];
                                $product_warehouse->save();
                            }

                        }

                    }
                } else {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_adjustment->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail = Product::where('deleted_at', '=', null)
                            ->where('id', $value['product_id'])
                            ->first();

                        if ($product_detail->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $value['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $value['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_adjustment->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte -= $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $value['quantity'];
                                $product_warehouse->save();
                            }

                        }
                    }
                }

                // Delete Detail
                if (! in_array($old_products_id[$key], $new_products_id)) {
                    $AdjustmentDetail = AdjustmentDetail::findOrFail($value->id);
                    $AdjustmentDetail->delete();
                }

            }

            // Update Data with New request
            $newPersistedDetails = [];
            foreach ($new_adjustment_details as $key => $product_detail) {
                if ($product_detail['type'] == 'add') {

                    if ($product_detail['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $request->warehouse_id)
                            ->where('product_id', $product_detail['product_id'])
                            ->where('product_variant_id', $product_detail['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $product_detail['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail_type = Product::where('deleted_at', '=', null)
                            ->where('id', $product_detail['product_id'])
                            ->first();

                        if ($product_detail_type->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $product_detail['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail_type->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $product_detail['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $product_detail['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte -= $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $product_detail['quantity'];
                                $product_warehouse->save();
                            }

                        }
                    }
                } else {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $request->warehouse_id)
                            ->where('product_id', $product_detail['product_id'])
                            ->where('product_variant_id', $product_detail['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte -= $product_detail['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail_type = Product::where('deleted_at', '=', null)
                            ->where('id', $product_detail['product_id'])
                            ->first();

                        if ($product_detail_type->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $product_detail['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail_type->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $product_detail['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $product_detail['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte += $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $product_detail['quantity'];
                                $product_warehouse->save();
                            }

                        }
                    }
                }

                $orderDetails['adjustment_id'] = $id;
                $orderDetails['quantity'] = $product_detail['quantity'];
                $orderDetails['product_id'] = $product_detail['product_id'];
                $orderDetails['product_variant_id'] = $product_detail['product_variant_id'];
                $orderDetails['type'] = $product_detail['type'];

                if (! in_array($product_detail['id'], $old_products_id)) {
                    $persistedDetail = AdjustmentDetail::Create($orderDetails);
                } else {
                    AdjustmentDetail::where('id', $product_detail['id'])->update($orderDetails);
                    $persistedDetail = AdjustmentDetail::find($product_detail['id']);
                }
                $newPersistedDetails[$key] = $persistedDetail;

            }

            // Pharmacy: re-apply per-batch movements now that AdjustmentDetail rows exist.
            if ($batchService->isSupported()) {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($new_adjustment_details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $current_adjustment->warehouse_id = (int) $request['warehouse_id'];
                $batchService->applyForAdjustmentWithAutoFallback(
                    $current_adjustment,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            $current_adjustment->update([
                'warehouse_id' => $request['warehouse_id'],
                'notes' => $request['notes'],
                'date' => $request['date'],
                'items' => $length,
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Adjustement -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Adjustment::class);

        $preload = Adjustment::whereNull('deleted_at')->findOrFail($id);
        if ($preload->inventory_location_id !== null) {
            if ($denied = $this->assertCanModifyDocument($request, $preload)) {
                return $denied;
            }

            return $this->destroyLocationAware($request, $preload);
        }

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_adjustment = Adjustment::findOrFail($id);

            /**
             * Warehouses restriction
             * Allow if:
             * - user has access to all warehouses (is_all_warehouses = 1)
             * - OR sale warehouse_id is in user's assigned warehouses
            */
            $user_auth = auth()->user();

            if (! $user_auth->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                    ->pluck('warehouse_id')
                    ->toArray();

                if (empty($current_adjustment->warehouse_id) || ! in_array($current_adjustment->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_adjustment_details = AdjustmentDetail::where('adjustment_id', $id)->get();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === current_adjustment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_adjustment);
            }

            // Pharmacy: reverse batch movements before warehouse-stock reversal so the
            // per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForAdjustmentDetails($old_adjustment_details);
            }

            // Init Data with old Parametre
            foreach ($old_adjustment_details as $key => $value) {
                if ($value['type'] == 'add') {

                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_adjustment->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte -= $value['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail = Product::where('deleted_at', '=', null)
                            ->where('id', $value['product_id'])
                            ->first();

                        if ($product_detail->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $value['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $value['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_adjustment->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte += $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $value['quantity'];
                                $product_warehouse->save();
                            }

                        }
                    }
                } else {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_adjustment->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }

                    } else {

                        $product_detail = Product::where('deleted_at', '=', null)
                            ->where('id', $value['product_id'])
                            ->first();

                        if ($product_detail->type == 'is_single') {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $value['quantity'];
                                $product_warehouse->save();
                            }
                        } elseif ($product_detail->type == 'is_combo') {

                            $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();

                            foreach ($combined_products as $combined_product) {

                                $qty_combined = $combined_product->quantity * $value['quantity'];

                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_adjustment->warehouse_id)
                                    ->where('product_id', $combined_product->combined_product_id)
                                    ->first();

                                if ($product_warehouse) {
                                    $product_warehouse->qte -= $qty_combined;
                                    $product_warehouse->save();
                                }

                            }

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_adjustment->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $value['quantity'];
                                $product_warehouse->save();
                            }

                        }
                    }
                }

            }
            $current_adjustment->details()->delete();

            $current_adjustment->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true], 200);
    }

    // ================= #81 · Ajustes LOCATION-AWARE =====================
    // El flujo NUEVO exige inventory_location_id; los movimientos viven en
    // inventory_location_stocks / inventory_location_movements vía InventoryService.
    // NUNCA se toca product_warehouse ni BatchService. La rama legacy (abajo,
    // sin inventory_location_id almacenado) sólo aplica a UPDATE/DESTROY de
    // registros históricos.

    /** (#81 · C5) available (physical - reserved) de una fila inventory_location_stocks. */
    private function locationAwareAvailable(int $locationId, int $productId, ?int $variantId): float
    {
        $row = \DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0))
            ->first();

        return $row ? round((float) $row->quantity - (float) $row->reserved_quantity, 3) : 0.0;
    }

    // (#81 · C6) Paridad de autorización histórica antes del branch location-aware:
    // warehouse scope + check_record (record_view). Devuelve una Response 403 o
    // lanza; null si todo OK.
    private function assertCanModifyDocument(Request $request, Adjustment $doc)
    {
        $user = Auth::user();
        if (! $user->is_all_warehouses) {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
            if (empty($doc->warehouse_id) || ! in_array((int) $doc->warehouse_id, $ids, true)) {
                return response()->json(['success' => false, 'message' => 'You are not allowed to access this record (warehouse restriction).'], 403);
            }
        }
        if (! $user->hasRecordView()) {
            $this->authorizeForUser($request->user('api'), 'check_record', $doc);
        }

        return null;
    }

    private function assertWarehouseAccess(int $warehouseId): void
    {
        $user = auth()->user();
        if ($user && $user->is_all_warehouses) {
            return;
        }
        $ids = UserWarehouse::where('user_id', $user->id ?? 0)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
        abort_unless(in_array($warehouseId, $ids, true), 403, 'No tienes acceso a este almacén.');
    }

    // (BLOCKER 6) Este endpoint lo usan Create Y Edit: autoriza create OR update.
    private function authorizeLocationRead(Request $request): void
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', Adjustment::class) || Gate::forUser($u)->allows('update', Adjustment::class)),
            403,
            'No tienes permiso para consultar ubicaciones de inventario de ajustes.'
        );
    }

    /**
     * Ubicaciones de inventario ACTIVAS del almacén — select obligatorio
     * "Ubicación de inventario" de Create/Edit Adjustment. Incluye cuarentena.
     */
    public function inventoryLocationsForWarehouse(Request $request, $warehouseId)
    {
        $this->authorizeLocationRead($request);
        $warehouseId = (int) $warehouseId;
        $this->assertWarehouseAccess($warehouseId);

        $locations = \App\Models\InventoryLocation::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', 1)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_quarantine']);

        $default = \App\Models\Warehouse::whereNull('deleted_at')->whereKey($warehouseId)->value('default_inventory_location_id');
        $defaultEligible = $default && $locations->firstWhere(fn ($l) => (int) $l->id === (int) $default && $l->type === 'storage' && ! $l->is_quarantine);

        return response()->json([
            'locations' => $locations,
            'default_inventory_location_id' => $defaultEligible ? (int) $default : null,
        ]);
    }

    /**
     * (BLOCKER 3) Catálogo de productos con stock POR UBICACIÓN para el flujo
     * location-aware. available = physical - reserved. Incluye productos a 0
     * (Adjustment ADD puede crear existencia desde cero).
     */
    public function inventoryLocationCatalog(Request $request, $locationId)
    {
        $this->authorizeLocationRead($request);
        $locationId = (int) $locationId;

        // (#81 · C9) sólo catálogo de una ubicación ACTIVA y no eliminada.
        $location = \App\Models\InventoryLocation::whereNull('deleted_at')
            ->where('is_active', 1)
            ->whereKey($locationId)
            ->first();
        abort_if(! $location, 404, 'La ubicación de inventario no existe o está inactiva.');
        $this->assertWarehouseAccess((int) $location->warehouse_id);

        return response()->json(app(\App\Services\LocationCatalogReadService::class)->forLocation($locationId));
    }

    private function locationAwareLines(Request $request): array
    {
        return array_values($request->input('details', []));
    }

    private function storeLocationAware(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|integer',
            'inventory_location_id' => 'required|integer',
            'details' => 'required|array|min:1',
        ]);

        $warehouseId = (int) $request->warehouse_id;
        $locationId = (int) $request->inventory_location_id;
        $this->assertWarehouseAccess($warehouseId);
        $svc = app(LocationAwareAdjustmentService::class);
        $lines = $this->locationAwareLines($request);

        \DB::transaction(function () use ($request, $warehouseId, $locationId, $lines, $svc) {
            // (BLOCKER 4) validación write-critical + locks DENTRO de la transacción.
            $validated = $svc->validateAndLock($warehouseId, $locationId, $lines);

            $order = new Adjustment;
            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->warehouse_id = $warehouseId;
            $order->inventory_location_id = $locationId;
            $order->notes = $request->notes;
            $order->items = count($validated['lines']);
            $order->user_id = Auth::id();
            $order->save();

            $withDetailIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create([
                    'adjustment_id' => $order->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                    'type' => $ln['type'],
                ]);
                $withDetailIds[] = $ln + ['detail_id' => $d->id];
            }

            // (BLOCKER 2) snapshot EXACTO ya expandido -> se persiste y se aplica ESE.
            $snapshot = $svc->buildSnapshot($withDetailIds);
            $order->update(['inventory_effect_snapshot' => $snapshot]);
            $svc->applySnapshot($snapshot, $order->id, $warehouseId, $locationId, 'create');
        }, 10);

        return response()->json(['success' => true]);
    }

    private function updateLocationAware(Request $request, Adjustment $current)
    {
        $request->validate([
            'warehouse_id' => 'required|integer',
            'inventory_location_id' => 'required|integer',
            'details' => 'required|array|min:1',
        ]);

        $newWarehouseId = (int) $request->warehouse_id;
        $newLocationId = (int) $request->inventory_location_id;
        $this->assertWarehouseAccess((int) $current->warehouse_id);
        $this->assertWarehouseAccess($newWarehouseId);
        $svc = app(LocationAwareAdjustmentService::class);
        $lines = $this->locationAwareLines($request);

        \DB::transaction(function () use ($request, $current, $newWarehouseId, $newLocationId, $lines, $svc) {
            // (BLOCKER 5) el documento se relee y bloquea DENTRO de la transacción.
            $locked = Adjustment::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['adjustment' => 'Registro legacy: usa la ruta histórica.']);
            }
            // (BLOCKER 2.3) FAIL CLOSED si no hay snapshot.
            $oldSnapshot = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            // (D6) el snapshot VIEJO debe ser artifact-safe ANTES de cualquier reversa,
            // aunque el producto ya no aparezca en el request nuevo.
            $svc->assertSnapshotArtifactSafeAndLock($oldSnapshot);
            AdjustmentDetail::where('adjustment_id', $locked->id)->lockForUpdate()->get();

            $extra = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $oldSnapshot)));
            $validated = $svc->validateAndLock($newWarehouseId, $newLocationId, $lines, $extra);

            // 1) revertir EXACTAMENTE el snapshot viejo (ubicación/warehouse ORIGINALES).
            $svc->reverseSnapshot($oldSnapshot, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'update');

            // 2) reemplazar detalles.
            AdjustmentDetail::where('adjustment_id', $locked->id)->delete();
            $withDetailIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = AdjustmentDetail::create([
                    'adjustment_id' => $locked->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                    'type' => $ln['type'],
                ]);
                $withDetailIds[] = $ln + ['detail_id' => $d->id];
            }

            // 3) construir + aplicar el snapshot NUEVO (composición vigente).
            $newSnapshot = $svc->buildSnapshot($withDetailIds);
            $svc->applySnapshot($newSnapshot, $locked->id, $newWarehouseId, $newLocationId, 'update');

            $locked->update([
                'warehouse_id' => $newWarehouseId,
                'inventory_location_id' => $newLocationId,
                'inventory_effect_snapshot' => $newSnapshot,
                'notes' => $request->notes,
                'date' => $request->date,
                'items' => count($validated['lines']),
            ]);
        }, 10);

        return response()->json(['success' => true]);
    }

    private function destroyLocationAware(Request $request, Adjustment $current)
    {
        $this->assertWarehouseAccess((int) $current->warehouse_id);
        $svc = app(LocationAwareAdjustmentService::class);

        \DB::transaction(function () use ($current, $svc) {
            $locked = Adjustment::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['adjustment' => 'Registro legacy: usa la ruta histórica.']);
            }
            $snapshot = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            // (D7) artifact-safe guard ANTES de bloquear details / revertir.
            $svc->assertSnapshotArtifactSafeAndLock($snapshot);
            AdjustmentDetail::where('adjustment_id', $locked->id)->lockForUpdate()->get();

            $svc->reverseSnapshot($snapshot, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'destroy');
            $locked->details()->delete();
            $locked->update(['deleted_at' => Carbon::now()]);
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // ------------ Reference Number of Adjustement  -----------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'AD' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->adjustment_prefix) ? $setting->adjustment_prefix : 'AD';
        
        // Get the last adjustment with a reference that starts with the prefix
        $last = DB::table('adjustments')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            
            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;

    }

    // ------ batches_for_adjustment ---------------\\
    //
    // Mirrors SalesController@batches_for_sale but authorizes against the
    // Adjustment policy so adjustment users (without Sales_create) can use
    // the picker.

    public function batches_for_adjustment(Request $request, $product_id, $warehouse_id, $variant_id = null)
    {
        $this->authorizeForUser($request->user('api'), 'create', Adjustment::class);

        $productId = (int) $product_id;
        $warehouseId = (int) $warehouse_id;
        $variantId = ($variant_id !== null && $variant_id !== '' && $variant_id !== 'null' && (int) $variant_id > 0)
            ? (int) $variant_id
            : null;

        $batchService = app(BatchService::class);

        return response()->json([
            'supported' => $batchService->isSupported(),
            'batches' => $batchService->availableBatchesForSale($productId, $variantId, $warehouseId),
        ]);
    }

    // ---------------- Show Form Create Adjustment ---------------\\

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Adjustment::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json(['warehouses' => $warehouses]);
    }

    // -------------Show Form Edit Adjustment-----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Adjustment::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Adjustment_data = Adjustment::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        /**
         * Warehouses restriction
         * Allow if:
         * - user has access to all warehouses (is_all_warehouses = 1)
         * - OR sale warehouse_id is in user's assigned warehouses
        */
        $user_auth = auth()->user();

        if (! $user_auth->is_all_warehouses) {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                ->pluck('warehouse_id')
                ->toArray();

            if (empty($Adjustment_data->warehouse_id) || ! in_array($Adjustment_data->warehouse_id, $warehouses_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this sale (warehouse restriction).',
                ], 403);
            }
        }

        $details = [];
        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Adjustment->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Adjustment_data);
        }

        if ($Adjustment_data->warehouse_id) {
            if (Warehouse::where('id', $Adjustment_data->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $adjustment['warehouse_id'] = $Adjustment_data->warehouse_id;
            } else {
                $adjustment['warehouse_id'] = '';
            }
        } else {
            $adjustment['warehouse_id'] = '';
        }

        $adjustment['notes'] = $Adjustment_data->notes;
        $adjustment['date'] = $Adjustment_data->date;
        // (#81 · C1) NULL => histórico legacy; NOT NULL => location-aware.
        $adjustment['inventory_location_id'] = $Adjustment_data->inventory_location_id !== null
            ? (int) $Adjustment_data->inventory_location_id
            : null;
        $locationAware = $adjustment['inventory_location_id'] !== null;

        $batchesByDetail = app(BatchService::class)->batchesForAdjustmentDetails($Adjustment_data['details']);

        $detail_id = 0;
        foreach ($Adjustment_data['details'] as $detail) {

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Adjustment_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['product_variant_id'] = $detail->product_variant_id;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                // (#81 · C5) location-aware => current = available de la ubicación
                // del documento; legacy => product_warehouse.qte.
                $data['current'] = $locationAware
                    ? $this->locationAwareAvailable((int) $adjustment['inventory_location_id'], (int) $detail->product_id, $detail->product_variant_id ? (int) $detail->product_variant_id : null)
                    : ($item_product ? $item_product->qte : 0);
                $data['type'] = $detail->type;
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = ($locationAware || $item_product) ? 0 : 1;

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $Adjustment_data->warehouse_id)
                    ->where('product_variant_id', '=', null)
                    ->first();

                $data['id'] = $detail->id;
                $data['detail_id'] = $detail_id += 1;
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['current'] = $locationAware
                    ? $this->locationAwareAvailable((int) $adjustment['inventory_location_id'], (int) $detail->product_id, null)
                    : ($item_product ? $item_product->qte : 0);
                $data['type'] = $detail->type;
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = ($locationAware || $item_product) ? 0 : 1;
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'details' => $details,
            'adjustment' => $adjustment,
            'warehouses' => $warehouses,
        ]);
    }

    // ---------------- Get Details Adjustment-----------------\\

    public function Adjustment_detail(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Adjustment::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Adjustment_data = Adjustment::with('details.product.unit', 'warehouse')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = [];
        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Adjustment->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Adjustment_data);
        }

        $batchesByDetail = app(BatchService::class)->batchesForAdjustmentDetails($Adjustment_data['details']);

        $createdBy = User::where('id', $Adjustment_data->user_id)->value('username');

        $Adjustment['Ref'] = $Adjustment_data->Ref;
        $Adjustment['date'] = $Adjustment_data->date.' '.$Adjustment_data->time;
        $Adjustment['note'] = $Adjustment_data->notes;
        $Adjustment['warehouse'] = $Adjustment_data['warehouse']->name;
        $Adjustment['items'] = (int) ($Adjustment_data->items ?? count($Adjustment_data['details']));
        $Adjustment['created_by'] = $createdBy ?? '';
        $Adjustment['created_at'] = $Adjustment_data->created_at ? $Adjustment_data->created_at->format('Y-m-d H:i:s') : null;

        foreach ($Adjustment_data['details'] as $detail) {
            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = $detail->quantity;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['type'] = $detail->type;

            } else {

                $data['quantity'] = $detail->quantity;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['type'] = $detail->type;
                $data['unit'] = $detail['product']['unit']->ShortName;
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'adjustment' => $Adjustment,
        ]);
    }

    // -------------- adjustment_pdf -----------\\

    public function adjustment_pdf(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $adjustment_data = Adjustment::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $batchesByDetail = app(BatchService::class)->batchesForAdjustmentDetails($adjustment_data['details']);

        $adjustment['warehouse_name'] = $adjustment_data['warehouse']->name;
        $adjustment['Ref'] = $adjustment_data->Ref;
        $adjustment['date'] = $adjustment_data->date.' '.$adjustment_data->time;

        $detail_id = 0;
        foreach ($adjustment_data['details'] as $detail) {

            $data['detail_id'] = $detail_id += 1;

            $unitCost = (float) ($detail['product']['cost'] ?? 0);
            $unitPrice = (float) ($detail['product']['price'] ?? 0);

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = $detail->type == 'add' ? '+'.' '.number_format($detail->quantity, 2, '.', '') : '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;

                if ($productsVariants) {
                    $unitCost = (float) ($productsVariants->cost ?? $unitCost);
                    $unitPrice = (float) ($productsVariants->price ?? $unitPrice);
                }

            } else {

                $data['quantity'] = $detail->type == 'add' ? '+'.' '.number_format($detail->quantity, 2, '.', '') : '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
            }

            $qty = (float) $detail->quantity;
            $data['purchase_cost'] = $unitCost * $qty;
            $data['sale_price'] = $unitPrice * $qty;

            $data['type'] = $detail->type;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.adjustment_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'adjustment' => $adjustment,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Adjustment.pdf');

    }
}
