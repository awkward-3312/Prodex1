<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  WOOCOMMERCE STOCK PUSH LOCATION-AWARE — architecture contract (MS7-B2-2C)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - WooCommerceStockSyncJob is the canonical LIVE push path; its
 *     compute*StockQuantity() helpers delegate to
 *     ExternalChannelInventoryService::sellableQuantityAcrossWarehouses()
 *     instead of a raw product_warehouse sum.
 *   - that shared calculator is the ONLY place a location_primary
 *     warehouse's contribution is computed — a location_primary
 *     warehouse's stock read NEVER touches product_warehouse; every other
 *     transition mode still contributes product_warehouse.qte exactly as
 *     before (same aggregate-across-warehouses formula, unchanged).
 *   - WooCommercePushProducts and WooCommerceSyncStock (manual, secondary
 *     paths) reuse the SAME canonical calculator — no duplicated native
 *     logic.
 *   - SyncService::syncStock() remains dead/untouched (still zero callers).
 *   - stockMetrics is native-aware (no raw product_warehouse-only sum).
 *   - variant/serial/batch reads are exact (product+variant+location keyed).
 *   - reserved_quantity is excluded from every native read.
 *   - batch/serial coverage mismatches FAIL CLOSED (blocked=true), never a
 *     fake published zero.
 *   - a missing/invalid fulfillment location FAILS CLOSED, never a silent
 *     0 fallback pushed to the remote API.
 *   - B2-2B order import, B2-2B.1 reverse hardening, B2-2D absolute-set
 *     safety, Store, Shopify, Subscription, Dashboard/Report, promotion all
 *     stay untouched by this milestone.
 */
class WooCommerceStockPushLocationNativeArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Crude same-file function-body extractor (brace counting). */
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

    /** Live (non-comment) lines only — strips explanatory `//`/`*` prose. */
    private function liveLines(string $body): string
    {
        $lines = array_filter(explode("\n", $body), fn ($line) => ! preg_match('/^\s*(\/\/|\*)/', $line));

        return implode("\n", $lines);
    }

    public function test_stock_sync_job_delegates_to_canonical_calculator(): void
    {
        $src = $this->read('app/Jobs/WooCommerceStockSyncJob.php');
        foreach (['computeStockQuantity', 'computeVariantStockQuantity'] as $fn) {
            $body = $this->extractFunction($src, $fn);
            $this->assertStringContainsString('ExternalChannelInventoryService::class)', $body, "{$fn}() must delegate to the canonical calculator");
            $this->assertStringNotContainsString('product_warehouse', $this->liveLines($body), "{$fn}() must not read product_warehouse directly any more");
        }
    }

    public function test_combo_keeps_structural_formula_but_blocks_on_blocked_component(): void
    {
        $body = $this->extractFunction($this->read('app/Jobs/WooCommerceStockSyncJob.php'), 'computeComboStockQuantity');
        $this->assertStringContainsString('floor(', $body, 'the min-of-floor(component/required) formula must be unchanged');
        $this->assertStringContainsString("'blocked' => true", $body);
    }

    public function test_stock_sync_job_never_pushes_on_blocked_read(): void
    {
        $src = $this->read('app/Jobs/WooCommerceStockSyncJob.php');
        // Every call site must check ['blocked'] before building a payload —
        // pinned by requiring the check to appear at least 3 times (simple,
        // variant, combo branches).
        $this->assertGreaterThanOrEqual(3, substr_count($src, "['blocked']"));
    }

    public function test_secondary_paths_reuse_canonical_calculator(): void
    {
        foreach ([
            'app/Console/Commands/WooCommercePushProducts.php',
            'app/Console/Commands/WooCommerceSyncStock.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString('ExternalChannelInventoryService::class)', $src, "{$rel} must reuse the canonical calculator, not its own native logic");
            $this->assertStringNotContainsString('product_warehouse::where', $src, "{$rel} must not keep its own raw product_warehouse sum");
        }
    }

    public function test_sync_service_sync_stock_remains_dead_and_untouched(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringNotContainsString('MS7-B2-2C', $this->extractFunction($src, 'syncStock'));

        // Confirm it's still uncalled anywhere outside its own definition.
        $callers = 0;
        foreach ([
            'app/Http/Controllers/WooCommerceSyncController.php',
            'app/Jobs/WooCommerceStockSyncJob.php',
            'app/Jobs/WooCommerceProductsSyncJob.php',
            'app/Jobs/WooCommerceProductsPullJob.php',
            'app/Console/Commands/WooCommerceSync.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $callers += substr_count(file_get_contents($path), '->syncStock(');
        }
        $this->assertSame(0, $callers, 'SyncService::syncStock() must still have zero external callers');
    }

    public function test_stock_metrics_is_native_aware(): void
    {
        $src = $this->extractFunction($this->read('app/Http/Controllers/WooCommerceSyncController.php'), 'stockMetrics');
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)', $src);
        $this->assertStringContainsString('inventory_location_stocks', $src);
        $this->assertStringContainsString('reserved_quantity', $src);
    }

    public function test_external_channel_service_excludes_reserved_and_fails_closed(): void
    {
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'sellableQuantityAcrossWarehouses');
        // Reserved exclusion for the plain-native branch happens via the
        // shared availableQuantity() helper (quantity - reserved_quantity);
        // batch/serial coverage checks read their own general_quantity.
        $this->assertStringContainsString('$this->availableQuantity(', $body);
        $this->assertStringContainsString("'blocked' => true", $body);
        $this->assertStringContainsString('batch_coverage_mismatch', $body);
        $this->assertStringContainsString('serial_coverage_mismatch', $body);
        $this->assertStringContainsString('missing_or_invalid_fulfillment_location', $body);
    }

    public function test_external_channel_service_native_branch_never_reads_product_warehouse(): void
    {
        $src = $this->read('app/Services/ExternalChannelInventoryService.php');
        $body = $this->extractFunction($src, 'sellableQuantityAcrossWarehouses');
        // The native (location_primary) branch starts right after the
        // legacy `continue;` and the resolveFulfillmentLocation() call —
        // everything from there to the loop's end must never reference
        // product_warehouse.
        $nativeBranchStart = strpos($body, 'resolveFulfillmentLocation($warehouseId)');
        $this->assertNotFalse($nativeBranchStart);
        $nativeBranch = substr($body, $nativeBranchStart);
        $this->assertStringNotContainsString('product_warehouse', $this->liveLines($nativeBranch));
    }

    public function test_legacy_absolute_read_unchanged_in_shared_calculator(): void
    {
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'sellableQuantityAcrossWarehouses');
        $this->assertStringContainsString("DB::table('product_warehouse')", $body);
        $this->assertStringContainsString("->sum('qte')", $body);
    }

    public function test_out_of_scope_surfaces_are_untouched(): void
    {
        foreach ([
            'app/Http/Controllers/SalesController.php',
            'app/Http/Controllers/SalesReturnController.php',
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
            $this->assertStringNotContainsString('MS7-B2-2C', $src, "{$rel} must stay untouched by MS7-B2-2C.");
        }
    }

    public function test_b2_2b_and_b2_2d_writers_still_intact(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString('private function processWooOrder(array $order, int $warehouseId, int $userId): array', $src);
        $this->assertStringContainsString('$sale->details()->delete();', $src);
        $this->assertStringContainsString('isLocationPrimary($defaultWarehouseId)', $src);
        $this->assertStringContainsString("'native_stock_skipped' => \$nativeStockSkipped,", $src);
    }
}
