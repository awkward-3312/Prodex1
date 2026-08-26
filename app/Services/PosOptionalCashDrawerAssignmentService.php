<?php

namespace App\Services;

use App\Models\User;

/**
 * Keeps physical cash drawers optional for the native POS flow.
 *
 * Legacy warehouse-based flows retain their original behaviour. Only CreatePOS
 * requests that already carry the native branch + inventory-location context
 * are relaxed so they can operate without a physical drawer.
 */
class PosOptionalCashDrawerAssignmentService extends UserOperationalAssignmentService
{
    public function validateRequestedAssignment(User $user, ?int $warehouseId, ?int $cashDrawerId, bool $requireDrawer = true): void
    {
        if ($this->isNativeLocationPosRequest()) {
            $requireDrawer = false;
        }

        parent::validateRequestedAssignment($user, $warehouseId, $cashDrawerId, $requireDrawer);
    }

    public function validateRequestedOperationalAssignment(
        User $user,
        ?int $branchId,
        ?int $locationId,
        ?int $cashDrawerId,
        bool $requireDrawer = true
    ): void {
        if ($this->isNativeLocationPosRequest()) {
            $requireDrawer = false;
        }

        parent::validateRequestedOperationalAssignment(
            $user,
            $branchId,
            $locationId,
            $cashDrawerId,
            $requireDrawer
        );
    }

    private function isNativeLocationPosRequest(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        try {
            $request = request();
            $branchId = (int) $request->input('branch_id', 0);
            $locationId = (int) $request->input('inventory_location_id', 0);

            if ($branchId <= 0 || $locationId <= 0) {
                return false;
            }

            return app(PosLocationStockBridge::class)->isLocationPosRequest($request);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
