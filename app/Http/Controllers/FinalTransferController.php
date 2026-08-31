<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Services\TransferBusinessDestinationService;
use App\Services\TransferListScopeService;
use App\Services\TransferLogisticsService;
use App\Services\TransferWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinalTransferController extends TransferController
{
    private ?string $createdTransferReference = null;

    public function store(Request $request)
    {
        $this->assertBusinessRoute($request);
        $user = $request->user('api') ?: Auth::user();

        // Product decision:
        //   - transfer_add WITHOUT transfer_edit → the user may CREATE the transfer,
        //     but it stays "pending" and is NOT auto-approved/dispatched. No stock
        //     moves. A later approve/dispatch still needs transfer_edit (workflow).
        //   - transfer_add WITH transfer_edit → keep the one-shot fast path
        //     (create → approve → dispatch) atomically.
        // Lacking transfer_edit is NOT a 403 for creating — the user has transfer_add.
        $mayAutoDispatch = $this->creatorMayAutoDispatch($user);

        // Capture the user's explicit per-line batch picks BEFORE parent::store()
        // (which only persists header + details and drops the batches payload for a
        // pending transfer). They are handed to dispatch so the location dispatcher
        // honours them exactly instead of silently auto-FEFO.
        $batchPlan = $this->extractBatchPlan($request);

        return DB::transaction(function () use ($request, $user, $batchPlan, $mayAutoDispatch) {
            $this->createdTransferReference = null;
            parent::store($request);

            abort_unless($this->createdTransferReference, 500, 'No se pudo identificar la transferencia recién creada.');

            $transfer = Transfer::whereNull('deleted_at')
                ->where('user_id', $user->id)
                ->where('Ref', $this->createdTransferReference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($mayAutoDispatch) {
                // Atomic one-shot: approve + dispatch the exact row before commit.
                // If dispatch fails, the whole creation is rolled back.
                $workflow = app(TransferWorkflowService::class);
                $transfer = $workflow->approve($transfer, $user);
                $transfer = $workflow->dispatch($transfer, $user, $batchPlan);
            }
            // else: parent::store() already left it as "pending" and moved no stock.

            return response()->json([
                'success' => true,
                'transfer' => [
                    'id' => (int) $transfer->id,
                    'reference' => $transfer->Ref,
                    'approval_status' => $transfer->approval_status,
                    'logistics_status' => $transfer->logistics_status ?: 'pending',
                    'receiving_token' => $transfer->receiving_token,
                    'dispatched_at' => optional($transfer->dispatched_at)->toIso8601String(),
                    'auto_dispatched' => (bool) $mayAutoDispatch,
                ],
            ]);
        }, 10);
    }

    /**
     * Whether the creating user also holds the authority to approve + dispatch in
     * the same operation. This is the same permission the workflow uses to gate a
     * later approval (transfer_edit).
     */
    private function creatorMayAutoDispatch($user): bool
    {
        return $user && $user->hasPermissionName('transfer_edit');
    }

    /**
     * Parent::store() calls this method to build the transfer reference. We serialize
     * that generation on the tenant settings row and retain the exact reference used
     * by this request, so concurrent creates can never rediscover another request's
     * transfer by "latest id".
     */
    public function getNumberOrder()
    {
        $setting = DB::table('settings')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $setting) {
            $reference = parent::getNumberOrder();
            $this->createdTransferReference = $reference;
            return $reference;
        }

        $prefix = ! empty($setting->transfer_prefix) ? $setting->transfer_prefix : 'TR';
        $last = DB::table('transfers')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($last) {
            $parts = explode('_', (string) $last->Ref);
            $reference = isset($parts[1]) && is_numeric($parts[1])
                ? $parts[0].'_'.str_pad(((int) $parts[1]) + 1, 4, '0', STR_PAD_LEFT)
                : $prefix.'_0001';
        } else {
            $reference = $prefix.'_0001';
        }

        $this->createdTransferReference = $reference;
        return $reference;
    }

    public function update(Request $request, $id)
    {
        $this->assertBusinessRoute($request);

        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $isLocationAware = $transfer->from_inventory_location_id || $transfer->to_inventory_location_id;

        // Legacy warehouse-only transfers keep the battle-tested legacy update
        // (with its warehouse-based stock reversal). Nothing changes for them.
        if (! $isLocationAware) {
            return parent::update($request, $id);
        }

        // A location-aware transfer that already moved (or committed to moving)
        // stock must NEVER run the legacy warehouse reversal — it would corrupt the
        // per-location and per-batch ledgers. It is simply not editable.
        $blockReason = $this->locationTransferEditBlockReason($transfer);
        if ($blockReason !== null) {
            return response()->json([
                'success' => false,
                'code' => 'transfer_not_editable',
                'message' => $blockReason,
                'approval_status' => $transfer->approval_status,
                'logistics_status' => $transfer->logistics_status ?: 'pending',
                'statut' => $transfer->statut,
            ], 409);
        }

        // Safe branch: genuinely pending, zero stock movement. Header + details can
        // be replaced with NO stock reversal because nothing has left the origin.
        return $this->updatePendingLocationAware($request, $transfer);
    }

    /**
     * Reason a location-aware transfer may not be edited, or null when it is safe.
     * Uses lifecycle state AND hard movement/receipt evidence — never status alone.
     */
    private function locationTransferEditBlockReason(Transfer $transfer): ?string
    {
        if ($transfer->isApproved()) {
            return 'La transferencia ya fue aprobada y no puede editarse. Crea una nueva si necesitas cambios.';
        }

        $logistics = (string) ($transfer->logistics_status ?: 'pending');
        if (! in_array($logistics, ['', 'pending'], true)) {
            return 'La transferencia ya inició su flujo logístico ('.$logistics.') y no puede editarse.';
        }
        // NOTE: `statut` is chosen by the creator at creation time (and normalized
        // completed -> sent), so a still-pending transfer can legitimately carry
        // statut='sent'. It is NOT a reliable dispatch signal here — dispatch always
        // also sets approval_status='approved' + dispatched_at, both covered above/below.
        if ($transfer->dispatched_at || $transfer->received_at) {
            return 'La transferencia ya tiene despacho o recepción registrados y no puede editarse.';
        }

        $detailIds = TransferDetail::where('transfer_id', $transfer->id)->pluck('id');

        if ($detailIds->isNotEmpty() && Schema::hasTable('transfer_detail_batches')
            && TransferDetailBatch::whereIn('transfer_detail_id', $detailIds)->exists()) {
            return 'La transferencia ya tiene lotes asignados por despacho y no puede editarse.';
        }
        if (Schema::hasTable('transfer_receipts')
            && DB::table('transfer_receipts')->where('transfer_id', $transfer->id)->exists()) {
            return 'La transferencia ya tiene recepciones registradas y no puede editarse.';
        }
        if (Schema::hasTable('transfer_discrepancies')
            && DB::table('transfer_discrepancies')->where('transfer_id', $transfer->id)->exists()) {
            return 'La transferencia tiene incidencias registradas y no puede editarse.';
        }
        if ($detailIds->isNotEmpty() && Schema::hasTable('product_batch_location_movements')
            && DB::table('product_batch_location_movements')
                ->where('reference_type', 'TransferDispatchBatch')
                ->whereIn('reference_id', $detailIds->map(fn ($x) => (string) $x)->all())
                ->exists()) {
            return 'La transferencia ya movió lotes por ubicación y no puede editarse.';
        }
        if (Schema::hasTable('inventory_location_movements')
            && DB::table('inventory_location_movements')
                ->where('reference_type', 'TransferDispatch')
                ->where('reference_id', (string) $transfer->id)
                ->exists()) {
            return 'La transferencia ya movió inventario por ubicación y no puede editarse.';
        }

        return null;
    }

    /**
     * Edit a still-pending, movement-free location-aware transfer. No stock is
     * touched anywhere: details are replaced outright. A rejected transfer
     * re-enters the approval queue.
     */
    private function updatePendingLocationAware(Request $request, Transfer $transfer)
    {
        $request->validate([
            'transfer.from_warehouse' => 'required',
            'transfer.to_warehouse' => 'required',
            'details' => 'required|array|min:1',
        ]);

        return DB::transaction(function () use ($request, $transfer) {
            $apiUser = $request->user('api');
            $user = Auth::user() ?: $apiUser;

            if (! $user->hasRecordView() && (int) $transfer->user_id !== (int) $user->id) {
                $this->authorizeForUser($apiUser, 'check_record', $transfer);
            }

            $t = $request->input('transfer');
            $transfer->date = $t['date'] ?? $transfer->date;
            $transfer->from_warehouse_id = (int) $t['from_warehouse'];
            $transfer->to_warehouse_id = (int) $t['to_warehouse'];
            if (! empty($t['from_inventory_location_id'])) {
                $transfer->from_inventory_location_id = (int) $t['from_inventory_location_id'];
            }
            if (! empty($t['to_inventory_location_id'])) {
                $transfer->to_inventory_location_id = (int) $t['to_inventory_location_id'];
            }
            $transfer->items = count($request->input('details'));
            $transfer->tax_rate = $t['tax_rate'] ?? 0;
            $transfer->TaxNet = $t['TaxNet'] ?? 0;
            $transfer->discount = $t['discount'] ?? 0;
            $transfer->shipping = $t['shipping'] ?? 0;
            $transfer->statut = $t['statut'] ?? $transfer->statut;
            $transfer->notes = $t['notes'] ?? null;
            $transfer->GrandTotal = $request->input('GrandTotal', $transfer->GrandTotal);
            if ($transfer->approval_status === 'rejected') {
                $transfer->approval_status = 'pending';
            }
            $transfer->save();

            $oldDetailIds = TransferDetail::where('transfer_id', $transfer->id)->pluck('id')->all();
            if ($oldDetailIds && Schema::hasTable('transfer_detail_batches')) {
                TransferDetailBatch::whereIn('transfer_detail_id', $oldDetailIds)->delete();
            }
            TransferDetail::where('transfer_id', $transfer->id)->delete();

            foreach ($request->input('details') as $d) {
                TransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'quantity' => $d['quantity'],
                    'purchase_unit_id' => $d['purchase_unit_id'] ?? null,
                    'product_id' => $d['product_id'],
                    'product_variant_id' => $d['product_variant_id'] ?? null,
                    'cost' => $d['Unit_cost'] ?? 0,
                    'TaxNet' => $d['tax_percent'] ?? 0,
                    'tax_method' => $d['tax_method'] ?? '1',
                    'discount' => $d['discount'] ?? 0,
                    'discount_method' => $d['discount_Method'] ?? '1',
                    'total' => $d['subtotal'] ?? 0,
                ]);
            }

            app(TransferLogisticsService::class)->recordEvent(
                $transfer->id, 'updated', $user->id, $transfer->from_warehouse_id,
                ['reference' => $transfer->Ref]
            );

            return response()->json([
                'success' => true,
                'transfer' => ['id' => (int) $transfer->id, 'reference' => $transfer->Ref],
            ]);
        }, 10);
    }

    /**
     * Pull explicit per-line batch picks out of the create payload, keyed by
     * "<product_id>:<variant_id|0>". Lines with no picks are omitted (FEFO fallback).
     */
    private function extractBatchPlan(Request $request): array
    {
        $plan = [];
        foreach ((array) $request->input('details', []) as $line) {
            if (! is_array($line)) continue;
            $raw = $line['batches'] ?? null;
            if (! is_array($raw) || $raw === []) continue;

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) continue;
            $variantId = (isset($line['product_variant_id']) && $line['product_variant_id'] !== '' && $line['product_variant_id'] !== null)
                ? (int) $line['product_variant_id'] : 0;

            $picks = [];
            foreach ($raw as $b) {
                if (! is_array($b)) continue;
                $batchId = (int) ($b['product_batch_id'] ?? 0);
                $qty = round((float) ($b['qty'] ?? 0), 3);
                if ($batchId <= 0 || $qty <= 0) continue;
                $picks[] = ['product_batch_id' => $batchId, 'qty' => $qty];
            }
            if ($picks) {
                $plan[$productId.':'.$variantId] = $picks;
            }
        }

        return $plan;
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Transfer::class);
        $user = Auth::user() ?: $user;

        $query = Transfer::with([
            'from_warehouse:id,name',
            'to_warehouse:id,name',
        ])->whereNull('deleted_at');

        $hasLocations = Schema::hasTable('inventory_locations')
            && Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id');

        if ($hasLocations) {
            $query->with([
                'fromInventoryLocation.branch:id,name',
                'fromInventoryLocation.warehouse:id,name',
                'toInventoryLocation.branch:id,name',
                'toInventoryLocation.warehouse:id,name',
            ]);
        }

        if (! $user->hasRecordView()) {
            $query->where('user_id', $user->id);
        }

        app(TransferListScopeService::class)->apply($query, $user);

        if ($request->filled('Ref')) {
            $query->where('Ref', 'like', '%'.$request->string('Ref')->toString().'%');
        }
        if ($request->filled('statut')) {
            $query->where('statut', 'like', '%'.$request->string('statut')->toString().'%');
        }

        if ($request->filled('from_warehouse_id')) {
            $value = (int) $request->input('from_warehouse_id');
            if ($hasLocations) $query->where('from_inventory_location_id', $value);
            else $query->where('from_warehouse_id', $value);
        }
        if ($request->filled('to_warehouse_id')) {
            $value = (int) $request->input('to_warehouse_id');
            if ($hasLocations) $query->where('to_inventory_location_id', $value);
            else $query->where('to_warehouse_id', $value);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($scope) use ($search, $hasLocations) {
                $scope->where('Ref', 'like', "%{$search}%")
                    ->orWhere('statut', 'like', "%{$search}%")
                    ->orWhereHas('from_warehouse', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('to_warehouse', fn ($q) => $q->where('name', 'like', "%{$search}%"));

                if ($hasLocations) {
                    $scope->orWhereHas('fromInventoryLocation', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('toInventoryLocation', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('fromInventoryLocation.branch', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('toInventoryLocation.branch', fn ($q) => $q->where('name', 'like', "%{$search}%"));
                }
            });
        }

        $totalRows = (clone $query)->count();
        $limit = (int) $request->input('limit', 10);
        $page = max(1, (int) $request->input('page', 1));
        if ($limit === -1) $limit = max(1, $totalRows);
        if ($limit <= 0) $limit = 10;

        $sortField = (string) $request->input('SortField', 'id');
        $sortType = strtolower((string) $request->input('SortType', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['id', 'date', 'Ref', 'GrandTotal', 'items', 'statut', 'approval_status', 'from_warehouse_id', 'to_warehouse_id'];
        if (! in_array($sortField, $allowedSorts, true)) $sortField = 'id';

        if ($hasLocations && $sortField === 'from_warehouse_id') {
            $query->orderByRaw('COALESCE(from_inventory_location_id, from_warehouse_id) '.$sortType);
        } elseif ($hasLocations && $sortField === 'to_warehouse_id') {
            $query->orderByRaw('COALESCE(to_inventory_location_id, to_warehouse_id) '.$sortType);
        } else {
            $query->orderBy($sortField, $sortType);
        }

        $service = app(TransferListScopeService::class);
        $transfers = $query
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(fn (Transfer $transfer) => [
                'id' => (int) $transfer->id,
                'date' => trim((string) $transfer->date.' '.(string) $transfer->time),
                'Ref' => $transfer->Ref,
                'from_warehouse' => $service->transferLabel($transfer, 'from'),
                'to_warehouse' => $service->transferLabel($transfer, 'to'),
                'GrandTotal' => (float) $transfer->GrandTotal,
                'items' => (float) $transfer->items,
                'statut' => $transfer->statut,
                'approval_status' => $transfer->approval_status,
                'logistics_status' => $transfer->logistics_status ?: 'pending',
                'from_inventory_location_id' => $transfer->from_inventory_location_id ? (int) $transfer->from_inventory_location_id : null,
                'to_inventory_location_id' => $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null,
            ])->values();

        return response()->json([
            'totalRows' => $totalRows,
            'warehouses' => $service->sourceOptions($user)->values(),
            'transfers' => $transfers,
            'inventory_location_mode' => $hasLocations,
        ]);
    }

    private function assertBusinessRoute(Request $request): void
    {
        $from = $request->input('transfer.from_inventory_location_id');
        $to = $request->input('transfer.to_inventory_location_id');

        // Legacy warehouse-only payloads remain supported during the rollout.
        if (! $from || ! $to) return;

        app(TransferBusinessDestinationService::class)->assertAllowed((int) $from, (int) $to);
    }
}
