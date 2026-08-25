<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Transitional bridge for the POS cutover.
 *
 * Location-aware sales are now pre-applied directly through InventoryService as
 * soon as their Sale header exists. The historical PosController can therefore
 * keep executing its old product_warehouse arithmetic during the transition:
 * reads are projected from the selected InventoryLocation and writes are
 * neutralized so the CD row remains unchanged.
 */
class PosLocationStockBridge
{
    public function isLocationPosRequest(?Request $request = null): bool
    {
        $request = $request ?: request();
        if (! $request || ! $this->isCreatePosAction($request)) return false;

        $branchId = (int) $request->input('branch_id', 0);
        $locationId = (int) $request->input('inventory_location_id', 0);
        if ($branchId > 0 && $locationId > 0) return true;

        $user = $request->user('api') ?: auth()->user();
        if (! $user instanceof User) return false;

        $effective = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);
        $branch = $effective['branch'] ?? null;
        $location = $effective['inventory_location'] ?? null;
        $drawer = $effective['cash_drawer'] ?? null;
        $requestDrawerId = $request->input('cash_drawer_id') ? (int) $request->input('cash_drawer_id') : null;

        return $branch
            && $location
            && $drawer
            && (int) $location->branch_id === (int) $branch->id
            && (bool) $location->is_sellable
            && (int) $drawer->branch_id === (int) $branch->id
            && (int) $drawer->inventory_location_id === (int) $location->id
            && (! $requestDrawerId || (int) $drawer->id === $requestDrawerId);
    }

    public function resolveContext(Request $request, User $user): array
    {
        if (! $this->isLocationPosRequest($request)) {
            throw ValidationException::withMessages([
                'operational_context' => 'La solicitud no está usando el modo POS por sucursal y ubicación.',
            ]);
        }

        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $locationId = $request->filled('inventory_location_id') ? (int) $request->input('inventory_location_id') : null;
        $drawerId = $request->filled('cash_drawer_id') ? (int) $request->input('cash_drawer_id') : null;

        if (! $branchId || ! $locationId) {
            $effective = app(UserOperationalAssignmentService::class)->effectiveAssignment($user);
            $branchId = $effective['branch_id'] ? (int) $effective['branch_id'] : null;
            $locationId = $effective['inventory_location_id'] ? (int) $effective['inventory_location_id'] : null;
            $drawerId = $drawerId ?: ($effective['cash_drawer_id'] ? (int) $effective['cash_drawer_id'] : null);
        }

        app(UserOperationalAssignmentService::class)->validateRequestedOperationalAssignment(
            $user,
            $branchId,
            $locationId,
            $drawerId,
            true
        );

        $location = InventoryLocation::active()->find($locationId);
        if (! $location || ! $location->is_sellable || (int) $location->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación operativa no está habilitada para ventas o no pertenece a la sucursal.',
            ]);
        }

        return [
            'mode' => 'branch_location',
            'warehouse_id' => $this->resolveLegacyWarehouseId($request, (int) $branchId),
            'branch_id' => $branchId,
            'inventory_location_id' => $locationId,
            'cash_drawer_id' => $drawerId,
            'inventory_location' => $location,
        ];
    }

    public function resolveLocation(Request $request, User $user): InventoryLocation
    {
        return $this->resolveContext($request, $user)['inventory_location'];
    }

    public function resolvedOperationalIds(Request $request, User $user): array
    {
        $context = $this->resolveContext($request, $user);
        return [
            'branch_id' => (int) $context['branch_id'],
            'inventory_location_id' => (int) $context['inventory_location_id'],
            'cash_drawer_id' => (int) $context['cash_drawer_id'],
        ];
    }

    /**
     * Project a legacy product_warehouse.qte read from the active branch stock.
     * This is request-scoped and only affects CreatePOS, primarily to make the
     * historical multi-pack preflight compare against the correct physical site.
     */
    public function legacyReadableQuantity(int $productId, ?int $variantId, float $fallback, ?Request $request = null): float
    {
        $request = $request ?: request();
        if (! $request || ! $this->isLocationPosRequest($request)) return $fallback;

        $user = $request->user('api') ?: auth()->user();
        if (! $user instanceof User) return $fallback;

        try {
            $location = $this->resolveLocation($request, $user);
            return app(InventoryService::class)->available((int) $location->id, $productId, $variantId);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    public function assertCartSupported(Request $request): void
    {
        if (! $this->isLocationPosRequest($request)) return;

        $details = collect((array) $request->input('details', []));
        $productIds = $details->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($productIds->isEmpty()) return;

        $hasBatchColumn = Schema::hasColumn('products', 'is_batch_tracked');
        $hasSerialColumn = Schema::hasColumn('products', 'is_imei');
        if (! $hasBatchColumn && ! $hasSerialColumn) return;

        $tracked = Product::whereIn('id', $productIds)
            ->get(array_values(array_filter([
                'id', 'name',
                $hasBatchColumn ? 'is_batch_tracked' : null,
                $hasSerialColumn ? 'is_imei' : null,
            ])));

        $usesBatches = $hasBatchColumn && $tracked->contains(fn ($product) => (bool) $product->is_batch_tracked);
        $usesSerials = $hasSerialColumn && $tracked->contains(fn ($product) => (bool) $product->is_imei);

        if ($usesBatches && (! Schema::hasTable('product_batch_location_stocks') || ! Schema::hasTable('sale_detail_batches'))) {
            throw ValidationException::withMessages([
                'details' => 'El inventario por lote de este tenant todavía no está preparado para ventas por ubicación. Ejecuta la actualización de esquema antes de usar este POS.',
            ]);
        }

        if ($usesSerials && (! Schema::hasColumn('product_serials', 'inventory_location_id')
            || ! Schema::hasColumn('product_serial_movements', 'from_inventory_location_id')
            || ! Schema::hasColumn('product_serial_movements', 'to_inventory_location_id'))) {
            throw ValidationException::withMessages([
                'details' => 'El inventario serializado de este tenant todavía no está preparado para ventas por ubicación. Ejecuta la actualización de esquema antes de usar este POS.',
            ]);
        }
    }

    public function redirectLegacyDecrease(
        int $productId,
        ?int $variantId,
        float $legacyOriginal,
        float $legacyTarget,
        ?Request $request = null
    ): bool {
        $request = $request ?: request();
        if (! $request || ! $this->isLocationPosRequest($request)) return false;

        // The new Sale::created hook already deducted every line atomically from
        // InventoryLocation. Returning true tells product_warehouse to restore its
        // raw CD value and prevents a second deduction.
        if ($request->attributes->get('prodex_location_stock_preapplied') === true) {
            return true;
        }

        if ($legacyTarget >= $legacyOriginal) return false;

        // Fallback retained for transition tests and any legacy code path that
        // reaches a qte mutation without having created the Sale through Eloquent.
        $user = $request->user('api') ?: auth()->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'No se pudo resolver el usuario operativo del POS.']);
        }

        $this->assertCartSupported($request);
        $context = $this->resolveContext($request, $user);
        $location = $context['inventory_location'];
        $quantity = round($legacyOriginal - $legacyTarget, 3);
        if ($quantity <= 0) return false;

        app(InventoryService::class)->decrease(
            (int) $location->id,
            $productId,
            $quantity,
            $variantId,
            [
                'user_id' => $user->id,
                'reference_type' => 'pos_sale_location_bridge',
                'reference_id' => $request->input('sale_uuid') ?: null,
                'notes' => 'Venta POS descontada desde la ubicación operativa durante la transición.',
                'metadata' => [
                    'branch_id' => (int) $context['branch_id'],
                    'inventory_location_id' => (int) $location->id,
                    'cash_drawer_id' => (int) $context['cash_drawer_id'],
                    'legacy_warehouse_id' => $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null,
                    'legacy_original_quantity' => round($legacyOriginal, 3),
                    'legacy_target_quantity' => round($legacyTarget, 3),
                ],
            ]
        );

        return true;
    }

    private function resolveLegacyWarehouseId(Request $request, int $branchId): ?int
    {
        $requested = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        if ($requested) {
            $candidate = Warehouse::whereNull('deleted_at')
                ->whereKey($requested)
                ->where('branch_id', $branchId)
                ->first();
            if ($candidate) return (int) $candidate->id;
        }

        $branch = Branch::whereNull('deleted_at')->find($branchId);
        if ($branch && $branch->default_warehouse_id) {
            $default = Warehouse::whereNull('deleted_at')
                ->whereKey($branch->default_warehouse_id)
                ->where('branch_id', $branchId)
                ->first();
            if ($default) return (int) $default->id;
        }

        $fallback = Warehouse::whereNull('deleted_at')
            ->where('branch_id', $branchId)
            ->orderBy('id')
            ->first();

        return $fallback ? (int) $fallback->id : null;
    }

    private function isCreatePosAction(Request $request): bool
    {
        $route = $request->route();
        if (! $route) return false;

        // Real Laravel controller routes expose getActionName(); lightweight route
        // fixtures and some cached-route states may only carry the original `uses`
        // action. Accept either representation but still require the exact POS action.
        $candidates = [];
        if (method_exists($route, 'getActionName')) {
            $candidates[] = (string) $route->getActionName();
        }
        if (method_exists($route, 'getAction')) {
            $uses = $route->getAction('uses');
            if (is_string($uses)) $candidates[] = $uses;
            $controller = $route->getAction('controller');
            if (is_string($controller)) $candidates[] = $controller;
        }

        foreach (array_unique($candidates) as $action) {
            if (str_contains($action, 'PosController@CreatePOS')
                || str_contains($action, 'PosController::CreatePOS')) {
                return true;
            }
        }

        return false;
    }
}
