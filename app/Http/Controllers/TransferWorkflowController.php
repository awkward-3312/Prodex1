<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\User;
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
        $updated = $this->workflow->approve($transfer, $user);
        return $this->payload($request, $updated);
    }

    public function reject(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Transfer::class);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $updated = $this->workflow->reject($transfer, $user, $validated['reason'] ?? null);
        return $this->payload($request, $updated);
    }

    public function dispatch(Request $request, int $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Transfer::class);
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $updated = $this->workflow->dispatch($transfer, $user);
        return $this->payload($request, $updated);
    }

    private function payload(Request $request, Transfer $transfer)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', Transfer::class);

        $transfer->load(['from_warehouse:id,name', 'to_warehouse:id,name']);
        if (Schema::hasTable('inventory_locations') && Schema::hasColumn('transfers', 'from_inventory_location_id')) {
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

        $fromName = optional($transfer->fromInventoryLocation)->name ?: optional($transfer->from_warehouse)->name;
        $toName = optional($transfer->toInventoryLocation)->name ?: optional($transfer->to_warehouse)->name;
        $pending = ! $transfer->approval_status || $transfer->approval_status === 'pending';
        $inTransit = in_array((string) $transfer->logistics_status, ['in_transit', 'partially_received'], true);

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
                'can_approve' => $pending && $user->hasPermissionName('transfer_edit'),
                'can_reject' => $pending && $user->hasPermissionName('transfer_edit'),
                'can_dispatch' => $transfer->isApproved() && ! $inTransit
                    && ! in_array((string) $transfer->logistics_status, ['received', 'received_with_issues'], true)
                    && $user->hasPermissionName('transfer_edit'),
            ],
        ]);
    }
}
