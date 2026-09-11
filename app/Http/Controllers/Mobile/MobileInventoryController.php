<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mobile\MobileInventoryRequest;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Services\InventoryLocationScopeService;
use App\Services\Mobile\MobileInventoryService;
use App\Services\WarehouseInventoryModeResolver;

class MobileInventoryController extends Controller
{
    public function __invoke(
        MobileInventoryRequest $request,
        MobileInventoryService $inventory,
        InventoryLocationScopeService $locationScope,
        WarehouseInventoryModeResolver $modeResolver
    ) {
        $user = $request->user('api');
        abort_unless($user, 401);
        $this->authorizeForUser($user, 'Sales_pos', Sale::class);

        $locationId = (int) $request->validated('inventory_location_id');

        $location = InventoryLocation::active()
            ->where('is_sellable', true)
            ->find($locationId);

        if (! $location) {
            return $this->error('invalid_location', 422);
        }

        if (! $locationScope->canAccess($user, $locationId)) {
            return $this->error('forbidden_location', 403);
        }

        if (! $this->isLocationReady($location, $modeResolver)) {
            return $this->error(
                'inventory_not_ready',
                409,
                'El inventario moderno todavía no está disponible para esta ubicación en la app móvil.'
            );
        }

        return response()->json([
            'data' => $inventory->inventory(
                $locationId,
                $request->validated('search'),
                $request->validated('category_id'),
                $request->validated('stock_status'),
                $request->validated('page'),
                $request->validated('per_page')
            ),
        ]);
    }

    /**
     * inventory_location_stocks is authoritative for a location only when:
     *
     *   - the location is branch-owned (a modern, branch-native operational
     *     location has no legacy warehouse predecessor to migrate from, so its
     *     stock rows are authoritative by construction, even if currently
     *     empty), or
     *   - the location is warehouse-owned AND that warehouse's Phase 3
     *     transition state is `location_primary` + `healthy` (reconciled).
     *
     * A missing/legacy_only/unreconciled warehouse transition state must never
     * be silently read as "zero stock" here, or Inventory and POS would expose
     * two different stock truths for the same location.
     */
    private function isLocationReady(InventoryLocation $location, WarehouseInventoryModeResolver $modeResolver): bool
    {
        if ($location->branch_id) {
            return true;
        }

        return $location->warehouse_id
            ? $modeResolver->isLocationPrimaryHealthy((int) $location->warehouse_id)
            : false;
    }

    private function error(string $code, int $status, ?string $message = null)
    {
        $error = ['code' => $code];
        if ($message !== null) {
            $error['message'] = $message;
        }

        return response()->json(['error' => $error], $status);
    }
}
