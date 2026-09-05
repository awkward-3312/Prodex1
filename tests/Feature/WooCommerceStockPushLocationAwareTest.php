<?php

namespace Tests\Feature;

use App\Models\InventoryTransitionState as Mode;
use App\Models\Product;
use App\Services\ExternalChannelInventoryService;
use App\Services\InventoryService;
use App\Console\Commands\WooCommercePushProducts;
use App\Console\Commands\WooCommerceSyncStock;
use App\Http\Controllers\WooCommerceSyncController;
use App\Jobs\WooCommerceStockSyncJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-2C — WooCommerce stock READS/PUSH location-aware.
 *
 * All Woo stock-push paths (WooCommerceStockSyncJob, WooCommercePushProducts,
 * WooCommerceSyncStock, WooCommerceSyncController::stockMetrics) publish a
 * SINGLE aggregate number per product, summed across every warehouse — this
 * codebase's Woo integration has never had a per-warehouse concept. This
 * milestone generalizes that sum via
 * ExternalChannelInventoryService::sellableQuantityAcrossWarehouses(): a
 * location_primary warehouse now contributes its exact fulfillment
 * location's available quantity (never its stale product_warehouse row);
 * every other mode still contributes product_warehouse.qte exactly as
 * before. FAILS CLOSED (never a fake zero) on a missing/invalid location or
 * a batch/serial coverage mismatch.
 */
class WooCommerceStockPushLocationAwareTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();
        $this->buildSerialSchema();

        Schema::table('products', function ($t) {
            $t->unsignedBigInteger('woocommerce_id')->nullable();
        });

        Schema::create('woocommerce_settings', function ($t) {
            $t->increments('id');
            $t->string('store_url')->nullable();
            $t->string('consumer_key')->nullable();
            $t->string('consumer_secret')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps();
        });

        Schema::create('product_batches', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('warehouse_id');
            $t->string('batch_no');
            $t->double('qty')->default(0);
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('product_batch_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('product_batch_id');
            $t->integer('inventory_location_id');
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('reserved_quantity', 12, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('combined_products', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('combined_product_id');
            $t->float('quantity');
            $t->timestamps();
        });

        $this->wh = $this->makeWarehouse('WOO-STOCK-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function svc(): ExternalChannelInventoryService
    {
        return app(ExternalChannelInventoryService::class);
    }

    private function setLocationPrimary(int $wh, int $loc): void
    {
        $this->setTransitionMode($wh, Mode::MODE_LOCATION_PRIMARY, $loc, 'healthy');
        DB::table('warehouses')->where('id', $wh)->update(['default_inventory_location_id' => $loc]);
    }

    private function seedLoc(int $productId, float $qty, ?int $variantId = null, ?int $loc = null): void
    {
        app(InventoryService::class)->increase($loc ?? $this->loc, $productId, $qty, $variantId, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
    }

    /** Invoke a private method via Reflection. */
    private function callPrivate(object $obj, string $method, array $args)
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    /** A Command instance with console I/O wired up (so ->warn()/->info() etc. don't crash). */
    private function newCommand(string $class): \Illuminate\Console\Command
    {
        $cmd = new $class();
        $cmd->setLaravel($this->app);
        $output = new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        $cmd->setOutput($output);

        return $cmd;
    }

    // ==================================================================
    // CANONICAL JOB — via ExternalChannelInventoryService (the shared
    // calculator every stock-push path now reuses)
    // ==================================================================

    public function test_legacy_simple_push_uses_qte(): void
    {
        $p = $this->makeProduct();
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 15,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertFalse($result['blocked']);
        $this->assertSame(15.0, $result['quantity']);
    }

    public function test_native_simple_push_uses_exact_location(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $this->seedLoc($p, 10);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertFalse($result['blocked']);
        $this->assertSame(10.0, $result['quantity']);
    }

    public function test_native_reserved_excluded(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $this->seedLoc($p, 10);
        DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->update(['reserved_quantity' => 4]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertSame(6.0, $result['quantity']);
    }

    public function test_product_warehouse_stale_ignored_native(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $this->seedLoc($p, 10);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertSame(10.0, $result['quantity'], 'the stale product_warehouse row must never contribute for a native warehouse');
    }

    public function test_missing_default_location_fails_closed(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // Deliberately no default_inventory_location_id set on the warehouse.
        $p = $this->makeProduct();

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertTrue($result['blocked']);
        $this->assertSame(0.0, $result['quantity']);
    }

    public function test_quarantine_default_location_fails_closed(): void
    {
        $quarantineLoc = $this->makeInventoryLocation($this->wh, ['is_quarantine' => true, 'type' => 'quarantine']);
        $this->setLocationPrimary($this->wh, $quarantineLoc);
        $p = $this->makeProduct();

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);

        $this->assertTrue($result['blocked']);
        $this->assertSame('missing_or_invalid_fulfillment_location', $result['blocked_reason']);
    }

    public function test_no_fake_zero_fallback_on_blocked_stock_sync_job(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        $p = $this->makeProduct();
        DB::table('products')->where('id', $p)->update(['woocommerce_id' => 5001]);

        $job = new WooCommerceStockSyncJob('test-key');
        $result = $this->callPrivate($job, 'computeStockQuantity', [$p]);

        $this->assertTrue($result['blocked'], 'a blocked read must never silently become a published 0');
    }

    // ==================================================================
    // VARIANT
    // ==================================================================

    public function test_exact_variant_a_and_b_no_cross_contamination(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $vA = $this->makeVariant($p, 'A');
        $vB = $this->makeVariant($p, 'B');
        $this->seedLoc($p, 3, $vA);
        $this->seedLoc($p, 9, $vB);

        $resultA = $this->svc()->sellableQuantityAcrossWarehouses($p, $vA, false, false);
        $resultB = $this->svc()->sellableQuantityAcrossWarehouses($p, $vB, false, false);

        $this->assertSame(3.0, $resultA['quantity']);
        $this->assertSame(9.0, $resultB['quantity']);
    }

    public function test_reserved_variant_excluded(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $vA = $this->makeVariant($p, 'A');
        $this->seedLoc($p, 10, $vA);
        DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->where('product_variant_id', $vA)->update(['reserved_quantity' => 2]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, $vA, false, false);

        $this->assertSame(8.0, $result['quantity']);
    }

    // ==================================================================
    // COMBO — WooCommerceStockSyncJob::computeComboStockQuantity() keeps
    // the exact structural formula (min of floor(component/required)); only
    // the per-component read is now native-aware.
    // ==================================================================

    public function test_combo_native_uses_component_native_stock(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $comboId = $this->makeProduct(['type' => 'is_combo']);
        $componentA = $this->makeProduct();
        $componentB = $this->makeProduct();
        DB::table('products')->where('id', $comboId)->update(['woocommerce_id' => 5002]);
        $this->seedLoc($componentA, 10);
        $this->seedLoc($componentB, 21);
        DB::table('combined_products')->insert([
            ['product_id' => $comboId, 'combined_product_id' => $componentA, 'quantity' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $comboId, 'combined_product_id' => $componentB, 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $job = new WooCommerceStockSyncJob('test-key');
        $result = $this->callPrivate($job, 'computeComboStockQuantity', [$comboId]);

        // floor(10/2)=5, floor(21/3)=7 -> min=5
        $this->assertFalse($result['blocked']);
        $this->assertSame(5, $result['quantity']);
    }

    public function test_combo_respects_exact_location(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $otherWh = $this->makeWarehouse('OTHER-WH');
        $otherLoc = $this->makeInventoryLocation($otherWh);

        $comboId = $this->makeProduct(['type' => 'is_combo']);
        $componentA = $this->makeProduct();
        $this->seedLoc($componentA, 10); // at $this->loc
        app(InventoryService::class)->increase($otherLoc, $componentA, 999, null, ['reference_type' => 'Seed', 'reference_id' => 2, 'user_id' => null]); // a DIFFERENT location, must not count
        DB::table('combined_products')->insert([
            'product_id' => $comboId, 'combined_product_id' => $componentA, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $job = new WooCommerceStockSyncJob('test-key');
        $result = $this->callPrivate($job, 'computeComboStockQuantity', [$comboId]);

        // otherWh is legacy (not location_primary) -> contributes its own
        // product_warehouse.qte (0, none seeded) + this->loc's 10 = 10.
        $this->assertSame(10, $result['quantity']);
    }

    public function test_combo_blocked_when_a_component_is_blocked(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // No default_inventory_location_id -> component read blocked.
        $comboId = $this->makeProduct(['type' => 'is_combo']);
        $componentA = $this->makeProduct();
        DB::table('combined_products')->insert([
            'product_id' => $comboId, 'combined_product_id' => $componentA, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $job = new WooCommerceStockSyncJob('test-key');
        $result = $this->callPrivate($job, 'computeComboStockQuantity', [$comboId]);

        $this->assertTrue($result['blocked'], 'an unknown component quantity can never be safely turned into a possible-combos number');
    }

    // ==================================================================
    // BATCH
    // ==================================================================

    public function test_batch_valid_coverage_publishes_native_available(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $this->seedLoc($p, 12);
        $batchId = DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'B1', 'qty' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $batchId, 'inventory_location_id' => $this->loc, 'quantity' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, true, false);

        $this->assertFalse($result['blocked']);
        $this->assertSame(12.0, $result['quantity']);
    }

    public function test_batch_coverage_mismatch_blocked(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $this->seedLoc($p, 12);
        $batchId = DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'B1', 'qty' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Batch slice total (5) disagrees with general (12) -> mismatch.
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $batchId, 'inventory_location_id' => $this->loc, 'quantity' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, true, false);

        $this->assertTrue($result['blocked']);
        $this->assertSame('batch_coverage_mismatch', $result['blocked_reason']);
    }

    // ==================================================================
    // SERIAL
    // ==================================================================

    public function test_serial_valid_coverage_publishes_correct_count(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct(['is_imei' => 1]);
        $this->seedLoc($p, 2);
        DB::table('product_serials')->insert([
            ['serial_number' => 'SN-1', 'product_id' => $p, 'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
            ['serial_number' => 'SN-2', 'product_id' => $p, 'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, true);

        $this->assertFalse($result['blocked']);
        $this->assertSame(2.0, $result['quantity']);
    }

    public function test_serial_mismatch_blocked(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct(['is_imei' => 1]);
        $this->seedLoc($p, 2);
        // Only ONE available serial exists, general says 2 -> mismatch.
        DB::table('product_serials')->insert([
            'serial_number' => 'SN-1', 'product_id' => $p, 'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, true);

        $this->assertTrue($result['blocked']);
        $this->assertSame('serial_coverage_mismatch', $result['blocked_reason']);
    }

    public function test_wrong_location_serial_excluded(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $otherLoc = $this->makeInventoryLocation($this->wh);
        $p = $this->makeProduct(['is_imei' => 1]);
        $this->seedLoc($p, 1);
        DB::table('product_serials')->insert([
            ['serial_number' => 'SN-1', 'product_id' => $p, 'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
            // wrong location — must not count toward THIS location's coverage
            ['serial_number' => 'SN-2', 'product_id' => $p, 'warehouse_id' => $this->wh, 'inventory_location_id' => $otherLoc, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, true);

        $this->assertFalse($result['blocked']);
        $this->assertSame(1.0, $result['quantity']);
    }

    // ==================================================================
    // SECONDARY PATHS
    // ==================================================================

    public function test_woocommerce_push_products_native_safe(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // No default location -> blocked.
        $product = Product::find($this->makeProduct(['name' => 'Native Product', 'code' => 'NATP1']));

        $cmd = $this->newCommand(WooCommercePushProducts::class);
        $payload = $this->callPrivate($cmd, 'buildProductPayload', [$product]);

        $this->assertArrayNotHasKey('manage_stock', $payload);
        $this->assertArrayNotHasKey('stock_quantity', $payload);
        $this->assertSame('Native Product', $payload['name']);
    }

    public function test_woocommerce_sync_stock_native_safe(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        $product = Product::find($this->makeProduct());

        $cmd = $this->newCommand(WooCommerceSyncStock::class);
        $result = $this->callPrivate($cmd, 'computeStockQuantity', [$product]);

        $this->assertTrue($result['blocked']);
    }

    public function test_legacy_behavior_of_secondary_paths_intact(): void
    {
        $product = Product::find($this->makeProduct(['code' => 'LEGP1']));
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id, 'warehouse_id' => $this->wh, 'qte' => 44,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pushCmd = $this->newCommand(WooCommercePushProducts::class);
        $payload = $this->callPrivate($pushCmd, 'buildProductPayload', [$product]);
        $this->assertSame(44, $payload['stock_quantity']);
        $this->assertTrue($payload['manage_stock']);

        $syncCmd = $this->newCommand(WooCommerceSyncStock::class);
        $result = $this->callPrivate($syncCmd, 'computeStockQuantity', [$product]);
        $this->assertFalse($result['blocked']);
        $this->assertSame(44.0, $result['quantity']);
    }

    // ==================================================================
    // METRICS
    // ==================================================================

    private function stockMetrics(): array
    {
        $controller = new WooCommerceSyncController();
        $request = Request::create('/woocommerce/stock-metrics', 'GET');
        $request->setUserResolver(fn () => $this->legacyOwnerUser);
        $response = $controller->stockMetrics($request);

        return json_decode($response->getContent(), true);
    }

    public function test_native_stock_metrics_uses_native_source(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $inStockProduct = $this->makeProduct();
        $this->seedLoc($inStockProduct, 5);
        $outOfStockProduct = $this->makeProduct();
        // InventoryService::increase() rejects a zero quantity, so seed the
        // zero-stock row directly — this is exactly what a real
        // inventory_location_stocks row looks like once stock is fully
        // depleted (quantity reaches 0 through normal decrements).
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->loc, 'product_id' => $outOfStockProduct, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 0, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Stale legacy row that must be ignored for this native warehouse.
        DB::table('product_warehouse')->insert([
            ['product_id' => $inStockProduct, 'warehouse_id' => $this->wh, 'qte' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $outOfStockProduct, 'warehouse_id' => $this->wh, 'qte' => 999, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $metrics = $this->stockMetrics();

        $this->assertSame(1, $metrics['in_stock']);
        $this->assertSame(1, $metrics['out_stock']);
    }

    public function test_legacy_stock_metrics_unchanged(): void
    {
        $inStockProduct = $this->makeProduct();
        $outOfStockProduct = $this->makeProduct();
        DB::table('product_warehouse')->insert([
            ['product_id' => $inStockProduct, 'warehouse_id' => $this->wh, 'qte' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $outOfStockProduct, 'warehouse_id' => $this->wh, 'qte' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $metrics = $this->stockMetrics();

        $this->assertSame(1, $metrics['in_stock']);
        $this->assertSame(1, $metrics['out_stock']);
    }

    // ==================================================================
    // CONSISTENCY WITH B2-2B ORDER FULFILLMENT
    // ==================================================================

    public function test_push_location_equals_fulfillment_location_used_by_order_import(): void
    {
        // Both MS7-B2-2B (order import/reverse) and this milestone's stock
        // push resolve the SAME warehouse through the SAME
        // ExternalChannelInventoryService::resolveFulfillmentLocation() —
        // there is only one resolution path, so they can never diverge.
        $this->setLocationPrimary($this->wh, $this->loc);

        $fromOrderFulfillmentPerspective = $this->svc()->resolveFulfillmentLocation($this->wh);
        $fromStockPushPerspective = $this->svc()->resolveFulfillmentLocation($this->wh);

        $this->assertSame($this->loc, $fromOrderFulfillmentPerspective->id);
        $this->assertSame($fromOrderFulfillmentPerspective->id, $fromStockPushPerspective->id);
    }

    // ==================================================================
    // FAILURE
    // ==================================================================

    public function test_stock_read_never_mutates_local_state(): void
    {
        $this->setLocationPrimary($this->wh, $this->loc);
        $p = $this->makeProduct();
        $this->seedLoc($p, 6);

        $before = DB::table('inventory_location_stocks')->where('product_id', $p)->first();
        $this->svc()->sellableQuantityAcrossWarehouses($p, null, false, false);
        $after = DB::table('inventory_location_stocks')->where('product_id', $p)->first();

        $this->assertEquals($before, $after, 'a stock READ must never mutate inventory_location_stocks');
    }

    public function test_one_product_failure_does_not_affect_another(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // No default location -> blocked for product A.
        $blockedProduct = $this->makeProduct();

        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
        $healthyProduct = $this->makeProduct();
        $this->seedLoc($healthyProduct, 9);

        $resultBlocked = $this->svc()->sellableQuantityAcrossWarehouses($blockedProduct, null, false, false);
        $resultHealthy = $this->svc()->sellableQuantityAcrossWarehouses($healthyProduct, null, false, false);

        $this->assertFalse($resultHealthy['blocked'], 'a blocked read for one product must never affect another');
        $this->assertSame(9.0, $resultHealthy['quantity']);
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
}
