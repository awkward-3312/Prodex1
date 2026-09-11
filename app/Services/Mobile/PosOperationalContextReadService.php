<?php

namespace App\Services\Mobile;

use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\BranchScopeService;
use App\Services\InventoryLocationScopeService;
use App\Services\UserOperationalAssignmentService;

class PosOperationalContextReadService
{
    public function forUser(User $user): array
    {
        $branchIds = app(BranchScopeService::class)->allowedBranchIds($user);
        $locationIds = app(InventoryLocationScopeService::class)->allowedLocationIds($user);
        $effective = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);

        $branches = Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', $branchIds ?: [0])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'default_warehouse_id', 'default_inventory_location_id']);

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

        $posEffective = $this->posEffective($effective, $branches, $locations, $drawers);

        return [
            'effective' => [
                'source' => $effective['source'],
                'branch_id' => $posEffective['branch_id'],
                'inventory_location_id' => $posEffective['inventory_location_id'],
                'cash_drawer_id' => $posEffective['cash_drawer_id'],
                'legacy_warehouse_id' => $effective['warehouse_id'],
                'can_override' => (bool) $effective['can_override'],
            ],
            'branches' => $branches,
            'inventory_locations' => $locations,
            'cash_drawers' => $drawers,
            'ready_for_location_pos' => $posEffective['branch_id'] !== null
                && $posEffective['inventory_location_id'] !== null
                && $posEffective['cash_drawer_id'] !== null,
        ];
    }

    private function posEffective(array $effective, $branches, $locations, $drawers): array
    {
        $effectiveBranchId = $effective['branch_id'] && $branches->contains('id', (int) $effective['branch_id'])
            ? (int) $effective['branch_id']
            : null;

        $selectedLocation = null;
        if ($effective['inventory_location_id']) {
            $candidate = $locations->firstWhere('id', (int) $effective['inventory_location_id']);
            if ($candidate && (! $effectiveBranchId || (int) $candidate->branch_id === $effectiveBranchId)) {
                $selectedLocation = $candidate;
                $effectiveBranchId = (int) $candidate->branch_id;
            }
        }

        $branchId = $effectiveBranchId ?: $this->firstBranchWithLocation($branches, $locations);
        if (! $branchId) {
            return [
                'branch_id' => null,
                'inventory_location_id' => null,
                'cash_drawer_id' => null,
            ];
        }

        $branch = $branches->firstWhere('id', $branchId);
        $branchLocations = $locations->where('branch_id', $branchId)->values();

        if (! $selectedLocation) {
            $selectedLocation = $branchLocations->firstWhere('is_default_sales', true);
        }

        if (! $selectedLocation && $branch?->default_inventory_location_id) {
            $selectedLocation = $branchLocations->firstWhere('id', (int) $branch->default_inventory_location_id);
        }

        $selectedLocation = $selectedLocation ?: $branchLocations->first();

        if (! $selectedLocation) {
            return [
                'branch_id' => $branchId,
                'inventory_location_id' => null,
                'cash_drawer_id' => null,
            ];
        }

        $drawer = null;
        if ($effective['cash_drawer_id']) {
            $drawer = $drawers
                ->where('branch_id', $branchId)
                ->where('inventory_location_id', (int) $selectedLocation->id)
                ->firstWhere('id', (int) $effective['cash_drawer_id']);
        }

        $drawer = $drawer ?: $drawers
            ->where('branch_id', $branchId)
            ->where('inventory_location_id', (int) $selectedLocation->id)
            ->first();

        return [
            'branch_id' => $branchId,
            'inventory_location_id' => (int) $selectedLocation->id,
            'cash_drawer_id' => $drawer ? (int) $drawer->id : null,
        ];
    }

    private function firstBranchWithLocation($branches, $locations): ?int
    {
        foreach ($branches as $branch) {
            if ($locations->contains('branch_id', (int) $branch->id)) {
                return (int) $branch->id;
            }
        }

        return null;
    }
}
