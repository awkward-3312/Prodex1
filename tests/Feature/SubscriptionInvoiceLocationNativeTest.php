<?php

namespace Tests\Feature;

use App\Models\InventoryTransitionState as Mode;
use App\Models\Subscription;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-4 — Subscription::generateInvoice(): the single, safe, atomic entry
 * point for subscription billing. For a location_primary warehouse it
 * reuses LocationAwareSaleStockService (the SAME engine MS7-B1/B2-1/B2-3
 * use); for legacy it keeps product_warehouse but now guarded against going
 * negative. Idempotent under a locked re-check, atomic across Sale +
 * SaleDetail + stock + payment + billing-state advance.
 */
class SubscriptionInvoiceLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SUB-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);

        Schema::create('clients', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $this->clientId = (int) DB::table('clients')->insertGetId(['name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('sales', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->time('time')->nullable();
            $t->string('Ref')->nullable();
            $t->boolean('is_pos')->default(0);
            $t->integer('subscription_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->json('inventory_effect_snapshot')->nullable();
            $t->string('statut')->nullable();
            $t->string('shipping_status')->nullable();
            $t->double('discount')->default(0);
            $t->double('shipping')->default(0);
            $t->double('GrandTotal')->default(0);
            $t->double('paid_amount')->default(0);
            $t->string('payment_statut')->nullable();
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

        Schema::create('subscriptions', function ($t) {
            $t->increments('id');
            $t->date('date')->nullable();
            $t->integer('user_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->integer('product_id');
            $t->integer('warehouse_id')->nullable();
            $t->string('cycle_type')->nullable();
            $t->integer('total_cycles')->default(1);
            $t->string('billing_cycle')->default('monthly');
            $t->integer('remaining_cycles')->default(1);
            $t->double('price_per_cycle')->default(0);
            $t->double('price_per_unit')->default(0);
            $t->double('quantity')->default(1);
            $t->date('next_billing_date')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function makeSubscription(int $productId, float $qty, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'date' => now()->toDateString(),
            'user_id' => null,
            'client_id' => $this->clientId,
            'product_id' => $productId,
            'warehouse_id' => $this->wh,
            'cycle_type' => 'monthly',
            'total_cycles' => 12,
            'billing_cycle' => 'monthly',
            'remaining_cycles' => 3,
            'price_per_cycle' => 20,
            'price_per_unit' => 20,
            'quantity' => $qty,
            'next_billing_date' => Carbon::today()->toDateString(),
            'status' => 'active',
        ], $overrides));
    }

    private function setLocationPrimary(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    // ---------------------------------------------------------------- native

    public function test_native_simple_billing_decreases_exact_location_and_leaves_product_warehouse_untouched(): void
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
        $sub = $this->makeSubscription($p, 5);

        $sale = $sub->generateInvoice();

        $this->assertNotNull($sale);
        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty);

        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(999.0, (float) $pw);

        $this->assertSame($this->loc, (int) $sale->inventory_location_id);
        $this->assertNotNull($sale->fresh()->inventory_effect_snapshot);
    }

    public function test_native_billing_advances_cycle_and_next_billing_date(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        $sub = $this->makeSubscription($p, 1);

        $sub->generateInvoice();

        $fresh = Subscription::find($sub->id);
        $this->assertSame(2, $fresh->remaining_cycles);
        $this->assertTrue(Carbon::parse($fresh->next_billing_date)->gt(Carbon::today()));
        $this->assertSame('active', $fresh->status);
    }

    public function test_native_fails_closed_on_insufficient_stock_and_rolls_back_everything(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 2, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        $sub = $this->makeSubscription($p, 5);

        try {
            $sub->generateInvoice();
            $this->fail('expected exception');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('payment_sales')->count());
        $fresh = Subscription::find($sub->id);
        $this->assertSame(3, $fresh->remaining_cycles); // unchanged
        $this->assertSame(Carbon::today()->toDateString(), Carbon::parse($fresh->next_billing_date)->toDateString()); // unchanged
    }

    public function test_native_fails_closed_without_default_location(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // Deliberately no default_inventory_location_id.
        $p = $this->makeProduct();
        $sub = $this->makeSubscription($p, 1);

        $this->expectException(\Throwable::class);
        $sub->generateInvoice();
    }

    public function test_native_fails_closed_for_batch_tracked_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $sub = $this->makeSubscription($p, 1);

        $this->expectException(\Throwable::class);
        $sub->generateInvoice();
    }

    public function test_native_fails_closed_for_imei_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_imei' => 1]);
        $sub = $this->makeSubscription($p, 1);

        $this->expectException(\Throwable::class);
        $sub->generateInvoice();
    }

    // ---------------------------------------------------------------- idempotency / loop

    public function test_generate_invoice_is_idempotent_across_two_calls_same_period(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 20, null, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
        $sub = $this->makeSubscription($p, 5);

        $sale1 = $sub->generateInvoice();
        // Second call on the SAME (now-advanced) in-memory model — simulates
        // a double scheduler run picking up the same subscription id twice.
        $sub2 = Subscription::find($sub->id);
        $sale2 = $sub2->generateInvoice();

        $this->assertNotNull($sale1);
        $this->assertNull($sale2); // no-op: no longer due.

        $this->assertSame(1, DB::table('sales')->count());
        $qty = DB::table('inventory_location_stocks')->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(15.0, (float) $qty); // still just one decrement
    }

    public function test_one_failed_subscription_does_not_block_the_next(): void
    {
        $this->setLocationPrimary();
        $pGood1 = $this->makeProduct();
        $pBad = $this->makeProduct(); // insufficient stock
        $pGood2 = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $pGood1, 10, null, ['reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null]);
        app(InventoryService::class)->increase($this->loc, $pBad, 1, null, ['reference_type' => 'Seed', 'reference_id' => 2, 'user_id' => null]);
        app(InventoryService::class)->increase($this->loc, $pGood2, 10, null, ['reference_type' => 'Seed', 'reference_id' => 3, 'user_id' => null]);

        $subA = $this->makeSubscription($pGood1, 2);
        $subB = $this->makeSubscription($pBad, 5); // will fail
        $subC = $this->makeSubscription($pGood2, 3);

        $saleA = $subA->generateInvoice();
        $failed = null;
        try {
            $subB->generateInvoice();
        } catch (\Throwable $e) {
            $failed = $e;
        }
        $saleC = $subC->generateInvoice();

        $this->assertNotNull($saleA);
        $this->assertNotNull($failed);
        $this->assertNotNull($saleC);
        $this->assertSame(2, DB::table('sales')->count()); // A and C only
    }

    // ---------------------------------------------------------------- legacy

    public function test_legacy_billing_unchanged_decrements_product_warehouse(): void
    {
        $p = $this->makeProduct();
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sub = $this->makeSubscription($p, 5);

        $sale = $sub->generateInvoice();

        $this->assertNotNull($sale);
        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(15.0, (float) $pw);
        $this->assertNull($sale->inventory_location_id);
    }

    public function test_legacy_never_goes_negative(): void
    {
        $p = $this->makeProduct();
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sub = $this->makeSubscription($p, 5);

        try {
            $sub->generateInvoice();
            $this->fail('expected exception');
        } catch (\Throwable $e) {
            // expected
        }

        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(2.0, (float) $pw); // never went negative, never touched
        $this->assertSame(0, DB::table('sales')->count());
    }
}
