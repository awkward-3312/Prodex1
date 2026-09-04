<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode;
use App\Models\Account;
use App\Models\Client;
use App\Models\InventoryLocation;
use App\Models\PaymentMethod;
use App\Models\PaymentSaleReturns;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\Setting;
use App\Models\StoreCreditVoucher;
use App\Models\StoreCreditVoucherTransaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\InventoryLocationScopeService;
use App\Services\LocationAwareSaleStockService;
use App\Services\SerialNumberService;
use App\Services\WarehouseInventoryModeResolver;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDF;

class SalesReturnController extends BaseController
{
    use GuardsPurchaseTransitionMode;

    // ------------ GET ALL Sale Return--------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SaleReturn::class);
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
            2 => 'client_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
            6 => 'sale_id',
        ];
        $data = [];

        // Check If User Has Permission View  All Records
        $SaleReturn = SaleReturn::with('sale', 'facture', 'client', 'warehouse', 'storeCreditVoucher')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        if (! $is_all_warehouses) {
            $SaleReturn->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($SaleReturn, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('GrandTotal', $request->search)
                        ->orWhere('payment_statut', 'like', "$request->search")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale', function ($q) use ($request) {
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
        $SaleReturn = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($SaleReturn as $Sale_Return) {

            $item['id'] = $Sale_Return->id;
            $item['date'] = $Sale_Return['date'].' '.$Sale_Return['time'];
            $item['Ref'] = $Sale_Return->Ref;
            $item['discount'] = $Sale_Return->discount;
            $item['shipping'] = $Sale_Return->shipping;
            $item['statut'] = $Sale_Return->statut;
            $item['qte_retturn'] = $Sale_Return->qte_retour;
            $item['warehouse_name'] = $Sale_Return['warehouse']->name;
            $item['sale_ref'] = $Sale_Return['sale'] ? $Sale_Return['sale']->Ref : '---';
            $item['sale_id'] = $Sale_Return['sale'] ? $Sale_Return['sale']->id : null;
            $item['client_id'] = $Sale_Return['client']->id;
            $item['client_name'] = $Sale_Return['client']->name;
            $item['client_email'] = $Sale_Return['client']->email;
            $item['client_tele'] = $Sale_Return['client']->phone;
            $item['client_code'] = $Sale_Return['client']->code;
            $item['client_adr'] = $Sale_Return['client']->adresse;
            $item['GrandTotal'] = number_format($Sale_Return['GrandTotal'], helpers::price_decimals(), '.', '');
            $item['paid_amount'] = number_format($Sale_Return['paid_amount'], helpers::price_decimals(), '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], helpers::price_decimals(), '.', '');
            $item['payment_status'] = $Sale_Return['payment_statut'];
            $item['refund_mode'] = $Sale_Return->refund_mode;
            $item['store_credit_amount'] = number_format((float) ($Sale_Return->store_credit_amount ?? 0), helpers::price_decimals(), '.', '');
            $item['store_credit_code'] = optional($Sale_Return->storeCreditVoucher)->code;

            $data[] = $item;
        }

        $customers = client::where('deleted_at', '=', null)->get(['id', 'name']);
        $sales = Sale::where('deleted_at', '=', null)->get(['id', 'Ref']);
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
            'sale_Return' => $data,
            'customers' => $customers,
            'warehouses' => $warehouses,
            'sales' => $sales,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);

    }

    // ------------ Store new Sale Return --------------\\

    public function store(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SaleReturn::class);

        request()->validate([
            'client_id' => 'required',
            'warehouse_id' => 'required',
            'statut' => 'required',
        ]);

        // MS7-B1 — a SaleReturn (never POS) for a location_primary warehouse
        // stays on this SAME method but branches at every physical-inventory
        // write point below to the location-native engine; every other
        // concern (store-credit voucher, ZATCA submit) is identical code for
        // both, never duplicated. Every other mode keeps the exact legacy
        // behaviour untouched.
        $__isNativeReturn = app(WarehouseInventoryModeResolver::class)->isLocationPrimary((int) $request->warehouse_id);
        $__nativeLocationId = null;
        $__nativeByRawIndex = [];
        if ($__isNativeReturn) {
            $this->assertLocationNativePurchaseTransitionSafe((int) $request->warehouse_id, null);
            // RETURN DESTINATION — the user explicitly picks where the goods
            // physically re-enter, never auto-assumed from the referenced
            // Sale's location (a customer may legitimately return to a
            // different valid location — §21/§25 of the MS7-B1 spec).
            request()->validate(['inventory_location_id' => 'required|integer']);
            $__nativeLocationId = (int) $request->inventory_location_id;
            $__nativeValidated = app(LocationAwareSaleStockService::class)->validateAndLock($this->locationAwareReturnLines($request));
            foreach ($__nativeValidated['lines'] as $__line) {
                $__nativeByRawIndex[(int) $__line['_line_index']] = $__line;
            }
            $this->assertReturnWithinSoldQuantity((int) ($request->sale_id ?: 0), null, $__nativeValidated['lines']);
        }

        $createdReturn = \DB::transaction(function () use ($request, $__isNativeReturn, $__nativeLocationId, $__nativeByRawIndex) {
            $order = new SaleReturn;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->client_id = $request->client_id;
            $order->sale_id = $request->sale_id;
            $order->warehouse_id = $request->warehouse_id;
            if ($__isNativeReturn) {
                $order->inventory_location_id = $__nativeLocationId;
            }
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->GrandTotal = $request->GrandTotal;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $data = $request['details'];
            $persistedDetails = [];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['sale_unit_id'])->first();

                // Multi-Pack Selling: a selected pack multiplies the base-unit
                // restock. Defaults to 1 so non-pack returns are unchanged.
                $packMultiplier = isset($value['pack_multiplier']) && (float) $value['pack_multiplier'] > 0
                    ? (float) $value['pack_multiplier'] : 1;
                $packQty = $value['quantity'] * $packMultiplier;

                $persistedDetails[$key] = SaleReturnDetails::create([
                    'sale_return_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'price' => $value['Unit_price'],
                    'sale_unit_id' => $value['sale_unit_id'],
                    'TaxNet' => $value['tax_percent'],
                    'tax_method' => $value['tax_method'],
                    'discount' => $value['discount'],
                    'discount_method' => $value['discount_Method'],
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'],
                    'total' => $value['subtotal'],
                    'imei_number' => $value['imei_number'],
                    'product_pack_id' => isset($value['product_pack_id']) && $value['product_pack_id'] ? (int) $value['product_pack_id'] : null,
                    'pack_multiplier' => $packMultiplier,
                    'pack_name' => $value['pack_name'] ?? null,
                ]);

                // MS7-B1 — a native return NEVER touches product_warehouse;
                // its GENERAL increase happens once, after this loop, via the
                // snapshot engine (InventoryService::increase).
                if (! $__isNativeReturn && $order->statut == 'received') {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte += $packQty / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $packQty * $unit->operator_value;
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
                                $product_warehouse->qte += $packQty / $unit->operator_value;
                            } else {
                                $product_warehouse->qte += $packQty * $unit->operator_value;
                            }

                            $product_warehouse->save();
                        }
                    }
                }

            }

            // Pharmacy: credit batches when the return is received (mirror sale flow inversely).
            // Already location-aware (self-branches to the native, location-
            // aware implementation via the LocationAware* singleton bindings
            // once $order->inventory_location_id is set) — unchanged call
            // site for both legacy and native.
            if ($order->statut == 'received') {
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->applyForSaleReturnWithAutoFallback(
                        $order,
                        array_values($data),
                        $persistedDetails
                    );
                }
            }

            // Serial / IMEI: return selected serials back to available stock.
            // returnFromSale() already self-branches — unchanged call site.
            if ($order->statut == 'received') {
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->applyForSaleReturn($order, $data, $persistedDetails);
                }
            }

            // GENERAL leg — native only. Runs unconditionally (independent of
            // whether batch/serial tracking happens to be enabled tenant-
            // wide) right after every batch/serial artifact above has had its
            // chance to run, so their provenance is available to fold into
            // the snapshot.
            if ($__isNativeReturn && $order->statut == 'received') {
                // $persistedDetails is already keyed 0..n-1 in the SAME order
                // as $data (created one at a time in the loop above) — no
                // re-query / id-order assumption needed.
                $__snapshot = app(LocationAwareSaleStockService::class)->buildSnapshotFromPersistedDetails(
                    LocationAwareSaleStockService::DOC_SALE_RETURN, (int) $order->warehouse_id, (int) $order->inventory_location_id,
                    $data, $__nativeByRawIndex, $persistedDetails, 1
                );
                $order->update(['inventory_effect_snapshot' => $__snapshot]);
                app(LocationAwareSaleStockService::class)->applySnapshot($__snapshot, $order->id);
            }

            if ($order->statut == 'received') {
                $this->issueStoreCreditVoucher($order);
            }

            return $order;
        }, 10);

        // ZATCA Phase 2: report the return as a credit note (non-blocking;
        // no-op unless the tenant enabled Phase 2 and finished onboarding).
        try {
            \App\Jobs\SubmitDocumentToZatca::dispatch(SaleReturn::class, $createdReturn->id)->afterCommit();
        } catch (\Throwable $e) {
            \Log::warning('ZATCA submit dispatch failed (non-blocking): '.$e->getMessage(), ['sale_return_id' => $createdReturn->id]);
        }

        $createdReturn->load([
            'storeCreditVoucher',
            'client',
            'warehouse',
            'sale',
            'user',
            'details.product.unitSale',
        ]);

        return response()->json([
            'success' => true,
            'store_credit_voucher' => $this->formatStoreCreditVoucherResponse($createdReturn),
        ]);
    }

    // =====================================================================
    // MS7-B1 — SaleReturn location-native helpers (warehouses in
    // MODE_LOCATION_PRIMARY). See SalesController's own MS7-B1 block for the
    // full architecture note — same split: GENERAL is snapshot-replay-
    // authoritative here (LocationAwareSaleStockService); BATCH/SERIAL are
    // owned by the already-proven LocationAwareBatchService /
    // LocationAwareSerialNumberService, unchanged call sites, self-branching
    // on $return->inventory_location_id.
    // =====================================================================

    /** Raw request details -> LocationAwareSaleStockService line shape. */
    private function locationAwareReturnLines(Request $request): array
    {
        return array_map(fn ($v) => [
            'product_id' => $v['product_id'] ?? null,
            'product_variant_id' => (isset($v['product_variant_id']) && $v['product_variant_id'] !== '') ? $v['product_variant_id'] : null,
            'quantity' => $v['quantity'] ?? 0,
            'sale_unit_id' => $v['sale_unit_id'] ?? null,
            'pack_multiplier' => $v['pack_multiplier'] ?? 1,
        ], array_values($request['details'] ?? []));
    }

    /**
     * MS7-B1 §27-29 — native only (legacy never had this guard, and keeps
     * none). Prevents a SaleReturn from crediting back more of a product/
     * variant than the referenced Sale actually sold, cumulative across
     * every OTHER active SaleReturn against the same sale — partial returns
     * (sold 10, returned 3 then 2 => 5 still returnable) stay valid; a
     * return attempting to exceed the remaining balance is FAIL CLOSED (422).
     *
     * Requires the referenced Sale itself to be native (it must carry an
     * inventory_effect_snapshot) — a legacy-linked or unlinked return has no
     * reliable "sold" baseline to compare against and is silently skipped
     * (matches legacy: no guard at all).
     */
    private function assertReturnWithinSoldQuantity(int $saleId, ?int $excludeReturnId, array $validatedLines): void
    {
        if ($saleId <= 0) {
            return;
        }
        $sale = Sale::find($saleId);
        if (! $sale || empty($sale->inventory_effect_snapshot)) {
            return;
        }

        $svc = app(LocationAwareSaleStockService::class);
        $saleSnapshot = $svc->normalizeSnapshot($sale->inventory_effect_snapshot);

        $sold = [];
        foreach ($saleSnapshot['effects'] as $e) {
            $key = $e['product_id'].':'.($e['product_variant_id'] ?? 'null');
            $sold[$key] = ($sold[$key] ?? 0) + (float) $e['quantity_base'];
        }

        $alreadyReturned = [];
        $others = SaleReturn::where('sale_id', $saleId)
            ->whereNull('deleted_at')
            ->when($excludeReturnId, fn ($q) => $q->where('id', '!=', $excludeReturnId))
            ->whereNotNull('inventory_effect_snapshot')
            ->get();
        foreach ($others as $r) {
            try {
                $rs = $svc->normalizeSnapshot($r->inventory_effect_snapshot);
            } catch (\Throwable $e) {
                continue; // corrupt/foreign snapshot — never let it block a legitimate return.
            }
            foreach ($rs['effects'] as $e) {
                $key = $e['product_id'].':'.($e['product_variant_id'] ?? 'null');
                $alreadyReturned[$key] = ($alreadyReturned[$key] ?? 0) + (float) $e['quantity_base'];
            }
        }

        foreach ($validatedLines as $line) {
            $key = $line['product_id'].':'.($line['product_variant_id'] ?? 'null');
            $soldQty = $sold[$key] ?? 0.0;
            $returnedSoFar = $alreadyReturned[$key] ?? 0.0;
            $thisQty = (float) $line['quantity_base'];
            if ($returnedSoFar + $thisQty > $soldQty + 0.0005) {
                $remaining = max(0, round($soldQty - $returnedSoFar, 3));
                throw ValidationException::withMessages([
                    'details' => "La cantidad a devolver del producto #{$line['product_id']} excede lo vendido en la venta original (disponible para devolución: {$remaining}).",
                ]);
            }
        }
    }

    /**
     * GET sale_returns_inventory_locations/{warehouse_id}
     *
     * A SaleReturn is an INBOUND movement (customer -> location) => the
     * user's RECEIVING scope (InventoryLocationScopeService::receivingLocationIds),
     * same as Purchase.
     */
    public function inventoryLocationsForWarehouse(Request $request, $warehouseId)
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', SaleReturn::class) || Gate::forUser($u)->allows('update', SaleReturn::class)),
            403,
            'No tienes permiso para consultar ubicaciones de inventario de devoluciones.'
        );

        $warehouseId = (int) $warehouseId;
        $user = auth()->user();
        if ($user && ! $user->is_all_warehouses) {
            $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($i) => (int) $i)->all();
            abort_unless(in_array($warehouseId, $ids, true), 403, 'No tienes acceso a este almacén.');
        }

        $allowedIds = ($user && ! $user->is_all_warehouses && (int) $user->role_id !== 1)
            ? app(InventoryLocationScopeService::class)->receivingLocationIds($user)
            : null;

        return response()->json($this->inventoryLocationContextPayload($warehouseId, $allowedIds));
    }

    /**
     * GET sale_returns_location_catalog/{location_id}
     *
     * Per-location stock catalog for the SaleReturn form — same reader
     * Purchase/PurchaseReturn/Sale already use.
     */
    public function inventoryLocationCatalog(Request $request, $locationId)
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', SaleReturn::class) || Gate::forUser($u)->allows('update', SaleReturn::class)),
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
            $allowed = app(InventoryLocationScopeService::class)->receivingLocationIds($user);
            abort_unless(in_array($locationId, $allowed, true), 403, 'No tienes acceso a esta ubicación de inventario.');
        }

        return response()->json(app(\App\Services\LocationCatalogReadService::class)->forLocation($locationId));
    }

    // ------------ Update Return Sale--------------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', SaleReturn::class);

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_SaleReturn = SaleReturn::findOrFail($id);
            if ($current_SaleReturn->store_credit_voucher_id) {
                abort(422, 'Esta devolución ya generó un vale de crédito y no puede modificarse sin cancelar el vale.');
            }

            // MS7-B1 — route by the PERSISTED document identity, not the
            // warehouse's current mode.
            $__isNativeReturn = $current_SaleReturn->inventory_location_id !== null;
            $__nativeLocationId = null;
            $__nativeByRawIndex = [];
            $__nativeOldRevision = 0;

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

                if (empty($current_SaleReturn->warehouse_id) || ! in_array($current_SaleReturn->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === SaleReturn->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_SaleReturn);
            }
            $old_return_details = SaleReturnDetails::where('sale_return_id', $id)->get();
            $new_return_details = $request['details'];
            $length = count($new_return_details);

            // Get Ids details
            $new_products_id = [];
            foreach ($new_return_details as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // MS7-B1 — FAIL CLOSED preflight (batch+IMEI fence, unknown
            // product/variant, non-positive/fractional base qty, over-return
            // vs. the referenced Sale) BEFORE any reversal or write happens.
            // Also resolves/validates the (possibly new) inventory_location_id.
            if ($__isNativeReturn) {
                $this->assertLocationNativePurchaseTransitionSafe((int) $current_SaleReturn->warehouse_id, (int) $request->warehouse_id);
                if (! $request->filled('inventory_location_id')) {
                    throw ValidationException::withMessages(['inventory_location_id' => 'Debes seleccionar una ubicación de inventario.']);
                }
                $__nativeLocationId = (int) $request->inventory_location_id;
                $__nativeValidated = app(LocationAwareSaleStockService::class)->validateAndLock($this->locationAwareReturnLines($request));
                foreach ($__nativeValidated['lines'] as $__line) {
                    $__nativeByRawIndex[(int) $__line['_line_index']] = $__line;
                }
                // Exclude THIS return's own current effect from the cumulative
                // check (§29) — it is about to be reversed and replanned below,
                // never double-counted against itself.
                $this->assertReturnWithinSoldQuantity((int) ($current_SaleReturn->sale_id ?: 0), (int) $current_SaleReturn->id, $__nativeValidated['lines']);
                if (! empty($current_SaleReturn->inventory_effect_snapshot)) {
                    $__nativeOldRevision = app(LocationAwareSaleStockService::class)->normalizeSnapshot($current_SaleReturn->inventory_effect_snapshot)['revision'];
                }
            }

            // Pharmacy: reverse old batch credits (subtract qty back from product_batches)
            // before we touch warehouse stock so the per-batch ledger stays consistent.
            // Already location-aware — unchanged call site for both.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $current_SaleReturn->statut == 'received') {
                $batchService->reverseForSaleReturnDetails($old_return_details);
            }

            // Serial / IMEI — native only (legacy update() never touches the
            // serial ledger at all; kept byte-for-byte). FAIL CLOSED reverse
            // of the OLD received state via the new MS7-B1 override.
            $serialService = app(SerialNumberService::class);
            if ($__isNativeReturn && $serialService->isSupported() && $current_SaleReturn->statut == 'received') {
                $serialService->reverseForSaleReturn($current_SaleReturn);
            }

            // GENERAL leg — native only. Mirrors the raw product_warehouse
            // reversal legacy performs unconditionally below when the OLD
            // state was received.
            if ($__isNativeReturn && $current_SaleReturn->statut == 'received' && ! empty($current_SaleReturn->inventory_effect_snapshot)) {
                $__saleReturnSvc = app(LocationAwareSaleStockService::class);
                $__saleReturnSvc->reverseSnapshot($__saleReturnSvc->normalizeSnapshot($current_SaleReturn->inventory_effect_snapshot), $current_SaleReturn->id);
            }

            // Init Data with old Parametre
            $old_products_id = [];
            foreach ($old_return_details as $key => $value) {
                $old_products_id[] = $value->id;

                // check if detail has sale_unit_id Or Null
                if ($value['sale_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['sale_unit_id'])->first();
                } else {
                    $product_unit_sale_id = Product::with('unitSale')
                        ->where('id', $value['product_id'])
                        ->first();

                    if ($product_unit_sale_id['unitSale']) {
                        $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                    }
                    $unit = null;

                }

                // Multi-Pack Selling: reverse using the pack multiplier snapshot
                // stored on the original return line (defaults to 1 for legacy rows).
                $oldPackMultiplier = (float) ($value['pack_multiplier'] ?? 0) > 0 ? (float) $value['pack_multiplier'] : 1;
                $oldPackQty = $value['quantity'] * $oldPackMultiplier;

                if ($value['sale_unit_id'] !== null) {
                    // MS7-B1 — a native return's GENERAL leg was already
                    // reversed in one shot above; it never touches
                    // product_warehouse.
                    if (! $__isNativeReturn && $current_SaleReturn->statut == 'received') {
                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $oldPackQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $oldPackQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $oldPackQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $oldPackQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                    // Delete Detail
                    if (! in_array($old_products_id[$key], $new_products_id)) {
                        $SaleReturnDetails = SaleReturnDetails::findOrFail($value->id);
                        $SaleReturnDetails->delete();
                    }
                }

            }

            // Update Data with New request
            $newPersistedDetails = [];
            foreach ($new_return_details as $key => $product_detail) {

                $get_type_product = Product::where('id', $product_detail['product_id'])->first()->type;

                if ($product_detail['no_unit'] !== 0 || $get_type_product == 'is_service') {

                    $unit_prod = Unit::where('id', $product_detail['sale_unit_id'])->first();

                    // Multi-Pack Selling: apply the pack multiplier to the restock
                    $newPackMultiplier = isset($product_detail['pack_multiplier']) && (float) $product_detail['pack_multiplier'] > 0
                        ? (float) $product_detail['pack_multiplier'] : 1;
                    $newPackQty = $product_detail['quantity'] * $newPackMultiplier;

                    // MS7-B1 — a native return's GENERAL leg is applied once,
                    // after this loop, via the snapshot engine; never
                    // product_warehouse.
                    if (! $__isNativeReturn && $request['statut'] == 'received') {

                        if ($product_detail['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $product_detail['product_id'])
                                ->where('product_variant_id', $product_detail['product_variant_id'])
                                ->first();

                            if ($unit_prod && $product_warehouse) {
                                if ($unit_prod->operator == '/') {
                                    $product_warehouse->qte += $newPackQty / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte += $newPackQty * $unit_prod->operator_value;
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
                                    $product_warehouse->qte += $newPackQty / $unit_prod->operator_value;
                                } else {
                                    $product_warehouse->qte += $newPackQty * $unit_prod->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                    $orderDetails['sale_return_id'] = $id;
                    $orderDetails['sale_unit_id'] = $product_detail['sale_unit_id'];
                    $orderDetails['quantity'] = $product_detail['quantity'];
                    $orderDetails['price'] = $product_detail['Unit_price'];
                    $orderDetails['TaxNet'] = $product_detail['tax_percent'];
                    $orderDetails['tax_method'] = $product_detail['tax_method'];
                    $orderDetails['discount'] = $product_detail['discount'];
                    $orderDetails['discount_method'] = $product_detail['discount_Method'];
                    $orderDetails['product_id'] = $product_detail['product_id'];
                    $orderDetails['product_variant_id'] = $product_detail['product_variant_id'];
                    $orderDetails['total'] = $product_detail['subtotal'];
                    $orderDetails['imei_number'] = $product_detail['imei_number'];
                    $orderDetails['product_pack_id'] = isset($product_detail['product_pack_id']) && $product_detail['product_pack_id'] ? (int) $product_detail['product_pack_id'] : null;
                    $orderDetails['pack_multiplier'] = $newPackMultiplier;
                    $orderDetails['pack_name'] = $product_detail['pack_name'] ?? null;

                    if (! in_array($product_detail['id'], $old_products_id)) {
                        $persistedDetail = SaleReturnDetails::Create($orderDetails);
                    } else {
                        SaleReturnDetails::where('id', $product_detail['id'])->update($orderDetails);
                        $persistedDetail = SaleReturnDetails::find($product_detail['id']);
                    }
                    $newPersistedDetails[$key] = $persistedDetail;
                }

            }

            // MS7-B1 — set BEFORE the batch/serial calls below read it to
            // self-branch to the location-aware implementation.
            if ($__isNativeReturn) {
                $current_SaleReturn->inventory_location_id = $__nativeLocationId;
            }

            // Pharmacy: re-apply batch credits now that SaleReturnDetails rows exist.
            // Pair input rows with persisted details lockstep; rows skipped above (no_unit==0
            // and not a service) leave gaps that would otherwise misalign indices when
            // BatchService re-keys via collect()->values(). Already location-aware —
            // unchanged call site for both.
            if ($batchService->isSupported() && $request['statut'] == 'received') {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($new_return_details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $current_SaleReturn->warehouse_id = (int) $request->warehouse_id;
                $batchService->applyForSaleReturnWithAutoFallback(
                    $current_SaleReturn,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            // Serial / IMEI — native only (legacy update() never touches the
            // serial ledger; kept byte-for-byte). returnFromSale() already
            // self-branches — unchanged call shape, just a native-only guard.
            if ($__isNativeReturn && $serialService->isSupported() && $request['statut'] == 'received') {
                $current_SaleReturn->warehouse_id = (int) $request->warehouse_id;
                $serialService->applyForSaleReturn($current_SaleReturn, $new_return_details, $newPersistedDetails);
            }

            // GENERAL leg — native only. Runs unconditionally (independent of
            // whether batch/serial tracking happens to be enabled tenant-
            // wide) right after every batch/serial artifact above has had its
            // chance to run, so their provenance is available to fold into
            // the snapshot.
            $__nativeNewSnapshot = null;
            if ($__isNativeReturn && $request['statut'] == 'received') {
                $__nativeNewSnapshot = app(LocationAwareSaleStockService::class)->buildSnapshotFromPersistedDetails(
                    LocationAwareSaleStockService::DOC_SALE_RETURN, (int) $request->warehouse_id, (int) $__nativeLocationId,
                    $new_return_details, $__nativeByRawIndex, $newPersistedDetails, $__nativeOldRevision + 1
                );
                app(LocationAwareSaleStockService::class)->applySnapshot($__nativeNewSnapshot, $current_SaleReturn->id);
            }

            $due = $request['GrandTotal'] - $current_SaleReturn->paid_amount;
            if ($due === 0.0 || $due < 0.0) {
                $payment_statut = 'paid';
            } elseif ($due != $request['GrandTotal']) {
                $payment_statut = 'partial';
            } elseif ($due == $request['GrandTotal']) {
                $payment_statut = 'unpaid';
            }

            $__updatePayload = [
                'date' => $request['date'],
                'notes' => $request['notes'],
                'statut' => $request['statut'],
                'tax_rate' => $request['tax_rate'],
                'TaxNet' => $request['TaxNet'],
                'discount' => $request['discount'],
                'shipping' => $request['shipping'],
                'GrandTotal' => $request['GrandTotal'],
                'payment_statut' => $payment_statut,
            ];
            if ($__isNativeReturn) {
                $__updatePayload['inventory_location_id'] = $__nativeLocationId;
                if ($__nativeNewSnapshot !== null) {
                    $__updatePayload['inventory_effect_snapshot'] = $__nativeNewSnapshot; // B / C
                }
                // A (non->non) and D (received->non-received): keep the last
                // historical snapshot so a later re-apply bumps its revision.
            }
            $current_SaleReturn->update($__updatePayload);

            $current_SaleReturn->refresh();
            if ($current_SaleReturn->statut == 'received') {
                $this->issueStoreCreditVoucher($current_SaleReturn);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Sale Return--------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SaleReturn::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_SaleReturn = SaleReturn::findOrFail($id);
            if ($current_SaleReturn->store_credit_voucher_id) {
                abort(422, 'Esta devolución ya generó un vale de crédito y no puede eliminarse sin cancelar el vale.');
            }

            // MS7-B1 — route by the PERSISTED document identity.
            $__isNativeReturn = $current_SaleReturn->inventory_location_id !== null;
            if ($__isNativeReturn) {
                $this->assertLocationNativePurchaseTransitionSafe((int) $current_SaleReturn->warehouse_id, null);
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

                if (empty($current_SaleReturn->warehouse_id) || ! in_array($current_SaleReturn->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_return_details = SaleReturnDetails::where('sale_return_id', $id)->get();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === current_SaleReturn->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_SaleReturn);
            }

            // Pharmacy: subtract back the batch credits before deleting the return so the
            // per-batch ledger mirrors the warehouse stock subtract that follows.
            // Already location-aware — unchanged call site for both.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $current_SaleReturn->statut == 'received') {
                $batchService->reverseForSaleReturnDetails($old_return_details);
            }

            // Serial / IMEI: re-mark returned serials as sold (undo the return).
            // reverseForSaleReturn() already self-branches — unchanged call site.
            if ($current_SaleReturn->statut == 'received') {
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->reverseForSaleReturn($current_SaleReturn);
                }
            }

            // GENERAL leg — native only.
            if ($__isNativeReturn && $current_SaleReturn->statut == 'received' && ! empty($current_SaleReturn->inventory_effect_snapshot)) {
                $__saleReturnSvc = app(LocationAwareSaleStockService::class);
                $__saleReturnSvc->reverseSnapshot($__saleReturnSvc->normalizeSnapshot($current_SaleReturn->inventory_effect_snapshot), $current_SaleReturn->id);
            }

            foreach ($__isNativeReturn ? [] : $old_return_details as $key => $value) {

                // check if detail has sale_unit_id Or Null
                if ($value['sale_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['sale_unit_id'])->first();
                } else {
                    $product_unit_sale_id = Product::with('unitSale')
                        ->where('id', $value['product_id'])
                        ->first();

                    if ($product_unit_sale_id['unitSale']) {
                        $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                    }
                    $unit = null;

                }

                // Multi-Pack Selling: reverse using the pack multiplier snapshot.
                $packMul = (float) ($value['pack_multiplier'] ?? 0) > 0 ? (float) $value['pack_multiplier'] : 1;
                $packQty = $value['quantity'] * $packMul;

                if ($current_SaleReturn->statut == 'received') {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                            ->where('product_id', $value['product_id'])->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte -= $packQty / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $packQty * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }

                    } else {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($unit && $product_warehouse) {
                            if ($unit->operator == '/') {
                                $product_warehouse->qte -= $packQty / $unit->operator_value;
                            } else {
                                $product_warehouse->qte -= $packQty * $unit->operator_value;
                            }
                            $product_warehouse->save();
                        }
                    }
                }

            }

            $current_SaleReturn->details()->delete();
            $current_SaleReturn->update([
                'deleted_at' => Carbon::now(),
            ]);

            // get all payments
            $payments = PaymentSaleReturns::where('sale_return_id', $id)->get();

            foreach ($payments as $payment) {

                $account = Account::find($payment->account_id);

                if ($account) {
                    $account->update([
                        'balance' => $account->balance + $payment->montant,
                    ]);
                }

            }

            PaymentSaleReturns::where('sale_return_id', $id)->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', SaleReturn::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $SaleReturn_id) {

                $current_SaleReturn = SaleReturn::findOrFail($SaleReturn_id);
                if ($current_SaleReturn->store_credit_voucher_id) {
                    abort(422, 'Una de las devoluciones seleccionadas ya generó un vale de crédito.');
                }

                // MS7-B1 — route by the PERSISTED document identity.
                $__isNativeReturn = $current_SaleReturn->inventory_location_id !== null;
                if ($__isNativeReturn) {
                    $this->assertLocationNativePurchaseTransitionSafe((int) $current_SaleReturn->warehouse_id, null);
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

                    if (empty($current_SaleReturn->warehouse_id) || ! in_array($current_SaleReturn->warehouse_id, $warehouses_id)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'You are not allowed to access this sale (warehouse restriction).',
                        ], 403);
                    }
                }

                $old_return_details = SaleReturnDetails::where('sale_return_id', $SaleReturn_id)->get();
                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === current_SaleReturn->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_SaleReturn);
                }

                // Pharmacy: subtract back the batch credits before deleting so the per-batch
                // ledger mirrors the warehouse stock subtract that follows.
                // Already location-aware — unchanged call site for both.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported() && $current_SaleReturn->statut == 'received') {
                    $batchService->reverseForSaleReturnDetails($old_return_details);
                }

                // Serial / IMEI: re-mark returned serials as sold (undo the return).
                // reverseForSaleReturn() already self-branches — unchanged call site.
                if ($current_SaleReturn->statut == 'received') {
                    $serialService = app(SerialNumberService::class);
                    if ($serialService->isSupported()) {
                        $serialService->reverseForSaleReturn($current_SaleReturn);
                    }
                }

                // GENERAL leg — native only.
                if ($__isNativeReturn && $current_SaleReturn->statut == 'received' && ! empty($current_SaleReturn->inventory_effect_snapshot)) {
                    $__saleReturnSvc = app(LocationAwareSaleStockService::class);
                    $__saleReturnSvc->reverseSnapshot($__saleReturnSvc->normalizeSnapshot($current_SaleReturn->inventory_effect_snapshot), $current_SaleReturn->id);
                }

                foreach ($__isNativeReturn ? [] : $old_return_details as $key => $value) {

                    // check if detail has sale_unit_id Or Null
                    if ($value['sale_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['sale_unit_id'])->first();
                    } else {
                        $product_unit_sale_id = Product::with('unitSale')
                            ->where('id', $value['product_id'])
                            ->first();

                        if ($product_unit_sale_id['unitSale']) {
                            $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                        }
                        $unit = null;

                    }

                    // Multi-Pack Selling: reverse using the pack multiplier snapshot.
                    $selPackMul = (float) ($value['pack_multiplier'] ?? 0) > 0 ? (float) $value['pack_multiplier'] : 1;
                    $selPackQty = $value['quantity'] * $selPackMul;

                    if ($current_SaleReturn->statut == 'received') {
                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $selPackQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $selPackQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }

                        } else {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)->where('warehouse_id', $current_SaleReturn->warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->first();

                            if ($unit && $product_warehouse) {
                                if ($unit->operator == '/') {
                                    $product_warehouse->qte -= $selPackQty / $unit->operator_value;
                                } else {
                                    $product_warehouse->qte -= $selPackQty * $unit->operator_value;
                                }
                                $product_warehouse->save();
                            }
                        }
                    }

                }

                $current_SaleReturn->details()->delete();
                $current_SaleReturn->update([
                    'deleted_at' => Carbon::now(),
                ]);

                // get all payments
                $payments = PaymentSaleReturns::where('sale_return_id', $SaleReturn_id)->get();

                foreach ($payments as $payment) {

                    $account = Account::find($payment->account_id);

                    if ($account) {
                        $account->update([
                            'balance' => $account->balance + $payment->montant,
                        ]);
                    }

                }
                PaymentSaleReturns::where('sale_return_id', $SaleReturn_id)->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------- GET Payments Sale Return-----------\\

    public function Payment_Returns(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentSaleReturns::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $SaleReturn = SaleReturn::findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === SaleReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $SaleReturn);
        }

        $payments = PaymentSaleReturns::with('SaleReturn', 'payment_method')
            ->where('sale_return_id', $id)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $SaleReturn->GrandTotal - $SaleReturn->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    // ------------ Reference Order Of Sale Return --------------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'RT' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->sale_return_prefix) ? $setting->sale_return_prefix : 'RT';
        
        // Get the last sale return with a reference that starts with the prefix
        $last = DB::table('sale_returns')
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

    protected function issueStoreCreditVoucher(SaleReturn $saleReturn): ?StoreCreditVoucher
    {
        if ((float) $saleReturn->GrandTotal <= 0 || $saleReturn->store_credit_voucher_id) {
            return $saleReturn->store_credit_voucher_id
                ? StoreCreditVoucher::find($saleReturn->store_credit_voucher_id)
                : null;
        }

        $existing = StoreCreditVoucher::where('sale_return_id', $saleReturn->id)->first();
        if ($existing) {
            $saleReturn->update([
                'refund_mode' => 'store_credit',
                'store_credit_voucher_id' => $existing->id,
                'store_credit_amount' => $existing->original_amount,
                'paid_amount' => $saleReturn->GrandTotal,
                'payment_statut' => 'paid',
            ]);

            return $existing;
        }

        $amount = round((float) $saleReturn->GrandTotal, 2);
        $voucher = StoreCreditVoucher::create([
            'code' => $this->generateStoreCreditCode(),
            'tenant_id' => $this->tenantSnapshotId(),
            'client_id' => $saleReturn->client_id,
            'original_sale_id' => $saleReturn->sale_id,
            'sale_return_id' => $saleReturn->id,
            'warehouse_id' => $saleReturn->warehouse_id,
            'issued_by_user_id' => Auth::id(),
            'original_amount' => $amount,
            'remaining_balance' => $amount,
            'currency' => (new helpers)->Get_Currency_Code(),
            'status' => 'active',
            'issued_at' => now(),
            'notes' => $saleReturn->notes,
        ]);

        StoreCreditVoucherTransaction::create([
            'voucher_id' => $voucher->id,
            'sale_return_id' => $saleReturn->id,
            'user_id' => Auth::id(),
            'warehouse_id' => $saleReturn->warehouse_id,
            'type' => 'issue',
            'amount' => $amount,
            'balance_before' => 0,
            'balance_after' => $amount,
            'notes' => 'Emitido por devolución '.$saleReturn->Ref,
        ]);

        $saleReturn->update([
            'refund_mode' => 'store_credit',
            'store_credit_voucher_id' => $voucher->id,
            'store_credit_amount' => $amount,
            'paid_amount' => $amount,
            'payment_statut' => 'paid',
        ]);

        return $voucher;
    }

    protected function generateStoreCreditCode(): string
    {
        do {
            $code = 'VAL-HN-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (StoreCreditVoucher::where('code', $code)->exists());

        return $code;
    }

    protected function tenantSnapshotId(): ?string
    {
        try {
            return function_exists('tenant') && tenant() ? (string) tenant()->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function formatStoreCreditVoucherResponse(SaleReturn $saleReturn): ?array
    {
        $voucher = $saleReturn->storeCreditVoucher;
        if (! $voucher) {
            return null;
        }

        $details = $saleReturn->details->map(function ($detail) {
            $unit = $detail->sale_unit_id ? Unit::find($detail->sale_unit_id) : optional($detail->product)->unitSale;

            return [
                'product_id' => $detail->product_id,
                'code' => optional($detail->product)->code,
                'name' => optional($detail->product)->name,
                'quantity' => number_format((float) $detail->quantity, helpers::price_decimals(), '.', ''),
                'unit' => optional($unit)->ShortName,
                'amount' => number_format((float) $detail->total, helpers::price_decimals(), '.', ''),
            ];
        })->values()->all();

        return [
            'voucher_id' => $voucher->id,
            'code' => $voucher->code,
            'original_amount' => number_format((float) $voucher->original_amount, helpers::price_decimals(), '.', ''),
            'remaining_balance' => number_format((float) $voucher->remaining_balance, helpers::price_decimals(), '.', ''),
            'status' => $voucher->status,
            'client' => [
                'id' => $saleReturn->client_id,
                'name' => optional($saleReturn->client)->name,
                'code' => optional($saleReturn->client)->code,
                'tax_number' => optional($saleReturn->client)->tax_number,
            ],
            'sale_return_id' => $saleReturn->id,
            'sale_return_ref' => $saleReturn->Ref,
            'original_sale_id' => $saleReturn->sale_id,
            'original_sale_ref' => optional($saleReturn->sale)->Ref,
            'issued_at' => optional($voucher->issued_at)->format('Y-m-d H:i:s'),
            'expires_at' => optional($voucher->expires_at)->format('Y-m-d H:i:s'),
            'cashier' => optional($saleReturn->user)->username ?: trim((optional($saleReturn->user)->firstname ?? '').' '.(optional($saleReturn->user)->lastname ?? '')),
            'warehouse' => [
                'id' => $saleReturn->warehouse_id,
                'name' => optional($saleReturn->warehouse)->name,
            ],
            'items' => $details,
            'company' => optional(Setting::where('deleted_at', '=', null)->first())->only([
                'CompanyName',
                'company_name',
                'logo',
                'tax_number',
                'company_tax_number',
                'adresse',
                'address',
                'phone',
                'CompanyPhone',
            ]),
        ];
    }

    // ---------------- Get Details Sale Return  -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', SaleReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Sale_Return = SaleReturn::with('sale', 'details.product.unitSale', 'storeCreditVoucher.transactions')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === SaleReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Sale_Return);
        }

        $return_details['Ref'] = $Sale_Return->Ref;
        $return_details['sale_id'] = $Sale_Return->sale_id ? $Sale_Return['sale']->id : null;
        $return_details['sale_ref'] = $Sale_Return['sale'] ? $Sale_Return['sale']->Ref : '---';
        $return_details['date'] = $Sale_Return->date.' '.$Sale_Return->time;
        $return_details['note'] = $Sale_Return->notes;
        $return_details['statut'] = $Sale_Return->statut;
        $return_details['discount'] = $Sale_Return->discount;
        $return_details['shipping'] = $Sale_Return->shipping;
        $return_details['tax_rate'] = $Sale_Return->tax_rate;
        $return_details['TaxNet'] = $Sale_Return->TaxNet;
        $return_details['client_name'] = $Sale_Return['client']->name;
        $return_details['client_phone'] = $Sale_Return['client']->phone;
        $return_details['client_adr'] = $Sale_Return['client']->adresse;
        $return_details['client_email'] = $Sale_Return['client']->email;
        $return_details['client_tax'] = $Sale_Return['client']->tax_number;
        $return_details['warehouse'] = $Sale_Return['warehouse']->name;
        $return_details['GrandTotal'] = number_format($Sale_Return->GrandTotal, helpers::price_decimals(), '.', '');
        $return_details['paid_amount'] = number_format($Sale_Return->paid_amount, helpers::price_decimals(), '.', '');
        $return_details['due'] = number_format($return_details['GrandTotal'] - $return_details['paid_amount'], helpers::price_decimals(), '.', '');
        $return_details['payment_status'] = $Sale_Return->payment_statut;
        $return_details['refund_mode'] = $Sale_Return->refund_mode;
        $return_details['store_credit_amount'] = number_format((float) ($Sale_Return->store_credit_amount ?? 0), helpers::price_decimals(), '.', '');
        $return_details['store_credit_voucher'] = $Sale_Return->storeCreditVoucher ? [
            'id' => $Sale_Return->storeCreditVoucher->id,
            'code' => $Sale_Return->storeCreditVoucher->code,
            'original_amount' => number_format((float) $Sale_Return->storeCreditVoucher->original_amount, helpers::price_decimals(), '.', ''),
            'remaining_balance' => number_format((float) $Sale_Return->storeCreditVoucher->remaining_balance, helpers::price_decimals(), '.', ''),
            'status' => $Sale_Return->storeCreditVoucher->status,
            'issued_at' => optional($Sale_Return->storeCreditVoucher->issued_at)->format('Y-m-d H:i:s'),
        ] : null;

        $batchesByDetail = app(BatchService::class)->batchesForSaleReturnDetails($Sale_Return['details']);

        foreach ($Sale_Return['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['quantity'] = $detail->quantity;
            $data['total'] = $detail->total;
            $data['price'] = $detail->price;
            $data['unit_sale'] = $unit ? $unit->ShortName : '';

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['discount'] = $detail->discount;

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
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
            'sale_Return' => $return_details,
            'company' => $company,
        ]);
    }

    // ---------------- Show Elements Sale Return ---------------\\

    public function create(Request $request)
    {

        //

    }

    // ---------------- edit ---------------\\

    public function edit(Request $request, $id)
    {

        //

    }

    // --------------- POS: search sales to start a return ---------------\\
    // Gated on the sale-return permission so a cashier can look up an
    // original sale from the POS without needing the full "view sales"
    // permission. Returns a small payload sufficient for the picker.
    public function pos_search_sales(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SaleReturn::class);

        $user = Auth::user();
        $view_records = $user->hasRecordView();
        $is_all_warehouses = $user->is_all_warehouses;

        $search = $request->input('search', '');
        $limit = (int) $request->input('limit', 10);
        if ($limit <= 0 || $limit > 50) {
            $limit = 10;
        }

        $query = Sale::with('client', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($q) use ($view_records, $user) {
                if (! $view_records) {
                    $q->where('user_id', '=', $user->id);
                }
            });

        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
            $query->whereIn('warehouse_id', $warehouse_ids);
        }

        if ($search !== '' && $search !== null) {
            $query->where(function ($q) use ($search) {
                $q->where('Ref', 'LIKE', "%{$search}%")
                    ->orWhere('GrandTotal', $search)
                    ->orWhereHas('client', function ($c) use ($search) {
                        $c->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        $sales = $query->orderBy('id', 'desc')->limit($limit)->get();

        $data = [];
        foreach ($sales as $sale) {
            $has_return = SaleReturn::where('sale_id', $sale->id)
                ->where('deleted_at', '=', null)->exists();

            $data[] = [
                'id' => $sale->id,
                'Ref' => $sale->Ref,
                'date' => $sale->date,
                'client_id' => $sale->client ? $sale->client->id : null,
                'client_name' => $sale->client ? $sale->client->name : '',
                'warehouse_name' => $sale->warehouse ? $sale->warehouse->name : '',
                'GrandTotal' => number_format($sale->GrandTotal, helpers::price_decimals(), '.', ''),
                'sale_has_return' => $has_return ? 'yes' : 'no',
            ];
        }

        return response()->json(['sales' => $data]);
    }

    public function create_sell_return(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'create', SaleReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $SaleReturn = Sale::with('details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === SaleReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $SaleReturn);
        }

        $Return_detail['client_id'] = $SaleReturn->client_id;
        $Return_detail['warehouse_id'] = $SaleReturn->warehouse_id;
        $Return_detail['sale_id'] = $SaleReturn->id;
        $Return_detail['sale_ref'] = $SaleReturn->Ref;
        $Return_detail['tax_rate'] = 0;
        $Return_detail['TaxNet'] = 0;
        $Return_detail['discount'] = 0;
        $Return_detail['shipping'] = 0;
        $Return_detail['statut'] = 'received';
        $Return_detail['notes'] = '';

        $detail_id = 0;
        foreach ($SaleReturn['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $SaleReturn->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = $detail->product_variant_id;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('warehouse_id', $SaleReturn->warehouse_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['product_type'] = $detail['product']['type'];
            $data['quantity'] = 0;
            $data['sale_quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            $data['sale_unit_id'] = $unit ? $unit->id : '';
            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {

                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'sale_return' => $Return_detail,
        ]);

    }

    // ------------- Sale Return PDF-----------\\

    public function Return_pdf(Request $request, $id)
    {

        $details = [];
        $helpers = new helpers;
        $Sale_Return = SaleReturn::with('sale', 'details.product.unitSale')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $batchesByDetail = app(BatchService::class)->batchesForSaleReturnDetails($Sale_Return['details']);

        $return_details['client_name'] = $Sale_Return['client']->name;
        $return_details['client_phone'] = $Sale_Return['client']->phone;
        $return_details['client_adr'] = $Sale_Return['client']->adresse;
        $return_details['client_email'] = $Sale_Return['client']->email;
        $return_details['client_tax'] = $Sale_Return['client']->tax_number;
        $return_details['TaxNet'] = number_format($Sale_Return->TaxNet, helpers::price_decimals(), '.', '');
        $return_details['discount'] = number_format($Sale_Return->discount, helpers::price_decimals(), '.', '');
        $return_details['shipping'] = number_format($Sale_Return->shipping, helpers::price_decimals(), '.', '');
        $return_details['statut'] = $Sale_Return->statut;
        $return_details['sale_ref'] = $Sale_Return['sale'] ? $Sale_Return['sale']->Ref : '---';
        $return_details['Ref'] = $Sale_Return->Ref;
        $return_details['date'] = $Sale_Return->date.' '.$Sale_Return->time;
        $return_details['GrandTotal'] = number_format($Sale_Return->GrandTotal, helpers::price_decimals(), '.', '');
        $return_details['paid_amount'] = number_format($Sale_Return->paid_amount, helpers::price_decimals(), '.', '');
        $return_details['due'] = number_format($return_details['GrandTotal'] - $return_details['paid_amount'], helpers::price_decimals(), '.', '');
        $return_details['payment_status'] = $Sale_Return->payment_statut;

        $detail_id = 0;
        foreach ($Sale_Return['details'] as $detail) {
            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

            }

            if ($detail->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total, helpers::price_decimals(), '.', '');
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            $data['price'] = number_format($detail->price, helpers::price_decimals(), '.', '');

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = number_format($detail->discount, helpers::price_decimals(), '.', '');
            } else {
                $data['DiscountNet'] = number_format($detail->price * $detail->discount / 100, helpers::price_decimals(), '.', '');
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = number_format($detail->price, helpers::price_decimals(), '.', '');
            $data['discount'] = $detail->discount;
            number_format($detail->discount, helpers::price_decimals(), '.', '');

            if ($detail->tax_method == '1') {
                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = number_format($tax_price, helpers::price_decimals(), '.', '');
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = number_format($detail->price - $data['Net_price'] - $data['DiscountNet'], helpers::price_decimals(), '.', '');
            }

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.Sales_Return_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'return_sale' => $return_details,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Sales_Return.pdf');
    }

    // ------------- Show Form Edit Sale Return-----------\\

    public function edit_sell_return(Request $request, $id, $sale_id)
    {

        $this->authorizeForUser($request->user('api'), 'update', SaleReturn::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $SaleReturn = SaleReturn::with('sale', 'details.product.unitSale')
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

            if (empty($SaleReturn->warehouse_id) || ! in_array($SaleReturn->warehouse_id, $warehouses_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this sale (warehouse restriction).',
                ], 403);
            }
        }


        $details = [];
        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === SaleReturn->id
            $this->authorizeForUser($request->user('api'), 'check_record', $SaleReturn);
        }

        $Return_detail['client_id'] = $SaleReturn->client_id;
        $Return_detail['warehouse_id'] = $SaleReturn->warehouse_id;
        $Return_detail['sale_id'] = $SaleReturn->sale_id ? $SaleReturn['sale']->id : null;
        $Return_detail['sale_ref'] = $SaleReturn['sale'] ? $SaleReturn['sale']->Ref : '---';
        $Return_detail['date'] = $SaleReturn->date;
        $Return_detail['tax_rate'] = $SaleReturn->tax_rate;
        $Return_detail['TaxNet'] = $SaleReturn->TaxNet;
        $Return_detail['discount'] = $SaleReturn->discount;
        $Return_detail['shipping'] = $SaleReturn->shipping;
        $Return_detail['notes'] = $SaleReturn->notes;
        $Return_detail['statut'] = $SaleReturn->statut;
        // MS7-B1 — the persisted destination location, if this is a native document.
        $Return_detail['inventory_location_id'] = $SaleReturn->inventory_location_id;

        $detail_id = 0;
        foreach ($SaleReturn['details'] as $detail) {

            // check if detail has sale_unit_id Or Null
            if ($detail->sale_unit_id !== null) {
                $unit = Unit::where('id', $detail->sale_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_sale_id = Product::with('unitSale')
                    ->where('id', $detail->product_id)
                    ->first();

                if ($product_unit_sale_id['unitSale']) {
                    $unit = Unit::where('id', $product_unit_sale_id['unitSale']->id)->first();
                }
                $unit = null;

                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $SaleReturn->warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = $detail->product_variant_id;

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('warehouse_id', $SaleReturn->warehouse_id)
                    ->where('deleted_at', '=', null)->where('product_variant_id', '=', null)
                    ->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;

            $sell_detail = SaleDetail::where('sale_id', $sale_id)
                ->where('product_id', $detail->product_id)
                ->where('product_variant_id', $detail->product_variant_id)
                ->first();

            $data['sale_quantity'] = $sell_detail->quantity;
            $data['product_type'] = $detail['product']['type'];
            $data['quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitSale'] = $unit ? $unit->ShortName : '';
            $data['sale_unit_id'] = $unit ? $unit->id : '';
            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->price * $detail->discount / 100;
            }

            $tax_price = $detail->TaxNet * (($detail->price - $data['DiscountNet']) / 100);
            $data['Unit_price'] = $detail->price;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {

                $data['Net_price'] = $detail->price - $data['DiscountNet'];
                $data['taxe'] = $tax_price;
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            } else {
                $data['Net_price'] = ($detail->price - $data['DiscountNet'] - $tax_price);
                $data['taxe'] = $detail->price - $data['Net_price'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_price'] * $data['quantity']) + ($tax_price * $data['quantity']);
            }

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'sale_return' => $Return_detail,
        ]);
    }
}
