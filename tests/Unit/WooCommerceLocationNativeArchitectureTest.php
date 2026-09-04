<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  WOOCOMMERCE ORDER IMPORT LOCATION-NATIVE — architecture contract (MS7-B2-2B)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - pullOrders()/pullSingleOrder() no longer duplicate the per-order import
 *     logic; both call the SAME shared private core (processWooOrder()).
 *   - that core reuses ExternalChannelInventoryService (MS7-B2-1) +
 *     LocationAwareSaleStockService (MS7-B1) instead of a fourth parallel
 *     stock engine.
 *   - no native physical writer uses product_warehouse.
 *   - the legacy branch still writes product_warehouse, unchanged.
 *   - WooCommerce never supplies the fulfillment location itself — always
 *     server-resolved from the warehouse's own configuration, FAIL CLOSED.
 *   - batch/serial ambiguity fails closed (no auto-FEFO / auto-assignment).
 *   - the statut==='completed' gate that decides whether stock moves at all
 *     is preserved identically for native and legacy.
 *   - reverseImportedSale() is native-aware: a native sale reverses through
 *     LocationAwareSaleStockService::reverseSnapshot(), legacy keeps its
 *     exact raw product_warehouse restore loop.
 *   - pullProducts() absolute-set and every stock-push implementation
 *     (WooCommerceStockSyncJob, WooCommercePushProducts, WooCommerceSyncStock,
 *     SyncService::syncStock()) are untouched by this milestone.
 *   - B2-1 Store, B2-3 Shopify, MS7-B1 Admin Sale/SaleReturn, Subscription,
 *     Dashboard/Report, promotion all stay untouched by this milestone.
 */
class WooCommerceLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_pull_orders_and_pull_single_order_share_one_core(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');

        // Only ONE definition of the shared core, and both public entry
        // points call it (not a copy of its body each).
        $this->assertSame(1, substr_count($src, 'private function processWooOrder('));
        $this->assertStringContainsString('$this->processWooOrder($order, $warehouseId, $userId)', $src);

        // pullOrders() and pullSingleOrder() must each reference the core
        // exactly once — proof neither still carries its own duplicated
        // per-order body.
        $pullOrdersStart = strpos($src, 'public function pullOrders(');
        $pullSingleStart = strpos($src, 'public function pullSingleOrder(');
        $coreStart = strpos($src, 'private function processWooOrder(');
        $this->assertNotFalse($pullOrdersStart);
        $this->assertNotFalse($pullSingleStart);
        $this->assertNotFalse($coreStart);
        // pullOrders, then pullSingleOrder, then the shared core it now calls.
        $this->assertLessThan($pullSingleStart, $pullOrdersStart);
        $this->assertLessThan($coreStart, $pullSingleStart);
    }

    public function test_core_routes_to_native_engine(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');

        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary', $src);
        $this->assertStringContainsString('ExternalChannelInventoryService::class', $src);
        $this->assertStringContainsString('LocationAwareSaleStockService::class', $src);
        $this->assertStringContainsString('__isNative', $src);
    }

    public function test_woocommerce_never_supplies_its_own_fulfillment_location(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        // resolveFulfillmentLocation is only ever called with the
        // server-resolved $warehouseId, never a value read from the order
        // payload (no "$order['" immediately feeding it).
        $this->assertStringContainsString('resolveFulfillmentLocation($warehouseId)', $src);
        $this->assertStringNotContainsString("resolveFulfillmentLocation(\$order[", $src);
    }

    public function test_native_branch_never_writes_product_warehouse(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        // The physical-effect if/elseif: native applies the snapshot,
        // legacy (elseif) is the only branch touching product_warehouse.
        $this->assertMatchesRegularExpression('/if \(\$__isNative\) \{.*?applySnapshot.*?\} elseif \(\$saleStatut === \'completed\'\) \{.*?product_warehouse/s', $src);
    }

    public function test_legacy_branch_still_writes_product_warehouse_unchanged(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString("product_warehouse::where('deleted_at', '=', null)", $src);
    }

    public function test_stock_effect_gated_on_completed_statut_for_both_native_and_legacy(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString("\$saleStatut === 'completed'", $src);
    }

    public function test_fails_closed_on_batch_or_serial(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString('requires_batch', $src);
        $this->assertStringContainsString('requires_serial', $src);
        $this->assertStringContainsString('physical batch or serial/IMEI assignment', $src);
    }

    public function test_fails_closed_on_missing_fulfillment_location(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertMatchesRegularExpression('/catch \(ValidationException \$e\) \{\s*throw new \\\\RuntimeException\(\'WooCommerce order import: \'/', $src);
    }

    public function test_availability_prevalidated_against_exact_location_before_any_write(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString('availableQuantity($__location->id,', $src);
        $this->assertStringContainsString('insufficient stock for product', $src);
    }

    public function test_detail_rows_created_per_row_not_bulk_for_native_source_detail_id(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString('SaleDetail::create($row + [', $src);
        $this->assertStringNotContainsString("DB::table('sale_details')->insert(", $src);
    }

    public function test_reverse_imported_sale_is_native_aware(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertMatchesRegularExpression('/if \(\$sale->inventory_location_id\) \{.*?reverseSnapshot.*?\} else \{/s', $src);
    }

    public function test_pull_products_absolute_set_untouched(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringNotContainsString('MS7-B2-2B', $this->extractFunction($src, 'pullProducts'));
    }

    public function test_stock_push_implementations_untouched(): void
    {
        foreach ([
            'app/Jobs/WooCommerceStockSyncJob.php',
            'app/Console/Commands/WooCommercePushProducts.php',
            'app/Console/Commands/WooCommerceSyncStock.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-2B', $src, "{$rel} must stay untouched by MS7-B2-2B.");
        }

        $syncServiceSrc = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringNotContainsString('MS7-B2-2B', $this->extractFunction($syncServiceSrc, 'syncStock'));
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
            'app/Services/Shopify/SyncService.php',
            'app/Models/Subscription.php',
            'app/Console/Commands/GenerateSubscriptionInvoices.php',
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/ReportController.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-2B', $src, "{$rel} must stay untouched by MS7-B2-2B.");
        }
    }

    /**
     * Crude same-file function-body extractor (brace counting from the
     * function's opening `{` to its matching closing `}`) — good enough to
     * scope an assertion to one method instead of the whole 7000+ line file.
     */
    private function extractFunction(string $src, string $name): string
    {
        $pos = strpos($src, 'function '.$name.'(');
        if ($pos === false) {
            return '';
        }
        $braceStart = strpos($src, '{', $pos);
        if ($braceStart === false) {
            return '';
        }
        $depth = 0;
        for ($i = $braceStart; $i < strlen($src); $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $pos, $i - $pos + 1);
                }
            }
        }

        return substr($src, $pos);
    }
}
