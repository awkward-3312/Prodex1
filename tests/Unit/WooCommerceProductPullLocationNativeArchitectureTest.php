<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  WOOCOMMERCE PRODUCT PULL STOCK SAFETY — architecture contract (MS7-B2-2D)
 * ============================================================================
 *
 * Pattern/marker based — NEVER line numbers.
 * Pins:
 *   - pullProducts()/pullWooVariationsIntoStocky() resolve
 *     WarehouseInventoryModeResolver::isLocationPrimary() ONCE (per pull
 *     run / per default warehouse), never per-item re-resolution drift.
 *   - a location_primary warehouse's remote Woo stock_quantity is NEVER
 *     written to product_warehouse.qte (simple or variant) — no
 *     InventoryService::adjustTo(), no inventory_location_stocks write,
 *     no batch/serial writer (none exists here to begin with).
 *   - legacy (every OTHER transition mode) keeps the exact pre-existing
 *     absolute-set ($pw->qte = $qtyF) unconditionally.
 *   - qte=0 compatibility provisioning (ensureProductInAllWarehouses /
 *     ensureVariantInAllWarehouses) is untouched and still runs for every
 *     mode — distinguished from the physical quantity write it precedes.
 *   - pullProducts() reports a native_stock_skipped counter (aggregate,
 *     not per-item logging) without changing its existing response shape.
 *   - ProductsPullJob still completes and aggregates that counter.
 *   - B2-2B order import, B2-2B.1 reverse hardening, stock push
 *     (WooCommerceStockSyncJob/WooCommercePushProducts/WooCommerceSyncStock),
 *     Shopify, Store, Subscription, Dashboard/Report, promotion all stay
 *     untouched by this milestone.
 */
