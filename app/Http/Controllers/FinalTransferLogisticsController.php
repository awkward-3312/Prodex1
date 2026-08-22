<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferDetailSerial;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinalTransferLogisticsController extends TransferLogisticsController
{
    public function __construct(private TransferLogisticsService $finalLogistics)
    {
        parent::__construct($finalLogistics);
    }

    public function incoming(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION), 403);

        $warehouseIds = $this->finalLogistics->warehouseIdsForUser($user);
        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);

        $transfers = Transfer::with([
                'from_warehouse:id,name', 'to_warehouse:id,name',
                'fromInventoryLocation:id,name', 'toInventoryLocation:id,name',
            ])
            ->whereNull('deleted_at')
            ->whereIn('logistics_status', ['in_transit', 'partially_received'])
            ->where(function ($query) use ($warehouseIds, $locationIds) {
                if ($locationIds) {
                    $query->where(function ($q) use ($locationIds) {
                        $q->whereNotNull('to_inventory_location_id')
                            ->whereIn('to_inventory_location_id', $locationIds);
                    });
                }

                if ($warehouseIds) {
                    $method = $locationIds ? 'orWhere' : 'where';
                    $query->{$method}(function ($q) use ($warehouseIds) {
                        $q->whereNull('to_inventory_location_id')
                            ->whereIn('to_warehouse_id', $warehouseIds);
                    });
                }

                if (! $locationIds && ! $warehouseIds) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('dispatched_at')
            ->get()
            ->filter(fn (Transfer $transfer) => $this->finalLogistics->userCanReceive($user, $transfer))
            ->map(fn (Transfer $transfer) => $this->physicalSummary($this->baseSummary($transfer, $user), $transfer))
            ->values();

        $unread = Schema::hasTable('transfer_notifications')
            ? DB::table('transfer_notifications')->where('user_id', $user->id)->whereNull('read_at')->count()
            : 0;

        return response()->json(['transfers' => $transfers, 'unread' => $unread]);
    }

    public function showByToken(Request $request, string $token)
    {
        return $this->augmentReceivingResponse(parent::showByToken($request, $token));
    }

    public function show(Request $request, int $id)
    {
        return $this->augmentReceivingResponse(parent::show($request, $id));
    }

    public function receive(Request $request, int $id)
    {
        return $this->augmentSummaryResponse(parent::receive($request, $id));
    }

    public function qrPayload(Request $request, int $id)
    {
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $user = $request->user('api');
        abort_unless($user, 401);

        if ($transfer->from_inventory_location_id || $transfer->to_inventory_location_id) {
            $scope = app(InventoryLocationScopeService::class);
            $allowed = (int) $user->role_id === 1
                || ($transfer->from_inventory_location_id && $scope->canAccess($user, (int) $transfer->from_inventory_location_id))
                || ($transfer->to_inventory_location_id && $scope->canAccess($user, (int) $transfer->to_inventory_location_id));
        } else {
            $warehouseIds = $this->finalLogistics->warehouseIdsForUser($user);
            $allowed = (int) $user->is_all_warehouses === 1
                || in_array((int) $transfer->from_warehouse_id, $warehouseIds, true)
                || in_array((int) $transfer->to_warehouse_id, $warehouseIds, true);
        }
        abort_unless($allowed, 403);

        if (! $transfer->receiving_token && $transfer->isApproved() && $transfer->statut === 'sent') {
            $this->finalLogistics->syncDispatchState($transfer, $user);
            $transfer->refresh();
        }

        abort_unless($transfer->receiving_token, 422, 'El QR estará disponible cuando la transferencia sea despachada.');

        return response()->json([
            'transfer_id' => $transfer->id,
            'reference' => $transfer->Ref,
            'token' => $transfer->receiving_token,
            'qr_value' => url('/transfer-receive/'.$transfer->receiving_token),
        ]);
    }

    private function augmentReceivingResponse($response)
    {
        if (! $response instanceof JsonResponse) return $response;
        $data = $response->getData(true);
        $transferId = (int) ($data['transfer']['id'] ?? 0);
        if ($transferId <= 0) return $response;

        $transfer = Transfer::with(['fromInventoryLocation', 'toInventoryLocation'])->find($transferId);
        if ($transfer) {
            $data['transfer'] = $this->physicalSummary($data['transfer'] ?? [], $transfer);
        }

        if ($transfer && Schema::hasTable('transfer_detail_serials') && Schema::hasTable('product_serials')) {
            $detailIds = collect($data['details'] ?? [])->pluck('transfer_detail_id')->filter()->map(fn ($id) => (int) $id)->all();
            $serials = TransferDetailSerial::whereIn('transfer_detail_id', $detailIds)
                ->with('serial:id,serial_number')
                ->orderBy('id')
                ->get()
                ->groupBy('transfer_detail_id');

            foreach ($data['details'] as &$line) {
                $rows = $serials->get((int) ($line['transfer_detail_id'] ?? 0), collect());
                $manifest = $rows->map(fn (TransferDetailSerial $row) => [
                    'serial_number' => (string) optional($row->serial)->serial_number,
                    'status' => (string) $row->status,
                    'issue_type' => $row->issue_type,
                ])->filter(fn ($row) => $row['serial_number'] !== '')->values()->all();

                $line['serials'] = $manifest;
                if ($manifest) {
                    $numbers = collect($manifest)->pluck('serial_number')->implode(', ');
                    $line['code'] = trim((string) ($line['code'] ?? '').' · Serie/IMEI: '.$numbers);
                }
            }
            unset($line);
        }

        return response()->json($data, $response->getStatusCode());
    }

    private function augmentSummaryResponse($response)
    {
        if (! $response instanceof JsonResponse) return $response;
        $data = $response->getData(true);

        if (isset($data['transfer']['id'])) {
            $transfer = Transfer::with(['fromInventoryLocation', 'toInventoryLocation'])->find((int) $data['transfer']['id']);
            if ($transfer) $data['transfer'] = $this->physicalSummary($data['transfer'], $transfer);
        }

        return response()->json($data, $response->getStatusCode());
    }

    private function baseSummary(Transfer $transfer, $user): array
    {
        return [
            'id' => (int) $transfer->id,
            'reference' => $transfer->Ref,
            'from_warehouse_id' => $transfer->from_warehouse_id ? (int) $transfer->from_warehouse_id : null,
            'from_warehouse' => optional($transfer->from_warehouse)->name,
            'to_warehouse_id' => $transfer->to_warehouse_id ? (int) $transfer->to_warehouse_id : null,
            'to_warehouse' => optional($transfer->to_warehouse)->name,
            'items' => (float) $transfer->items,
            'approval_status' => $transfer->approval_status,
            'status' => $transfer->statut,
            'logistics_status' => $transfer->logistics_status,
            'dispatched_at' => optional($transfer->dispatched_at)->toIso8601String(),
            'received_at' => optional($transfer->received_at)->toIso8601String(),
            'receiving_token' => $transfer->receiving_token,
            'can_receive' => $this->finalLogistics->userCanReceive($user, $transfer),
        ];
    }

    private function physicalSummary(array $summary, Transfer $transfer): array
    {
        if (! $transfer->from_inventory_location_id && ! $transfer->to_inventory_location_id) return $summary;

        $from = $transfer->fromInventoryLocation;
        $to = $transfer->toInventoryLocation;
        $summary['from_inventory_location_id'] = $transfer->from_inventory_location_id ? (int) $transfer->from_inventory_location_id : null;
        $summary['to_inventory_location_id'] = $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null;
        $summary['from_inventory_location'] = $from?->name;
        $summary['to_inventory_location'] = $to?->name;
        $summary['legacy_from_warehouse'] = $summary['from_warehouse'] ?? null;
        $summary['legacy_to_warehouse'] = $summary['to_warehouse'] ?? null;

        if ($from) $summary['from_warehouse'] = $from->name;
        if ($to) $summary['to_warehouse'] = $to->name;

        return $summary;
    }
}
