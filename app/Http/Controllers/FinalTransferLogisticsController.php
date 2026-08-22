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
    public function incoming(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user && $user->hasPermissionName(TransferLogisticsService::RECEIVE_PERMISSION), 403);

        $logistics = app(TransferLogisticsService::class);
        $warehouseIds = $logistics->warehouseIdsForUser($user);
        $hasLocationColumns = Schema::hasColumn('transfers', 'from_inventory_location_id')
            && Schema::hasColumn('transfers', 'to_inventory_location_id')
            && Schema::hasTable('inventory_locations');
        $locationIds = $hasLocationColumns
            ? app(InventoryLocationScopeService::class)->allowedLocationIds($user)
            : [];

        if (! $warehouseIds && ! $locationIds) {
            return response()->json(['transfers' => [], 'unread' => 0]);
        }

        $relations = ['from_warehouse:id,name', 'to_warehouse:id,name'];
        if ($hasLocationColumns) {
            $relations[] = 'fromInventoryLocation:id,name';
            $relations[] = 'toInventoryLocation:id,name';
        }

        $query = Transfer::with($relations)
            ->whereNull('deleted_at')
            ->whereIn('logistics_status', ['in_transit', 'partially_received']);

        // A modern transfer is visible only through its physical destination.
        // Warehouse membership must never leak another branch/location that happens
        // to share the same legacy compatibility warehouse.
        $query->where(function ($scope) use ($warehouseIds, $locationIds, $hasLocationColumns) {
            $hasCondition = false;

            if ($hasLocationColumns && $locationIds) {
                $scope->where(function ($modern) use ($locationIds) {
                    $modern->whereNotNull('to_inventory_location_id')
                        ->whereIn('to_inventory_location_id', $locationIds);
                });
                $hasCondition = true;
            }

            if ($warehouseIds) {
                $method = $hasCondition ? 'orWhere' : 'where';
                $scope->{$method}(function ($legacy) use ($warehouseIds, $hasLocationColumns) {
                    if ($hasLocationColumns) {
                        $legacy->whereNull('from_inventory_location_id')
                            ->whereNull('to_inventory_location_id');
                    }
                    $legacy->whereIn('to_warehouse_id', $warehouseIds);
                });
            }
        });

        $transfers = $query->orderByDesc('dispatched_at')
            ->get()
            ->map(fn (Transfer $transfer) => $this->summaryForUser($transfer, $user))
            ->values();

        $unread = Schema::hasTable('transfer_notifications')
            ? DB::table('transfer_notifications')
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count()
            : 0;

        return response()->json(compact('transfers', 'unread'));
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

        $logistics = app(TransferLogisticsService::class);
        $modern = Schema::hasColumn('transfers', 'from_inventory_location_id')
            && ($transfer->from_inventory_location_id || $transfer->to_inventory_location_id);

        if ($modern) {
            $scope = app(InventoryLocationScopeService::class);
            $allowed = ($transfer->from_inventory_location_id
                    && $scope->canAccess($user, (int) $transfer->from_inventory_location_id))
                || ($transfer->to_inventory_location_id
                    && $scope->canAccess($user, (int) $transfer->to_inventory_location_id));
        } else {
            $warehouseIds = $logistics->warehouseIdsForUser($user);
            $allowed = (int) $user->is_all_warehouses === 1
                || in_array((int) $transfer->from_warehouse_id, $warehouseIds, true)
                || in_array((int) $transfer->to_warehouse_id, $warehouseIds, true);
        }
        abort_unless($allowed, 403);

        if (! $transfer->receiving_token && $transfer->isApproved() && $transfer->statut === 'sent') {
            $logistics->syncDispatchState($transfer, $user);
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

        $transfer = Transfer::find($transferId);
        if ($transfer && Schema::hasTable('inventory_locations')
            && Schema::hasColumn('transfers', 'from_inventory_location_id')) {
            $transfer->load(['fromInventoryLocation', 'toInventoryLocation']);
        }
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
            $transfer = Transfer::find((int) $data['transfer']['id']);
            if ($transfer && Schema::hasTable('inventory_locations')
                && Schema::hasColumn('transfers', 'from_inventory_location_id')) {
                $transfer->load(['fromInventoryLocation', 'toInventoryLocation']);
            }
            if ($transfer) $data['transfer'] = $this->physicalSummary($data['transfer'], $transfer);
        }

        if (isset($data['transfers']) && is_array($data['transfers'])) {
            $ids = collect($data['transfers'])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $query = Transfer::whereIn('id', $ids);
            if (Schema::hasTable('inventory_locations') && Schema::hasColumn('transfers', 'from_inventory_location_id')) {
                $query->with(['fromInventoryLocation', 'toInventoryLocation']);
            }
            $models = $query->get()->keyBy('id');
            foreach ($data['transfers'] as &$summary) {
                $transfer = $models->get((int) ($summary['id'] ?? 0));
                if ($transfer) $summary = $this->physicalSummary($summary, $transfer);
            }
            unset($summary);
        }

        return response()->json($data, $response->getStatusCode());
    }

    private function summaryForUser(Transfer $transfer, $user): array
    {
        $summary = [
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
            'can_receive' => app(TransferLogisticsService::class)->userCanReceive($user, $transfer),
        ];

        return $this->physicalSummary($summary, $transfer);
    }

    private function physicalSummary(array $summary, Transfer $transfer): array
    {
        if (! Schema::hasColumn('transfers', 'from_inventory_location_id')
            || (! $transfer->from_inventory_location_id && ! $transfer->to_inventory_location_id)) {
            return $summary;
        }

        $from = $transfer->relationLoaded('fromInventoryLocation') ? $transfer->fromInventoryLocation : null;
        $to = $transfer->relationLoaded('toInventoryLocation') ? $transfer->toInventoryLocation : null;
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
