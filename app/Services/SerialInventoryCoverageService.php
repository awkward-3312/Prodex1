<?php

namespace App\Services;

use App\Models\ProductSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MS6-B0 — serial readiness / drift check for a serialized product at ONE
 * inventory location. The invariant a healthy location_primary warehouse must
 * hold for an is_imei product:
 *
 *     inventory_location_stocks.quantity  ==  COUNT(product_serials
 *         WHERE product+variant match AND inventory_location_id = X
 *           AND status = 'available')
 *     AND the general quantity is an INTEGER.
 *
 * `voided` / `sold` / `returned_supplier` / `damaged` / `reserved` serials and
 * serials with a NULL location are all EXCLUDED from the available count.
 *
 * This service only READS. The native serial set operations call it to
 * FAIL CLOSED before touching an artifact.
 */
class SerialInventoryCoverageService
{
    private const EPS = 0.0005;

    /**
     * @return array{
     *   general_quantity: float,
     *   available_serial_count: int,
     *   is_integer: bool,
     *   is_ready: bool
     * }
     */
    public function coverageForLocation(int $locationId, int $productId, ?int $variantId = null): array
    {
        $general = 0.0;
        if (Schema::hasTable('inventory_location_stocks')) {
            $q = DB::table('inventory_location_stocks')
                ->where('inventory_location_id', $locationId)
                ->where('product_id', $productId);
            if (Schema::hasColumn('inventory_location_stocks', 'variant_key')) {
                $q->where('variant_key', (int) ($variantId ?: 0));
            } else {
                $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId);
            }
            $general = round((float) ($q->value('quantity') ?? 0), 3);
        }

        $serialCount = 0;
        if (Schema::hasTable('product_serials') && Schema::hasColumn('product_serials', 'inventory_location_id')) {
            $sq = ProductSerial::query()
                ->where('product_id', $productId)
                ->where('inventory_location_id', $locationId)
                ->where('status', ProductSerial::STATUS_AVAILABLE);
            $variantId === null ? $sq->whereNull('product_variant_id') : $sq->where('product_variant_id', $variantId);
            $serialCount = (int) $sq->count();
        }

        $isInteger = abs($general - round($general)) <= self::EPS;
        $isReady = $isInteger && abs($general - $serialCount) <= self::EPS;

        return [
            'general_quantity' => $general,
            'available_serial_count' => $serialCount,
            'is_integer' => $isInteger,
            'is_ready' => $isReady,
        ];
    }

    /**
     * Legacy serials never migrated to a location: status=available,
     * inventory_location_id IS NULL, for this warehouse. Their presence blocks
     * serial-native readiness — they must NOT be auto-assigned to a default
     * location.
     */
    public function unmigratedLegacySerialCount(int $warehouseId, ?int $productId = null): int
    {
        if (! Schema::hasTable('product_serials') || ! Schema::hasColumn('product_serials', 'inventory_location_id')) {
            return 0;
        }

        $q = ProductSerial::query()
            ->where('warehouse_id', $warehouseId)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->whereNull('inventory_location_id');
        if ($productId !== null) {
            $q->where('product_id', $productId);
        }

        return (int) $q->count();
    }
}
