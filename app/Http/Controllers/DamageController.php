<?php

namespace App\Http\Controllers;

use App\Models\CombinedProduct;
use App\Models\Damage;
use App\Models\DamageDetail;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\LocationAwareDamageService;
use App\utils\helpers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PDF;

class DamageController extends BaseController
{
    // ------------ Show All Damages  -----------\\
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Damage::class);
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

        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        $columns = [0 => 'Ref', 1 => 'warehouse_id', 2 => 'date'];
        $param = [0 => 'like', 1 => '=', 2 => '='];
        $data = [];

        $Damages = Damage::with('warehouse')
            ->where('deleted_at', '=', null)
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Damages->whereIn('warehouse_id', $warehouse_ids);
        }

        $Filtred = $helpers->filter($Damages, $columns, $param, $request)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($q) use ($request) {
                            return $q->whereHas('warehouse', function ($q2) use ($request) {
                                $q2->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Damages = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Damages as $DamageRow) {
            $item['id'] = $DamageRow->id;
            $item['date'] = $DamageRow['date'].' '.$DamageRow['time'];
            $item['Ref'] = $DamageRow->Ref;
            $item['warehouse_name'] = $DamageRow['warehouse']->name;
            $item['items'] = $DamageRow->items;
            $data[] = $item;
        }

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'damages' => $data,
            'totalRows' => $totalRows,
            'warehouses' => $warehouses,
        ]);
    }

    // ------------ Store New Damage -----------\\
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

        // (#81 · BLOCKER 1) TODO Damage NUEVO es location-aware: exige
        // inventory_location_id explícita. Sin ella => 422, 0 escrituras.
        if (! $request->filled('inventory_location_id')) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Debes seleccionar una ubicación de inventario: los daños nuevos se registran por ubicación.',
            ]);
        }

        return $this->storeLocationAware($request);
    }


    public function show($id)
    {
        // not used
    }

    // --------------- Update Damage ----------------------\\
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $current_damage = Damage::whereNull('deleted_at')->findOrFail($id);

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $current_damage);
        }

        // (#81 · C6/C7) location-aware, respetando warehouse scope y sólo si no
        // está eliminado (check_record ya se ejecutó arriba).
        if ($current_damage->inventory_location_id !== null) {
            if ($denied = $this->assertCanModifyDocument($request, $current_damage)) {
                return $denied;
            }

            return $this->updateLocationAware($request, $current_damage);
        }

        request()->validate([
            'warehouse_id' => 'required',
        ]);

        \DB::transaction(function () use ($request, $id, $current_damage) {
            $old_details = DamageDetail::where('damage_id', $id)->get();
            $new_details = $request['details'];
            $length = count($new_details);

            $new_ids = [];
            foreach ($new_details as $new_detail) {
                $new_ids[] = $new_detail['id'];
            }

            // Pharmacy: reverse old batch debits before warehouse-stock reversal so
            // the per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForDamageDetails($old_details);
            }

            $old_ids = [];
            foreach ($old_details as $key => $value) {
                $old_ids[] = $value->id;

                // Reverse previous subtraction
                if ($value['product_variant_id'] !== null) {
                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $current_damage->warehouse_id)
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

                    if ($product_detail && $product_detail->type == 'is_single') {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_damage->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }
                    } elseif ($product_detail && $product_detail->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $value['quantity'];

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_damage->warehouse_id)
                                ->where('product_id', $combined_product->combined_product_id)
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $qty_combined;
                                $product_warehouse->save();
                            }
                        }

                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_damage->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }
                    }
                }

                if (! in_array($old_ids[$key], $new_ids)) {
                    $detail = DamageDetail::findOrFail($value->id);
                    $detail->delete();
                }
            }

            $newPersistedDetails = [];
            foreach ($new_details as $key => $product_detail) {
                // Apply new subtraction
                if (! empty($product_detail['product_variant_id'])) {
                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $request->warehouse_id)
                        ->where('product_id', $product_detail['product_id'])
                        ->where('product_variant_id', $product_detail['product_variant_id'])
                        ->first();

                    if ($product_warehouse) {
                        $product_warehouse->qte -= $product_detail['quantity'];
                        if ($product_warehouse->qte < 0) {
                            $product_warehouse->qte = 0;
                        }
                        $product_warehouse->save();
                    }
                } else {
                    $prod = Product::where('deleted_at', '=', null)
                        ->where('id', $product_detail['product_id'])
                        ->first();

                    if ($prod && $prod->type == 'is_single') {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $request->warehouse_id)
                            ->where('product_id', $product_detail['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte -= $product_detail['quantity'];
                            if ($product_warehouse->qte < 0) {
                                $product_warehouse->qte = 0;
                            }
                            $product_warehouse->save();
                        }
                    } elseif ($prod && $prod->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $product_detail['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $product_detail['quantity'];
                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $request->warehouse_id)
                                ->where('product_id', $combined_product->combined_product_id)
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte -= $qty_combined;
                                if ($product_warehouse->qte < 0) {
                                    $product_warehouse->qte = 0;
                                }
                                $product_warehouse->save();
                            }
                        }

                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $request->warehouse_id)
                            ->where('product_id', $product_detail['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte -= $product_detail['quantity'];
                            if ($product_warehouse->qte < 0) {
                                $product_warehouse->qte = 0;
                            }
                            $product_warehouse->save();
                        }
                    }
                }

                $orderDetails['damage_id'] = $id;
                $orderDetails['quantity'] = $product_detail['quantity'];
                $orderDetails['product_id'] = $product_detail['product_id'];
                $orderDetails['product_variant_id'] = $product_detail['product_variant_id'] ?? null;

                if (! in_array($product_detail['id'], $old_ids)) {
                    $persistedDetail = DamageDetail::Create($orderDetails);
                } else {
                    DamageDetail::where('id', $product_detail['id'])->update($orderDetails);
                    $persistedDetail = DamageDetail::find($product_detail['id']);
                }
                $newPersistedDetails[$key] = $persistedDetail;
            }

            // Pharmacy: re-apply per-batch debits now that DamageDetail rows exist.
            // Lockstep alignment so any skipped rows don't misalign indices.
            if ($batchService->isSupported()) {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($new_details as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $current_damage->warehouse_id = (int) $request['warehouse_id'];
                $batchService->applyForDamageWithAutoFallback(
                    $current_damage,
                    $alignedInput,
                    $alignedPersisted
                );
            }

            $current_damage->update([
                'warehouse_id' => $request['warehouse_id'],
                'notes' => $request['notes'],
                'date' => $request['date'],
                'items' => $length,
            ]);
        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Damage -----------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Damage::class);

        $preload = Damage::whereNull('deleted_at')->findOrFail($id);
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
            $current_damage = Damage::findOrFail($id);
            $old_details = DamageDetail::where('damage_id', $id)->get();

            if (! $view_records) {
                $this->authorizeForUser($request->user('api'), 'check_record', $current_damage);
            }

            // Pharmacy: reverse batch debits before warehouse-stock reversal so the
            // per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()) {
                $batchService->reverseForDamageDetails($old_details);
            }

            foreach ($old_details as $key => $value) {
                // Reverse subtraction (add back)
                if ($value['product_variant_id'] !== null) {
                    $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $current_damage->warehouse_id)
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

                    if ($product_detail && $product_detail->type == 'is_single') {
                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_damage->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }
                    } elseif ($product_detail && $product_detail->type == 'is_combo') {
                        $combined_products = CombinedProduct::where('product_id', $value['product_id'])->with('product')->get();
                        foreach ($combined_products as $combined_product) {
                            $qty_combined = $combined_product->quantity * $value['quantity'];

                            $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_damage->warehouse_id)
                                ->where('product_id', $combined_product->combined_product_id)
                                ->first();

                            if ($product_warehouse) {
                                $product_warehouse->qte += $qty_combined;
                                $product_warehouse->save();
                            }
                        }

                        $product_warehouse = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_damage->warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->first();

                        if ($product_warehouse) {
                            $product_warehouse->qte += $value['quantity'];
                            $product_warehouse->save();
                        }
                    }
                }
            }
            $current_damage->details()->delete();

            $current_damage->update([
                'deleted_at' => Carbon::now(),
            ]);
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // ================= #81 · Daños LOCATION-AWARE =====================

    /** (#81 · C5) available (physical - reserved) de inventory_location_stocks. */
    private function locationAwareAvailable(int $locationId, int $productId, ?int $variantId): float
    {
        $row = \DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $locationId)
            ->where('product_id', $productId)
            ->where('variant_key', (int) ($variantId ?: 0))
            ->first();

        return $row ? round((float) $row->quantity - (float) $row->reserved_quantity, 3) : 0.0;
    }

    // (#81 · C6) warehouse scope + check_record antes del branch location-aware.
    private function assertCanModifyDocument(Request $request, Damage $doc)
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

    // (BLOCKER 6) usado por Create Y Edit: autoriza create OR update.
    private function authorizeLocationRead(Request $request): void
    {
        $u = $request->user('api');
        abort_unless(
            $u && (Gate::forUser($u)->allows('create', Damage::class) || Gate::forUser($u)->allows('update', Damage::class)),
            403,
            'No tienes permiso para consultar ubicaciones de inventario de daños.'
        );
    }

    /** Ubicaciones de inventario ACTIVAS del almacén (incluye cuarentena). */
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

    /** (BLOCKER 3) Catálogo por UBICACIÓN. available = physical - reserved. Incluye 0. */
    public function inventoryLocationCatalog(Request $request, $locationId)
    {
        $this->authorizeLocationRead($request);
        $locationId = (int) $locationId;

        $location = \App\Models\InventoryLocation::whereNull('deleted_at')->where('is_active', 1)->whereKey($locationId)->first();
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
        $svc = app(LocationAwareDamageService::class);
        $lines = $this->locationAwareLines($request);

        \DB::transaction(function () use ($request, $warehouseId, $locationId, $lines, $svc) {
            $validated = $svc->validateAndLock($warehouseId, $locationId, $lines);

            $order = new Damage;
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
                $d = DamageDetail::create([
                    'damage_id' => $order->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                ]);
                $withDetailIds[] = $ln + ['detail_id' => $d->id];
            }

            $snapshot = $svc->buildSnapshot($withDetailIds);
            $order->update(['inventory_effect_snapshot' => $snapshot]);
            $svc->applySnapshot($snapshot, $order->id, $warehouseId, $locationId, 'create');
        }, 10);

        return response()->json(['success' => true]);
    }

    private function updateLocationAware(Request $request, Damage $current)
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
        $svc = app(LocationAwareDamageService::class);
        $lines = $this->locationAwareLines($request);

        \DB::transaction(function () use ($request, $current, $newWarehouseId, $newLocationId, $lines, $svc) {
            $locked = Damage::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['damage' => 'Registro legacy: usa la ruta histórica.']);
            }
            $oldSnapshot = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            // (D6) el snapshot VIEJO debe ser artifact-safe ANTES de cualquier reversa,
            // aunque el producto ya no aparezca en el request nuevo.
            $svc->assertSnapshotArtifactSafeAndLock($oldSnapshot);
            DamageDetail::where('damage_id', $locked->id)->lockForUpdate()->get();

            $extra = array_values(array_unique(array_map(fn ($e) => (int) $e['product_id'], $oldSnapshot)));
            $validated = $svc->validateAndLock($newWarehouseId, $newLocationId, $lines, $extra);

            $svc->reverseSnapshot($oldSnapshot, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'update');

            DamageDetail::where('damage_id', $locked->id)->delete();
            $withDetailIds = [];
            foreach ($validated['lines'] as $ln) {
                $d = DamageDetail::create([
                    'damage_id' => $locked->id, 'quantity' => $ln['quantity'],
                    'product_id' => $ln['product_id'], 'product_variant_id' => $ln['product_variant_id'],
                ]);
                $withDetailIds[] = $ln + ['detail_id' => $d->id];
            }

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

    private function destroyLocationAware(Request $request, Damage $current)
    {
        $this->assertWarehouseAccess((int) $current->warehouse_id);
        $svc = app(LocationAwareDamageService::class);

        \DB::transaction(function () use ($current, $svc) {
            $locked = Damage::whereKey($current->id)->whereNull('deleted_at')->lockForUpdate()->firstOrFail();
            if ($locked->inventory_location_id === null) {
                throw ValidationException::withMessages(['damage' => 'Registro legacy: usa la ruta histórica.']);
            }
            $snapshot = $svc->normalizeSnapshot($locked->inventory_effect_snapshot);
            // (D7) artifact-safe guard ANTES de bloquear details / revertir.
            $svc->assertSnapshotArtifactSafeAndLock($snapshot);
            DamageDetail::where('damage_id', $locked->id)->lockForUpdate()->get();

            $svc->reverseSnapshot($snapshot, $locked->id, (int) $locked->warehouse_id, (int) $locked->inventory_location_id, 'destroy');
            $locked->details()->delete();
            $locked->update(['deleted_at' => Carbon::now()]);
        }, 10);

        return response()->json(['success' => true], 200);
    }

    // -------------Show Form Create Damage-----------\\
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json(['warehouses' => $warehouses]);
    }

    // -------------Show Form Edit Damage-----------\\
    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Damage_data = Damage::with('details.product')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = [];

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $Damage_data);
        }

        if ($Damage_data->warehouse_id) {
            if (Warehouse::where('id', $Damage_data->warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $damage['warehouse_id'] = $Damage_data->warehouse_id;
            } else {
                $damage['warehouse_id'] = '';
            }
        } else {
            $damage['warehouse_id'] = '';
        }

        $damage['notes'] = $Damage_data->notes;
        $damage['date'] = $Damage_data->date;
        // (#81 · C1) NULL => legacy; NOT NULL => location-aware.
        $damage['inventory_location_id'] = $Damage_data->inventory_location_id !== null ? (int) $Damage_data->inventory_location_id : null;
        $locationAware = $damage['inventory_location_id'] !== null;

        $batchesByDetail = app(BatchService::class)->batchesForDamageDetails($Damage_data['details']);

        $detail_id = 0;
        foreach ($Damage_data['details'] as $detail) {
            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Damage_data->warehouse_id)
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
                $data['current'] = $locationAware
                    ? $this->locationAwareAvailable((int) $damage['inventory_location_id'], (int) $detail->product_id, $detail->product_variant_id ? (int) $detail->product_variant_id : null)
                    : ($item_product ? $item_product->qte : 0);
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = ($locationAware || $item_product) ? 0 : 1;
                $data['product_type'] = $detail['product']['type'] ?? 'is_single';
            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('warehouse_id', $Damage_data->warehouse_id)
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
                    ? $this->locationAwareAvailable((int) $damage['inventory_location_id'], (int) $detail->product_id, null)
                    : ($item_product ? $item_product->qte : 0);
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['del'] = ($locationAware || $item_product) ? 0 : 1;
                $data['product_type'] = $detail['product']['type'] ?? 'is_single';
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $user_auth = auth()->user();
        if ($user_auth->is_all_warehouses) {
            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        } else {
            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)->pluck('warehouse_id')->toArray();
            $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $warehouses_id)->get(['id', 'name']);
        }

        return response()->json([
            'details' => $details,
            'damage' => $damage,
            'warehouses' => $warehouses,
        ]);
    }

    // ---------------- Get Details Damage-----------------\\
    public function Damage_detail(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Damage::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Damage_data = Damage::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $details = [];

        if (! $view_records) {
            $this->authorizeForUser($request->user('api'), 'check_record', $Damage_data);
        }

        $DamageArr['Ref'] = $Damage_data->Ref;
        $DamageArr['date'] = $Damage_data->date;
        $DamageArr['note'] = $Damage_data->notes;
        $DamageArr['warehouse'] = $Damage_data['warehouse']->name;

        $batchesByDetail = app(BatchService::class)->batchesForDamageDetails($Damage_data['details']);

        foreach ($Damage_data['details'] as $detail) {
            if ($detail->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = $detail->quantity;
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
                $data['type'] = 'sub';
            } else {
                $data['quantity'] = $detail->quantity;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['type'] = 'sub';
                $data['unit'] = $detail['product']['unit']->ShortName;
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'damage' => $DamageArr,
        ]);
    }

    // -------------- damage_pdf -----------\\
    public function damage_pdf(Request $request, $id)
    {
        $details = [];
        $helpers = new helpers;
        $damage_data = Damage::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $adjustment['warehouse_name'] = $damage_data['warehouse']->name;
        $adjustment['Ref'] = $damage_data->Ref;
        $adjustment['date'] = $damage_data->date.' '.$damage_data->time;

        $detail_id = 0;
        foreach ($damage_data['details'] as $detail) {
            $data['detail_id'] = $detail_id += 1;

            if ($detail->product_variant_id) {
                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)
                    ->first();

                $data['quantity'] = '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
            } else {
                $data['quantity'] = '-'.' '.number_format($detail->quantity, 2, '.', '');
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
                $data['unit'] = $detail['product']['unit']->ShortName;
            }
            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $Html = view('pdf.adjustment_pdf', [
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

        return $pdf->download('Damage.pdf');
    }

    // ------------- Delete by selection  ---------------\\
    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Damage::class);

        foreach ($request->selectedIds as $id) {
            $this->destroy($request, $id);
        }

        return response()->json(['success' => true]);
    }

    // ------ batches_for_damage ---------------\\
    //
    // Returns FEFO-ordered active batches at the source warehouse for batch-tracked
    // products. Mirrors batches_for_sale but authorized via the Damage policy.
    public function batches_for_damage(Request $request, $product_id, $warehouse_id, $variant_id = null)
    {
        $this->authorizeForUser($request->user('api'), 'create', Damage::class);

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

    // ------------ Reference Number of Damage  -----------\\
    public function getNumberOrder()
    {
        $last = DB::table('damages')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = isset($nwMsg[1]) ? ($nwMsg[1] + 1) : 1112;
            $code = 'DM_'.$inMsg;
        } else {
            $code = 'DM_1111';
        }

        return $code;
    }
}





