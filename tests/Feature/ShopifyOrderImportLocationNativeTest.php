<?php

namespace Tests\Feature;

use App\Models\InventoryTransitionState as Mode;
use App\Models\ShopifyMapping;
use App\Models\ShopifyStore;
use App\Services\InventoryService;
use App\Services\Shopify\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-3 — Shopify::importOrderPayload(): for a location_primary channel
 * warehouse, the physical mutation reuses the SAME native Sale engine
 * MS7-B1/MS7-B2-1 already use (LocationAwareSaleStockService), instead of a
 * third parallel stock engine. Legacy stays byte-for-byte on
 * product_warehouse, decremented UNCONDITIONALLY regardless of the mapped
 * Stocky `statut` (Shopify already reserved the stock on its own side) —
 * this native path preserves that exact quirk.
 */
class ShopifyOrderImportLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;
    private int $storeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SHOPIFY-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);

        Schema::create('clients', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('sales', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->time('time')->nullable();
            $t->string('Ref')->nullable();
            $t->boolean('is_pos')->default(0);
            $t->integer('client_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->json('inventory_effect_snapshot')->nullable();
            $t->string('statut')->nullable();
            $t->double('discount')->default(0);
            $t->double('shipping')->default(0);
            $t->double('TaxNet')->default(0);
            $t->double('tax_rate')->default(0);
            $t->double('GrandTotal')->default(0);
            $t->double('paid_amount')->default(0);
            $t->string('payment_statut')->nullable();
            $t->text('notes')->nullable();
            $t->integer('user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('sale_details', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->integer('sale_id');
            $t->integer('sale_unit_id')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->double('price')->default(0);
            $t->double('TaxNet')->nullable();
            $t->string('tax_method')->nullable();
            $t->double('discount')->nullable();
            $t->string('discount_method')->nullable();
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->double('total')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('shopify_stores', function ($t) {
            $t->id();
            $t->string('name', 191);
            $t->string('shop_domain', 191);
            $t->text('access_token')->nullable();
            $t->text('api_secret')->nullable();
            $t->string('api_version', 20)->default('2025-01');
            $t->unsignedBigInteger('location_id')->nullable();
            $t->string('location_name', 191)->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('enable_auto_sync')->default(false);
            $t->string('sync_interval', 32)->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamps(6);
        });

        Schema::create('shopify_mappings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('shopify_store_id');
            $t->string('entity_type', 32);
            $t->unsignedBigInteger('local_id');
            $t->unsignedBigInteger('shopify_id');
            $t->json('extra')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps(6);
        });

        $this->storeId = (int) ShopifyStore::create([
            'name' => 'QA Store', 'shop_domain' => 'qa-store.myshopify.com',
            'warehouse_id' => $this->wh,
        ])->id;
    }

    private function service(): SyncService
    {
        return SyncService::forStore(ShopifyStore::find($this->storeId));
    }

    private function mapProduct(int $productId, int $shopifyProductId): void
    {
        ShopifyMapping::put($this->storeId, ShopifyMapping::TYPE_PRODUCT, $productId, $shopifyProductId);
    }

    private function order(int $shopifyOrderId, int $shopifyProductId, float $qty, array $overrides = []): array
    {
        return array_merge([
            'id' => $shopifyOrderId,
            'order_number' => $shopifyOrderId,
            'name' => '#'.$shopifyOrderId,
            'created_at' => now()->toIso8601String(),
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'total_price' => (string) (10 * $qty),
            'total_tax' => '0',
            'total_discounts' => '0',
            'line_items' => [[
                'product_id' => $shopifyProductId,
                'variant_id' => null,
                'sku' => null,
                'title' => 'Line',
                'quantity' => $qty,
                'price' => '10.00',
            ]],
        ], $overrides);
    }

    private function setLocationPrimary(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    // ---------------------------------------------------------------- native

    public function test_native_simple_order_decreases_exact_location_and_leaves_product_warehouse_untouched(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9001);
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service()->importOrderPayload($this->order(555001, 9001, 5), 1);

        $this->assertSame('imported', $result);
        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty);

        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(999.0, (float) $pw, 'product_warehouse must stay untouched by the native path');

        $saleId = ShopifyMapping::localId($this->storeId, ShopifyMapping::TYPE_ORDER, 555001);
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame($this->loc, (int) $sale->inventory_location_id);
        $this->assertNotNull($sale->inventory_effect_snapshot);
    }

    public function test_native_decrements_unconditionally_regardless_of_fulfillment_status(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9002);
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);

        // Unfulfilled order (maps to Stocky statut "ordered", never "completed").
        $this->service()->importOrderPayload($this->order(555002, 9002, 3, ['fulfillment_status' => null]), 1);

        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(17.0, (float) $qty);
    }

    public function test_webhook_replay_is_idempotent_no_second_sale_or_decrement(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9003);
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);

        $svc = $this->service();
        $r1 = $svc->importOrderPayload($this->order(555003, 9003, 5), 1);
        $r2 = $svc->importOrderPayload($this->order(555003, 9003, 5), 1); // orders/updated replay

        $this->assertSame('imported', $r1);
        $this->assertSame('updated', $r2);

        $saleCount = DB::table('sales')->where('warehouse_id', $this->wh)->count();
        $this->assertSame(1, $saleCount);

        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty); // still just one decrement
    }

    public function test_native_fails_closed_on_insufficient_stock(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9004);
        app(InventoryService::class)->increase($this->loc, $p, 2, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service()->importOrderPayload($this->order(555004, 9004, 5), 1);
    }

    public function test_native_fails_closed_without_default_location(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // Deliberately no default_inventory_location_id.
        $p = $this->makeProduct();
        $this->mapProduct($p, 9005);

        $this->expectException(\RuntimeException::class);
        $this->service()->importOrderPayload($this->order(555005, 9005, 1), 1);
    }

    public function test_native_fails_closed_for_batch_tracked_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $this->mapProduct($p, 9006);

        $this->expectException(\RuntimeException::class);
        $this->service()->importOrderPayload($this->order(555006, 9006, 1), 1);
    }

    public function test_native_fails_closed_for_imei_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_imei' => 1]);
        $this->mapProduct($p, 9007);

        $this->expectException(\RuntimeException::class);
        $this->service()->importOrderPayload($this->order(555007, 9007, 1), 1);
    }

    public function test_native_variant_exact(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        ShopifyMapping::put($this->storeId, ShopifyMapping::TYPE_VARIANT, $v1, 8001);
        ShopifyMapping::put($this->storeId, ShopifyMapping::TYPE_VARIANT, $v2, 8002);
        app(InventoryService::class)->increase($this->loc, $p, 10, $v1, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        app(InventoryService::class)->increase($this->loc, $p, 20, $v2, [
            'reference_type' => 'Seed', 'reference_id' => 2, 'user_id' => null,
        ]);

        $order = $this->order(555008, 0, 4);
        $order['line_items'][0]['variant_id'] = 8001;

        $this->service()->importOrderPayload($order, 1);

        $qtyV1 = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->where('product_variant_id', $v1)->value('quantity');
        $qtyV2 = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->where('product_variant_id', $v2)->value('quantity');
        $this->assertSame(6.0, (float) $qtyV1);
        $this->assertSame(20.0, (float) $qtyV2); // untouched — exact variant only
    }

    // ---------------------------------------------------------------- legacy

    public function test_legacy_import_unchanged_decrements_product_warehouse(): void
    {
        $p = $this->makeProduct();
        $this->mapProduct($p, 9010);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service()->importOrderPayload($this->order(555010, 9010, 5), 1);

        $this->assertSame('imported', $result);
        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(15.0, (float) $pw);

        $saleId = ShopifyMapping::localId($this->storeId, ShopifyMapping::TYPE_ORDER, 555010);
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertNull($sale->inventory_location_id);
    }
}
