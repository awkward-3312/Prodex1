<?php

namespace App\Http\Controllers;

use App\Models\CashDrawer;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Models\Warehouse;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;

class UserOperationalAssignmentController extends BaseController
{
    public function show(Request $request, User $user, UserOperationalAssignmentService $service)
    {
        $this->authorizeForUser($request->user('api'), 'view', User::class);

        return response()->json([
            'default' => [
                'warehouse_id' => $user->default_warehouse_id,
                'warehouse_name' => optional($user->defaultWarehouse)->name,
                'cash_drawer_id' => $user->default_cash_drawer_id,
                'cash_drawer_name' => optional($user->defaultCashDrawer)->name,
            ],
            'effective' => $service->effectiveAssignment($user),
            'active_temporary_assignment' => $this->assignmentPayload($service->activeTemporaryAssignment($user)),
        ]);
    }

    public function storeTemporary(Request $request, User $user, UserOperationalAssignmentService $service)
    {
        $actor = $request->user('api');
        if (! $actor || ! $actor->hasPermissionName('user_temporary_assignment')) {
            abort(403);
        }
        if ((int) $actor->id === (int) $user->id) {
            return response()->json(['message' => 'No puede reasignarse a sí mismo.'], 403);
        }

        $data = $request->validate([
            'temporary_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'temporary_cash_drawer_id' => ['required', 'integer', 'exists:cash_drawers,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $drawer = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->where('warehouse_id', $data['temporary_warehouse_id'])
            ->findOrFail($data['temporary_cash_drawer_id']);
        $warehouse = Warehouse::whereNull('deleted_at')->findOrFail($data['temporary_warehouse_id']);

        UserOperationalAssignment::where('user_id', $user->id)
            ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
            ->update(['status' => UserOperationalAssignment::STATUS_ENDED, 'ends_at' => now()]);

        $assignment = UserOperationalAssignment::create([
            'user_id' => $user->id,
            'default_warehouse_id_snapshot' => $user->default_warehouse_id,
            'default_warehouse_name_snapshot' => optional($user->defaultWarehouse)->name,
            'default_cash_drawer_id_snapshot' => $user->default_cash_drawer_id,
            'default_cash_drawer_name_snapshot' => optional($user->defaultCashDrawer)->name,
            'temporary_warehouse_id' => $warehouse->id,
            'temporary_warehouse_name_snapshot' => $warehouse->name,
            'temporary_cash_drawer_id' => $drawer->id,
            'temporary_cash_drawer_name_snapshot' => $drawer->name,
            'assigned_by_user_id' => $actor->id,
            'assigned_by_user_name_snapshot' => $this->displayName($actor),
            'starts_at' => $data['starts_at'] ?? now(),
            'ends_at' => $data['ends_at'] ?? null,
            'reason' => $data['reason'],
            'status' => UserOperationalAssignment::STATUS_ACTIVE,
        ]);

        return response()->json([
            'success' => true,
            'assignment' => $assignment,
            'effective' => $service->effectiveAssignment($user->fresh()),
        ]);
    }

    public function end(Request $request, UserOperationalAssignment $assignment)
    {
        $actor = $request->user('api');
        if (! $actor || ! $actor->hasPermissionName('user_temporary_assignment')) {
            abort(403);
        }

        $assignment->update([
            'status' => UserOperationalAssignment::STATUS_ENDED,
            'ends_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function displayName($user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: ($user->username ?? null);
    }

    private function assignmentPayload(?UserOperationalAssignment $assignment): ?array
    {
        if (! $assignment) {
            return null;
        }

        return [
            'id' => $assignment->id,
            'temporary_warehouse_id' => $assignment->temporary_warehouse_id,
            'temporary_warehouse_name' => $assignment->temporary_warehouse_name_snapshot,
            'temporary_cash_drawer_id' => $assignment->temporary_cash_drawer_id,
            'temporary_cash_drawer_name' => $assignment->temporary_cash_drawer_name_snapshot,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assigned_by_user_name' => $assignment->assigned_by_user_name_snapshot,
            'starts_at' => optional($assignment->starts_at)->format('Y-m-d H:i:s'),
            'ends_at' => optional($assignment->ends_at)->format('Y-m-d H:i:s'),
            'reason' => $assignment->reason,
            'status' => $assignment->status,
        ];
    }
}