class WooCommerceProductPullLocationNativeArchitectureTest extends TestCase
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

    /**
     * §19 — explicit source-of-truth contract, pinned in code (not
     * configuration): for a location_primary warehouse PRODEX is the
     * inventory authority, full stop. There is no config column, setting,
     * or feature flag anywhere (WooCommerceSetting, Setting, warehouses)
     * that lets WooCommerce declare itself the stock authority — that
     * absence is deliberate, not an oversight, and must never be inferred
     * from a Woo-side option. Legacy (every other transition mode) keeps
     * the pre-existing Woo-authoritative pull behaviour completely
     * unchanged.
     */
    public function test_source_of_truth_contract_is_explicit_prodex_authoritative_for_native(): void
    {
        $syncServiceSrc = $this->read('app/Services/WooCommerce/SyncService.php');
        $this->assertStringContainsString('PRODEX is the stock authority', $syncServiceSrc);

        // No inbound stock-authority config field exists anywhere to
        // silently flip this policy.
        foreach ([
            'app/Models/WooCommerceSetting.php',
            'app/Models/Setting.php',
            'app/Models/Warehouse.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            foreach (['sync_stock', 'pull_stock', 'inventory_authority', 'stock_authority'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $src, "{$rel} must not declare a Woo-side stock-authority override — none exists today.");
            }
        }
    }

    public function test_pull_products_resolves_location_primary_once(): void
    {
        $src = $this->extractFunction($this->read('app/Services/WooCommerce/SyncService.php'), 'pullProducts');
        $this->assertSame(1, substr_count($src, 'isLocationPrimary('), 'pullProducts() must resolve the mode once, not per item');
        $this->assertStringContainsString('WarehouseInventoryModeResolver::class)->isLocationPrimary($defaultWarehouseId)', $src);
    }

    public function test_pull_products_gates_simple_stock_write_on_native_mode(): void
    {
        $src = $this->extractFunction($this->read('app/Services/WooCommerce/SyncService.php'), 'pullProducts');
        $this->assertMatchesRegularExpression('/if \(\$isLocationPrimaryWarehouse\) \{.*?\$nativeStockSkipped\+\+;.*?\} else \{.*?\$pw->qte = \$qtyF;/s', $src);
    }

    public function test_pull_woo_variations_gates_variant_stock_write_on_native_mode(): void
    {
        $src = $this->extractFunction($this->read('app/Services/WooCommerce/SyncService.php'), 'pullWooVariationsIntoStocky');
        $this->assertMatchesRegularExpression('/if \(\$isLocationPrimaryWarehouse\) \{.*?\$nativeStockSkipped\+\+;.*?\} else \{.*?\$pw->qte = \$qtyF;/s', $src);
        $this->assertStringContainsString('bool $isLocationPrimaryWarehouse', $src);
        $this->assertStringContainsString('return $nativeStockSkipped;', $src);
    }

    public function test_no_adjust_to_or_inventory_service_mutation_in_product_pull(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $pullProducts = $this->extractFunction($src, 'pullProducts');
        $pullVariations = $this->extractFunction($src, 'pullWooVariationsIntoStocky');
        $this->assertStringNotContainsString('adjustTo', $pullProducts);
        $this->assertStringNotContainsString('adjustTo', $pullVariations);
        $this->assertStringNotContainsString('InventoryService::class', $pullProducts);
        $this->assertStringNotContainsString('InventoryService::class', $pullVariations);
        // "inventory_location_stocks" appears only in explanatory comments
        // here (the whole point of this milestone is that it's NEVER
        // touched by live code) — check live (non-comment) lines only.
        foreach (['pullProducts' => $pullProducts, 'pullWooVariationsIntoStocky' => $pullVariations] as $fn => $body) {
            $liveLines = array_filter(explode("\n", $body), fn ($line) => ! preg_match('/^\s*(\/\/|\*)/', $line));
            $this->assertStringNotContainsString('inventory_location_stocks', implode("\n", $liveLines), "{$fn}() must never touch inventory_location_stocks in live code");
        }
    }

    public function test_no_batch_or_serial_writer_in_product_pull(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $pullProducts = $this->extractFunction($src, 'pullProducts');
        $pullVariations = $this->extractFunction($src, 'pullWooVariationsIntoStocky');
        foreach (['ProductBatch', 'ProductSerial', 'BatchService', 'SerialNumberService'] as $needle) {
            $this->assertStringNotContainsString($needle, $pullProducts);
            $this->assertStringNotContainsString($needle, $pullVariations);
        }
    }

    public function test_legacy_absolute_set_remains_unconditional_for_every_other_mode(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        // The unconditional legacy write ($pw->qte = $qtyF;) must still
        // exist verbatim in BOTH sites, reachable via the else branch.
        $this->assertSame(2, substr_count($src, '$pw->qte = $qtyF;'));
    }

    public function test_provisioning_helpers_are_distinct_from_physical_write_and_untouched(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        $ensureProduct = $this->extractFunction($src, 'ensureProductInAllWarehouses');
        $ensureVariant = $this->extractFunction($src, 'ensureVariantInAllWarehouses');
        $this->assertStringNotContainsString('isLocationPrimaryWarehouse', $ensureProduct, 'provisioning (qte=0) must stay unconditional for every mode');
        $this->assertStringNotContainsString('isLocationPrimaryWarehouse', $ensureVariant);
        $this->assertStringContainsString("'qte' => 0,", $ensureProduct);
        $this->assertStringContainsString("'qte' => 0,", $ensureVariant);
    }

    public function test_native_stock_skipped_reported_without_breaking_response_shape(): void
    {
        $src = $this->extractFunction($this->read('app/Services/WooCommerce/SyncService.php'), 'pullProducts');
        $this->assertStringContainsString("'native_stock_skipped' => \$nativeStockSkipped,", $src);
        // Pre-existing keys must still be present.
        foreach (['created', 'updated', 'errors', 'processed', 'skipped', 'remote_total', 'cursor_page', 'cursor_index', 'done'] as $key) {
            $this->assertStringContainsString("'{$key}'", $src);
        }
    }

    public function test_products_pull_job_aggregates_native_stock_skipped(): void
    {
        $src = $this->read('app/Jobs/WooCommerceProductsPullJob.php');
        $this->assertStringContainsString("native_stock_skipped", $src);
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
            'app/Jobs/WooCommerceStockSyncJob.php',
            'app/Console/Commands/WooCommercePushProducts.php',
            'app/Console/Commands/WooCommerceSyncStock.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            if (! file_exists($path)) {
                continue;
            }
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-2D', $src, "{$rel} must stay untouched by MS7-B2-2D.");
        }
    }

    public function test_woo_order_import_and_reverse_hardening_untouched(): void
    {
        $src = $this->read('app/Services/WooCommerce/SyncService.php');
        // The processWooOrder()/reverseImportedSale() core added/hardened
        // by MS7-B2-2B/B2-2B.1 must still exist unchanged in shape.
        $this->assertStringContainsString('private function processWooOrder(array $order, int $warehouseId, int $userId): array', $src);
        $this->assertStringContainsString('$sale->details()->delete();', $src);
    }
}
