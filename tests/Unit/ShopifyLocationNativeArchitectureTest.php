<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  SHOPIFY LOCATION-NATIVE — architecture contract (MS7-B2-3)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - Shopify::importOrderPayload() reuses ExternalChannelInventoryService
 *     (MS7-B2-1) + LocationAwareSaleStockService (MS7-B1) instead of a third
 *     parallel stock engine.
 *   - no native physical writer uses product_warehouse.
 *   - the fulfillment warehouse/location is always server-resolved
 *     (ShopifyStore.warehouse_id -> Warehouse.default_inventory_location_id),
 *     never trusted from the remote payload.
 *   - batch/serial ambiguity fails closed.
 *   - webhook replay (orders/create + orders/updated both call
 *     importOrderPayload) cannot double-import / double-decrement — guarded
 *     by the existing ShopifyMapping order lookup.
 *   - no absolute remote -> native stock writer exists (no inventory_levels
 *     inbound handler).
 *   - B2-1 Store, B1 Admin Sale/SaleReturn, WooCommerce, Subscription,
 *     Dashboard/Report, promotion all stay untouched by this milestone.
 */
class ShopifyLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_shopify_sync_service_routes_order_import_to_native_engine(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');

        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $src);
        $this->assertStringContainsString('LocationAwareSaleStockService::class', $src);
        $this->assertStringContainsString('__isNative', $src);
    }

    public function test_shopify_sync_service_never_writes_product_warehouse_when_native(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        $this->assertMatchesRegularExpression('/if \(\$__isNative\) \{\s*continue;/s', $src);
    }

    public function test_shopify_sync_service_fails_closed_on_batch_or_serial(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        $this->assertStringContainsString('requires_batch', $src);
        $this->assertStringContainsString('requires_serial', $src);
        $this->assertStringContainsString('physical batch or serial/IMEI assignment', $src);
    }

    public function test_shopify_warehouse_and_location_are_never_trusted_from_remote_payload(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        // The webhook path always passes warehouseId=null, forcing server-side
        // resolution from ShopifyStore.warehouse_id — never $order['...'].
        $this->assertStringContainsString("importOrderPayload(\$payload, \$userId, null)", $src);
        $this->assertStringNotContainsString("resolveFulfillmentLocation(\$order[", $src);
    }

    public function test_shopify_push_inventory_reads_exact_location_for_native(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        $this->assertStringContainsString('function localQuantity', $src);
        $this->assertMatchesRegularExpression('/function localQuantity.*?ExternalChannelInventoryService/s', $src);
    }

    public function test_no_absolute_remote_to_native_stock_writer_exists(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        // No inbound inventory_levels handler / webhook topic — push only.
        $this->assertStringNotContainsString("'inventory_levels/", str_replace(
            "'inventory_levels/set.json'", '', str_replace("'inventory_levels/connect.json'", '', $src)
        ));

        $webhook = $this->read('app/Http/Controllers/ShopifyWebhookController.php');
        $this->assertStringNotContainsString('inventory_level', strtolower($webhook));
    }

    public function test_webhook_replay_guard_is_the_existing_order_mapping_lookup(): void
    {
        $src = $this->read('app/Services/Shopify/SyncService.php');
        $this->assertMatchesRegularExpression('/existingSaleId\s*=\s*ShopifyMapping::localId.*?TYPE_ORDER/s', $src);
    }

    public function test_out_of_scope_surfaces_are_untouched(): void
    {
        foreach ([
            'app/Http/Controllers/SalesController.php',
            'app/Http/Controllers/SalesReturnController.php',
            'app/Http/Controllers/PosController.php',
            'app/Http/Controllers/Api/Store/OnlineOrdersApiController.php',
            'app/Http/Controllers/Api/Store/CheckoutController.php',
            'app/Http/Controllers/StoreFrontController.php',
            'app/Services/WooCommerce/SyncService.php',
            'app/Console/Commands/GenerateSubscriptionInvoices.php',
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/ReportController.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-3', $src, "{$rel} must stay untouched by MS7-B2-3.");
        }
    }
}
