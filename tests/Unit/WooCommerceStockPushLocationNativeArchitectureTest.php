<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  WOOCOMMERCE STOCK PUSH WAREHOUSE-SCOPE — architecture contract (MS7-B2-2C.1)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - WooCommerceStockSyncJob is the canonical LIVE push path; its
 *     compute*StockQuantity() helpers delegate to
 *     ExternalChannelInventoryService::sellableQuantityForFulfillmentWarehouse()
 *     — a SINGLE canonical warehouse, never an aggregate across every
 *     warehouse in the tenant.
 *   - Woo order import (SyncService::resolveOrderWarehouseId()) and Woo
 *     stock push share the EXACT SAME warehouse-resolution contract
 *     (ExternalChannelInventoryService::resolveCanonicalWarehouseId()) —
 *     there is only one such rule in the codebase.
 *   - the exact fulfillment location is used — no first/random warehouse
 *     fallback, no new schema/migration.
 *   - a location_primary warehouse's stock read NEVER touches
 *     product_warehouse; every other transition mode reads
 *     product_warehouse.qte for THAT SAME single warehouse only (legacy
 *     aggregate-across-warehouses semantics removed, since it was already
 *     the same inconsistency this hardening fixes).
 *   - WooCommercePushProducts and WooCommerceSyncStock (manual, secondary
 *     paths) reuse the SAME canonical calculator — no duplicated logic.
 *   - SyncService::syncStock() was removed entirely by MS7-B2-2E
 *     (confirmed dead, zero callers, across the whole B2 series).
 *   - stockMetrics reads the SAME single canonical warehouse/location, not
 *     an aggregate.
 *   - batch/serial coverage is checked at the canonical location only.
 *   - batch/serial coverage mismatches, and a missing/invalid fulfillment
 *     location, or no resolvable canonical warehouse, all FAIL CLOSED
 *     (blocked=true) — never a fake published zero, never a silent
 *     aggregate fallback.
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

    public function test_stock_sync_job_delegates_to_single_warehouse_calculator(): void
    {
        $src = $this->read('app/Jobs/WooCommerceStockSyncJob.php');
        foreach (['computeStockQuantity', 'computeVariantStockQuantity'] as $fn) {
            $body = $this->extractFunction($src, $fn);
            $this->assertStringContainsString('sellableQuantityForFulfillmentWarehouse(', $body, "{$fn}() must delegate to the single-warehouse calculator");
            $this->assertStringNotContainsString('product_warehouse', $this->liveLines($body), "{$fn}() must not read product_warehouse directly");
        }
    }

    public function test_no_aggregate_across_warehouses_calculator_remains(): void
    {
        // The old per-warehouse-summing method must be gone entirely — it
        // had zero callers left besides the ones this hardening rewrote,
        // and it contradicted the physical Woo fulfillment contract.
        foreach ([
            'app/Services/ExternalChannelInventoryService.php',
            'app/Jobs/WooCommerceStockSyncJob.php',
            'app/Console/Commands/WooCommercePushProducts.php',
            'app/Console/Commands/WooCommerceSyncStock.php',
            'app/Http/Controllers/WooCommerceSyncController.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringNotContainsString('function sellableQuantityAcrossWarehouses', $src, "{$rel} must not define the removed aggregate calculator");
            $this->assertStringNotContainsString('->sellableQuantityAcrossWarehouses(', $src, "{$rel} must not call the removed aggregate calculator");
        }
    }

    public function test_order_import_and_stock_push_share_one_warehouse_resolver(): void
    {
        $externalSvcSrc = $this->read('app/Services/ExternalChannelInventoryService.php');
        $this->assertSame(1, substr_count($externalSvcSrc, 'function resolveCanonicalWarehouseId('), 'there must be exactly ONE canonical warehouse-resolution rule');

        $syncServiceBody = $this->extractFunction($this->read('app/Services/WooCommerce/SyncService.php'), 'resolveOrderWarehouseId');
        $this->assertStringContainsString('resolveCanonicalWarehouseId(', $syncServiceBody, 'order import must delegate to the SAME resolver stock push uses, not a second formula');

        $jobSrc = $this->read('app/Jobs/WooCommerceStockSyncJob.php');
        $pushSrc = $this->read('app/Console/Commands/WooCommercePushProducts.php');
        $syncStockSrc = $this->read('app/Console/Commands/WooCommerceSyncStock.php');
        foreach ([$jobSrc, $pushSrc, $syncStockSrc] as $src) {
            $this->assertStringContainsString('sellableQuantityForFulfillmentWarehouse(', $src);
        }
    }

    public function test_no_first_or_random_warehouse_fallback(): void
    {
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'resolveCanonicalWarehouseId');
        $this->assertStringContainsString('Setting::whereNull(\'deleted_at\')->first()', $body, 'must resolve via the canonical Setting.warehouse_id, not an arbitrary pick');
        $this->assertStringContainsString('->min(\'id\')', $body, 'the ONLY fallback is the lowest-id warehouse, matching the existing, pre-established rule (no new heuristic)');
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

    public function test_sync_service_sync_stock_was_removed_as_dead_code(): void
    {
        // MS7-B2-2E — confirmed dead (zero callers, verified repeatedly
        // across B2-2C/B2-2C.1/B2-2E) and removed entirely, rather than
        // left in place as untouched dead code.
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringNotContainsString('public function syncStock(', $src);

        // Confirm it's still uncalled anywhere (nothing broke by removing it).
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
        $this->assertSame(0, $callers, 'SyncService::syncStock() must have had zero external callers before removal');
    }

    public function test_stock_metrics_reads_single_canonical_warehouse(): void
    {
        $src = $this->extractFunction($this->read('app/Http/Controllers/WooCommerceSyncController.php'), 'stockMetrics');
        $this->assertStringContainsString('resolveCanonicalWarehouseId(', $src, 'stockMetrics must resolve the SAME single warehouse the push uses, not an aggregate');
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)', $src);
        $this->assertStringContainsString('inventory_location_stocks', $src);
        $this->assertStringContainsString('reserved_quantity', $src);
        // Must NOT loop over multiple warehouses building a per-product total.
        $this->assertStringNotContainsString('nativeWarehouseIds', $src, 'must not iterate multiple native warehouses any more');
        $this->assertStringNotContainsString('legacyWarehouseIds', $src, 'must not iterate multiple legacy warehouses any more');
    }

    public function test_external_channel_service_excludes_reserved_and_fails_closed(): void
    {
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'sellableQuantityForFulfillmentWarehouse');
        // Reserved exclusion for the plain-native branch happens via the
        // shared availableQuantity() helper (quantity - reserved_quantity);
        // batch/serial coverage checks read their own general_quantity.
        $this->assertStringContainsString('$this->availableQuantity(', $body);
        $this->assertStringContainsString("'blocked' => true", $body);
        $this->assertStringContainsString('no_canonical_warehouse', $body);
        $this->assertStringContainsString('batch_coverage_mismatch', $body);
        $this->assertStringContainsString('serial_coverage_mismatch', $body);
        $this->assertStringContainsString('missing_or_invalid_fulfillment_location', $body);
    }

    public function test_external_channel_service_native_branch_never_reads_product_warehouse(): void
    {
        $src = $this->read('app/Services/ExternalChannelInventoryService.php');
        $body = $this->extractFunction($src, 'sellableQuantityForFulfillmentWarehouse');
        // The native (location_primary) branch starts right after the
        // legacy early-return and the resolveFulfillmentLocation() call —
        // everything from there to the end of the function must never
        // reference product_warehouse.
        $nativeBranchStart = strpos($body, 'resolveFulfillmentLocation($warehouseId)');
        $this->assertNotFalse($nativeBranchStart);
        $nativeBranch = substr($body, $nativeBranchStart);
        $this->assertStringNotContainsString('product_warehouse', $this->liveLines($nativeBranch));
    }

    public function test_legacy_absolute_read_scoped_to_single_warehouse_only(): void
    {
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'sellableQuantityForFulfillmentWarehouse');
        $this->assertStringContainsString("DB::table('product_warehouse')", $body);
        $this->assertStringContainsString("->sum('qte')", $body);
        // The legacy branch must filter by the resolved warehouse id, not
        // scan every warehouse (no whereIn(...) over a warehouse id list).
        $this->assertStringContainsString("->where('warehouse_id', \$warehouseId)", $body);
        $this->assertStringNotContainsString('whereIn(\'warehouse_id\'', $body);
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
            $this->assertStringNotContainsString('MS7-B2-2C.1', $src, "{$rel} must stay untouched by MS7-B2-2C.1.");
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

    public function test_no_new_migration_introduced(): void
    {
        // resolveCanonicalWarehouseId() must resolve entirely from
        // pre-existing schema (Setting.warehouse_id / warehouses.id) — no
        // new column/table this hardening would need a migration for.
        $body = $this->extractFunction($this->read('app/Services/ExternalChannelInventoryService.php'), 'resolveCanonicalWarehouseId');
        $this->assertStringNotContainsString("DB::table('woocommerce_settings')", $body);
        $this->assertStringContainsString('$settings->warehouse_id', $body);
    }
}
