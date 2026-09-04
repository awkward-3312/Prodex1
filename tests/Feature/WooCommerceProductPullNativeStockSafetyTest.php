<?php

namespace Tests\Feature;

use App\Models\InventoryTransitionState as Mode;
use App\Services\InventoryService;
use App\Services\WooCommerce\Client;
use App\Services\WooCommerce\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-2D — WooCommerce PRODUCT/CATALOG pull (pullProducts() /
 * pullWooVariationsIntoStocky()): PRODEX is the stock authority for a
 * location_primary warehouse. A Woo aggregate `stock_quantity` carries no
 * location distribution, reserved state, batch/serial identity, or
 * provenance, so for that warehouse it is treated as read-only external
 * metadata and NEVER written to product_warehouse.qte /
 * inventory_location_stocks / any batch or serial table — catalog metadata
 * (name, price, mapping, variant structure, ...) still syncs normally
 * either way. Every other transition mode (legacy_only/shadow_compare/
 * dual_write) keeps the exact pre-existing absolute-set behaviour.
 */
class WooCommerceProductPullNativeStockSafetyTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('WOO-CATALOG-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);

        Schema::table('products', function ($t) {
            $t->string('Type_barcode')->nullable();
            $t->boolean('is_variant')->default(0);
            $t->boolean('is_active')->default(1);
            $t->decimal('stock_alert', 12, 3)->default(0);
            $t->string('tax_method')->nullable();
            $t->decimal('TaxNet', 12, 3)->default(0);
            $t->integer('category_id')->nullable();
            $t->integer('brand_id')->nullable();
            $t->string('gtin')->nullable();
            $t->decimal('discount', 12, 3)->default(0);
            $t->string('discount_method')->nullable();
            $t->decimal('wholesale_price', 15, 4)->default(0);
            $t->decimal('weight', 12, 3)->nullable();
            $t->decimal('length', 12, 3)->nullable();
            $t->decimal('width', 12, 3)->nullable();
            $t->decimal('height', 12, 3)->nullable();
            $t->text('note')->nullable();
            $t->string('image')->nullable();
            $t->unsignedBigInteger('woocommerce_id')->nullable();
        });

        Schema::table('product_variants', function ($t) {
            $t->unsignedBigInteger('woocommerce_variation_id')->nullable();
            $t->string('gtin')->nullable();
            $t->decimal('price', 15, 4)->default(0);
            $t->decimal('cost', 15, 4)->default(0);
            $t->string('image')->nullable();
        });

        Schema::create('categories', function ($t) {
            $t->increments('id');
            $t->string('code')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('categories')->insert(['name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('settings')->where('id', 1)->update(['warehouse_id' => $this->wh]);
    }

    private function service(array $products = [], array $variationsByProductId = []): SyncService
    {
        return new SyncService(new FakeWooProductsClient($products, $variationsByProductId));
    }

    private function setLocationPrimary(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    private function wooProduct(int $wooId, array $overrides = []): array
    {
        return array_merge([
            'id' => $wooId,
            'sku' => 'SKU-'.$wooId,
            'name' => 'Woo Product '.$wooId,
            'type' => 'simple',
            'stock_quantity' => 12,
            'regular_price' => '10.00',
        ], $overrides);
    }

    private function pw(int $productId, ?int $variantId = null): ?float
    {
        $q = DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $this->wh);
        $variantId === null ? $q->whereNull('product_variant_id') : $q->where('product_variant_id', $variantId);
        $v = $q->value('qte');

        return $v === null ? null : (float) $v;
    }

    // ==================================================================
    // SIMPLE
    // ==================================================================

    public function test_legacy_pull_remote_qty_updates_product_warehouse_as_before(): void
    {
        $svc = $this->service([$this->wooProduct(1001, ['stock_quantity' => 12])]);
        $result = $svc->pullProducts();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['native_stock_skipped']);
        $productId = (int) DB::table('products')->where('woocommerce_id', 1001)->value('id');
        $this->assertSame(12.0, $this->pw($productId));
    }

    public function test_native_pull_remote_qty_does_not_modify_product_warehouse(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1002, ['stock_quantity' => 12])]);
        $result = $svc->pullProducts();

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['native_stock_skipped']);
        $productId = (int) DB::table('products')->where('woocommerce_id', 1002)->value('id');
        // ensureProductInAllWarehouses() provisions qte=0 — never the remote 12.
        $this->assertSame(0.0, $this->pw($productId));
    }

    public function test_native_pull_does_not_modify_inventory_location_stocks(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1003, ['stock_quantity' => 12])]);
        $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', 1003)->value('id');
        $row = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->first();
        $this->assertNull($row, 'a catalog pull must never create/touch a native inventory_location_stocks row');
    }

    public function test_native_catalog_metadata_still_updates(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1004, ['name' => 'Widget', 'regular_price' => '19.99'])]);
        $svc->pullProducts();

        $p = DB::table('products')->where('woocommerce_id', 1004)->first();
        $this->assertSame('Widget', $p->name);
        $this->assertSame(19.99, (float) $p->price);
    }

    public function test_native_mapping_still_created_and_updated(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1005)]);
        $svc->pullProducts();
        $this->assertSame(1, DB::table('products')->where('woocommerce_id', 1005)->count());

        // Second pull of the SAME woo id must UPDATE, not duplicate.
        $svc2 = $this->service([$this->wooProduct(1005, ['name' => 'Renamed'])]);
        $result2 = $svc2->pullProducts();
        $this->assertSame(1, $result2['updated']);
        $this->assertSame(1, DB::table('products')->where('woocommerce_id', 1005)->count());
    }

    // ==================================================================
    // EXISTING DATA
    // ==================================================================

    public function test_stale_product_warehouse_qte_preserved_native(): void
    {
        $this->setLocationPrimary();
        $productId = $this->makeProduct(['category_id' => 1]);
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => 1006]);
        DB::table('product_warehouse')->insert([
            'product_id' => $productId, 'warehouse_id' => $this->wh, 'qte' => 77,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = $this->service([$this->wooProduct(1006, ['stock_quantity' => 12])]);
        $svc->pullProducts();

        $this->assertSame(77.0, $this->pw($productId), 'a stale compatibility qte must survive a native catalog pull untouched');
    }

    public function test_existing_native_quantity_preserved(): void
    {
        $this->setLocationPrimary();
        $productId = $this->makeProduct(['category_id' => 1]);
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => 1007]);
        app(InventoryService::class)->increase($this->loc, $productId, 8, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);

        $svc = $this->service([$this->wooProduct(1007, ['stock_quantity' => 999])]);
        $svc->pullProducts();

        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->value('quantity');
        $this->assertSame(8.0, (float) $qty, 'existing native quantity must be completely untouched by a remote catalog pull');
    }

    public function test_reserved_quantity_preserved(): void
    {
        $this->setLocationPrimary();
        $productId = $this->makeProduct(['category_id' => 1]);
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => 1008]);
        app(InventoryService::class)->increase($this->loc, $productId, 10, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->loc)->where('product_id', $productId)
            ->update(['reserved_quantity' => 3]);

        $svc = $this->service([$this->wooProduct(1008, ['stock_quantity' => 500])]);
        $svc->pullProducts();

        $reserved = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->value('reserved_quantity');
        $this->assertSame(3.0, (float) $reserved);
    }

    // ==================================================================
    // VARIANT
    // ==================================================================

    public function test_legacy_variation_stock_updates_legacy_qte(): void
    {
        $wooProductId = 1009;
        $svc = $this->service(
            [$this->wooProduct($wooProductId, ['type' => 'variable'])],
            [$wooProductId => [
                ['id' => 9001, 'sku' => 'V-A', 'stock_quantity' => 10, 'attributes' => [['option' => 'A']]],
                ['id' => 9002, 'sku' => 'V-B', 'stock_quantity' => 20, 'attributes' => [['option' => 'B']]],
            ]]
        );
        $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', $wooProductId)->value('id');
        $vA = (int) DB::table('product_variants')->where('woocommerce_variation_id', 9001)->value('id');
        $vB = (int) DB::table('product_variants')->where('woocommerce_variation_id', 9002)->value('id');

        $this->assertSame(10.0, $this->pw($productId, $vA));
        $this->assertSame(20.0, $this->pw($productId, $vB));
    }

    public function test_native_variation_remote_stock_ignored_physically(): void
    {
        $this->setLocationPrimary();
        $wooProductId = 1010;
        $svc = $this->service(
            [$this->wooProduct($wooProductId, ['type' => 'variable'])],
            [$wooProductId => [
                ['id' => 9101, 'sku' => 'NV-A', 'stock_quantity' => 10, 'attributes' => [['option' => 'A']]],
            ]]
        );
        $result = $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', $wooProductId)->value('id');
        $vA = (int) DB::table('product_variants')->where('woocommerce_variation_id', 9101)->value('id');

        $this->assertSame(0.0, $this->pw($productId, $vA), 'variant compatibility row stays at qte=0 provisioning, never the remote 10');
        $this->assertGreaterThanOrEqual(1, $result['native_stock_skipped']);
        $row = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->where('product_variant_id', $vA)->first();
        $this->assertNull($row);
    }

    public function test_variant_a_does_not_affect_variant_b(): void
    {
        $this->setLocationPrimary();
        $wooProductId = 1011;
        $svc = $this->service(
            [$this->wooProduct($wooProductId, ['type' => 'variable'])],
            [$wooProductId => [
                ['id' => 9201, 'sku' => 'X-A', 'stock_quantity' => 10, 'attributes' => [['option' => 'A']]],
                ['id' => 9202, 'sku' => 'X-B', 'stock_quantity' => 20, 'attributes' => [['option' => 'B']]],
            ]]
        );
        $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', $wooProductId)->value('id');
        $vA = (int) DB::table('product_variants')->where('woocommerce_variation_id', 9201)->value('id');
        $vB = (int) DB::table('product_variants')->where('woocommerce_variation_id', 9202)->value('id');

        app(InventoryService::class)->increase($this->loc, $productId, 4, $vA, ['reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null]);
        app(InventoryService::class)->increase($this->loc, $productId, 7, $vB, ['reference_type' => 'Seed', 'reference_id' => 2, 'user_id' => null]);

        // Re-pull with different remote quantities.
        $svc2 = $this->service(
            [$this->wooProduct($wooProductId, ['type' => 'variable'])],
            [$wooProductId => [
                ['id' => 9201, 'sku' => 'X-A', 'stock_quantity' => 999, 'attributes' => [['option' => 'A']]],
                ['id' => 9202, 'sku' => 'X-B', 'stock_quantity' => 888, 'attributes' => [['option' => 'B']]],
            ]]
        );
        $svc2->pullProducts();

        $qtyA = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->where('product_variant_id', $vA)->value('quantity');
        $qtyB = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $productId)->where('product_variant_id', $vB)->value('quantity');
        $this->assertSame(4.0, (float) $qtyA);
        $this->assertSame(7.0, (float) $qtyB);
    }

    public function test_variant_mapping_still_updates(): void
    {
        $this->setLocationPrimary();
        $wooProductId = 1012;
        $svc = $this->service(
            [$this->wooProduct($wooProductId, ['type' => 'variable'])],
            [$wooProductId => [
                ['id' => 9301, 'sku' => 'M-A', 'stock_quantity' => 1, 'attributes' => [['option' => 'Red']]],
            ]]
        );
        $svc->pullProducts();

        $variant = DB::table('product_variants')->where('woocommerce_variation_id', 9301)->first();
        $this->assertNotNull($variant);
        $this->assertSame('Red', $variant->name);
    }

    // ==================================================================
    // TRACKED (batch/serial) — Woo product pull has no batch/serial writer
    // at all; a tracked product simply goes through the SAME gated stock
    // block, so it must behave identically to a plain native product.
    // ==================================================================

    public function test_batch_tracked_product_native_pull_creates_no_artifact_or_general_mutation(): void
    {
        $this->setLocationPrimary();
        $productId = $this->makeProduct(['category_id' => 1, 'is_batch_tracked' => true]);
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => 1013]);

        $svc = $this->service([$this->wooProduct(1013, ['stock_quantity' => 50])]);
        $svc->pullProducts();

        $this->assertSame(0.0, $this->pw($productId));
        $this->assertSame(0, DB::table('inventory_location_stocks')->where('product_id', $productId)->count());
    }

    public function test_imei_serial_product_native_pull_creates_no_serial_or_general_mutation(): void
    {
        $this->setLocationPrimary();
        $productId = $this->makeProduct(['category_id' => 1, 'is_imei' => 1]);
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => 1014]);

        $svc = $this->service([$this->wooProduct(1014, ['stock_quantity' => 50])]);
        $svc->pullProducts();

        $this->assertSame(0.0, $this->pw($productId));
        $this->assertSame(0, DB::table('inventory_location_stocks')->where('product_id', $productId)->count());
    }

    // ==================================================================
    // PROVISIONING
    // ==================================================================

    public function test_qte_zero_compatibility_row_creation_remains_allowed(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1015, ['stock_quantity' => 30])]);
        $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', 1015)->value('id');
        $row = DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $this->wh)->first();
        $this->assertNotNull($row, 'ensureProductInAllWarehouses() compatibility provisioning must still run');
        $this->assertSame(0.0, (float) $row->qte);
    }

    public function test_no_nonzero_remote_quantity_inserted_for_native_new_compatibility_row(): void
    {
        $this->setLocationPrimary();
        $this->assertSame(0, DB::table('products')->where('woocommerce_id', 1016)->count());
        $svc = $this->service([$this->wooProduct(1016, ['stock_quantity' => 42])]);
        $svc->pullProducts();

        $productId = (int) DB::table('products')->where('woocommerce_id', 1016)->value('id');
        $qte = DB::table('product_warehouse')->where('product_id', $productId)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(0.0, (float) $qte, 'a brand-new native product must never get the remote 42 inserted as its qte');
    }

    // ==================================================================
    // ATOMICITY
    // ==================================================================

    public function test_metadata_failure_does_not_partially_mutate_stock(): void
    {
        $this->setLocationPrimary();
        // A missing/invalid name causes pullProducts() to skip the row
        // entirely (before ever reaching the DB transaction) — proving no
        // partial product/stock write can occur for an invalid row.
        $svc = $this->service([
            $this->wooProduct(1017, ['name' => '']), // invalid: skipped
            $this->wooProduct(1018, ['stock_quantity' => 5]), // valid
        ]);
        $result = $svc->pullProducts();

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, DB::table('products')->where('woocommerce_id', 1017)->count());
        $this->assertSame(1, DB::table('products')->where('woocommerce_id', 1018)->count());
    }

    public function test_stock_skip_itself_never_causes_catalog_transaction_corruption(): void
    {
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1019, ['stock_quantity' => 15, 'name' => 'Still Created'])]);
        $result = $svc->pullProducts();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['errors']);
        $p = DB::table('products')->where('woocommerce_id', 1019)->first();
        $this->assertNotNull($p, 'the catalog write must commit even though the stock write was skipped');
        $this->assertSame('Still Created', $p->name);
    }

    // ==================================================================
    // JOB
    // ==================================================================

    public function test_products_pull_job_state_reflects_native_stock_skipped(): void
    {
        // Exercises the SAME aggregation WooCommerceProductsPullJob performs
        // (created/updated/native_stock_skipped accumulation) without the
        // queue/cache machinery itself.
        $this->setLocationPrimary();
        $svc = $this->service([$this->wooProduct(1020, ['stock_quantity' => 9])]);
        $result = $svc->pullProducts();

        $state = ['created' => 0, 'updated' => 0, 'native_stock_skipped' => 0];
        $state['created'] += (int) ($result['created'] ?? 0);
        $state['updated'] += (int) ($result['updated'] ?? 0);
        $state['native_stock_skipped'] += (int) ($result['native_stock_skipped'] ?? 0);

        $this->assertSame(1, $state['created']);
        $this->assertSame(1, $state['native_stock_skipped']);
        $this->assertTrue($result['done']);
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
}

