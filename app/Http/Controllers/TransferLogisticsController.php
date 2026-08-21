<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Unit;
use App\Services\BatchService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferLogisticsController extends Controller
{
    public function __construct(private TransferLogisticsService $logistics)
    {
    }

    public function incoming(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION), 403);

        $warehouseIds = $this->logistics->warehouseIdsForUser($user);
        if (! $warehouseIds) {
            return response()->json(['transfers' => [], 'unread' => 0]);
        }

        $transfers = Transfer::with(['from_warehouse:id,name', 'to_warehouse:id,name'])
            ->whereNull('deleted_at')
            ->whereIn('to_warehouse_id', $warehouseIds)
            ->whereIn('logistics_status', ['in_transit', 'partially_received'])
            ->orderByDesc('dispatched_at')
            ->get()
            ->map(fn (Transfer $transfer) => $this->summary($transfer, $user));

        $unread = DB::table('transfer_notifications')
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(compact('transfers', 'unread'));
    }

    public function showByToken(Request $request, string $token)
    {
        $transfer = Transfer::with(['from_warehouse:id,name', 'to_warehouse:id,name'])
            ->whereNull('deleted_at')
            ->where('receiving_token', $token)
            ->firstOrFail();

        return $this->receivingPayload($request, $transfer);
    }

    public function show(Request $request, int $id)
    {
        $transfer = Transfer::with(['from_warehouse:id,name', 'to_warehouse:id,name'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        return $this->receivingPayload($request, $transfer);
    }

    public function receive(Request $request, int $id)
    {
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $user = $request->user('api');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.transfer_detail_id' => ['required', 'integer'],
            'items.*.quantity_good' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_defective' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_missing' => ['nullable', 'numeric', 'min:0'],
        ]);

        $updated = $this->logistics->receive(
            $transfer,
            $user,
            $validated['items'],
            $validated['notes'] ?? null
        );

        // Once the shipment is fully accounted for, no other authorized receiver
        // should keep seeing a stale "en camino" unread alert. Partial receipts stay
        // visible to every receiver because more physical stock is still outstanding.
        if (in_array($updated->logistics_status, ['received', 'received_with_issues'], true)) {
            DB::table('transfer_notifications')
                ->where('transfer_id', $updated->id)
                ->where('type', 'incoming_transfer')
                ->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'transfer' => $this->summary($updated, $user),
            'open_discrepancies' => DB::table('transfer_discrepancies')
                ->where('transfer_id', $updated->id)
                ->where('resolution_status', 'open')
                ->count(),
        ]);
    }

    public function notifications(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION), 403);

        $rows = DB::table('transfer_notifications')
            ->join('transfers', 'transfers.id', '=', 'transfer_notifications.transfer_id')
            ->where('transfer_notifications.user_id', $user->id)
            ->whereNull('transfers.deleted_at')
            ->orderByDesc('transfer_notifications.created_at')
            ->limit(20)
            ->get([
                'transfer_notifications.id',
                'transfer_notifications.transfer_id',
                'transfer_notifications.type',
                'transfer_notifications.title',
                'transfer_notifications.message',
                'transfer_notifications.read_at',
                'transfer_notifications.created_at',
                'transfers.Ref as reference',
                'transfers.receiving_token',
                'transfers.logistics_status',
            ]);

        return response()->json([
            'notifications' => $rows,
            'unread' => $rows->whereNull('read_at')->count(),
        ]);
    }

    public function markNotificationRead(Request $request, int $notificationId)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $updated = DB::table('transfer_notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->update(['read_at' => now(), 'updated_at' => now()]);

        abort_if(! $updated, 404);

        return response()->json(['success' => true]);
    }

    public function qrPayload(Request $request, int $id)
    {
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $user = $request->user('api');
        abort_unless($user, 401);

        $warehouseIds = $this->logistics->warehouseIdsForUser($user);
        $allowed = (int) $user->is_all_warehouses === 1
            || in_array((int) $transfer->from_warehouse_id, $warehouseIds, true)
            || in_array((int) $transfer->to_warehouse_id, $warehouseIds, true);
        abort_unless($allowed, 403);

        if (! $transfer->receiving_token && $transfer->isApproved() && $transfer->statut === 'sent') {
            $this->logistics->syncDispatchState($transfer, $user);
            $transfer->refresh();
        }

        abort_unless($transfer->receiving_token, 422, 'El QR estará disponible cuando la transferencia sea despachada.');

        return response()->json([
            'transfer_id' => $transfer->id,
            'reference' => $transfer->Ref,
            'token' => $transfer->receiving_token,
            // The QR should encode this route, not a numeric transfer id.
            'qr_value' => url('/transfer-receive/'.$transfer->receiving_token),
        ]);
    }

    private function receivingPayload(Request $request, Transfer $transfer)
    {
        $user = $request->user('api');
        abort_unless($user && $this->logistics->userCanReceive($user, $transfer), 403, 'Esta transferencia no pertenece a una bodega que puedas recibir.');

        abort_unless(in_array($transfer->logistics_status, ['in_transit', 'partially_received', 'received', 'received_with_issues'], true), 422, 'La transferencia aún no ha sido despachada.');

        $details = TransferDetail::with(['product:id,name,code'])
            ->where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get();

        $accounted = DB::table('transfer_receipt_items')
            ->join('transfer_receipts', 'transfer_receipts.id', '=', 'transfer_receipt_items.transfer_receipt_id')
            ->where('transfer_receipts.transfer_id', $transfer->id)
            ->selectRaw('transfer_receipt_items.transfer_detail_id, SUM(quantity_good) as good, SUM(quantity_defective) as defective, SUM(quantity_missing) as missing')
            ->groupBy('transfer_receipt_items.transfer_detail_id')
            ->get()
            ->keyBy('transfer_detail_id');

        $batchMap = app(BatchService::class)->batchesForTransferDetails($details);

        $lines = $details->map(function (TransferDetail $detail) use ($accounted, $batchMap) {
            $prior = $accounted->get($detail->id);
            $good = (float) ($prior->good ?? 0);
            $defective = (float) ($prior->defective ?? 0);
            $missing = (float) ($prior->missing ?? 0);
            $counted = $good + $defective + $missing;
            $variant = $detail->product_variant_id ? ProductVariant::find($detail->product_variant_id) : null;
            $unit = $detail->purchase_unit_id ? Unit::find($detail->purchase_unit_id) : null;

            return [
                'transfer_detail_id' => (int) $detail->id,
                'product_id' => (int) $detail->product_id,
                'product_variant_id' => $detail->product_variant_id ? (int) $detail->product_variant_id : null,
                'code' => $variant?->code ?: optional($detail->product)->code,
                'name' => $variant ? '['.$variant->name.'] '.optional($detail->product)->name : optional($detail->product)->name,
                'unit' => $unit?->ShortName,
                'quantity_sent' => (float) $detail->quantity,
                'quantity_good_prior' => $good,
                'quantity_defective_prior' => $defective,
                'quantity_missing_prior' => $missing,
                'quantity_remaining' => max(0, (float) $detail->quantity - $counted),
                'batches' => $batchMap[(int) $detail->id] ?? [],
            ];
        })->values();

        if (DB::table('transfer_notifications')->where('transfer_id', $transfer->id)->where('user_id', $user->id)->whereNull('read_at')->exists()) {
            DB::table('transfer_notifications')
                ->where('transfer_id', $transfer->id)
                ->where('user_id', $user->id)
                ->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return response()->json([
            'transfer' => $this->summary($transfer, $user),
            'details' => $lines,
            'events' => DB::table('transfer_events')->where('transfer_id', $transfer->id)->orderBy('created_at')->get(),
            'can_receive' => $this->logistics->userCanReceive($user, $transfer)
                && in_array($transfer->logistics_status, ['in_transit', 'partially_received'], true),
        ]);
    }

    private function summary(Transfer $transfer, $user): array
    {
        return [
            'id' => (int) $transfer->id,
            'reference' => $transfer->Ref,
            'from_warehouse_id' => (int) $transfer->from_warehouse_id,
            'from_warehouse' => optional($transfer->from_warehouse)->name,
            'to_warehouse_id' => (int) $transfer->to_warehouse_id,
            'to_warehouse' => optional($transfer->to_warehouse)->name,
            'items' => (float) $transfer->items,
            'approval_status' => $transfer->approval_status,
            'status' => $transfer->statut,
            'logistics_status' => $transfer->logistics_status,
            'dispatched_at' => optional($transfer->dispatched_at)->toIso8601String(),
            'received_at' => optional($transfer->received_at)->toIso8601String(),
            'receiving_token' => $transfer->receiving_token,
            'can_receive' => $user ? $this->logistics->userCanReceive($user, $transfer) : false,
        ];
    }
}
