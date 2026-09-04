<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  ONLINE STORE LOCATION-NATIVE — architecture contract (MS7-B2-1)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - OnlineOrdersApiController::update() (confirm) reuses the SAME native
 *     Sale engine MS7-B1 closed (LocationAwareSaleStockService) instead of a
 *     second parallel stock engine.
 *   - it never writes product_warehouse for a native document.
 *   - batch/serial ambiguity fails closed (no auto-FEFO, no first-available-
 *     serial guess) for the online channel.
 *   - the client never supplies inventory_location_id (§28) — it is always
 *     server-resolved from the warehouse via ExternalChannelInventoryService.
 *   - CheckoutController / StoreFrontController read the exact fulfillment
 *     location (never product_warehouse, never a whole-warehouse aggregate)
 *     for a location_primary channel.
 *   - MS7-B1 / POS / Purchase / Transfer remain untouched; Dashboard/Report/
 *     WooCommerce/Shopify/Subscription/promotion stay out of scope.
 */
class OnlineStoreLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_online_orders_controller_routes_confirm_to_native_engine(): void
    {
        $src = $this->read('app/Http/Controllers/Api/Store/OnlineOrdersApiController.php');

        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('LocationAwareSaleStockService::class', $src);
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $src);
        $this->assertStringContainsString('__isNative', $src);
    }

    public function test_online_orders_controller_never_writes_product_warehouse_when_native(): void
    {
        $src = $this->read('app/Http/Controllers/Api/Store/OnlineOrdersApiController.php');
        $this->assertMatchesRegularExpression('/is_preorder.{0,20}\|\|.{0,20}__isNative.{0,40}continue/s', $src);
    }

    public function test_online_orders_controller_fails_closed_on_batch_or_serial(): void
    {
        $src = $this->read('app/Http/Controllers/Api/Store/OnlineOrdersApiController.php');
        $this->assertStringContainsString('requires_batch', $src);
        $this->assertStringContainsString('requires_serial', $src);
        $this->assertStringContainsString('asignación física de lote o serie', $src);
    }

    public function test_online_orders_controller_has_idempotency_lock(): void
    {
        $src = $this->read('app/Http/Controllers/Api/Store/OnlineOrdersApiController.php');
        $this->assertStringContainsString('lockForUpdate()->firstOrFail()', $src);
        $this->assertStringContainsString('already processed', $src);
    }

    public function test_client_never_supplies_inventory_location_id(): void
    {
        foreach ([
            'app/Http/Controllers/Api/Store/OnlineOrdersApiController.php',
            'app/Http/Controllers/Api/Store/CheckoutController.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringNotContainsString("input('inventory_location_id')", $src);
            $this->assertStringNotContainsString("'inventory_location_id' => \$request", $src);
            $this->assertStringNotContainsString("'inventory_location_id' => \$data", $src);
        }
    }

    public function test_external_channel_inventory_service_fails_closed_never_guesses(): void
    {
        $src = $this->read('app/Services/ExternalChannelInventoryService.php');
        $this->assertStringContainsString('function resolveFulfillmentLocation', $src);
        $this->assertStringContainsString('is_quarantine', $src);
        $this->assertStringContainsString('ValidationException', $src);
        // Never picks "the first" or "any" location — only the warehouse's
        // own configured default.
        $this->assertStringNotContainsString('->first()', str_replace(
            "InventoryLocation::whereNull('deleted_at')\n            ->whereKey(\$defaultId)\n            ->where('warehouse_id', \$warehouseId)\n            ->where('is_active', 1)\n            ->first();",
            '',
            $src
        ));
    }

    public function test_checkout_and_storefront_use_exact_location_read_for_native(): void
    {
        $checkout = $this->read('app/Http/Controllers/Api/Store/CheckoutController.php');
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $checkout);
        $this->assertStringContainsString('inventory_location_stocks', $checkout);

        $storefront = $this->read('app/Http/Controllers/StoreFrontController.php');
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $storefront);
        $this->assertStringContainsString('function readChannelStockMap', $storefront);
    }

    public function test_ms7_b1_pos_purchase_transfer_untouched_by_this_milestone(): void
    {
        foreach ([
            'app/Http/Controllers/SalesController.php',
            'app/Http/Controllers/SalesReturnController.php',
            'app/Http/Controllers/PosController.php',
            'app/Services/PosLocationSaleStockService.php',
            'app/Http/Controllers/PurchasesController.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2', $src, "{$rel} must stay untouched by MS7-B2-1.");
        }
    }

    public function test_out_of_scope_surfaces_are_untouched(): void
    {
        foreach ([
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/ReportController.php',
            'app/Services/WooCommerce/SyncService.php',
            'app/Services/Shopify/SyncService.php',
            'app/Console/Commands/GenerateSubscriptionInvoices.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-1', $src, "{$rel} is a later sub-milestone (B2-2/B2-3/B2-4), not this one.");
        }
    }
}
