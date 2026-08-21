<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\UserOperationalAssignment;
use App\Services\BranchScopeService;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserOperationalAssignmentController extends BaseController
{
    public function show(Request $request, User $user, UserOperationalAssignmentService $service)
    {
        $actor = $request->user('api');
        $this->authorizeForUser($actor, 'view', User::class);

        $branchIds = app(BranchScopeService::class)->allowedBranchIds($actor);
        $branches = Branch::whereNull('deleted_at')->where('is_active', true)->orderBy('name');
        if ((int) $actor->role_id !== 1) $branches->whereIn('id', $branchIds ?: [0]);
        $branches = $branches->get(['id', 'code', 'name', 'default_inventory_location_id']);

        $visibleBranchIds = $branches->pluck('id')->all();
        $locations = InventoryLocation::active()->whereIn('branch_id', $visibleBranchIds ?: [0])
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales']);
        $drawers = CashDrawer::whereNull('deleted_at')->where('is_active', true)
            ->whereIn('branch_id', $visibleBranchIds ?: [0])
            ->orderBy('branch_id')->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'name', 'code']);

        return response()->json([
            'default' => [
                'branch_id' => $user->default_branch_id,
                'branch_name' => optional($user->defaultBranch)->name,
                'inventory_location_id' => $user->default_inventory_location_id,
                'inventory_location_name' => optional($user->defaultInventoryLocation)->name,
                'cash_drawer_id' => $user->default_cash_drawer_id,
                'cash_drawer_name' => optional($user->defaultCashDrawer)->name,
                // Legacy visibility while POS routes still carry warehouse IDs.
                'warehouse_id' => $user->default_warehouse_id,
                'warehouse_name' => optional($user->defaultWarehouse)->name,
            ],
            'effective' => $service->effectiveAssignment($user),
            'active_temporary_assignment' => $this->assignmentPayload($service->activeTemporaryAssignment($user)),
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $drawers,
        ]);
    }

    public function storeTemporary(Request $request, User $user, UserOperationalAssignmentService $service)
    {
        $actor = $request->user('api');
        if (! $actor || ! $actor->hasPermissionName('user_temporary_assignment')) abort(403);
        if ((int) $actor->id === (int) $user->id) {
            return response()->json(['message' => 'No puede reasignarse a sí mismo.'], 403);
        }

        $data = $request->validate([
            'temporary_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'temporary_inventory_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'temporary_cash_drawer_id' => ['nullable', 'integer', 'exists:cash_drawers,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $branchId = (int) $data['temporary_branch_id'];
        if ((int) $actor->role_id !== 1) {
            abort_unless(app(BranchScopeService::class)->canAccess($actor, $branchId), 403, 'No puedes reasignar usuarios a una sucursal fuera de tu alcance.');
        }

        $branch = Branch::whereNull('deleted_at')->where('is_active', true)->findOrFail($branchId);
        $location = InventoryLocation::active()
            ->where('branch_id', $branch->id)
            ->findOrFail((int) $data['temporary_inventory_location_id']);

        $drawer = null;
        if (! empty($data['temporary_cash_drawer_id'])) {
            $drawer = CashDrawer::whereNull('deleted_at')->where('is_active', true)
                ->where('branch_id', $branch->id)
                ->findOrFail((int) $data['temporary_cash_drawer_id']);

            if ($drawer->inventory_location_id && (int) $drawer->inventory_location_id !== (int) $location->id) {
                throw ValidationException::withMessages([
                    'temporary_cash_drawer_id' => 'La caja seleccionada no está vinculada a la ubicación de inventario seleccionada.',
                ]);
            }
        }

        $startsAt = $data['starts_at'] ?? now();
        $endsAt = $data['ends_at'] ?? null;

        $assignment = DB::transaction(function () use ($user, $actor, $branch, $location, $drawer, $startsAt, $endsAt, $data) {
            UserOperationalAssignment::where('user_id', $user->id)
                ->where('status', UserOperationalAssignment::STATUS_ACTIVE)
                ->lockForUpdate()
                ->update(['status' => UserOperationalAssignment::STATUS_ENDED, 'ends_at' => now()]);

            $freshUser = User::with(['defaultBranch', 'defaultInventoryLocation', 'defaultCashDrawer', 'defaultWarehouse'])->findOrFail($user->id);

            return UserOperationalAssignment::create([
                'user_id' => $freshUser->id,
                'default_warehouse_id_snapshot' => $freshUser->default_warehouse_id,
                'default_warehouse_name_snapshot' => optional($freshUser->defaultWarehouse)->name,
                'default_branch_id_snapshot' => $freshUser->default_branch_id,
                'default_branch_name_snapshot' => optional($freshUser->defaultBranch)->name,
                'default_inventory_location_id_snapshot' => $freshUser->default_inventory_location_id,
                'default_inventory_location_name_snapshot' => optional($freshUser->defaultInventoryLocation)->name,
                'default_cash_drawer_id_snapshot' => $freshUser->default_cash_drawer_id,
                'default_cash_drawer_name_snapshot' => optional($freshUser->defaultCashDrawer)->name,
                'temporary_warehouse_id' => null,
                'temporary_warehouse_name_snapshot' => null,
                'temporary_branch_id' => $branch->id,
                'temporary_branch_name_snapshot' => $branch->name,
                'temporary_inventory_location_id' => $location->id,
                'temporary_inventory_location_name_snapshot' => $location->name,
                'temporary_cash_drawer_id' => $drawer?->id,
                'temporary_cash_drawer_name_snapshot' => $drawer?->name,
                'assigned_by_user_id' => $actor->id,
                'assigned_by_user_name_snapshot' => $this->displayName($actor),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $data['reason'],
                'status' => UserOperationalAssignment::STATUS_ACTIVE,
            ]);
        });

        return response()->json([
            'success' => true,
            'assignment' => $this->assignmentPayload($assignment),
            'effective' => $service->effectiveAssignment($user->fresh()),
        ]);
    }

    public function end(Request $request, UserOperationalAssignment $assignment)
    {
        $actor = $request->user('api');
        if (! $actor || ! $actor->hasPermissionName('user_temporary_assignment')) abort(403);

        $assignment->update([
            'status' => UserOperationalAssignment::STATUS_ENDED,
            'ends_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function displayName($user): ?string
    {
        if (! $user) return null;
        return trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: ($user->username ?? null);
    }

    private function assignmentPayload(?UserOperationalAssignment $assignment): ?array
    {
        if (! $assignment) return null;

        return [
            'id' => $assignment->id,
            'temporary_branch_id' => $assignment->temporary_branch_id,
            'temporary_branch_name' => $assignment->temporary_branch_name_snapshot,
            'temporary_inventory_location_id' => $assignment->temporary_inventory_location_id,
            'temporary_inventory_location_name' => $assignment->temporary_inventory_location_name_snapshot,
            'temporary_cash_drawer_id' => $assignment->temporary_cash_drawer_id,
            'temporary_cash_drawer_name' => $assignment->temporary_cash_drawer_name_snapshot,
            'temporary_warehouse_id' => $assignment->temporary_warehouse_id,
            'temporary_warehouse_name' => $assignment->temporary_warehouse_name_snapshot,
            'assigned_by_user_id' => $assignment->assigned_by_user_id,
            'assigned_by_user_name' => $assignment->assigned_by_user_name_snapshot,
            'starts_at' => optional($assignment->starts_at)->format('Y-m-d H:i:s'),
            'ends_at' => optional($assignment->ends_at)->format('Y-m-d H:i:s'),
            'reason' => $assignment->reason,
            'status' => $assignment->status,
        ];
    }
}
