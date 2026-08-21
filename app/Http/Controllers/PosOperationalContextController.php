<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationScopeService;
use App\Services\UserOperationalAssignmentService;
use Illuminate\Http\Request;

class PosOperationalContextController extends BaseController
{
    public function show(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);

        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);
        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);
        $effective = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);

        $branches = Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', $branchIds ?: [0])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'default_inventory_location_id']);

        $locations = InventoryLocation::active()
            ->whereNotNull('branch_id')
            ->where('is_sellable', true)
            ->whereIn('branch_id', $branchIds ?: [0])
            ->whereIn('id', $locationIds ?: [0])
            ->orderBy('branch_id')
            ->orderByDesc('is_default_sales')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'code', 'name', 'type', 'is_sellable', 'is_default_sales']);

        $drawers = CashDrawer::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('branch_id')
            ->whereNotNull('inventory_location_id')
            ->whereIn('branch_id', $branchIds ?: [0])
            ->whereIn('inventory_location_id', $locations->pluck('id')->all() ?: [0])
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'inventory_location_id', 'warehouse_id', 'code', 'name']);

        return response()->json([
            'effective' => [
                'source' => $effective['source'],
                'branch_id' => $effective['branch_id'],
                'inventory_location_id' => $effective['inventory_location_id'],
                'cash_drawer_id' => $effective['cash_drawer_id'],
                'legacy_warehouse_id' => $effective['warehouse_id'],
                'can_override' => (bool) $effective['can_override'],
            ],
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $drawers,
            'ready_for_location_pos' => $branches->isNotEmpty() && $locations->isNotEmpty() && $drawers->isNotEmpty(),
        ]);
    }
}
