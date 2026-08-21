<?php

namespace App\Services;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Suppresses the historical product_warehouse debit during approval of a modern
 * location transfer. TransferLocationDispatchService performs the authoritative
 * debit after the Transfer row is marked approved, even when the product has no
 * legacy CD row at all.
 */
class TransferLocationStockBridge
{
    public function redirectLegacyDecrease(
        int $productId,
        ?int $variantId,
        float $legacyOriginal,
        float $legacyTarget,
        ?Request $request = null
    ): bool {
        $request = $request ?: request();
        if (! $request || $legacyTarget >= $legacyOriginal) return false;
        if (! $this->isTransferApproval($request)) return false;
        if (! Schema::hasColumn('transfers', 'from_inventory_location_id')) return false;

        $transfer = $this->transferFromRoute($request);
        return (bool) ($transfer && $transfer->from_inventory_location_id);
    }

    private function transferFromRoute(Request $request): ?Transfer
    {
        $route = $request->route();
        if (! $route) return null;

        foreach ((array) $route->parameters() as $parameter) {
            if ($parameter instanceof Transfer) return $parameter;
            if (is_numeric($parameter) && (int) $parameter > 0) {
                $transfer = Transfer::find((int) $parameter);
                if ($transfer) return $transfer;
            }
        }

        return null;
    }

    private function isTransferApproval(Request $request): bool
    {
        $route = $request->route();
        $action = $route ? (string) $route->getActionName() : '';

        return str_contains($action, 'TransferController@approve')
            || str_contains($action, 'TransferController::approve');
    }
}
