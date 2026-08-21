<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Transitional bridge for the POS cutover.
 *
 * The historical POS still performs its arithmetic against product_warehouse.
 * When an explicitly branch/location-scoped POS request is being processed, this
 * service lets the product_warehouse model redirect the calculated delta to the
 * new InventoryService without changing the legacy quantity row.
 *
 * This is intentionally narrow: only PosController@CreatePOS requests that carry
 * both branch_id and inventory_location_id are eligible. Batch/serial products
 * remain blocked in this bridge stage because their physical ledgers require the
 * dedicated location-aware sale workflow before they can safely be sold.
 */
class PosLocationStockBridge
{
    public function isLocationPosRequest(?Request $request = null): bool
    {
        $request = $request ?: request();
        if (! $request) return false;

        $branchId = (int) $request->input('branch_id', 0);
        $locationId = (int) $request->input('inventory_location_id', 0);
        if ($branchId <= 0 || $locationId <= 0) return false;

        $route = $request->route();
        $action = $route ? (string) $route->getActionName() : '';

        return str_contains($action, 'PosController@CreatePOS')
            || str_contains($action, 'PosController::CreatePOS');
    }

    public function resolveLocation(Request $request, User $user): InventoryLocation
    {
        if (! $this->isLocationPosRequest($request)) {
            throw ValidationException::withMessages([
                'operational_context' => 'La solicitud no está usando el modo POS por sucursal y ubicación.',
            ]);
        }

        $context = app(PosOperationalContextService::class)->resolve(
            $user,
            $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null,
            (int) $request->input('branch_id'),
            (int) $request->input('inventory_location_id'),
            $request->input('cash_drawer_id') ? (int) $request->input('cash_drawer_id') : null
        );

        $location = $context['inventory_location'] ?? null;
        if (! $location || ! $location->is_sellable) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'La ubicación operativa no está habilitada para ventas.',
            ]);
        }

        return $location;
    }

    public function assertCartSupported(Request $request): void
    {
        if (! $this->isLocationPosRequest($request)) return;

        $details = collect((array) $request->input('details', []));
        $productIds = $details->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($productIds->isEmpty()) return;

        $unsupported = Product::whereIn('id', $productIds)
            ->where(function ($query) {
                $query->where('is_batch_tracked', true)
                    ->orWhere('is_imei', true);
            })
            ->get(['id', 'name', 'is_batch_tracked', 'is_imei']);

        if ($unsupported->isNotEmpty()) {
            $names = $unsupported->pluck('name')->filter()->take(5)->implode(', ');
            throw ValidationException::withMessages([
                'details' => 'El POS por ubicación todavía no puede procesar productos con lote o serie/IMEI en esta etapa de transición'.($names ? ": {$names}." : '.'),
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
        if ($legacyTarget >= $legacyOriginal) return false;

        $user = $request->user('api') ?: auth()->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => 'No se pudo resolver el usuario operativo del POS.']);
        }

        $this->assertCartSupported($request);
        $location = $this->resolveLocation($request, $user);
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
                    'branch_id' => (int) $request->input('branch_id'),
                    'inventory_location_id' => (int) $location->id,
                    'legacy_warehouse_id' => $request->input('warehouse_id') ? (int) $request->input('warehouse_id') : null,
                    'legacy_original_quantity' => round($legacyOriginal, 3),
                    'legacy_target_quantity' => round($legacyTarget, 3),
                ],
            ]
        );

        return true;
    }
}
