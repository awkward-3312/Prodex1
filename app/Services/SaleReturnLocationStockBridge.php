<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility bridge for the legacy SalesReturnController.
 *
 * That controller still expresses restocks/reversals by changing
 * product_warehouse.qte. For returns linked to a location-aware POS sale we
 * translate the same delta into InventoryService and leave the CD/warehouse
 * aggregate untouched. Legacy returns continue to behave exactly as before.
 */
class SaleReturnLocationStockBridge
{
    public function redirectLegacyMutation(
        int $productId,
        ?int $variantId,
        float $legacyOriginal,
        float $legacyTarget,
        ?Request $request = null
    ): bool {
        $request = $request ?: request();
        $return = $this->resolveReturn($request);
        if (! $return || ! $return->inventory_location_id) return false;

        $delta = round($legacyTarget - $legacyOriginal, 3);
        if (abs($delta) < 0.0005) return false;

        $meta = [
            'user_id' => auth()->id(),
            'reference_type' => 'SaleReturn',
            'reference_id' => (string) $return->id,
            'notes' => $delta > 0
                ? 'Devolución de venta reingresada a la ubicación original.'
                : 'Reversión de devolución retirada de la ubicación original.',
            'metadata' => [
                'sale_return_id' => (int) $return->id,
                'sale_id' => $return->sale_id ? (int) $return->sale_id : null,
                'branch_id' => $return->branch_id ? (int) $return->branch_id : null,
                'inventory_location_id' => (int) $return->inventory_location_id,
                'cash_drawer_id' => $return->cash_drawer_id ? (int) $return->cash_drawer_id : null,
                'legacy_warehouse_id' => $return->warehouse_id ? (int) $return->warehouse_id : null,
            ],
        ];

        if ($delta > 0) {
            app(InventoryService::class)->increase(
                (int) $return->inventory_location_id,
                $productId,
                $delta,
                $variantId,
                $meta
            );
        } else {
            app(InventoryService::class)->decrease(
                (int) $return->inventory_location_id,
                $productId,
                abs($delta),
                $variantId,
                $meta
            );
        }

        return true;
    }

    public function resolveReturn(?Request $request = null): ?SaleReturn
    {
        $request = $request ?: request();
        if (! $request || ! $this->isSaleReturnMutation($request)) return null;
        if (! Schema::hasColumn('sale_returns', 'inventory_location_id')) return null;

        $route = $request->route();
        if ($route) {
            foreach ((array) $route->parameters() as $parameter) {
                if ($parameter instanceof SaleReturn) {
                    return $this->hydrateContext($parameter);
                }
                if (is_numeric($parameter) && (int) $parameter > 0) {
                    $found = SaleReturn::find((int) $parameter);
                    if ($found) return $this->hydrateContext($found);
                }
            }
        }

        // During store(), the return has already been inserted when the legacy
        // stock row is mutated. Resolve the newly-created row through sale_id.
        if ($request->filled('sale_id')) {
            $found = SaleReturn::where('sale_id', (int) $request->input('sale_id'))
                ->latest('id')
                ->first();
            if ($found) return $this->hydrateContext($found);
        }

        return null;
    }

    private function hydrateContext(SaleReturn $return): SaleReturn
    {
        if ($return->inventory_location_id || ! $return->sale_id) return $return;

        $sale = Sale::find($return->sale_id);
        if (! $sale || ! $sale->inventory_location_id) return $return;

        $return->branch_id = $sale->branch_id;
        $return->inventory_location_id = $sale->inventory_location_id;
        $return->cash_drawer_id = $sale->cash_drawer_id;
        $return->saveQuietly();

        return $return;
    }

    private function isSaleReturnMutation(Request $request): bool
    {
        $route = $request->route();
        $action = $route ? (string) $route->getActionName() : '';

        return str_contains($action, 'SalesReturnController@store')
            || str_contains($action, 'SalesReturnController@update')
            || str_contains($action, 'SalesReturnController@destroy')
            || str_contains($action, 'SalesReturnController::store')
            || str_contains($action, 'SalesReturnController::update')
            || str_contains($action, 'SalesReturnController::destroy');
    }
}
