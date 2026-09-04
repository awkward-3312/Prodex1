<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Store\OnlineOrdersApiController;
use App\Models\InventoryTransitionState as Mode;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-1 — OnlineOrdersApiController::update() (confirm): for a
 * location_primary channel warehouse, the physical mutation reuses the SAME
 * native Sale engine MS7-B1 closed for Admin Sale (LocationAwareSaleStockService)
 * instead of a second, parallel OnlineOrder stock engine. Legacy stays
 * byte-for-byte on product_warehouse.
 */
class OnlineOrderConfirmLocationNativeTest extends TestCase
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

        $this->wh = $this->makeWarehouse('STORE-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);

        Schema::create('clients', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('online_orders', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->time('time')->nullable();
            $t->string('ref')->nullable();
            $t->string('status')->default('pending');
            $t->boolean('has_preorder_items')->default(false);
            $t->integer('client_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('stripe_payment_intent_id')->nullable();
            $t->timestamps();
        });

        Schema::create('online_order_items', function ($t) {
            $t->increments('id');
            $t->integer('order_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('qty', 12, 3)->default(1);
            $t->decimal('price', 12, 2)->default(0);
            $t->decimal('line_total', 12, 2)->default(0);
            $t->double('TaxNet')->nullable();
            $t->double('discount')->nullable();
            $t->string('discount_method')->nullable();
            $t->string('tax_method')->nullable();
            $t->boolean('is_preorder')->default(false);
            $t->timestamps();
        });

        Schema::create('store_settings', function ($t) {
            $t->increments('id');
            $t->integer('default_warehouse_id')->nullable();
            $t->timestamps();
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
            $t->string('shipping_status')->nullable();
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

        Schema::create('payment_sales', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->date('date')->nullable();
            $t->string('Ref')->nullable();
            $t->integer('sale_id')->nullable();
            $t->integer('account_id')->nullable();
            $t->double('montant')->default(0);
            $t->double('change')->default(0);
            $t->integer('payment_method_id')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('payment_methods', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('payment_methods')->insert(['id' => 1, 'name' => 'Credit Card', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function controller(): OnlineOrdersApiController
    {
        return new OnlineOrdersApiController;
    }

    private function makeClient(): int
    {
        return (int) DB::table('clients')->insertGetId(['name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeOrder(int $productId, float $qty, bool $isPreorder = false): int
    {
        $clientId = $this->makeClient();
        $orderId = (int) DB::table('online_orders')->insertGetId([
            'date' => now()->toDateString(), 'time' => now()->toTimeString(),
            'ref' => 'SO-TEST-'.uniqid(), 'status' => 'pending',
            'client_id' => $clientId, 'warehouse_id' => $this->wh, 'total' => 100,
            'payment_method' => 'cod', 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('online_order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'product_variant_id' => null,
            'qty' => $qty, 'price' => 10, 'line_total' => $qty * 10, 'is_preorder' => $isPreorder,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function setLocationPrimary(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    private function confirm(int $orderId): \Illuminate\Http\JsonResponse
    {
        $request = Request::create("/store/orders/{$orderId}", 'PUT', ['status' => 'confirmed']);
        $request->setUserResolver(fn () => auth()->user());

        return $this->controller()->update($request, $orderId);
    }

    // ---------------------------------------------------------------- native

    public function test_native_simple_confirm_decreases_exact_location_and_leaves_product_warehouse_untouched(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = $this->makeOrder($p, 5);
        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertTrue($data['ok'] ?? false, json_encode($data));

        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty);

        // product_warehouse untouched by the native path.
        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(999.0, (float) $pw);

        $sale = DB::table('sales')->where('id', $data['sale_id'])->first();
        $this->assertSame($this->loc, (int) $sale->inventory_location_id);
        $this->assertNotNull($sale->inventory_effect_snapshot);

        $order = DB::table('online_orders')->find($orderId);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_native_confirm_replay_is_idempotent_no_double_decrement(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        $orderId = $this->makeOrder($p, 5);

        $this->confirm($orderId);
        // Second confirm attempt on the same (now-confirmed) order — the
        // controller's own fast-path no-op returns ok:true without
        // re-running any effect (idempotent, not an error).
        $res2 = $this->confirm($orderId);
        $data2 = $res2->getData(true);

        $this->assertTrue($data2['ok'] ?? false, json_encode($data2));
        $this->assertSame('confirmed', $data2['status'] ?? null);

        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty); // still just one decrement
    }

    public function test_native_confirm_blocks_on_insufficient_stock(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 2, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        $orderId = $this->makeOrder($p, 5);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertArrayHasKey('error', $data);
        $order = DB::table('online_orders')->find($orderId);
        $this->assertSame('pending', $order->status);
    }

    public function test_native_confirm_fails_closed_without_default_location(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // Deliberately no default_inventory_location_id on the warehouse.
        $p = $this->makeProduct();
        $orderId = $this->makeOrder($p, 1);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertArrayHasKey('error', $data);
    }

    public function test_native_confirm_fails_closed_for_batch_tracked_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $orderId = $this->makeOrder($p, 1);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertArrayHasKey('error', $data);
    }

    public function test_native_confirm_fails_closed_for_imei_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_imei' => 1]);
        $orderId = $this->makeOrder($p, 1);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertArrayHasKey('error', $data);
    }

    public function test_native_preorder_item_has_no_physical_effect(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $orderId = $this->makeOrder($p, 5, isPreorder: true);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertTrue($data['ok'] ?? false, json_encode($data));
        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertNull($qty); // no stock row was ever created — no effect at all.
    }

    // ---------------------------------------------------------------- legacy

    public function test_legacy_confirm_unchanged_decrements_product_warehouse(): void
    {
        $p = $this->makeProduct();
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = $this->makeOrder($p, 5);

        $res = $this->confirm($orderId);
        $data = $res->getData(true);

        $this->assertTrue($data['ok'] ?? false, json_encode($data));
        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(15.0, (float) $pw);

        $sale = DB::table('sales')->where('id', $data['sale_id'])->first();
        $this->assertNull($sale->inventory_location_id);
    }
}
