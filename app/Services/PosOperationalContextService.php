<?php

namespace App\Services;

use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class PosOperationalContextService
{
    public function resolve(
        User $user,
        ?int $warehouseId,
        ?int $branchId,
        ?int $inventoryLocationId,
        ?int $cashDrawerId
    ): array {
        $assignments = app(UserOperationalAssignmentService::class);

        // New POS contract: branch + inventory location are the operational
        // address. warehouse_id may still be sent as a compatibility pointer.
        if ($branchId || $inventoryLocationId) {
            if (! $branchId || ! $inventoryLocationId) {
                throw ValidationException::withMessages([
                    'operational_context' => 'La venta debe indicar tanto la sucursal como la ubicación de inventario.',
                ]);
            }

            $assignments->validateRequestedOperationalAssignment(
                $user,
                $branchId,
                $inventoryLocationId,
                $cashDrawerId,
                true
            );

            $location = InventoryLocation::active()->findOrFail($inventoryLocationId);
            if (! $location->is_sellable) {
                throw ValidationException::withMessages([
                    'inventory_location_id' => 'La ubicación seleccionada no está habilitada para venta.',
                ]);
            }

            $drawer = CashDrawer::whereNull('deleted_at')->where('is_active', true)->findOrFail($cashDrawerId);

            // A legacy warehouse pointer is accepted only if it still exists. It
            // must never redefine the branch/location ownership of the sale.
            $legacyWarehouse = $warehouseId
                ? Warehouse::whereNull('deleted_at')->find($warehouseId)
                : null;

            return [
                'mode' => 'branch_location',
                'warehouse_id' => $legacyWarehouse?->id ? (int) $legacyWarehouse->id : null,
                'branch_id' => $branchId,
                'inventory_location_id' => $inventoryLocationId,
                'cash_drawer_id' => (int) $drawer->id,
                'inventory_location' => $location,
                'cash_drawer' => $drawer,
            ];
        }

        // Compatibility path for the existing POS frontend. The sale still
        // validates against warehouse/cash drawer exactly as before, but we also
        // snapshot any branch/location context already configured for the user.
        if (! $warehouseId) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Seleccione el almacén operativo mientras este POS usa el modo de compatibilidad.',
            ]);
        }

        $assignments->validateRequestedAssignment($user, $warehouseId, $cashDrawerId, true);
        $effective = $assignments->effectiveAssignment($user);

        return [
            'mode' => 'legacy_warehouse',
            'warehouse_id' => $warehouseId,
            'branch_id' => $effective['branch_id'] ? (int) $effective['branch_id'] : null,
            'inventory_location_id' => $effective['inventory_location_id'] ? (int) $effective['inventory_location_id'] : null,
            'cash_drawer_id' => $cashDrawerId,
            'inventory_location' => $effective['inventory_location'] ?? null,
            'cash_drawer' => $effective['cash_drawer'] ?? null,
        ];
    }
}
