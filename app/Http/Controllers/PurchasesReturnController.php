<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode;
use App\Models\Account;
use App\Models\InventoryLocation;
use App\Models\InventoryTransitionState;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchaseReturns;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetailBatch;
use App\Models\PurchaseReturnDetails;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\InventoryLocationScopeService;
use App\Services\LocationAwarePurchaseBatchPlanner;
use App\Services\LocationAwarePurchaseStockService;
use App\Services\SerialNumberService;
use App\Services\WarehouseInventoryModeResolver;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PDF;

class PurchasesReturnController extends BaseController
{
    use GuardsPurchaseTransitionMode;

    // ------------ GET ALL Purchases Return  --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PurchaseReturn::class);
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
        $param = [
            0 => 'like',
            1 => 'like',
            2 => '=',
            3 => 'like',
            4 => '=',
            5 => '=',
            6 => '=',
        ];
        $columns = [
            0 => 'Ref',
            1 => 'statut',
            2 => 'provider_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
            6 => 'purchase_id',
        ];
        $data = [];

        // Check If User Has Permission View  All Records
        $PurchaseReturn = PurchaseReturn::with('purchase', 'facture', 'provider', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        if (! $is_all_warehouses) {
            $PurchaseReturn->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($PurchaseReturn, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('provider', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('purchase', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
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
        $PurchaseReturn = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($PurchaseReturn as $Purchase_Return) {

            $item['id'] = $Purchase_Return->id;
            $item['date'] = $Purchase_Return['date'].' '.$Purchase_Return['time'];
            $item['Ref'] = $Purchase_Return->Ref;
            $item['discount'] = $Purchase_Return->discount;
            $item['shipping'] = $Purchase_Return->shipping;
            $item['statut'] = $Purchase_Return->statut;
            $item['purchase_ref'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->Ref : '---';
            $item['purchase_id'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->id : null;
            $item['warehouse_name'] = $Purchase_Return['warehouse']->name;
            $item['provider_id'] = $Purchase_Return['provider']->id;
            $item['provider_name'] = $Purchase_Return['provider']->name;
            $item['provider_email'] = $Purchase_Return['provider']->email;
            $item['provider_tele'] = $Purchase_Return['provider']->phone;
            $item['provider_code'] = $Purchase_Return['provider']->code;
            $item['provider_adr'] = $Purchase_Return['provider']->adresse;
            $item['GrandTotal'] = number_format($Purchase_Return['GrandTotal'], helpers::price_decimals(), '.', '');
            $item['paid_amount'] = number_format($Purchase_Return['paid_amount'], helpers::price_decimals(), '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], helpers::price_decimals(), '.', '');
            $item['payment_status'] = $Purchase_Return['payment_statut'];

            $data[] = $item;
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $purchases = Purchase::where('deleted_at', '=', null)->get(['id', 'Ref']);
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);
        $payment_methods = PaymentMethod::whereNull('deleted_at')->get(['id', 'name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'totalRows' => $totalRows,
            'purchase_returns' => $data,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'purchases' => $purchases,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);

    }

    // ------------ Store New Purchase Return  --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PurchaseReturn::class);

        // MS3 — a CREATE for a location_primary warehouse goes to the
        // location-native engine; every other mode keeps the legacy flow below.
        if (app(WarehouseInventoryModeResolver::class)->isLocationPrimary((int) $request->warehouse_id)) {
            return $this->storeLocationAware($request);
        }

        \DB::transaction(function () use ($request) {
            // MS3 boundary — a location_primary warehouse must NEVER take a
            // legacy-only stock mutation (closes the router race).
            $this->assertLegacyPurchaseTransitionSafe(null, (int) $request->warehouse_id);

            $order = new PurchaseReturn;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->purchase_id = $request->purchase_id;
            $order->provider_id = $request->supplier_id;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->GrandTotal = $request->GrandTotal;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $data = $request['details'];
            $persistedDetails = [];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                $persistedDetails[$key] = PurchaseReturnDetails::create([
                    'purchase_return_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'cost' => $value['Unit_cost'],
                    'purchase_unit_id' => $value['purchase_unit_id'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $value['subtotal'],
                    'imei_number' => $value['imei_number'],
                ]);

                if ($order->statut == 'completed') {
                    if ($value['product_variant_id'] !== null) {

                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                            }

                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $value['quantity'] * $unit->operator_value;
                            }

                            $product_warehouse->save();
                        }
                    }
                }

            }

            // Pharmacy: consume batches when the return is completed (mirror sale flow).
            if ($order->statut == 'completed') {
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->applyForPurchaseReturnWithAutoFallback(
                        $order,
                        array_values($data),
                        $persistedDetails
                    );
                }
            }

            // Serial / IMEI: mark selected serials as returned to supplier.
            if ($order->statut == 'completed') {
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->applyForPurchaseReturn($order, $data, $persistedDetails);
                }
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Update Purchase Return --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PurchaseReturn::class);

        // MS3 — route by the PERSISTED document identity (not the warehouse's
        // current mode). A location-native return stays location-native; a
        // legacy return stays legacy but FAILS CLOSED if its warehouse (or the
        // requested one) is now location_primary.
        $routing_return = PurchaseReturn::find($id);
        if ($routing_return && $routing_return->inventory_location_id !== null) {
            return $this->updateLocationAware($request, $routing_return);
        }

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_PurchaseReturn = PurchaseReturn::findOrFail($id);

            // MS3 boundary — legacy return editable only while NEITHER the stored
            // nor the requested warehouse is location_primary.
            $this->assertLegacyPurchaseTransitionSafe((int) $current_PurchaseReturn->warehouse_id, (int) $request->warehouse_id);

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

                if (empty($current_PurchaseReturn->warehouse_id) || ! in_array($current_PurchaseReturn->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === PurchaseReturn->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_PurchaseReturn);
            }

            $old_Return_Details = PurchaseReturnDetails::where('purchase_return_id', $id)->get();
            $New_Return_Details = $request['details'];
            $length = count($New_Return_Details);

            // Get Ids details
            $new_products_id = [];
            foreach ($New_Return_Details as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // Pharmacy: reverse old batch consumption (restore qty back to product_batches)
            // before we touch warehouse stock so the per-batch ledger stays consistent.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $current_PurchaseReturn->statut == 'completed') {
                $batchService->reverseForPurchaseReturnDetails($old_Return_Details);
            }

            // Init Data with old Parametre
            $old_products_id = [];
            foreach ($old_Return_Details as $key => $value) {
                $old_products_id[] = $value->id;

                // check if detail has purchase_unit_id Or Null
                if ($value['purchase_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $value['product_id'])
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                if ($value['purchase_unit_id'] !== null) {
                    if ($current_PurchaseReturn->statut == 'completed') {
                        if ($value['product_variant_id'] !== null) {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                    // Delete Detail
                    if (! in_array($old_products_id[$key], $new_products_id)) {
                        $PurchaseReturnDetails = PurchaseReturnDetails::findOrFail($value->id);
                        $PurchaseReturnDetails->delete();
                    }
                }

            }

            // Update Data with New request
            $newPersistedDetails = [];
            foreach ($New_Return_Details as $key => $product_detail) {

                if ($product_detail['no_unit'] !== 0) {
                    $unit_prod = Unit::where('id', $product_detail['purchase_unit_id'])->first();

                    if ($request->statut == 'completed') {
                        if ($product_detail['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->where('product_variant_id', $product_detail['product_variant_id'])
                                ->first();

                            if ($unit_prod && $product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte -= $product_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte -= $product_detail['quantity'] * $unit_prod->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->first();

                            if ($unit_prod && $product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte -= $product_detail['quantity'] / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte -= $product_detail['quantity'] * $unit_prod->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                    $orderDetails['purchase_return_id'] = $id;
                    $orderDetails['cost'] = $product_detail['Unit_cost'];
                    $orderDetails['purchase_unit_id'] = $product_detail['purchase_unit_id'];
                    $orderDetails['TaxNet'] = $product_detail['tax_percent'];
                    $orderDetails['tax_method'] = $product_detail['tax_method'];
                    $orderDetails['discount'] = $product_detail['discount'];
                    $orderDetails['discount_method'] = $product_detail['discount_Method'];
                    $orderDetails['quantity'] = $product_detail['quantity'];
                    $orderDetails['product_id'] = $product_detail['product_id'];
                    $orderDetails['product_variant_id'] = $product_detail['product_variant_id'];
                    $orderDetails['total'] = $product_detail['subtotal'];
                    $orderDetails['imei_number'] = $product_detail['imei_number'];

                    if (! in_array($product_detail['id'], $old_products_id)) {
                        $persistedDetail = PurchaseReturnDetails::Create($orderDetails);
                    } else {
                        PurchaseReturnDetails::where('id', $product_detail['id'])->update($orderDetails);
                        $persistedDetail = PurchaseReturnDetails::find($product_detail['id']);
                    }
                    $newPersistedDetails[$key] = $persistedDetail;
                }

            }

            // Pharmacy: re-apply batch consumption now that PurchaseReturnDetails rows exist.
            // We must keep the input row + persisted detail pairs in lockstep, because rows
            // with no_unit === 0 are skipped above and would otherwise misalign indices when
            // BatchService re-keys via `collect()->values()`.
            if ($batchService->isSupported() && $request['statut'] == 'completed') {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($New_Return_Details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $current_PurchaseReturn->warehouse_id = (int) $request->warehouse_id;
                $batchService->applyForPurchaseReturnWithAutoFallback(
                    $current_PurchaseReturn,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            $due = $request['GrandTotal'] - $current_PurchaseReturn->paid_amount;
            if ($due === 0.0 || $due < 0.0) {
                $payment_statut = 'paid';
            } elseif ($due != $request['GrandTotal']) {
                $payment_statut = 'partial';
            } elseif ($due == $request['GrandTotal']) {
                $payment_statut = 'unpaid';
            }

            $current_PurchaseReturn->update([
                'date' => $request['date'],
                'notes' => $request['notes'],
                'statut' => $request['statut'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $request['TaxNet'],
                'discount' => $request['discount'],
                'shipping' => $request['shipping'],
                'GrandTotal' => $request['GrandTotal'],
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Purchase Return  --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PurchaseReturn::class);

        // MS3 — route by persisted identity (see update()).
        $routing_return = PurchaseReturn::find($id);
        if ($routing_return && $routing_return->inventory_location_id !== null) {
            return $this->destroyLocationAware($request, $routing_return);
        }

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_PurchaseReturn = PurchaseReturn::findOrFail($id);

            // MS3 boundary — a legacy return in a location_primary warehouse must
            // NOT be torn down through the product_warehouse writer.
            $this->assertLegacyPurchaseTransitionSafe((int) $current_PurchaseReturn->warehouse_id, null);

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

                if (empty($current_PurchaseReturn->warehouse_id) || ! in_array($current_PurchaseReturn->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_Return_Details = PurchaseReturnDetails::where('purchase_return_id', $id)->get();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === PurchaseReturn->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_PurchaseReturn);
            }

            // Pharmacy: restore batches before deleting the return so the per-batch ledger
            // mirrors the warehouse stock restore that follows.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $current_PurchaseReturn->statut == 'completed') {
                $batchService->reverseForPurchaseReturnDetails($old_Return_Details);
            }

            // Serial / IMEI: bring serials returned to supplier back to available.
            if ($current_PurchaseReturn->statut == 'completed') {
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->reverseForPurchaseReturn($current_PurchaseReturn);
                }
            }

            foreach ($old_Return_Details as $key => $value) {

                // check if detail has purchase_unit_id Or Null
                if ($value['purchase_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $value['product_id'])
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                if ($current_PurchaseReturn->statut == 'completed') {
                    if ($value['product_variant_id'] !== null) {

                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }

            }
            $current_PurchaseReturn->details()->delete();
            $current_PurchaseReturn->update([
                'deleted_at' => Carbon::now(),
            ]);

            // get all payments
            $payments = PaymentPurchaseReturns::where('purchase_return_id', $id)->get();

            foreach ($payments as $payment) {

                $account = Account::find($payment->account_id);

                if ($account) {
                    $account->update([
                        'balance' => $account->balance - $payment->montant,
                    ]);
                }

            }

            PaymentPurchaseReturns::where('purchase_return_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', PurchaseReturn::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;

            // MS3 — lock EVERY involved transition state up front, ascending by
            // warehouse_id (see PurchasesController::delete_by_selection). The
            // per-row guards below then only assert.
            app(WarehouseInventoryModeResolver::class)->lockStates(
                PurchaseReturn::whereIn('id', (array) $selectedIds)->pluck('warehouse_id')->all()
            );

            foreach ($selectedIds as $PurchaseReturn_id) {
                $current_PurchaseReturn = PurchaseReturn::findOrFail($PurchaseReturn_id);

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

                    if (empty($current_PurchaseReturn->warehouse_id) || ! in_array($current_PurchaseReturn->warehouse_id, $warehouses_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'You are not allowed to access this sale (warehouse restriction).',
                        ], 403);
                    }
                }

                $old_Return_Details = PurchaseReturnDetails::where('purchase_return_id', $PurchaseReturn_id)->get();

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === current_PurchaseReturn->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_PurchaseReturn);
                }

                // MS3 — mixed selection: each return reverses by ITS OWN
                // identity, and each is fenced against a transition-mode
                // mismatch. A throw aborts the WHOLE \DB::transaction => zero
                // partial deletes, zero partial stock.
                if ($current_PurchaseReturn->inventory_location_id !== null) {
                    $this->assertLocationNativePurchaseTransitionSafe((int) $current_PurchaseReturn->warehouse_id, null);
                    // Physical reverse from the SNAPSHOT (batch receiveMany +
                    // general increase) — FAILS CLOSED if a slice drifted.
                    $this->reverseLocationNativePurchaseReturnStock($current_PurchaseReturn);
                    $nativeDetailIds = $old_Return_Details->pluck('id')->all();
                    if ($nativeDetailIds && Schema::hasTable('purchase_return_detail_batches')) {
                        PurchaseReturnDetailBatch::whereIn('purchase_return_detail_id', $nativeDetailIds)->delete();
                    }
                } else {

                $this->assertLegacyPurchaseTransitionSafe((int) $current_PurchaseReturn->warehouse_id, null);

                // Pharmacy: restore batches before deleting so the per-batch ledger
                // mirrors the warehouse stock restore that follows.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported() && $current_PurchaseReturn->statut == 'completed') {
                    $batchService->reverseForPurchaseReturnDetails($old_Return_Details);
                }

                // Serial / IMEI: bring serials returned to supplier back to available.
                if ($current_PurchaseReturn->statut == 'completed') {
                    $serialService = app(SerialNumberService::class);
                    if ($serialService->isSupported()) {
                        $serialService->reverseForPurchaseReturn($current_PurchaseReturn);
                    }
                }

                foreach ($old_Return_Details as $key => $value) {

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($current_PurchaseReturn->statut == 'completed') {
                        if ($value['product_variant_id'] !== null) {

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_PurchaseReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                }

                } // end legacy reversal branch

                $current_PurchaseReturn->details()->delete();
                $current_PurchaseReturn->update([
                    'deleted_at' => Carbon::now(),
                ]);

                $Payment_purchase_return_data = PaymentPurchaseReturns::where('purchase_return_id', $PurchaseReturn_id)->get();
                foreach ($Payment_purchase_return_data as $Payment_return) {
                    $account = Account::find($Payment_return->account_id);

                    if ($account) {
                        $account->update([
                            'balance' => $account->balance - $Payment_return->montant,
                        ]);
                    }

                    $Payment_return->delete();
                }

            }

        }, 10);

        return response()->json(['success' => true]);

    }

    // =====================================================================
    // MS3 — Purchase Returns location-native (warehouses in MODE_LOCATION_PRIMARY).
    //
    // Physical semantics: PurchaseReturn = location -> supplier => NEGATIVE
    // delta. apply = InventoryService::decrease (fails on insufficient stock,
    // NEVER clamps), reverse = increase. reference_type PurchaseReturn /
    // PurchaseReturnReversal. Applied effect exists ONLY when statut ==
    // 'completed' (Purchases use 'received' — the strings are NOT unified).
    //
    // Scope: is_single / is_variant, manual returns only, INCLUDING
    // is_batch_tracked (MS5-D). Import stays legacy (MS5-E). serial/IMEI (MS6)
    // stay legacy — IMEI fails closed in the service. NO legacy product_warehouse
    // / BatchService / SerialNumberService writes on this path. The document
    // identity (inventory_location_id NOT NULL) governs update/destroy. Same
    // transition-mode boundary guards as Purchases (shared trait).
    //
    // batch (MS5-D): validateAndLock(allow_batch=true) -> for a COMPLETED return
    // LocationAwarePurchaseBatchPlanner::planPurchaseReturnIssue freezes a
    // document-wide per-line batch_allocation (explicit selection reserves
    // first, then FEFO over the LOCKED per-location slices) -> buildSnapshot
    // embeds it -> applySnapshot runs BatchLocationService::issueMany BEFORE
    // InventoryService::decrease (and receiveMany BEFORE increase on a reverse).
    // The snapshot is the ONLY source of a reverse; purchase_return_detail_batches
    // are secondary UX/reporting (pivot.qty = the entered PURCHASE-unit quantity,
    // never used for the physical reverse). A PENDING return creates NO batch
    // artifact at all, even if the request carried `batches`.
    //
    // TRANSITIONAL DIFFERENCE (documented): a NEW return may be location-native
    // even when it is linked to a LEGACY Purchase whose warehouse is now
    // location_primary — because the user is stating NOW the concrete physical
    // location the goods leave from. We are NOT reconstructing the Purchase's
    // historical location. purchase_return.purchase_id stays nullable and is a
    // business relation only; the snapshot / reference_id use the RETURN id.
    // =====================================================================

    private function locationAwareReturnLines(Request $request): array
    {
        return array_map(fn ($v) => [
            'product_id' => $v['product_id'] ?? null,
            'product_variant_id' => (isset($v['product_variant_id']) && $v['product_variant_id'] !== '') ? $v['product_variant_id'] : null,
            'quantity' => $v['quantity'] ?? 0,
            'purchase_unit_id' => $v['purchase_unit_id'] ?? null,
        ], array_values($request['details'] ?? []));
    }

    /** Create PurchaseReturnDetails one by one -> [lineIndex => id]. */
    private function persistLocationAwareReturnDetails(int $returnId, array $rawLines): array
    {
        $ids = [];
        foreach (array_values($rawLines) as $i => $value) {
            $d = PurchaseReturnDetails::create([
                'purchase_return_id' => $returnId,
                'quantity' => $value['quantity'],
                'cost' => $value['Unit_cost'] ?? 0,
                'purchase_unit_id' => $value['purchase_unit_id'] ?? null,
                'TaxNet' => $value['tax_percent'] ?? 0,
                'tax_method' => $value['tax_method'] ?? '1',
                'discount' => $value['discount'] ?? 0,
                'discount_method' => $value['discount_Method'] ?? '2',
                'product_id' => $value['product_id'],
                'product_variant_id' => (isset($value['product_variant_id']) && $value['product_variant_id'] !== '') ? $value['product_variant_id'] : null,
                'total' => $value['subtotal'] ?? 0,
                'imei_number' => $value['imei_number'] ?? null,
            ]);
            $ids[$i] = $d->id;
        }

        return $ids;
    }

    private function withReturnSourceDetailIds(array $validatedLines, array $detailIds): array
    {
        $out = [];
        foreach (array_values($validatedLines) as $i => $ln) {
            $out[] = ['source_detail_id' => $detailIds[$i] ?? null] + $ln;
        }

        return $out;
    }

    /**
     * MS5-D — run the batch planner for a COMPLETED location-native return and
     * fold its document-wide per-line batch_allocation into the validated
     * lines. A no-op for a cart with no batch-tracked line (returns the lines
     * with batch_allocation => []). MUST run inside the document transaction.
     * Returns only CONSUME existing batches — no identity is created — so the
     * planner context stays empty.
     */
    private function planLocationAwarePurchaseReturnBatches(int $warehouseId, int $locationId, array $validatedLines, array $rawLines): array
    {
        return app(LocationAwarePurchaseBatchPlanner::class)->planPurchaseReturnIssue(
            $warehouseId,
            $locationId,
            $validatedLines,
            $rawLines,
            []
        );
    }

    /**
     * MS5-D — persist one purchase_return_detail_batches pivot per snapshot
     * batch allocation. pivot.qty keeps the COMMERCIAL (purchase-unit) quantity
     * the user entered (quantity_input); the physical BASE quantity lives only
     * in the snapshot. NOT used for the physical reverse. FEFO entries carry a
     * NULL quantity_input, so we fall back to quantity_base for those.
     *
     * @param  array  $plannedLines  validated lines enriched by the planner + withReturnSourceDetailIds()
     */
    private function persistLocationAwarePurchaseReturnDetailBatches(array $plannedLines): void
    {
        foreach ($plannedLines as $line) {
            $detailId = $line['source_detail_id'] ?? null;
            if (! $detailId || empty($line['batch_allocation'])) {
                continue;
            }
            $operator = (string) ($line['unit_operator'] ?? '*');
            $operatorValue = (float) ($line['unit_operator_value'] ?? 1.0);
            foreach ($line['batch_allocation'] as $a) {
                // Explicit selection carries the entered PURCHASE-unit qty;
                // a FEFO entry has none -> derive it from the BASE qty with the
                // inverse of the line's unit rule ('/' => base*value ; '*' =>
                // base/value) so the pivot always speaks the commercial unit.
                if ($a['quantity_input'] !== null) {
                    $qty = (float) $a['quantity_input'];
                } elseif ($operatorValue > 0) {
                    $qty = $operator === '/'
                        ? (float) $a['quantity_base'] * $operatorValue
                        : (float) $a['quantity_base'] / $operatorValue;
                } else {
                    $qty = (float) $a['quantity_base'];
                }
                PurchaseReturnDetailBatch::create([
                    'purchase_return_detail_id' => (int) $detailId,
                    'product_batch_id' => (int) $a['product_batch_id'],
                    'qty' => $qty,
                    'unit_cost' => $a['unit_cost'] !== null ? (float) $a['unit_cost'] : null,
                ]);
            }
        }
    }

    private function returnPaymentStatutFor(float $grandTotal, float $paidAmount): string
    {
        $due = $grandTotal - $paidAmount;
        if ($due <= 0.0) {
            return 'paid';
        }

        return $due != $grandTotal ? 'partial' : 'unpaid';
    }

    // --------- CREATE (location-native) ---------------------------------\\

    private function storeLocationAware(Request $request)
    {
        $svc = app(LocationAwarePurchaseStockService::class);
        $warehouseId = (int) $request->warehouse_id;
        $statut = $request->statut;

        \DB::transaction(function () use ($request, $svc, $warehouseId, $statut) {
            // FAIL CLOSED — location_primary must be healthy; locks the state first.
            $this->assertLocationNativePurchaseTransitionSafe($warehouseId, null);

            request()->validate(['inventory_location_id' => 'required|integer']);
            $locationId = (int) $request->inventory_location_id;
            $rawLines = array_values($request['details'] ?? []);

            // Validate + lock ALWAYS (pending included): a location_primary
            // return can only be saved with a valid location + valid lines.
            // allow_batch=true — a batch-tracked line is marked requires_batch
            // (the planner runs only for a COMPLETED return); IMEI still 422.
            $validated = $svc->validateAndLock(
                LocationAwarePurchaseStockService::DOC_PURCHASE_RETURN,
                $warehouseId,
                $locationId,
                $this->locationAwareReturnLines($request),
                [],
                ['allow_batch' => true]
            );

            $order = new PurchaseReturn;
            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->purchase_id = $request->purchase_id ?: null;
            $order->provider_id = $request->supplier_id;
            $order->warehouse_id = $warehouseId;
            $order->inventory_location_id = $locationId;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $statut;
            $order->GrandTotal = $request->GrandTotal;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            $order->save();

            $detailIds = $this->persistLocationAwareReturnDetails($order->id, $rawLines);

            // Physical effect ONLY for a completed return. Otherwise: location +
            // header + details, NO snapshot, NO batch artifact, NO movements —
            // the planner NEVER runs for pending, even if the request carried
            // `batches`.
            if ($statut === 'completed') {
                $planned = $this->planLocationAwarePurchaseReturnBatches(
                    $warehouseId, $locationId, $validated['lines'], $rawLines
                );
                $validated['lines'] = $this->withReturnSourceDetailIds($planned, $detailIds);
                $snapshot = $svc->buildSnapshot($validated, 1);
                $order->update(['inventory_effect_snapshot' => $snapshot]);
                // decrease()/issueMany() reject available < quantity_base -> whole tx rolls back.
                $svc->applySnapshot($snapshot, $order->id);
                $this->persistLocationAwarePurchaseReturnDetailBatches($validated['lines']);
            }
        }, 10);

        return response()->json(['success' => true]);
    }

    // --------- UPDATE (location-native) — state machine A/B/C/D/E -------\\

    private function updateLocationAware(Request $request, PurchaseReturn $current)
    {
        $svc = app(LocationAwarePurchaseStockService::class);
        $newWarehouseId = (int) $request->warehouse_id;
        $newStatut = $request->statut;

        $response = \DB::transaction(function () use ($request, $current, $svc, $newWarehouseId, $newStatut) {
            $user = Auth::user();
            $view_records = $user->hasRecordView();

            if (! $user->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
                if (empty($current->warehouse_id) || ! in_array($current->warehouse_id, $warehouses_id)) {
                    return response()->json(['success' => false, 'message' => 'You are not allowed to access this sale (warehouse restriction).'], 403);
                }
            }
            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $current);
            }

            $locked = PurchaseReturn::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['purchase_return' => 'Registro legacy: usa la ruta histórica.']);
            }

            // BOTH the stored and target warehouse must stay healthy location_primary.
            $this->assertLocationNativePurchaseTransitionSafe((int) $locked->warehouse_id, $newWarehouseId);
            $newLocationId = $request->filled('inventory_location_id') ? (int) $request->inventory_location_id : null;
            if (! $newLocationId) {
                throw ValidationException::withMessages(['inventory_location_id' => 'Debes seleccionar una ubicación de inventario.']);
            }

            $oldStatut = $locked->statut;
            $oldSnapshotRaw = $locked->inventory_effect_snapshot;
            $hasHistoricalSnapshot = ! empty($oldSnapshotRaw);
            $hadActiveEffect = ($oldStatut === 'completed') && $hasHistoricalSnapshot;

            $rawLines = array_values($request['details'] ?? []);

            $oldRevision = 0;
            if ($hasHistoricalSnapshot) {
                $oldRevision = $svc->normalizeSnapshot($oldSnapshotRaw)['revision'];
            }

            // (C, D) reverse the currently-applied effect (returns stock to the
            // OLD location — the snapshot carries old wh/location/effects).
            if ($hadActiveEffect) {
                $oldSnapshot = $svc->normalizeSnapshot($oldSnapshotRaw);
                // allow_batch=true so a now-batch product with a batch_allocation
                // reverses via receiveMany (old wh/location/effects live inside).
                $svc->assertSnapshotArtifactSafeAndLock($oldSnapshot, ['allow_batch' => true]);
                $svc->reverseSnapshot($oldSnapshot, $locked->id);
            }

            $extra = [];
            if ($hasHistoricalSnapshot) {
                $extra = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $svc->normalizeSnapshot($oldSnapshotRaw)['effects'])));
            }
            $validated = $svc->validateAndLock(
                LocationAwarePurchaseStockService::DOC_PURCHASE_RETURN,
                $newWarehouseId,
                $newLocationId,
                $this->locationAwareReturnLines($request),
                $extra,
                ['allow_batch' => true]
            );

            // Replace details + their batch pivots (the OLD snapshot already
            // drove the physical reverse above; pivots are only UX/reporting).
            $oldDetailIds = PurchaseReturnDetails::where('purchase_return_id', $locked->id)->pluck('id')->all();
            if ($oldDetailIds && Schema::hasTable('purchase_return_detail_batches')) {
                PurchaseReturnDetailBatch::whereIn('purchase_return_detail_id', $oldDetailIds)->delete();
            }
            PurchaseReturnDetails::where('purchase_return_id', $locked->id)->delete();
            $detailIds = $this->persistLocationAwareReturnDetails($locked->id, $rawLines);

            // (B, C) apply a NEW snapshot only when the new statut is completed.
            // decrease() may reject insufficient stock in the NEW location -> the
            // whole tx (incl. the reverse above) rolls back.
            $newSnapshot = null;
            if ($newStatut === 'completed') {
                $planned = $this->planLocationAwarePurchaseReturnBatches(
                    $newWarehouseId, $newLocationId, $validated['lines'], $rawLines
                );
                $validated['lines'] = $this->withReturnSourceDetailIds($planned, $detailIds);
                $newSnapshot = $svc->buildSnapshot($validated, $oldRevision + 1);
                $svc->applySnapshot($newSnapshot, $locked->id);
                $this->persistLocationAwarePurchaseReturnDetailBatches($validated['lines']);
            }

            $payload = [
                'date' => $request['date'],
                'provider_id' => $request['supplier_id'],
                'warehouse_id' => $newWarehouseId,
                'inventory_location_id' => $newLocationId,
                'notes' => $request['notes'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $request['TaxNet'],
                'discount' => $request['discount'],
                'shipping' => $request['shipping'],
                'statut' => $newStatut,
                'GrandTotal' => $request['GrandTotal'],
                'payment_statut' => $this->returnPaymentStatutFor((float) $request['GrandTotal'], (float) $locked->paid_amount),
            ];
            if ($newSnapshot !== null) {
                $payload['inventory_effect_snapshot'] = $newSnapshot; // B / C
            }
            // A (non-completed -> non-completed) and D (completed -> pending):
            // keep the last historical snapshot so a later ->completed bumps its
            // revision. Unlike legacy update() (L4), the native branch DOES
            // persist the new warehouse_id + inventory_location_id — its snapshot
            // depends on that identity.
            $locked->update($payload);

            return null;
        }, 10);

        return $response ?? response()->json(['success' => true]);
    }

    // --------- DESTROY (location-native) -------------------------------\\

    private function destroyLocationAware(Request $request, PurchaseReturn $current)
    {
        $response = \DB::transaction(function () use ($request, $current) {
            $user = Auth::user();
            $view_records = $user->hasRecordView();

            $locked = PurchaseReturn::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

            if (! $user->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
                if (empty($locked->warehouse_id) || ! in_array($locked->warehouse_id, $warehouses_id)) {
                    return response()->json(['success' => false, 'message' => 'You are not allowed to access this sale (warehouse restriction).'], 403);
                }
            }
            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $locked);
            }

            // The snapshot can only be reversed inside the architecture that
            // created it: the persisted warehouse must still be healthy primary.
            $this->assertLocationNativePurchaseTransitionSafe((int) $locked->warehouse_id, null);

            $this->reverseLocationNativePurchaseReturnStock($locked);

            $detailIds = $locked->details()->pluck('id')->all();
            if ($detailIds && Schema::hasTable('purchase_return_detail_batches')) {
                PurchaseReturnDetailBatch::whereIn('purchase_return_detail_id', $detailIds)->delete();
            }
            $locked->details()->delete();
            $locked->update(['deleted_at' => Carbon::now()]);

            $payments = PaymentPurchaseReturns::where('purchase_return_id', $locked->id)->get();
            foreach ($payments as $payment) {
                $account = Account::find($payment->account_id);
                if ($account) {
                    $account->update(['balance' => $account->balance - $payment->montant]);
                }
            }
            PaymentPurchaseReturns::where('purchase_return_id', $locked->id)->update(['deleted_at' => Carbon::now()]);

            return null;
        }, 10);

        return $response ?? response()->json(['success' => true]);
    }

    /**
     * Shared physical-stock reversal for a location-native return being deleted
     * (single destroy AND bulk delete). completed => reverse the exact
     * historical snapshot (stock RETURNS to the snapshot's location); anything
     * else => nothing. FAIL CLOSED on a missing/broken snapshot.
     */
    private function reverseLocationNativePurchaseReturnStock(PurchaseReturn $return): void
    {
        if ($return->statut !== 'completed') {
            return;
        }

        $svc = app(LocationAwarePurchaseStockService::class);
        $snapshot = $svc->normalizeSnapshot($return->inventory_effect_snapshot); // throws if null/malformed
        // allow_batch=true — a batch effect reverses via receiveMany + increase.
        $svc->assertSnapshotArtifactSafeAndLock($snapshot, ['allow_batch' => true]);
        $svc->reverseSnapshot($snapshot, $return->id);
    }

    // --------- Inventory-location select for the return form -----------\\

    /**
     * GET purchase_returns_inventory_locations/{warehouse_id}
     *
     * A purchase return is an OUTBOUND movement (location -> supplier) => the
     * user's OPERATING scope (InventoryLocationScopeService::allowedLocationIds),
     * NOT the broader receivingLocationIds used by Purchases.
     */
    public function inventoryLocationsForWarehouse(Request $request, $warehouseId)
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', PurchaseReturn::class) || Gate::forUser($u)->allows('update', PurchaseReturn::class)),
            403,
            'No tienes permiso para consultar ubicaciones de inventario de devoluciones de compra.'
        );

        $warehouseId = (int) $warehouseId;
        $user = auth()->user();
        if ($user && ! $user->is_all_warehouses) {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
            abort_unless(in_array($warehouseId, $ids, true), 403, 'No tienes acceso a este almacén.');
        }

        $allowedIds = ($user && ! $user->is_all_warehouses && (int) $user->role_id !== 1)
            ? app(InventoryLocationScopeService::class)->allowedLocationIds($user)
            : null;

        return response()->json($this->inventoryLocationContextPayload($warehouseId, $allowedIds));
    }

    /**
     * GET purchase_returns_location_catalog/{location_id}
     *
     * Per-location available stock (inventory_location_stocks) for the return
     * form line list — replaces the legacy product_warehouse.qte read for a
     * location-native return. Reuses LocationCatalogReadService (the same source
     * of truth as Adjustments / Damages). UX only — InventoryService is the
     * final validation.
     */
    public function inventoryLocationCatalog(Request $request, $locationId)
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', PurchaseReturn::class) || Gate::forUser($u)->allows('update', PurchaseReturn::class)),
            403,
            'No tienes permiso para consultar el catálogo por ubicación.'
        );

        $locationId = (int) $locationId;
        $location = InventoryLocation::whereNull('deleted_at')->where('is_active', 1)->whereKey($locationId)->first();
        abort_if(! $location, 404, 'La ubicación de inventario no existe o está inactiva.');

        $user = auth()->user();
        if ($user && ! $user->is_all_warehouses) {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
            abort_unless(in_array((int) $location->warehouse_id, $ids, true), 403, 'No tienes acceso a este almacén.');
        }
        if ($user && ! $user->is_all_warehouses && (int) $user->role_id !== 1) {
            $allowed = app(InventoryLocationScopeService::class)->allowedLocationIds($user);
            abort_unless(in_array($locationId, $allowed, true), 403, 'No tienes acceso a esta ubicación.');
        }

        return response()->json(app(\App\Services\LocationCatalogReadService::class)->forLocation($locationId));
    }

    // ---------------- Show Form Create Purchase Return ---------------\\

    public function create(Request $request)
    {

        //

    }

    // ---------------- edit ---------------\\

    public function edit(Request $request, $id)
    {

        //

    }

    // ------------------- create_purchase_return -----------------\\

    public function create_purchase_return(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'create', PurchaseReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
        }

        $Return_detail['supplier_id'] = $Purchase_data->provider_id;
        $Return_detail['warehouse_id'] = $Purchase_data->warehouse_id;
        // MS3 — suggested default only (the goods may have been moved since).
        // NULL for a legacy Purchase; a NEW return can still be location-native
        // if the warehouse is now location_primary (the user states the
        // physical location NOW, we don't reconstruct the Purchase's history).
        $Return_detail['inventory_location_id'] = $Purchase_data->inventory_location_id;
        $Return_detail['purchase_id'] = $Purchase_data->id;
        $Return_detail['purchase_ref'] = $Purchase_data->Ref;
        $Return_detail['tax_rate'] = 0;
        $Return_detail['TaxNet'] = 0;
        $Return_detail['discount'] = 0;
        $Return_detail['shipping'] = 0;
        $Return_detail['statut'] = 'completed';
        $Return_detail['notes'] = '';

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['product_variant_id'] = $detail->product_variant_id;

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->where('warehouse_id', $Purchase_data->warehouse_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }

            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = 0;
            $data['purchase_quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'purchase_return' => $Return_detail,
        ]);

    }

    // ------------- Show Form Edit Purchase Return-----------\\

    public function edit_purchase_return(Request $request, $id, $purchase_id)
    {

        $this->authorizeForUser($request->user('api'), 'update', PurchaseReturn::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Purchase_Return = PurchaseReturn::with('details.product.unitPurchase')
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

            if (empty($Purchase_Return->warehouse_id) || ! in_array($Purchase_Return->warehouse_id, $warehouses_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this sale (warehouse restriction).',
                ], 403);
            }
        }

        $details = [];
        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === PurchaseReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_Return);
        }

        $Return_detail['supplier_id'] = $Purchase_Return->provider_id;
        $Return_detail['warehouse_id'] = $Purchase_Return->warehouse_id;
        // MS3 — non-null only for a location-native return; the edit form loads
        // and keeps sending it.
        $Return_detail['inventory_location_id'] = $Purchase_Return->inventory_location_id;
        $Return_detail['purchase_id'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->id : null;
        $Return_detail['purchase_ref'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->Ref : '---';

        $Return_detail['date'] = $Purchase_Return->date;
        $Return_detail['tax_rate'] = $Purchase_Return->tax_rate;
        $Return_detail['TaxNet'] = $Purchase_Return->TaxNet;
        $Return_detail['discount'] = $Purchase_Return->discount;
        $Return_detail['shipping'] = $Purchase_Return->shipping;
        $Return_detail['notes'] = $Purchase_Return->notes;
        $Return_detail['statut'] = $Purchase_Return->statut;

        $detail_id = 0;
        foreach ($Purchase_Return['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Purchase_Return->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

                $data['product_variant_id'] = $detail->product_variant_id;

                if ($unit && $unit->operator == '/') {
                    $data['current_stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['current_stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['current_stock'] = 0;
                }

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('warehouse_id', $Purchase_Return->warehouse_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['product_variant_id'] = null;

                if ($unit && $unit->operator == '/') {
                    $data['current_stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['current_stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['current_stock'] = 0;
                }

            }

            $purchase_detail = PurchaseDetail::where('purchase_id', $purchase_id)
                ->where('product_id', $detail->product_id)
                ->where('product_variant_id', $detail->product_variant_id)
                ->first();

            $data['purchase_quantity'] = $purchase_detail->quantity;
            $data['stock'] = $data['current_stock'] + $detail->quantity;
            $data['quantity'] = $detail->quantity;
            $data['quantity_copy'] = $detail->quantity;

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'purchase_return' => $Return_detail,
        ]);

    }

    // ------------- GET Payments Purchase Return BY ID-----------\\

    public function Payment_Returns(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchaseReturns::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $PurchaseReturn = PurchaseReturn::findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === PurchaseReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $PurchaseReturn);
        }

        $payments = PaymentPurchaseReturns::with('PurchaseReturn', 'payment_method')
            ->where('purchase_return_id', $id)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $PurchaseReturn->GrandTotal - $PurchaseReturn->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    // ------------ Reference Number Purchase Return --------------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'RT' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->purchase_return_prefix) ? $setting->purchase_return_prefix : 'RT';
        
        // Get the last purchase return with a reference that starts with the prefix
        $last = DB::table('purchase_returns')
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

    // ---------------- Get Details Purchase Return  -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PurchaseReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Purchase_Return = PurchaseReturn::with('purchase', 'details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === PurchaseReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_Return);
        }

        $return_details['purchase_ref'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->Ref : '---';
        $return_details['purchase_id'] = $Purchase_Return['purchase'] ? $Purchase_Return['purchase']->id : null;
        $return_details['Ref'] = $Purchase_Return->Ref;
        $return_details['date'] = $Purchase_Return->date.' '.$Purchase_Return->time;
        $return_details['statut'] = $Purchase_Return->statut;
        $return_details['note'] = $Purchase_Return->notes;
        $return_details['discount'] = $Purchase_Return->discount;
        $return_details['shipping'] = $Purchase_Return->shipping;
        $return_details['tax_rate'] = $Purchase_Return->tax_rate;
        $return_details['TaxNet'] = $Purchase_Return->TaxNet;
        $return_details['supplier_name'] = $Purchase_Return['provider']->name;
        $return_details['supplier_email'] = $Purchase_Return['provider']->email;
        $return_details['supplier_phone'] = $Purchase_Return['provider']->phone;
        $return_details['supplier_adr'] = $Purchase_Return['provider']->adresse;
        $return_details['supplier_tax'] = $Purchase_Return['provider']->tax_number;
        $return_details['warehouse'] = $Purchase_Return['warehouse']->name;
        $return_details['GrandTotal'] = number_format($Purchase_Return->GrandTotal, helpers::price_decimals(), '.', '');
        $return_details['paid_amount'] = number_format($Purchase_Return->paid_amount, helpers::price_decimals(), '.', '');
        $return_details['due'] = number_format($return_details['GrandTotal'] - $return_details['paid_amount'], helpers::price_decimals(), '.', '');
        $return_details['payment_status'] = $Purchase_Return->payment_statut;

        $batchesByDetail = app(BatchService::class)->batchesForPurchaseReturnDetails($Purchase_Return['details']);

        foreach ($Purchase_Return['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total;
            $data['cost'] = $detail->cost;
            $data['unit_purchase'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }
            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['discount'] = $detail->discount;
            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'purchase_return' => $return_details,
            'company' => $company,
        ]);

    }

    // ------------- Purchase Return PDF-----------\\

    public function Return_pdf(Request $request, $id)
    {

        $details = [];
        $helpers = new helpers;
        $PurchaseReturn = PurchaseReturn::with('purchase', 'details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $batchesByDetail = app(BatchService::class)->batchesForPurchaseReturnDetails($PurchaseReturn['details']);

        $return_details['purchase_ref'] = $PurchaseReturn['purchase'] ? $PurchaseReturn['purchase']->Ref : '---';
        $return_details['supplier_name'] = $PurchaseReturn['provider']->name;
        $return_details['supplier_phone'] = $PurchaseReturn['provider']->phone;
        $return_details['supplier_adr'] = $PurchaseReturn['provider']->adresse;
        $return_details['supplier_email'] = $PurchaseReturn['provider']->email;
        $return_details['supplier_tax'] = $PurchaseReturn['provider']->tax_number;
        $return_details['TaxNet'] = number_format($PurchaseReturn->TaxNet, helpers::price_decimals(), '.', '');
        $return_details['discount'] = number_format($PurchaseReturn->discount, helpers::price_decimals(), '.', '');
        $return_details['shipping'] = number_format($PurchaseReturn->shipping, helpers::price_decimals(), '.', '');
        $return_details['statut'] = $PurchaseReturn->statut;
        $return_details['Ref'] = $PurchaseReturn->Ref;
        $return_details['date'] = $PurchaseReturn->date.' '.$PurchaseReturn->time;
        $return_details['GrandTotal'] = number_format($PurchaseReturn->GrandTotal, helpers::price_decimals(), '.', '');
        $return_details['paid_amount'] = number_format($PurchaseReturn->paid_amount, helpers::price_decimals(), '.', '');
        $return_details['due'] = number_format($return_details['GrandTotal'] - $return_details['paid_amount'], helpers::price_decimals(), '.', '');
        $return_details['payment_status'] = $PurchaseReturn->payment_statut;

        $detail_id = 0;
        foreach ($PurchaseReturn['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total, helpers::price_decimals(), '.', '');
            $data['cost'] = number_format($detail->cost, helpers::price_decimals(), '.', '');
            $data['unit_purchase'] = $unit->ShortName;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->cost * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = number_format($detail->cost, helpers::price_decimals(), '.', '');
            $data['discount'] = number_format($detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {

                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_cost, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = number_format($detail->cost - $data['Net_cost'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.Purchase_Return_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'return_purchase' => $return_details,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Purchase_Return.pdf');
    }
}