/**
 * Duck-typed Client double: overrides getNoRetry() to serve canned WooCommerce
 * REST payloads from memory, bypassing the real curl transport entirely
 * (Client is not built on Laravel's Http facade, so Http::fake() cannot
 * intercept it).
 */
class FakeWooProductsClient extends Client
{
    /** @param array<int,array> $products @param array<int,array<int,array>> $variationsByProductId */
    public function __construct(private array $products = [], private array $variationsByProductId = [])
    {
        // Deliberately do NOT call parent::__construct(): every method the
        // production code path touches (getNoRetry()) is overridden below.
    }

    public function getNoRetry(string $endpoint, array $query = [], int $timeoutSeconds = 20, int $connectTimeoutSeconds = 5)
    {
        $page = (int) ($query['page'] ?? 1);

        if ($endpoint === 'products') {
            $data = $page === 1 ? $this->products : [];

            return new FakeWooProductsResponse($data);
        }

        if (preg_match('#^products/(\d+)/variations$#', $endpoint, $m)) {
            $wooProductId = (int) $m[1];
            $data = $page === 1 ? ($this->variationsByProductId[$wooProductId] ?? []) : [];

            return new FakeWooProductsResponse($data);
        }

        return new FakeWooProductsResponse([]);
    }
}

class FakeWooProductsResponse
{
    private string $body;

    public function __construct(array $data)
    {
        $this->body = json_encode($data);
    }

    public function successful(): bool
    {
        return true;
    }

    public function status(): int
    {
        return 200;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function json()
    {
        return json_decode($this->body, true);
    }

    public function header(string $name): ?string
    {
        return null;
    }
}
