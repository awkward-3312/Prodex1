<?php

namespace App\Services;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Transitional adapter for TransferController@approve.
 *
 * The historical approval routine still mutates product_warehouse.qte. A modern
 * transfer, however, leaves its physical source InventoryLocation immediately on
 * dispatch and must not reduce the CD anchor. This bridge translates only that
 * source decrease into InventoryService. Legacy warehouse transfers are untouched.
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
        if (! $transfer || ! $transfer->from_inventory_location_id) return false;

        $quantity = round($legacyOriginal - $legacyTarget, 3);
        if ($quantity <= 0) return false;

        app(InventoryService::class)->decrease(
            (int) $transfer->from_inventory_location_id,
            $productId,
            $quantity,
            $variantId,
            [
                'user_id' => auth()->id(),
                'reference_type' => 'TransferDispatch',
                'reference_id' => (string) $transfer->id,
                'idempotency_key' => 'transfer:dispatch:'.$transfer->id.':product:'.$productId.':variant:'.($variantId ?: 0),
                'notes' => 'Salida física por despacho de transferencia.',
                'metadata' => [
                    'transfer_id' => (int) $transfer->id,
                    'from_inventory_location_id' => (int) $transfer->from_inventory_location_id,
                    'to_inventory_location_id' => $transfer->to_inventory_location_id ? (int) $transfer->to_inventory_location_id : null,
                    'legacy_from_warehouse_id' => $transfer->from_warehouse_id ? (int) $transfer->from_warehouse_id : null,
                ],
            ]
        );

        return true;
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
