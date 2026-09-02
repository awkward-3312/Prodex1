<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode;
use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\EmailMessage;
use App\Models\InventoryLocation;
use App\Models\InventoryTransitionState;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseCustomField;
use App\Models\PurchaseDetail;
use App\Models\PurchaseExtraCharge;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\InventoryLocationScopeService;
use App\Services\LocationAwarePurchaseStockService;
use App\Services\SerialNumberService;
use App\Services\WarehouseInventoryModeResolver;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PurchasesController extends BaseController
{
    use GuardsPurchaseTransitionMode;

    // ------------- Show ALL Purchases ----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
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
        ];
        $columns = [
            0 => 'Ref',
            1 => 'statut',
            2 => 'provider_id',
            3 => 'payment_statut',
            4 => 'warehouse_id',
            5 => 'date',
        ];
        $data = [];
        $total = 0;

        // Check If User Has Permission View  All Records
        $Purchases = Purchase::with('facture', 'provider', 'warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Purchases->whereIn('warehouse_id', $warehouse_ids);
        }

        // Multiple Filter
        $Filtred = $helpers->filter($Purchases, $columns, $param, $request)
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
        $Purchases = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Purchases as $Purchase) {

            $item['id'] = $Purchase->id;
            $item['date'] = $Purchase['date'].' '.$Purchase['time'];
            $item['Ref'] = $Purchase->Ref;
            $item['warehouse_name'] = $Purchase['warehouse']->name;
            $item['discount'] = $Purchase->discount;
            $item['shipping'] = $Purchase->shipping;
            $item['statut'] = $Purchase->statut;
            $item['provider_id'] = $Purchase['provider']->id;
            $item['provider_name'] = $Purchase['provider']->name;
            $item['provider_email'] = $Purchase['provider']->email;
            $item['provider_tele'] = $Purchase['provider']->phone;
            $item['provider_code'] = $Purchase['provider']->code;
            $item['provider_adr'] = $Purchase['provider']->adresse;
            $item['GrandTotal'] = number_format($Purchase->GrandTotal, helpers::price_decimals(), '.', '');
            $item['paid_amount'] = number_format($Purchase->paid_amount, helpers::price_decimals(), '.', '');
            $item['due'] = number_format($item['GrandTotal'] - $item['paid_amount'], helpers::price_decimals(), '.', '');
            $item['payment_status'] = $Purchase->payment_statut;

            if (PurchaseReturn::where('purchase_id', $Purchase['id'])->where('deleted_at', '=', null)->exists()) {
                $PurchaseReturn = PurchaseReturn::where('purchase_id', $Purchase['id'])->where('deleted_at', '=', null)->first();
                $item['purchasereturn_id'] = $PurchaseReturn->id;
                $item['purchase_has_return'] = 'yes';
            } else {
                $item['purchase_has_return'] = 'no';
            }

            // Get documents count
            $item['documents_count'] = DB::table('purchase_documents')
                ->where('purchase_id', $Purchase['id'])
                ->whereNull('deleted_at')
                ->count();

            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $accounts = Account::where('deleted_at', '=', null)->orderBy('id', 'desc')->get(['id', 'account_name']);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $payment_methods = PaymentMethod::whereNull('deleted_at')->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'purchases' => $data,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'accounts' => $accounts,
            'payment_methods' => $payment_methods,
        ]);
    }

    // ------ Store new Purchase -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
        ]);

        // MS2 — routing by warehouse transition mode. A CREATE for a
        // location_primary warehouse goes to the location-native engine; every
        // other mode (legacy_only / shadow_compare / dual_write / no row) keeps
        // the exact legacy flow below.
        if (app(WarehouseInventoryModeResolver::class)->isLocationPrimary((int) $request->warehouse_id)) {
            return $this->storeLocationAware($request);
        }

        \DB::transaction(function () use ($request) {
            // MS2 boundary — a location_primary warehouse must NEVER take a
            // legacy-only stock mutation (closes the router race: mode could
            // flip between routing and here). Locks the transition state first.
            $this->assertLegacyPurchaseTransitionSafe(null, (int) $request->warehouse_id);

            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = $request->GrandTotal;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $this->syncExtraChargesAndCustomFields($order->id, $request);

            $data = $request['details'];
            foreach ($data as $key => $value) {
                $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                $orderDetails[] = [
                    'purchase_id' => $order->id,
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
                    'imei_number' => $this->resolveImeiString($value),
                ];

                if ($order->statut == 'received') {
                    if ($value['product_variant_id'] !== null) {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $order->warehouse_id)
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
                            ->where('warehouse_id', $order->warehouse_id)
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
            PurchaseDetail::insert($orderDetails);

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            // Re-fetch in id order so position matches the input array order.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received') {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $data, $persisted);
            }

            // Serial / IMEI: register one ledger row per received unit.
            $serialService = app(SerialNumberService::class);
            if ($serialService->isSupported() && $order->statut == 'received') {
                $persistedSerials = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                foreach (array_values($data) as $i => $row) {
                    $detail = $persistedSerials->get($i);
                    if (! $detail) {
                        continue;
                    }
                    $serialService->receiveOnPurchase($order, $detail, $row['serial_numbers'] ?? null);
                }
            }
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    /**
     * Back-compat: collapse a detail row's serial_numbers (array or delimited
     * string) into the legacy comma-separated imei_number text column so existing
     * invoice/receipt templates keep working. Falls back to the raw imei_number.
     */
    private function resolveImeiString($value)
    {
        $serials = $value['serial_numbers'] ?? null;
        if (is_string($serials)) {
            $serials = preg_split('/[\r\n,;\t]+/', $serials) ?: [];
        }
        if (is_array($serials)) {
            $clean = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $serials), fn ($s) => $s !== ''));
            if (! empty($clean)) {
                return implode(',', $clean);
            }
        }

        return $value['imei_number'] ?? null;
    }

    /**
     * Replace a purchase's extra charges (name + amount, included in GrandTotal)
     * and custom fields (name + free text value, informational only) with the
     * arrays sent by the create/edit forms.
     */
    private function syncExtraChargesAndCustomFields($purchaseId, Request $request)
    {
        PurchaseExtraCharge::where('purchase_id', $purchaseId)->whereNull('deleted_at')
            ->update(['deleted_at' => Carbon::now()]);
        PurchaseCustomField::where('purchase_id', $purchaseId)->whereNull('deleted_at')
            ->update(['deleted_at' => Carbon::now()]);

        foreach ((array) $request['extra_charges'] as $charge) {
            $name = trim((string) ($charge['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            PurchaseExtraCharge::create([
                'purchase_id' => $purchaseId,
                'name' => $name,
                'amount' => is_numeric($charge['amount'] ?? null) ? $charge['amount'] : 0,
            ]);
        }

        foreach ((array) $request['custom_fields'] as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            PurchaseCustomField::create([
                'purchase_id' => $purchaseId,
                'name' => $name,
                'value' => $field['value'] ?? null,
            ]);
        }
    }

    // =====================================================================
    // MS2 — Purchases location-native (warehouses in MODE_LOCATION_PRIMARY).
    //
    // Scope: is_single / is_variant, manual purchases only. Import stays legacy
    // (MS4). PurchaseReturn stays legacy (MS3). batch / IMEI => the service
    // fails closed. NO legacy product_warehouse / BatchService / SerialNumber
    // writes on this path. The document identity (inventory_location_id NOT
    // NULL) — not the warehouse's current mode — governs update/destroy.
    // =====================================================================

    /** Raw request details -> LocationAwarePurchaseStockService line shape. */
    private function locationAwarePurchaseLines(Request $request): array
    {
        return array_map(fn ($v) => [
            'product_id' => $v['product_id'] ?? null,
            'product_variant_id' => (isset($v['product_variant_id']) && $v['product_variant_id'] !== '' ) ? $v['product_variant_id'] : null,
            'quantity' => $v['quantity'] ?? 0,
            'purchase_unit_id' => $v['purchase_unit_id'] ?? null,
        ], array_values($request['details'] ?? []));
    }

    /**
     * Create PurchaseDetail rows one by one (NOT bulk insert) so we get each
     * real id back in request order. Returns [lineIndex => purchase_detail_id].
     */
    private function persistLocationAwarePurchaseDetails(int $purchaseId, array $rawLines): array
    {
        $ids = [];
        foreach (array_values($rawLines) as $i => $value) {
            $d = PurchaseDetail::create([
                'purchase_id' => $purchaseId,
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
                'imei_number' => $this->resolveImeiString($value),
            ]);
            $ids[$i] = $d->id;
        }

        return $ids;
    }

    /** Attach the real source_detail_id to each validated line (same order). */
    private function withSourceDetailIds(array $validatedLines, array $detailIds): array
    {
        $out = [];
        foreach (array_values($validatedLines) as $i => $ln) {
            $out[] = ['source_detail_id' => $detailIds[$i] ?? null] + $ln;
        }

        return $out;
    }

    private function paymentStatutFor(float $grandTotal, float $paidAmount): string
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
            // FAIL CLOSED — location_primary must be healthy; no legacy fallback.
            // Locks inventory_transition_states before any stock write.
            $this->assertLocationNativePurchaseTransitionSafe($warehouseId, null);

            request()->validate(['inventory_location_id' => 'required|integer']);
            $locationId = (int) $request->inventory_location_id;
            $rawLines = array_values($request['details'] ?? []);

            // Validate + lock ALWAYS (pending included): a location_primary
            // purchase can only be saved with a valid location + valid lines.
            $validated = $svc->validateAndLock(
                LocationAwarePurchaseStockService::DOC_PURCHASE,
                $warehouseId,
                $locationId,
                $this->locationAwarePurchaseLines($request)
            );

            $order = new Purchase;
            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = $request->GrandTotal;
            $order->warehouse_id = $warehouseId;
            $order->inventory_location_id = $locationId;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = $request->TaxNet;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            $order->save();

            $this->syncExtraChargesAndCustomFields($order->id, $request);

            $detailIds = $this->persistLocationAwarePurchaseDetails($order->id, $rawLines);

            // Physical effect ONLY for a received purchase. A pending purchase
            // keeps location + header + details, but NO snapshot and NO
            // movements — the snapshot represents an effect that WAS applied.
            if ($statut === 'received') {
                $validated['lines'] = $this->withSourceDetailIds($validated['lines'], $detailIds);
                $snapshot = $svc->buildSnapshot($validated, 1);
                $order->update(['inventory_effect_snapshot' => $snapshot]);
                $svc->applySnapshot($snapshot, $order->id);
            }
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    // --------- UPDATE (location-native) — state machine A/B/C/D ---------\\

    private function updateLocationAware(Request $request, Purchase $current)
    {
        $svc = app(LocationAwarePurchaseStockService::class);
        $newWarehouseId = (int) $request->warehouse_id;
        $newStatut = $request->statut;

        $response = \DB::transaction(function () use ($request, $current, $svc, $newWarehouseId, $newStatut) {
            $user = Auth::user();
            $view_records = $user->hasRecordView();

            // Same guards as the legacy path.
            if (! $user->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
                if (empty($current->warehouse_id) || ! in_array($current->warehouse_id, $warehouses_id)) {
                    return response()->json(['success' => false, 'message' => 'You are not allowed to access this sale (warehouse restriction).'], 403);
                }
            }
            if (PurchaseReturn::where('purchase_id', $current->id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            }
            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $current);
            }

            $locked = Purchase::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['purchase' => 'Registro legacy: usa la ruta histórica.']);
            }

            // MS2 boundary — BOTH the stored warehouse and the target warehouse
            // must still be healthy location_primary. Fails closed if the origin
            // warehouse was demoted, or the target is not primary / unhealthy.
            // Locks inventory_transition_states before touching stock.
            $this->assertLocationNativePurchaseTransitionSafe((int) $locked->warehouse_id, $newWarehouseId);

            $oldStatut = $locked->statut;
            $oldSnapshotRaw = $locked->inventory_effect_snapshot; // array|null (cast)
            $hasHistoricalSnapshot = ! empty($oldSnapshotRaw);
            $hadActiveEffect = ($oldStatut === 'received') && $hasHistoricalSnapshot;

            $newLocationId = $request->filled('inventory_location_id') ? (int) $request->inventory_location_id : null;
            if (! $newLocationId) {
                throw ValidationException::withMessages(['inventory_location_id' => 'Debes seleccionar una ubicación de inventario.']);
            }

            $rawLines = array_values($request['details'] ?? []);

            // Revision progression: 1 if there was never a snapshot; else old + 1.
            $oldRevision = 0;
            if ($hasHistoricalSnapshot) {
                $normOld = $svc->normalizeSnapshot($oldSnapshotRaw);
                $oldRevision = $normOld['revision'];
            }

            // (C, D) reverse the currently-applied effect using the OLD snapshot
            // (old warehouse/location/effects live inside it).
            if ($hadActiveEffect) {
                $oldSnapshot = $svc->normalizeSnapshot($oldSnapshotRaw);
                $svc->assertSnapshotArtifactSafeAndLock($oldSnapshot);
                $svc->reverseSnapshot($oldSnapshot, $locked->id);
            }

            // Validate the NEW lines + lock (pending and received alike).
            $extra = [];
            if ($hasHistoricalSnapshot) {
                $extra = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $svc->normalizeSnapshot($oldSnapshotRaw)['effects'])));
            }
            $validated = $svc->validateAndLock(
                LocationAwarePurchaseStockService::DOC_PURCHASE,
                $newWarehouseId,
                $newLocationId,
                $this->locationAwarePurchaseLines($request),
                $extra
            );

            // Replace details (location-native never touched batch/serial).
            PurchaseDetail::where('purchase_id', $locked->id)->delete();
            $detailIds = $this->persistLocationAwarePurchaseDetails($locked->id, $rawLines);

            // (B, C) apply a NEW snapshot only when the new statut is received.
            $newSnapshot = null;
            if ($newStatut === 'received') {
                $validated['lines'] = $this->withSourceDetailIds($validated['lines'], $detailIds);
                $newSnapshot = $svc->buildSnapshot($validated, $oldRevision + 1);
                $svc->applySnapshot($newSnapshot, $locked->id);
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
                'payment_statut' => $this->paymentStatutFor((float) $request['GrandTotal'], (float) $locked->paid_amount),
            ];
            if ($newSnapshot !== null) {
                // C / B
                $payload['inventory_effect_snapshot'] = $newSnapshot;
            }
            // A (pending->pending) and D (received->pending): keep the last
            // historical snapshot so a later pending->received bumps its revision.
            $locked->update($payload);

            $this->syncExtraChargesAndCustomFields($locked->id, $request);

            return null;
        }, 10);

        if ($response !== null) {
            return $response;
        }

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);
    }

    // --------- DESTROY (location-native) -------------------------------\\

    private function destroyLocationAware(Request $request, Purchase $current)
    {
        $svc = app(LocationAwarePurchaseStockService::class);

        $response = \DB::transaction(function () use ($request, $current, $svc) {
            $user = Auth::user();
            $view_records = $user->hasRecordView();

            $locked = Purchase::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();

            if (! $user->is_all_warehouses) {
                $warehouses_id = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->toArray();
                if (empty($locked->warehouse_id) || ! in_array($locked->warehouse_id, $warehouses_id)) {
                    return response()->json(['success' => false, 'message' => 'You are not allowed to access this sale (warehouse restriction).'], 403);
                }
            }
            if (PurchaseReturn::where('purchase_id', $locked->id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            }
            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $locked);
            }

            // MS2 boundary — the snapshot can only be reversed inside the
            // architecture that created it: the persisted warehouse must still
            // be healthy location_primary. Locks the transition state first.
            $this->assertLocationNativePurchaseTransitionSafe((int) $locked->warehouse_id, null);

            $this->reverseLocationNativePurchaseStock($locked);

            $locked->details()->delete();
            $locked->update(['deleted_at' => Carbon::now()]);

            $Payment_purchase_data = PaymentPurchase::where('purchase_id', $locked->id)->get();
            foreach ($Payment_purchase_data as $Payment_purchase) {
                $account = Account::find($Payment_purchase->account_id);
                if ($account) {
                    $account->update(['balance' => $account->balance + $Payment_purchase->montant]);
                }
                $Payment_purchase->delete();
            }

            return null;
        }, 10);

        if ($response !== null) {
            return $response;
        }

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    /**
     * Shared physical-stock reversal for a location-native purchase being
     * deleted (single destroy AND bulk delete). Received => reverse the exact
     * historical snapshot; pending => nothing. FAIL CLOSED on a missing/broken
     * snapshot — never guess, never fall back to the legacy per-warehouse writer.
     */
    private function reverseLocationNativePurchaseStock(Purchase $purchase): void
    {
        if ($purchase->statut !== 'received') {
            return;
        }

        $svc = app(LocationAwarePurchaseStockService::class);
        $snapshot = $svc->normalizeSnapshot($purchase->inventory_effect_snapshot); // throws if null/malformed
        $svc->assertSnapshotArtifactSafeAndLock($snapshot);
        $svc->reverseSnapshot($snapshot, $purchase->id);
    }

    // Transition-mode boundary guards live in the shared trait
    // App\Http\Controllers\Concerns\GuardsPurchaseTransitionMode
    // (assertLegacyPurchaseTransitionSafe / assertLocationNativePurchaseTransitionSafe /
    // inventoryLocationContextPayload).

    // --------- Inventory-location select for the purchase form ----------\\

    /**
     * GET purchases_inventory_locations/{warehouse_id}
     *
     * A purchase RECEIVES stock into a warehouse location => the user's
     * "receiving" scope (InventoryLocationScopeService::receivingLocationIds).
     */
    public function inventoryLocationsForWarehouse(Request $request, $warehouseId)
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', Purchase::class) || Gate::forUser($u)->allows('update', Purchase::class)),
            403,
            'No tienes permiso para consultar ubicaciones de inventario de compras.'
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

    // --------- Update Purchase  -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);

        request()->validate([
            'warehouse_id' => 'required',
            'supplier_id' => 'required',
        ]);

        // MS2 — route by the PERSISTED document identity, not the warehouse's
        // current mode. A location-native purchase stays location-native; a
        // legacy purchase stays legacy. A legacy purchase whose warehouse is
        // now location_primary is NOT silently converted — it FAILS CLOSED
        // (see assertLegacyPurchaseTransitionSafe below).
        $routing_purchase = Purchase::find($id);
        if ($routing_purchase && $routing_purchase->inventory_location_id !== null) {
            return $this->updateLocationAware($request, $routing_purchase);
        }

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

            // MS2 boundary — a legacy purchase may only be edited while NEITHER
            // its stored warehouse NOR the requested warehouse is
            // location_primary. Locks inventory_transition_states first.
            $this->assertLegacyPurchaseTransitionSafe((int) $current_Purchase->warehouse_id, (int) $request->warehouse_id);

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

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();
                $new_purchase_details = $request['details'];
                $length = count($new_purchase_details);

                // Pharmacy: reverse all existing batch links for this purchase before
                // re-applying. The legacy stock-adjustment block below already reverses
                // and re-applies product_warehouse qte; we mirror that for batches.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Serial / IMEI: only touch the serial ledger when the edit payload
                // actually carries serials (forms without serial UI must not wipe them).
                $serialService = app(SerialNumberService::class);
                $serialPayloadPresent = $serialService->payloadHasSerials($new_purchase_details);
                if ($serialService->isSupported() && $serialPayloadPresent) {
                    $serialService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Get Ids for new Details
                $new_products_id = [];
                foreach ($new_purchase_details as $new_detail) {
                    $new_products_id[] = $new_detail['id'];
                }

                // Init Data with old Parametre
                $old_products_id = [];
                foreach ($old_purchase_details as $key => $value) {
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
                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
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
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
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

                        // Delete Detail
                        if (! in_array($old_products_id[$key], $new_products_id)) {
                            $PurchaseDetail = PurchaseDetail::findOrFail($value->id);
                            $PurchaseDetail->delete();
                        }
                    }

                }

                // Update Data with New request
                foreach ($new_purchase_details as $key => $prod_detail) {

                    if ($prod_detail['no_unit'] !== 0) {
                        $unit_prod = Unit::where('id', $prod_detail['purchase_unit_id'])->first();

                        if ($request['statut'] == 'received') {

                            if ($prod_detail['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->where('product_variant_id', $prod_detail['product_variant_id'])
                                    ->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }

                            } else {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $request->warehouse_id)
                                    ->where('product_id', $prod_detail['product_id'])
                                    ->first();

                                if ($unit_prod && $product_warehouse) {
                                    if ($unit_prod->operator == '/') {
                                        $product_warehouse->qte += $prod_detail['quantity'] / $unit_prod->operator_value;
                                    } else {
                                        $product_warehouse->qte += $prod_detail['quantity'] * $unit_prod->operator_value;
                                    }

                                    $product_warehouse->save();
                                }
                            }

                        }

                        $orderDetails['purchase_id'] = $id;
                        $orderDetails['cost'] = $prod_detail['Unit_cost'];
                        $orderDetails['purchase_unit_id'] = $prod_detail['purchase_unit_id'];
                        $orderDetails['TaxNet'] = $prod_detail['tax_percent'];
                        $orderDetails['tax_method'] = $prod_detail['tax_method'];
                        $orderDetails['discount'] = $prod_detail['discount'];
                        $orderDetails['discount_method'] = $prod_detail['discount_Method'];
                        $orderDetails['quantity'] = $prod_detail['quantity'];
                        $orderDetails['product_id'] = $prod_detail['product_id'];
                        $orderDetails['product_variant_id'] = $prod_detail['product_variant_id'];
                        $orderDetails['total'] = $prod_detail['subtotal'];
                        $orderDetails['imei_number'] = $this->resolveImeiString($prod_detail);

                        if (! in_array($prod_detail['id'], $old_products_id)) {
                            PurchaseDetail::Create($orderDetails);
                        } else {
                            PurchaseDetail::where('id', $prod_detail['id'])->update($orderDetails);
                        }
                    }
                }

                $due = $request['GrandTotal'] - $current_Purchase->paid_amount;
                if ($due === 0.0 || $due < 0.0) {
                    $payment_statut = 'paid';
                } elseif ($due != $request['GrandTotal']) {
                    $payment_statut = 'partial';
                } elseif ($due == $request['GrandTotal']) {
                    $payment_statut = 'unpaid';
                }

                $current_Purchase->update([
                    'date' => $request['date'],
                    'provider_id' => $request['supplier_id'],
                    'warehouse_id' => $request['warehouse_id'],
                    'notes' => $request['notes'],
                    'tax_rate' => $request['tax_rate'],
                    'TaxNet' => $request['TaxNet'],
                    'discount' => $request['discount'],
                    'shipping' => $request['shipping'],
                    'statut' => $request['statut'],
                    'GrandTotal' => $request['GrandTotal'],
                    'payment_statut' => $payment_statut,
                ]);

                $this->syncExtraChargesAndCustomFields($id, $request);

                // Pharmacy: re-apply batches to the now-persisted PurchaseDetail rows.
                if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                    // Build aligned arrays: skip rows the controller skipped (no_unit === 0).
                    $alignedInput = [];
                    foreach ($new_purchase_details as $row) {
                        if (($row['no_unit'] ?? 1) !== 0) {
                            $alignedInput[] = $row;
                        }
                    }
                    $persisted = PurchaseDetail::where('purchase_id', $id)
                        ->orderBy('id', 'asc')
                        ->get();
                    // Match by (product_id, product_variant_id) preserving input order.
                    $aligned = [];
                    $persistedByKey = [];
                    foreach ($persisted as $d) {
                        $k = $d->product_id . '|' . ($d->product_variant_id ?? '');
                        $persistedByKey[$k][] = $d;
                    }
                    foreach ($alignedInput as $row) {
                        $k = ($row['product_id'] ?? '') . '|' . ($row['product_variant_id'] ?? '');
                        $match = isset($persistedByKey[$k]) ? array_shift($persistedByKey[$k]) : null;
                        $aligned[] = $match;
                    }
                    $batchService->applyForPurchase($current_Purchase, $alignedInput, $aligned);
                }

                // Serial / IMEI: re-create serials for the now-persisted details.
                if ($serialService->isSupported() && $serialPayloadPresent && $current_Purchase->statut == 'received') {
                    $sInput = [];
                    foreach ($new_purchase_details as $row) {
                        if (($row['no_unit'] ?? 1) !== 0) {
                            $sInput[] = $row;
                        }
                    }
                    $sPersisted = PurchaseDetail::where('purchase_id', $id)
                        ->orderBy('id', 'asc')
                        ->get();
                    $sByKey = [];
                    foreach ($sPersisted as $d) {
                        $sByKey[$d->product_id . '|' . ($d->product_variant_id ?? '')][] = $d;
                    }
                    $sAligned = [];
                    foreach ($sInput as $row) {
                        $k = ($row['product_id'] ?? '') . '|' . ($row['product_variant_id'] ?? '');
                        $sAligned[] = isset($sByKey[$k]) ? array_shift($sByKey[$k]) : null;
                    }
                    $serialService->resyncPurchaseSerials($current_Purchase, $sInput, $sAligned);
                }
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Updated !!']);

    }

    // ------ Delete Purchase -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        // MS2 — route by persisted identity (see update()).
        $routing_purchase = Purchase::find($id);
        if ($routing_purchase && $routing_purchase->inventory_location_id !== null) {
            return $this->destroyLocationAware($request, $routing_purchase);
        }

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Purchase = Purchase::findOrFail($id);

            // MS2 boundary — a legacy purchase in a location_primary warehouse
            // must NOT be torn down through the product_warehouse writer.
            $this->assertLegacyPurchaseTransitionSafe((int) $current_Purchase->warehouse_id, null);

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

                if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $old_purchase_details = PurchaseDetail::where('purchase_id', $id)->get();

            if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
                return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
            } else {

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === current_Purchase->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                }

                foreach ($old_purchase_details as $key => $value) {

                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($current_Purchase->statut == 'received') {

                        if ($value['product_variant_id'] !== null) {
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
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
                                ->where('warehouse_id', $current_Purchase->warehouse_id)
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

                // Pharmacy: reverse batch links before soft-deleting the details.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()) {
                    $batchService->reverseForPurchaseDetails($old_purchase_details);
                }

                // Serial / IMEI: release (or block) serials before deleting the details.
                $serialService = app(SerialNumberService::class);
                if ($serialService->isSupported()) {
                    $serialService->reverseForPurchaseDetails($old_purchase_details);
                }

                $current_Purchase->details()->delete();
                $current_Purchase->update([
                    'deleted_at' => Carbon::now(),
                ]);

                $Payment_purchase_data = PaymentPurchase::where('purchase_id', $id)->get();
                foreach ($Payment_purchase_data as $Payment_purchase) {
                    $account = Account::find($Payment_purchase->account_id);

                    if ($account) {
                        $account->update([
                            'balance' => $account->balance + $Payment_purchase->montant,
                        ]);
                    }

                    $Payment_purchase->delete();
                }

            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Purchase::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;

            // MS2 — lock EVERY involved transition state up front, ascending by
            // warehouse_id, so two concurrent bulk deletes cannot acquire them
            // in opposite order (tx A: wh1->wh2 vs tx B: wh2->wh1). The per-row
            // guards below then only assert against an already-locked row.
            app(WarehouseInventoryModeResolver::class)->lockStates(
                Purchase::whereIn('id', (array) $selectedIds)->pluck('warehouse_id')->all()
            );

            foreach ($selectedIds as $purchase_id) {

                if (PurchaseReturn::where('purchase_id', $purchase_id)->where('deleted_at', '=', null)->exists()) {
                    return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
                } else {

                    $current_Purchase = Purchase::findOrFail($purchase_id);

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

                        if (empty($current_Purchase->warehouse_id) || ! in_array($current_Purchase->warehouse_id, $warehouses_id)) {
                            return response()->json([
                                'success' => false,
                                'message' => 'You are not allowed to access this sale (warehouse restriction).',
                            ], 403);
                        }
                    }

                    $old_purchase_details = PurchaseDetail::where('purchase_id', $purchase_id)->get();
                    // Check If User Has Permission view All Records
                    if (! $view_records) {
                        // Check If User->id === current_Purchase->id
                        $this->authorizeForUser($request->user('api'), 'check_record', $current_Purchase);
                    }

                    // MS2 — mixed selection: each purchase reverses by ITS OWN
                    // identity, and each is fenced against a transition-mode
                    // mismatch. A throw here aborts the WHOLE \DB::transaction
                    // (this loop is inside one) => zero partial deletes, zero
                    // partial stock.
                    if ($current_Purchase->inventory_location_id !== null) {
                        $this->assertLocationNativePurchaseTransitionSafe((int) $current_Purchase->warehouse_id, null);
                        $this->reverseLocationNativePurchaseStock($current_Purchase);
                    } else {

                    $this->assertLegacyPurchaseTransitionSafe((int) $current_Purchase->warehouse_id, null);

                    // Pharmacy: reverse batch consumption (subtracts qty from product_batches,
                    // removes pivot rows) before warehouse stock is decremented below.
                    $batchService = app(BatchService::class);
                    if ($batchService->isSupported() && $current_Purchase->statut == 'received') {
                        $batchService->reverseForPurchaseDetails($old_purchase_details);
                    }

                    foreach ($old_purchase_details as $key => $value) {

                        // check if detail has purchase_unit_id Or Null
                        if ($value['purchase_unit_id'] !== null) {
                            $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                        } else {
                            $product_unit_purchase_id = Product::with('unitPurchase')
                                ->where('id', $value['product_id'])
                                ->first();
                            $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                        }

                        if ($current_Purchase->statut == 'received') {

                            if ($value['product_variant_id'] !== null) {
                                $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
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
                                    ->where('warehouse_id', $current_Purchase->warehouse_id)
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

                    } // end legacy reversal branch

                    $current_Purchase->details()->delete();
                    $current_Purchase->update([
                        'deleted_at' => Carbon::now(),
                    ]);

                    $Payment_purchase_data = PaymentPurchase::where('purchase_id', $purchase_id)->get();
                    foreach ($Payment_purchase_data as $Payment_purchase) {
                        $account = Account::find($Payment_purchase->account_id);

                        if ($account) {
                            $account->update([
                                'balance' => $account->balance + $Payment_purchase->montant,
                            ]);
                        }

                        $Payment_purchase->delete();
                    }

                }
            }

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Deleted !!']);

    }

    // ---------------- Get Details Purchase -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::with('details.product.unitPurchase', 'details.batches.batch')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $purchase_data['Ref'] = $purchase->Ref;
        $purchase_data['date'] = $purchase->date.' '.$purchase->time;
        $purchase_data['statut'] = $purchase->statut;
        $purchase_data['note'] = $purchase->notes;
        $purchase_data['discount'] = $purchase->discount;
        $purchase_data['shipping'] = $purchase->shipping;
        $purchase_data['tax_rate'] = $purchase->tax_rate;
        $purchase_data['TaxNet'] = $purchase->TaxNet;
        $purchase_data['supplier_name'] = $purchase['provider']->name;
        $purchase_data['supplier_email'] = $purchase['provider']->email;
        $purchase_data['supplier_phone'] = $purchase['provider']->phone;
        $purchase_data['supplier_adr'] = $purchase['provider']->adresse;
        $purchase_data['supplier_tax'] = $purchase['provider']->tax_number;
        $purchase_data['warehouse'] = $purchase['warehouse']->name;
        $purchase_data['GrandTotal'] = number_format($purchase->GrandTotal, helpers::price_decimals(), '.', '');
        $purchase_data['paid_amount'] = number_format($purchase->paid_amount, helpers::price_decimals(), '.', '');
        $purchase_data['due'] = number_format($purchase_data['GrandTotal'] - $purchase_data['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase_data['payment_status'] = $purchase->payment_statut;
        $purchase_data['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount']);
        $purchase_data['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            $PurchaseReturn = PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->first();
            $purchase_data['purchasereturn_id'] = $PurchaseReturn->id;
            $purchase_data['purchase_has_return'] = 'yes';
        } else {
            $purchase_data['purchase_has_return'] = 'no';
        }

        foreach ($purchase['details'] as $detail) {

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

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

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
            $data['batches'] = $detail->batches->map(function ($b) {
                $expiry = optional($b->batch)->expiry_date;
                $mfg = optional($b->batch)->mfg_date;

                return [
                    'batch_no'    => optional($b->batch)->batch_no,
                    'expiry_date' => $expiry ? (is_string($expiry) ? $expiry : $expiry->toDateString()) : null,
                    'mfg_date'    => $mfg ? (is_string($mfg) ? $mfg : $mfg->toDateString()) : null,
                    'qty'         => $b->qty,
                    'unit_cost'   => $b->unit_cost,
                    'barcode'     => optional($b->batch)->barcode,
                    'notes'       => optional($b->batch)->notes,
                ];
            })->toArray();

            $details[] = $data;
        }

        $company = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'details' => $details,
            'purchase' => $purchase_data,
            'company' => $company,
        ]);

    }

    // --------------- Get Payments of Purchase ----------------\\

    public function Get_Payments(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $purchase = Purchase::findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $payments = PaymentPurchase::with('purchase', 'payment_method')
            ->where('purchase_id', $id)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            })->orderBy('id', 'DESC')->get();

        $due = $purchase->GrandTotal - $purchase->paid_amount;

        return response()->json(['payments' => $payments, 'due' => $due]);
    }

    // --------------- Reference Number of Purchase ----------------\\

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'PR' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->purchase_prefix) ? $setting->purchase_prefix : 'PR';

        // Get the last purchase with a reference that starts with the prefix
        $last = DB::table('purchases')
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

    // -------------- purchase PDF -----------\\

    public function Purchase_pdf(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet, helpers::price_decimals(), '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount, helpers::price_decimals(), '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping, helpers::price_decimals(), '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal, helpers::price_decimals(), '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount, helpers::price_decimals(), '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount']);
        $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

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

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total, helpers::price_decimals(), '.', '');
            $data['unit_purchase'] = $unit->ShortName;
            $data['cost'] = number_format($detail->cost, helpers::price_decimals(), '.', '');

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
            $data['batches'] = $purchaseBatchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'purchase' => $purchase,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('purchase.pdf');

    }

    /**
     * Returns the purchase invoice HTML (rendered from pdf.purchase_pdf template) instead of a PDF.
     * Uses the same template (`pdf.purchase_pdf`) but returns raw HTML instead of a PDF.
     * This is used by the print functionality, which opens a popup, injects the HTML, and
     * calls window.print().
     */
    public function Purchase_PDF_Inline(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $Purchase_data = Purchase::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $purchaseBatchesByDetail = app(BatchService::class)
            ->batchesForPurchaseDetails($Purchase_data['details']);

        $purchase['supplier_name'] = $Purchase_data['provider']->name;
        $purchase['supplier_phone'] = $Purchase_data['provider']->phone;
        $purchase['supplier_adr'] = $Purchase_data['provider']->adresse;
        $purchase['supplier_email'] = $Purchase_data['provider']->email;
        $purchase['supplier_tax'] = $Purchase_data['provider']->tax_number;
        $purchase['TaxNet'] = number_format($Purchase_data->TaxNet, helpers::price_decimals(), '.', '');
        $purchase['discount'] = number_format($Purchase_data->discount, helpers::price_decimals(), '.', '');
        $purchase['shipping'] = number_format($Purchase_data->shipping, helpers::price_decimals(), '.', '');
        $purchase['statut'] = $Purchase_data->statut;
        $purchase['Ref'] = $Purchase_data->Ref;
        $purchase['date'] = $Purchase_data->date.' '.$Purchase_data->time;
        $purchase['GrandTotal'] = number_format($Purchase_data->GrandTotal, helpers::price_decimals(), '.', '');
        $purchase['paid_amount'] = number_format($Purchase_data->paid_amount, helpers::price_decimals(), '.', '');
        $purchase['due'] = number_format($purchase['GrandTotal'] - $purchase['paid_amount'], helpers::price_decimals(), '.', '');
        $purchase['payment_status'] = $Purchase_data->payment_statut;
        $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount']);
        $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

        $detail_id = 0;
        foreach ($Purchase_data['details'] as $detail) {

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

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, helpers::price_decimals(), '.', '');
            $data['total'] = number_format($detail->total, helpers::price_decimals(), '.', '');
            $data['unit_purchase'] = $unit ? $unit->ShortName : '';
            $data['cost'] = number_format($detail->cost, helpers::price_decimals(), '.', '');

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
            $data['batches'] = $purchaseBatchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.purchase_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'purchase' => $purchase,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        // When rendering as HTML in the browser, filesystem paths like public_path('images/...')
        // do not work as <img src>. Convert any ".../public/images/<file>" path (Windows or Unix)
        // into a proper web URL so logos/images display.
        try {
            $webImagesPath = rtrim(url('images'), '/').'/';
            $Html = preg_replace_callback(
                '~(?:[A-Za-z]:)?[\/\\\\][^"\']*?[\/\\\\]public[\/\\\\]images[\/\\\\]([^"\'>]+)~',
                function ($m) use ($webImagesPath) {
                    $file = ltrim($m[1], '/\\');
                    return $webImagesPath.$file;
                },
                $Html
            );
        } catch (\Throwable $e) {
            // If anything goes wrong, fall back to the original HTML.
        }

        // Return raw HTML so the print popup can inject it and call window.print().
        return response($Html);
    }

    // ---------------- Show Form Create Purchase ---------------\\

    public function create(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        $setting = Setting::where('deleted_at', '=', null)->first();

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
            'purchase_extra_charges_enabled' => (bool) ($setting->purchase_extra_charges_enabled ?? false),
            'purchase_custom_fields_enabled' => (bool) ($setting->purchase_custom_fields_enabled ?? false),
        ]);
    }

    // -------------Show Form Edit Purchase-----------\\

    public function edit(Request $request, $id)
    {
        if (PurchaseReturn::where('purchase_id', $id)->where('deleted_at', '=', null)->exists()) {
            return response()->json(['success' => false, 'Return exist for the Transaction' => false], 403);
        } else {

            $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $Purchase_data = Purchase::with('details.product.unitPurchase')
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

                if (empty($Purchase_data->warehouse_id) || ! in_array($Purchase_data->warehouse_id, $warehouses_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You are not allowed to access this sale (warehouse restriction).',
                    ], 403);
                }
            }

            $details = [];
            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === Purchase->id
                $this->authorizeForUser($request->user('api'), 'check_record', $Purchase_data);
            }

            if ($Purchase_data->provider_id) {
                if (Provider::where('id', $Purchase_data->provider_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['supplier_id'] = $Purchase_data->provider_id;
                } else {
                    $purchase['supplier_id'] = '';
                }
            } else {
                $purchase['supplier_id'] = '';
            }

            if ($Purchase_data->warehouse_id) {
                if (Warehouse::where('id', $Purchase_data->warehouse_id)->where('deleted_at', '=', null)->first()) {
                    $purchase['warehouse_id'] = $Purchase_data->warehouse_id;
                } else {
                    $purchase['warehouse_id'] = '';
                }
            } else {
                $purchase['warehouse_id'] = '';
            }

            $purchase['date'] = $Purchase_data->date;
            // MS2 — non-null only for a location-native purchase; the edit form
            // loads/shows the inventory location and keeps sending it back.
            $purchase['inventory_location_id'] = $Purchase_data->inventory_location_id;
            $purchase['tax_rate'] = $Purchase_data->tax_rate;
            $purchase['TaxNet'] = $Purchase_data->TaxNet;
            $purchase['discount'] = $Purchase_data->discount;
            $purchase['shipping'] = $Purchase_data->shipping;
            $purchase['statut'] = $Purchase_data->statut;
            $purchase['notes'] = $Purchase_data->notes;
            $purchase['extra_charges'] = PurchaseExtraCharge::where('purchase_id', $id)
                ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'amount']);
            $purchase['custom_fields'] = PurchaseCustomField::where('purchase_id', $id)
                ->whereNull('deleted_at')->orderBy('id')->get(['id', 'name', 'value']);

            // Pharmacy: prefetch batch links keyed by purchase_detail_id.
            $batchService = app(BatchService::class);
            $batchesByDetail = $batchService->batchesForPurchaseDetails($Purchase_data['details']);

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
                $data['quantity'] = $detail->quantity;
                $data['product_id'] = $detail->product_id;
                $data['unitPurchase'] = $unit->ShortName;
                $data['purchase_unit_id'] = $unit->id;

                $data['is_imei'] = $detail['product']['is_imei'];
                $data['imei_number'] = $detail->imei_number;

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

                // Pharmacy: attach is_batch_tracked + existing batches for this line.
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

            $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

            $setting = Setting::where('deleted_at', '=', null)->first();

            return response()->json([
                'details' => $details,
                'purchase' => $purchase,
                'suppliers' => $suppliers,
                'warehouses' => $warehouses,
                'purchase_extra_charges_enabled' => (bool) ($setting->purchase_extra_charges_enabled ?? false),
                'purchase_custom_fields_enabled' => (bool) ($setting->purchase_custom_fields_enabled ?? false),
            ]);
        }
    }

    // ------------------- get_Products_by_purchase -----------------\\

    public function get_Products_by_purchase(Request $request, $id)
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
        $Return_detail['purchase_id'] = $Purchase_data->id;
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
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

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
            $data['quantity'] = $detail->quantity;
            $data['purchase_quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            $data['is_imei'] = $detail['product']['is_imei'];
            $data['imei_number'] = $detail->imei_number;

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

    // ------------------- Get barcode products for a Purchase -----------------\\

    public function get_barcode_products(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();

        $purchase = Purchase::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Purchase->id
            $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
        }

        $products = [];

        foreach ($purchase->details as $detail) {
            $product = $detail->product;

            if (! $product) {
                continue;
            }

            $item = [];
            $barcodeValue = null;
            $product_price = $product->price;

            if ($detail->product_variant_id) {
                $variant = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                if ($variant) {
                    $item['code'] = $variant->code;
                    $item['name'] = '['.$variant->name.']'.$product->name;
                    $barcodeValue = $variant->code;
                    $product_price = $variant->price ?? $product->price;
                } else {
                    $item['code'] = $product->code;
                    $item['name'] = $product->name;
                    $barcodeValue = $product->code;
                }
            } else {
                $item['code'] = $product->code;
                $item['name'] = $product->name;
                $barcodeValue = $product->code;
            }

            // Apply discount
            $price_discounted = $product_price;
            if ($product->discount != 0.0) {
                if ($product->discount_method == '1') {
                    $discount = $product_price * $product->discount / 100;
                } else {
                    $discount = $product->discount;
                }
                $price_discounted = $product_price - $discount;
            }

            // Apply tax
            if ($product->TaxNet != 0.0) {
                if ($product->tax_method == '1') {
                    $tax_price = $price_discounted * $product->TaxNet / 100;
                    $net_price = $price_discounted + $tax_price;
                } else {
                    $net_price = $price_discounted;
                }
            } else {
                $net_price = $price_discounted;
            }

            $item['barcode'] = $barcodeValue;
            $item['Type_barcode'] = $product->Type_barcode ?: 'CODE128';
            $item['Net_price'] = number_format($net_price, helpers::price_decimals(), '.', '');
            $item['qte'] = $detail->quantity;

            $products[] = $item;
        }

        return response()->json([
            'warehouse_id' => $purchase->warehouse_id,
            'products' => $products,
        ]);
    }

    // ------------- Send Email -----------\\

    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of sale
        $emailMessage = EmailMessage::getForLocale('purchase');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }
        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);

        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, helpers::price_decimals(), '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, helpers::price_decimals(), '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, helpers::price_decimals(), '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $purchase['provider']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', $contact_name, $message_body);
        $message_body = str_replace('{business_name}', $business_name, $message_body);
        $message_body = str_replace('{invoice_url}', $invoice_url, $message_body);
        $message_body = str_replace('{invoice_number}', $invoice_number, $message_body);

        $message_body = str_replace('{total_amount}', $total_amount, $message_body);
        $message_body = str_replace('{paid_amount}', $paid_amount, $message_body);
        $message_body = str_replace('{due_amount}', $due_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();
        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);
        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', Purchase::class);

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);
        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, helpers::price_decimals(), '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, helpers::price_decimals(), '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, helpers::price_decimals(), '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        if (! $default_sms_gateway) {
            return response()->json(['status' => 'error', 'message' => 'SMS gateway is not configured'], 500);
        }

        // credentials are resolved from the tenant's own sms_settings table
        try {
            app(\App\Services\SmsOtpSender::class)->sendVia($default_sms_gateway->title, $receiverNumber, $message_text);
        } catch (\Throwable $e) {
            Log::error('SMS Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true]);

    }

    // purchase_send_whatsapp
    public function purchase_send_whatsapp(Request $request)
    {

        // purchase
        $purchase = Purchase::with('provider')->where('deleted_at', '=', null)->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of purchase
        $smsMessage = SMSMessage::getForLocale('purchase');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        // Tags
        $random_number = Str::random(10);
        $invoice_url = url('/api/purchase_pdf/'.$request->id.'?'.$random_number);
        $invoice_number = $purchase->Ref;

        $total_amount = $currency.' '.number_format($purchase->GrandTotal, helpers::price_decimals(), '.', ',');
        $paid_amount = $currency.' '.number_format($purchase->paid_amount, helpers::price_decimals(), '.', ',');
        $due_amount = $currency.' '.number_format($purchase->GrandTotal - $purchase->paid_amount, helpers::price_decimals(), '.', ',');

        $contact_name = $purchase['provider']->name;
        $business_name = $settings->CompanyName;

        // receiver Number
        $receiverNumber = $purchase['provider']->phone;

        // Check if the phone number is empty or null
        if (empty($receiverNumber) || $receiverNumber == null || $receiverNumber == 'null' || $receiverNumber == '') {
            return response()->json(['error' => 'Phone number is missing'], 400);
        }

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{invoice_url}', $invoice_url, $message_text);
        $message_text = str_replace('{invoice_number}', $invoice_number, $message_text);

        $message_text = str_replace('{total_amount}', $total_amount, $message_text);
        $message_text = str_replace('{paid_amount}', $paid_amount, $message_text);
        $message_text = str_replace('{due_amount}', $due_amount, $message_text);

        return response()->json(['message' => $message_text, 'phone' => $receiverNumber]);

    }

    // ---------------- get_import_purchases ---------------\\

    public function get_import_purchases(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        // get warehouses assigned to user
        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'suppliers' => $suppliers,
        ]);
    }

    // ------ preview_import_purchases (parse CSV without saving) -------------\\

    public function preview_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        // request_products_csv returns a JsonResponse when parsing/validation fails
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        if ($data === null || !is_array($data)) {
            return response()->json([
                'status' => false,
                'msg' => 'Invalid CSV file',
            ]);
        }

        $rows = [];
        $subtotal = 0;

        foreach ($data as $row) {
            $product = Product::with('unitPurchase')
                ->where('deleted_at', '=', null)
                ->where('code', $row['productcode'])
                ->first();

            if (!$product) {
                continue;
            }

            $qty = (float) ($row['qty'] ?? 0);
            $cost = (float) $product->cost;
            $total = $qty * $cost;
            $subtotal += $total;

            // MS4 — additive UX hints so the form can warn BEFORE submit when
            // the chosen warehouse is location_primary. The store endpoint is
            // still the final guard.
            $isVariant = ((int) ($product->is_variant ?? 0) === 1) || ((string) $product->type === 'is_variant');
            $isBatch = (bool) ($product->is_batch_tracked ?? false);
            $isImei = (int) ($product->is_imei ?? 0) === 1;
            $warning = null;
            if ($isVariant) {
                $warning = 'variant';
            } elseif ($isBatch) {
                $warning = 'batch';
            } elseif ($isImei) {
                $warning = 'imei';
            }

            $rows[] = [
                'code' => $product->code,
                'name' => $product->name,
                'qty' => $qty,
                'cost' => $cost,
                'total' => $total,
                'unit' => optional($product->unitPurchase)->ShortName,
                'is_batch_tracked' => $isBatch,
                'is_imei' => $isImei,
                'is_variant' => $isVariant,
                'product_type' => (string) $product->type,
                'product_id' => $product->id,
                'product_variant_id' => null,
                'validation_warning' => $warning,
            ];
        }

        return response()->json([
            'status' => true,
            'rows' => $rows,
            'subtotal' => $subtotal,
            'count' => count($rows),
        ]);
    }

    // ------ store_import_purchases -------------\\

    public function store_import_purchases(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Purchase::class);

        $data = $this->request_products_csv($request);

        request()->validate([
            'supplier_id' => 'required',
            'warehouse_id' => 'required',
        ]);

        // Pharmacy: decode batches payload (map of productcode → batches[]).
        $batchesByCode = [];
        $rawBatches = $request->input('batches_by_code');
        if (is_string($rawBatches) && $rawBatches !== '') {
            $decoded = json_decode($rawBatches, true);
            if (is_array($decoded)) {
                $batchesByCode = $decoded;
            }
        }

        // MS4 — routing by warehouse transition mode. An import for a
        // location_primary warehouse goes to the location-native engine; every
        // other mode (legacy_only / shadow_compare / dual_write / no row) keeps
        // the exact legacy flow below.
        if (app(WarehouseInventoryModeResolver::class)->isLocationPrimary((int) $request->warehouse_id)) {
            if ($data instanceof \Illuminate\Http\JsonResponse) {
                return $data; // CSV parse / validation error already shaped by request_products_csv
            }
            if (! is_array($data)) {
                return response()->json(['status' => false, 'msg' => 'Invalid CSV file'], 422);
            }

            return $this->storeImportLocationAware($request, $data, $batchesByCode);
        }

        \DB::transaction(function () use ($request, $data, $batchesByCode) {
            $order = new Purchase;

            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = 0;
            $order->warehouse_id = $request->warehouse_id;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = 0;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $request->statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;

            $order->save();

            $total = 0;
            $inputDetailsForBatches = [];
            foreach ($data as $key => $value) {

                $product = Product::where('deleted_at', '=', null)->where('code', $value['productcode'])->first();
                $unit = Unit::where('id', $product->unit_purchase_id)->first();

                $total += $value['qty'] * $product->cost;

                $orderDetails[] = [
                    'purchase_id' => $order->id,
                    'quantity' => $value['qty'],
                    'cost' => $product->cost,
                    'purchase_unit_id' => $product->unit_purchase_id,
                    'TaxNet' => 0,
                    'tax_method' => 1,
                    'discount' => 0,
                    'discount_method' => 2,
                    'product_id' => $product->id,
                    'product_variant_id' => null,
                    'total' => $value['qty'] * $product->cost,
                    'imei_number' => null,
                ];

                $inputDetailsForBatches[] = [
                    'product_id' => $product->id,
                    'batches' => $batchesByCode[$value['productcode']] ?? [],
                ];

                if ($order->statut == 'received') {

                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $order->warehouse_id)
                        ->where('product_id', $product->id)
                        ->first();

                    if ($unit && $product_warehouse) {
                        if ($unit->operator == '/') {
                            $product_warehouse->qte += $value['qty'] / $unit->operator_value;
                        } else {
                            $product_warehouse->qte += $value['qty'] * $unit->operator_value;
                        }
                        $product_warehouse->save();
                    }
                }
            }
            PurchaseDetail::insert($orderDetails);

            // Pharmacy: link captured batches to the freshly inserted PurchaseDetail rows.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported() && $order->statut == 'received') {
                $persisted = PurchaseDetail::where('purchase_id', $order->id)
                    ->orderBy('id', 'asc')
                    ->get();
                $batchService->applyForPurchase($order, $inputDetailsForBatches, $persisted);
            }

            //  Calculte Grand_Total
            $purchase_data = Purchase::where('id', $order->id)->first();

            $total_without_discount = $total - $purchase_data->discount;

            $TaxNet = ($total_without_discount * $purchase_data->tax_rate) / 100;

            $purchase_data->TaxNet = $TaxNet;

            $purchase_data->GrandTotal = $total_without_discount + $TaxNet + $purchase_data->shipping;
            $purchase_data->save();

        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    // =====================================================================
    // MS4 — Import purchases location-native (warehouses in MODE_LOCATION_PRIMARY).
    //
    // The CSV contract (productcode;qty) identifies a Product by Product.code
    // ONLY — it has NO variant column, so a location-native import CANNOT
    // resolve a ProductVariant. Every is_variant / is_batch_tracked / is_imei
    // row FAILS CLOSED (422): resolveImportLinesForLocationNative() pre-flags it
    // with a clear per-row message and the engine's validateAndLock() is the
    // second fence. Legacy import (any non-primary mode) keeps its exact current
    // behaviour, including the historical variant-NULL product_warehouse write.
    // NO product_warehouse / BatchService / SerialNumberService on this path.
    // =====================================================================

    private function storeImportLocationAware(Request $request, array $data, array $batchesByCode)
    {
        $svc = app(LocationAwarePurchaseStockService::class);
        $warehouseId = (int) $request->warehouse_id;
        $statut = $request->statut;

        \DB::transaction(function () use ($request, $data, $svc, $warehouseId, $statut) {
            // FAIL CLOSED — location_primary must still be healthy INSIDE the tx
            // (locks inventory_transition_states first). No legacy fallback.
            $this->assertLocationNativePurchaseTransitionSafe($warehouseId, null);

            request()->validate(['inventory_location_id' => 'required|integer']);
            $locationId = (int) $request->inventory_location_id;

            // Resolve + validate EVERY row BEFORE any stock mutation.
            $resolved = $this->resolveImportLinesForLocationNative($data);

            // Full-document validate + lock. Fails closed on unknown/deleted
            // product, qty <= 0, invalid unit, batch/IMEI, or an is_variant row
            // (product_variant_id is always null here — the CSV cannot name one).
            $validated = $svc->validateAndLock(
                LocationAwarePurchaseStockService::DOC_PURCHASE,
                $warehouseId,
                $locationId,
                array_map(fn ($r) => [
                    'product_id' => $r['product']->id,
                    'product_variant_id' => null,
                    'quantity' => $r['qty'],
                    'purchase_unit_id' => $r['unit_purchase_id'],
                ], $resolved)
            );

            $order = new Purchase;
            $order->date = $request->date;
            $order->time = now()->toTimeString();
            $order->Ref = $this->getNumberOrder();
            $order->provider_id = $request->supplier_id;
            $order->GrandTotal = 0;
            $order->warehouse_id = $warehouseId;
            $order->inventory_location_id = $locationId;
            $order->tax_rate = $request->tax_rate;
            $order->TaxNet = 0;
            $order->discount = $request->discount;
            $order->shipping = $request->shipping;
            $order->statut = $statut;
            $order->payment_statut = 'unpaid';
            $order->notes = $request->notes;
            $order->user_id = Auth::user()->id;
            $order->save();

            // Details one by one -> real ids. request_products_csv already
            // rejects a duplicate productcode, so each CSV row is its own line.
            $total = 0;
            $detailIds = [];
            foreach (array_values($resolved) as $i => $r) {
                $lineTotal = $r['qty'] * (float) $r['product']->cost;
                $total += $lineTotal;
                $d = PurchaseDetail::create([
                    'purchase_id' => $order->id,
                    'quantity' => $r['qty'],
                    'cost' => $r['product']->cost,
                    'purchase_unit_id' => $r['product']->unit_purchase_id,
                    'TaxNet' => 0,
                    'tax_method' => 1,
                    'discount' => 0,
                    'discount_method' => 2,
                    'product_id' => $r['product']->id,
                    'product_variant_id' => null,
                    'total' => $lineTotal,
                    'imei_number' => null,
                ]);
                $detailIds[$i] = $d->id;
            }

            // GrandTotal — identical arithmetic to the legacy import.
            $total_without_discount = $total - $order->discount;
            $TaxNet = ($total_without_discount * $order->tax_rate) / 100;
            $order->TaxNet = $TaxNet;
            $order->GrandTotal = $total_without_discount + $TaxNet + $order->shipping;
            $order->save();

            // Physical effect ONLY for a received import. A pending / ordered
            // import keeps location + header + details, NO snapshot, NO movements.
            if ($statut === 'received') {
                $validated['lines'] = $this->withSourceDetailIds($validated['lines'], $detailIds);
                $snapshot = $svc->buildSnapshot($validated, 1);
                $order->update(['inventory_effect_snapshot' => $snapshot]);
                $svc->applySnapshot($snapshot, $order->id);
            }
        }, 10);

        return response()->json(['success' => true, 'message' => 'Purchase Created !!']);
    }

    /**
     * Resolve each CSV row (productcode;qty) to a real Product + its purchase
     * Unit for the location-native import, validating the WHOLE document before
     * any stock mutation. FAIL CLOSED (422) on: unknown / soft-deleted product,
     * qty <= 0, a product with variants (the CSV has no variant column), or a
     * batch / IMEI-tracked product (MS5 / MS6).
     *
     * @return array<int,array{code:string, product:\App\Models\Product, unit_purchase_id:?int, qty:float}>
     */
    private function resolveImportLinesForLocationNative(array $data): array
    {
        if (empty($data)) {
            throw ValidationException::withMessages(['products' => 'El archivo CSV no tiene filas.']);
        }

        $out = [];
        foreach (array_values($data) as $i => $row) {
            $code = isset($row['productcode']) ? trim((string) $row['productcode']) : '';
            $line = $i + 1;

            $product = Product::where('deleted_at', '=', null)->where('code', $code)->first();
            if (! $product) {
                throw ValidationException::withMessages([
                    "products.$i" => "Fila $line: el producto con código '$code' no existe o fue eliminado.",
                ]);
            }

            $qty = round((float) ($row['qty'] ?? 0), 3);
            if ($qty <= 0) {
                throw ValidationException::withMessages([
                    "products.$i" => "Fila $line ($code): la cantidad debe ser mayor que cero.",
                ]);
            }

            if ((int) ($product->is_variant ?? 0) === 1 || (string) $product->type === 'is_variant') {
                throw ValidationException::withMessages([
                    "products.$i" => "Fila $line ($code): es un producto con variantes. La importación de compra por ubicación no puede determinar la variante desde el CSV actual (columnas productcode;qty). FAIL CLOSED.",
                ]);
            }
            if ((int) ($product->is_batch_tracked ?? 0) === 1) {
                throw ValidationException::withMessages([
                    "products.$i" => "Fila $line ($code): es un producto con control de lote. La entrada de lote por ubicación llega en un hito posterior. FAIL CLOSED.",
                ]);
            }
            if ((int) ($product->is_imei ?? 0) === 1) {
                throw ValidationException::withMessages([
                    "products.$i" => "Fila $line ($code): es un producto serializado (IMEI). La entrada de series por ubicación llega en un hito posterior. FAIL CLOSED.",
                ]);
            }

            $out[] = [
                'code' => $code,
                'product' => $product,
                'unit_purchase_id' => $product->unit_purchase_id,
                'qty' => $qty,
            ];
        }

        return $out;
    }

    // import Products
    public function request_products_csv(Request $request)
    {

        ini_set('max_execution_time', 2000);

        $file = $request->file('products');
        $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION);
        if ($ext != 'csv') {
            return response()->json([
                'msg' => 'must be in csv format',
                'status' => false,
            ]);
        } else {
            // Read the CSV file
            $data = [];
            $rowcount = 0;
            if (($handle = fopen($file->getPathname(), 'r')) !== false) {
                $max_line_length = defined('MAX_LINE_LENGTH') ? MAX_LINE_LENGTH : 10000;
                $header = fgetcsv($handle, $max_line_length, ';'); // Use semicolon as the delimiter

                // Process the header row
                $escapedHeader = [];
                foreach ($header as $key => $value) {
                    $lheader = strtolower($value);
                    $escapedItem = preg_replace('/[^a-z]/', '', $lheader);
                    $escapedHeader[] = $escapedItem;
                }

                $header_colcount = count($header);
                while (($row = fgetcsv($handle, $max_line_length, ';')) !== false) { // Use semicolon as the delimiter
                    $row_colcount = count($row);
                    if ($row_colcount == $header_colcount) {
                        $entry = array_combine($escapedHeader, $row);
                        $data[] = $entry;
                    } else {
                        return null;
                    }
                    $rowcount++;
                }
                fclose($handle);
            } else {
                return null;
            }

            // Clean the data
            $cleanedData = [];
            foreach ($data as $row) {
                $cleanedRow = [];
                foreach ($row as $key => $value) {
                    $cleanedKey = trim($key);
                    $cleanedRow[$cleanedKey] = $value;
                }
                $cleanedData[] = $cleanedRow;
            }

            // Check for duplicate productcode in CSV
            $productCodes = array_column($cleanedData, 'productcode');
            if (count($productCodes) !== count(array_unique($productCodes))) {
                return response()->json([
                    'msg' => 'Duplicate product code found in CSV file',
                    'status' => false,
                ]);
            }

            // Validate productcode existence in the database
            $missingProductCodes = [];
            foreach ($productCodes as $code) {
                if (! Product::where('code', $code)->exists()) {
                    $missingProductCodes[] = $code;
                }
            }

            if (! empty($missingProductCodes)) {
                return response()->json([
                    'msg' => 'The following product codes do not exist in the database: '.implode(', ', $missingProductCodes),
                    'status' => false,
                ]);
            }

            // Define validation rules
            $rules = [];
            foreach ($cleanedData as $index => $row) {
                $rules[$index.'.productcode'] = 'required';
                $rules[$index.'.qty'] = 'required|numeric';
            }

            // Validate the data
            $validator = validator()->make($cleanedData, $rules);

            if ($validator->fails()) {
                return response()->json([
                    'msg' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'status' => false,
                ]);
            }

            // Return the cleaned data
            return $cleanedData;

        }
    }

    // ------------- Get Purchase Documents ----------\\
    public function getDocuments($purchaseId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);
        
        $documents = DB::table('purchase_documents')
            ->where('purchase_id', $purchaseId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'documents' => $documents,
            'status' => true
        ]);
    }

    // ------------- Upload Purchase Documents ----------\\
    public function uploadDocuments(Request $request, $purchaseId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Purchase::class);
        
        $purchase = Purchase::findOrFail($purchaseId);

        $request->validate([
            'documents.*' => 'required|file|max:10240', // Max 10MB per file
        ]);

        $uploadedDocuments = [];

        if ($request->hasFile('documents')) {
            // Create directory if it doesn't exist
            $uploadPath = upload_public_path('purchase_documents');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            foreach ($request->file('documents') as $file) {
                // Capture metadata BEFORE moving the file (tmp file is still readable)
                $originalName = $file->getClientOriginalName();
                $size = $file->getSize();
                $mimeType = $file->getMimeType();

                $filename = time() . '_' . Str::random(10) . '_' . $originalName;
                
                // Move file to public/images/purchase_documents
                $file->move($uploadPath, $filename);
                
                $relativePath = upload_path('purchase_documents') . '/' . $filename;

                $documentId = DB::table('purchase_documents')->insertGetId([
                    'purchase_id' => $purchaseId,
                    'name' => $originalName,
                    'path' => $relativePath,
                    'size' => $size,
                    'mime_type' => $mimeType,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                $uploadedDocuments[] = $documentId;
            }
        }

        return response()->json([
            'message' => 'Documents uploaded successfully',
            'documents' => $uploadedDocuments,
            'status' => true
        ]);
    }

    // ------------- Download Purchase Document ----------\\
    public function downloadDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'view', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found in database',
                'status' => false
            ], 404);
        }

        $filePath = public_path($document->path);

        if (!file_exists($filePath)) {
            return response()->json([
                'message' => 'Physical file not found on server',
                'status' => false,
                'path' => $document->path
            ], 404);
        }

        return response()->download($filePath, $document->name);
    }

    // ------------- Delete Purchase Document ----------\\
    public function deleteDocument($documentId)
    {
        $this->authorizeForUser(request()->user('api'), 'delete', Purchase::class);
        
        $document = DB::table('purchase_documents')
            ->where('id', $documentId)
            ->where('deleted_at', null)
            ->first();

        if (!$document) {
            return response()->json([
                'message' => 'Document not found',
                'status' => false
            ], 404);
        }

        // Soft delete
        DB::table('purchase_documents')
            ->where('id', $documentId)
            ->update(['deleted_at' => Carbon::now()]);

        // Optionally delete the physical file
        $filePath = public_path($document->path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return response()->json([
            'message' => 'Document deleted successfully',
            'status' => true
        ]);
    }
}
