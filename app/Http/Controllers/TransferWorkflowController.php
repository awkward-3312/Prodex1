<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransferWorkflowController extends BaseController
{
    public function __construct(private TransferWorkflowService $workflow)
    {
    }

    public function showByReference(Request $request, string $reference)
    {
        $transfer = Transfer::whereNull('deleted_at')->where('Ref', $reference)->firstOrFail();
        return $this->payload($request, $transfer);
    }

    public function show(Request $request, int $id)
    {
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        return $this->payload($request, $transfer);
    }

    public function approve(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Transfer::class);
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertSourceScope($user, $transfer);
        $this->assertRecordScope($user, $transfer);
        $updated = $this->workflow->approve($transfer, $user);
        return $this->payload($request, $updated);
    }

    public function reject(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Transfer::class);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertSourceScope($user, $transfer);
        $this->assertRecordScope($user, $transfer);
        $updated = $this->workflow->reject($transfer, $user, $validated['reason'] ?? null);
        return $this->payload($request, $updated);
    }

    public function dispatchTransfer(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Transfer::class);
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertSourceScope($user, $transfer);
        $this->assertRecordScope($user, $transfer);
        $updated = $this->workflow->dispatch($transfer, $user);
        return $this->payload($request, $updated);
    }

    private function payload(Request $request, Transfer $transfer)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Transfer::class);
        $this->assertViewScope($user, $transfer);

        $transfer->load(['from_warehouse:id,name', 'to_warehouse:id,name']);
        $hasLocations = Schema::hasTable('inventory_locations') && Schema::hasColumn('transfers', 'from_inventory_location_id');
        if ($hasLocations) {
            $transfer->load(['fromInventoryLocation:id,name', 'toInventoryLocation:id,name']);
        }

        $events = collect();
        if (Schema::hasTable('transfer_events')) {
            $events = DB::table('transfer_events')
                ->where('transfer_id', $transfer->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            $userIds = $events->pluck('actor_user_id')->filter()->unique()->values();
            $names = User::whereIn('id', $userIds)->get()->mapWithKeys(function (User $actor) {
                $name = trim(($actor->firstname ?? '').' '.($actor->lastname ?? ''));
                return [$actor->id => $name !== '' ? $name : ($actor->username ?: 'Usuario')];
            });
            $events = $events->map(function ($event) use ($names) {
                $payload = $event->payload ? json_decode($event->payload, true) : [];
                return [
                    'id' => (int) $event->id,
                    'event_type' => (string) $event->event_type,
                    'actor_user_id' => $event->actor_user_id ? (int) $event->actor_user_id : null,
                    'actor_name' => $event->actor_user_id ? ($names[$event->actor_user_id] ?? 'Usuario') : 'Sistema',
                    'warehouse_id' => $event->warehouse_id ? (int) $event->warehouse_id : null,
                    'payload' => is_array($payload) ? $payload : [],
                    'created_at' => (string) $event->created_at,
                ];
            })->values();
        }

        $fromName = $hasLocations && $transfer->fromInventoryLocation
            ? $transfer->fromInventoryLocation->name
            : optional($transfer->from_warehouse)->name;
        $toName = $hasLocations && $transfer->toInventoryLocation
            ? $transfer->toInventoryLocation->name
            : optional($transfer->to_warehouse)->name;
        $pending = ! $transfer->approval_status || $transfer->approval_status === 'pending';
        $inTransit = in_array((string) $transfer->logistics_status, ['in_transit', 'partially_received'], true);
        $canOperateSource = $this->canAccessSource($user, $transfer) && $this->canOperateRecord($user, $transfer);

        return response()->json([
            'transfer' => [
                'id' => (int) $transfer->id,
                'reference' => $transfer->Ref,
                'from' => $fromName,
                'to' => $toName,
                'approval_status' => $transfer->approval_status,
                'status' => $transfer->statut,
                'logistics_status' => $transfer->logistics_status ?: 'pending',
                'items' => (float) $transfer->items,
                'grand_total' => (float) $transfer->GrandTotal,
                'dispatched_at' => optional($transfer->dispatched_at)->toIso8601String(),
                'received_at' => optional($transfer->received_at)->toIso8601String(),
            ],
            'events' => $events,
            'actions' => [
                'can_approve' => $pending && $canOperateSource && $user->hasPermissionName('transfer_edit'),
                'can_reject' => $pending && $canOperateSource && $user->hasPermissionName('transfer_edit'),
                'can_dispatch' => $transfer->isApproved() && $canOperateSource && ! $inTransit
                    && ! in_array((string) $transfer->logistics_status, ['received', 'received_with_issues'], true)
                    && $user->hasPermissionName('transfer_edit'),
            ],
        ]);
    }

    private function assertViewScope(User $user, Transfer $transfer): void
    {
        abort_unless($this->canAccessSource($user, $transfer) || $this->canAccessDestination($user, $transfer), 403,
            'No tienes acceso al origen ni al destino de esta transferencia.');
    }

    private function assertSourceScope(User $user, Transfer $transfer): void
    {
        abort_unless($this->canAccessSource($user, $transfer), 403,
            'No tienes acceso a la ubicación de origen de esta transferencia.');
    }

    private function assertRecordScope(User $user, Transfer $transfer): void
    {
        if ($this->canOperateRecord($user, $transfer)) return;
        $this->authorizeForUser($user, 'check_record', $transfer);
    }

    private function canOperateRecord(User $user, Transfer $transfer): bool
    {
        return $user->hasRecordView() || (int) $transfer->user_id === (int) $user->id;
    }

    private function canAccessSource(User $user, Transfer $transfer): bool
    {
        if ((int) $user->is_all_warehouses === 1) return true;
        if ($transfer->from_inventory_location_id && Schema::hasTable('inventory_locations')) {
            return app(InventoryLocationScopeService::class)->canAccess($user, (int) $transfer->from_inventory_location_id);
        }
        return $this->warehouseIds($user)->contains((int) $transfer->from_warehouse_id);
    }

    private function canAccessDestination(User $user, Transfer $transfer): bool
    {
        if ((int) $user->is_all_warehouses === 1) return true;
        if ($transfer->to_inventory_location_id && Schema::hasTable('inventory_locations')) {
            return app(InventoryLocationScopeService::class)->canAccess($user, (int) $transfer->to_inventory_location_id);
        }
        return $this->warehouseIds($user)->contains((int) $transfer->to_warehouse_id);
    }

    private function warehouseIds(User $user)
    {
        $ids = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id);
        if ($user->default_warehouse_id) $ids->push((int) $user->default_warehouse_id);
        return $ids->unique();
    }
}
