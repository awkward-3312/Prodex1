<?php

namespace Tests\Feature;

use App\Models\InventoryTransitionState as Mode;
use App\Services\InventoryService;
use App\Services\LocationAwareSaleStockService;
use App\Services\WooCommerce\Client;
use App\Services\WooCommerce\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-2B — WooCommerce order import (pullOrders/pullSingleOrder, now a
 * single shared processWooOrder() core): for a location_primary channel
 * warehouse, the physical mutation reuses the SAME native Sale engine
 * MS7-B1/B2-1/B2-3/B2-4 already use (ExternalChannelInventoryService +
 * LocationAwareSaleStockService), instead of a fourth parallel stock engine.
 * Legacy stays byte-for-byte on product_warehouse, decrement gated on
 * statut === 'completed' exactly as before.
 *
 * processWooOrder() is exercised directly via Reflection — it takes an
 * already-fetched order array, so no WooCommerce HTTP client mocking is
 * needed (pullOrders()/pullSingleOrder() only add the HTTP fetch on top).
 */
class WooCommerceOrderImportLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // Never let composeSaleNotesFromWooOrder attempt a real HTTP call.
        putenv('WOO_PULL_ORDER_NOTES_MAX=0');

        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $user = $this->legacyOwner();
        $this->userId = (int) $user->id;

        $this->wh = $this->makeWarehouse('WOO-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);

        Schema::table('products', function ($t) {
            $t->unsignedBigInteger('woocommerce_id')->nullable();
        });
        Schema::table('product_variants', function ($t) {
            $t->unsignedBigInteger('woocommerce_variation_id')->nullable();
        });

        Schema::create('clients', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('code')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('adresse')->nullable();
            $t->string('city')->nullable();
            $t->string('state')->nullable();
            $t->string('zip')->nullable();
            $t->string('country')->nullable();
            $t->unsignedBigInteger('woocommerce_id')->nullable();
            $t->string('sync_issue_type')->nullable();
            $t->string('sync_issue_message')->nullable();
            $t->string('sync_issue_source')->nullable();
            $t->timestamp('sync_issue_at')->nullable();
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
            $t->string('discount_Method')->nullable();
            $t->double('shipping')->default(0);
            $t->double('TaxNet')->default(0);
            $t->double('tax_rate')->default(0);
            $t->double('GrandTotal')->default(0);
            $t->double('paid_amount')->default(0);
            $t->string('payment_statut')->nullable();
            $t->text('notes')->nullable();
            $t->integer('user_id')->nullable();
            $t->unsignedBigInteger('woocommerce_order_id')->nullable();
            $t->string('woocommerce_order_number')->nullable();
            $t->string('woocommerce_order_status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        // MS7-B2-2B.1 — sale_details deliberately has NO deleted_at column
        // here, matching the real production schema exactly (confirmed via
        // migration audit: sale_details has never had one, and SaleDetail
        // has no SoftDeletes trait). This is what exposes the reversal bug
        // this test file's REVERSE section characterizes/guards against.
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
            $t->string('price_type')->nullable();
            $t->timestamps();
        });

        Schema::create('payment_methods', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('payment_sales', function ($t) {
            $t->increments('id');
            $t->integer('sale_id')->nullable();
            $t->date('date')->nullable();
            $t->double('montant')->default(0);
            $t->string('Ref')->nullable();
            $t->double('change')->default(0);
            $t->integer('payment_method_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->text('notes')->nullable();
            $t->integer('account_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::table('payment_methods')->insert(['name' => 'Cash', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function service(): SyncService
    {
        return new SyncService(new Client('https://qa-store.test', 'ck_test', 'cs_test'));
    }

    /** Call the private processWooOrder() core directly. */
    private function process(SyncService $svc, array $order, ?int $warehouseId = null): array
    {
        $m = new ReflectionMethod(SyncService::class, 'processWooOrder');
        $m->setAccessible(true);

        return $m->invoke($svc, $order, $warehouseId ?? $this->wh, $this->userId);
    }

    private function mapProduct(int $productId, int $wooProductId): void
    {
        DB::table('products')->where('id', $productId)->update(['woocommerce_id' => $wooProductId]);
    }

    private function mapVariant(int $variantId, int $wooVariationId): void
    {
        DB::table('product_variants')->where('id', $variantId)->update(['woocommerce_variation_id' => $wooVariationId]);
    }

    private function order(int $wooOrderId, int $wooProductId, float $qty, array $overrides = []): array
    {
        return array_merge([
            'id' => $wooOrderId,
            'number' => (string) $wooOrderId,
            'status' => 'completed',
            'date_created' => now()->toIso8601String(),
            'total' => (string) (10 * $qty),
            'total_tax' => '0',
            'shipping_total' => '0',
            'discount_total' => '0',
            'customer_id' => 0,
            'billing' => [
                'first_name' => 'Jane', 'last_name' => 'Doe',
                'email' => 'woo-'.$wooOrderId.'@example.test',
                'phone' => '', 'address_1' => '', 'address_2' => '',
                'city' => '', 'state' => '', 'postcode' => '', 'country' => '',
            ],
            'line_items' => [[
                'product_id' => $wooProductId,
                'variation_id' => 0,
                'sku' => '',
                'quantity' => $qty,
                'price' => '10.00',
                'subtotal' => (string) (10 * $qty),
                'total' => (string) (10 * $qty),
            ]],
        ], $overrides);
    }

    private function setLocationPrimary(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    private function seedLoc(int $productId, float $qty, ?int $variantId = null): void
    {
        app(InventoryService::class)->increase($this->loc, $productId, $qty, $variantId, [
            'reference_type' => 'Seed', 'reference_id' => 1, 'user_id' => null,
        ]);
    }

    // ==================================================================
    // IMPORT
    // ==================================================================

    public function test_native_simple_order_decreases_exact_location_and_leaves_product_warehouse_untouched(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9001);
        $this->seedLoc($p, 20);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->process($this->service(), $this->order(700001, 9001, 5));

        $this->assertSame('imported', $result['action']);
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $pw = DB::table('product_warehouse')->where('product_id', $p)->where('warehouse_id', $this->wh)->value('qte');
        $this->assertSame(999.0, (float) $pw, 'product_warehouse must stay untouched by the native path');

        $sale = DB::table('sales')->where('id', $result['sale_id'])->first();
        $this->assertSame($this->loc, (int) $sale->inventory_location_id);
        $this->assertNotNull($sale->inventory_effect_snapshot);
        $this->assertSame('completed', $sale->statut);
    }

    public function test_native_does_not_decrement_when_not_completed(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9002);
        $this->seedLoc($p, 20);

        $result = $this->process($this->service(), $this->order(700002, 9002, 3, ['status' => 'processing']));

        $this->assertSame('imported', $result['action']);
        $this->assertSame(20.0, $this->locStock($this->loc, $p), 'processing/on-hold must not decrement — only completed does');

        $sale = DB::table('sales')->where('id', $result['sale_id'])->first();
        $this->assertSame('ordered', $sale->statut);
        $this->assertNull($sale->inventory_effect_snapshot);
        $this->assertSame($this->loc, (int) $sale->inventory_location_id, 'native routing is decided by warehouse mode, independent of statut');
    }

    public function test_native_fails_closed_on_insufficient_stock_and_writes_nothing(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9003);
        $this->seedLoc($p, 2);

        try {
            $this->process($this->service(), $this->order(700003, 9003, 5));
            $this->fail('Expected a RuntimeException for insufficient stock.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(2.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, DB::table('sales')->count(), 'insufficient stock must leave zero Sale rows behind');
    }

    public function test_native_fails_closed_without_default_location(): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, 'healthy');
        // Deliberately no default_inventory_location_id on the warehouse.
        $p = $this->makeProduct();
        $this->mapProduct($p, 9004);

        $this->expectException(\RuntimeException::class);
        $this->process($this->service(), $this->order(700004, 9004, 1));
    }

    public function test_native_fails_closed_for_batch_tracked_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $this->mapProduct($p, 9005);

        $this->expectException(\RuntimeException::class);
        $this->process($this->service(), $this->order(700005, 9005, 1));
    }

    public function test_native_fails_closed_for_imei_product(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_imei' => 1]);
        $this->mapProduct($p, 9006);

        $this->expectException(\RuntimeException::class);
        $this->process($this->service(), $this->order(700006, 9006, 1));
    }

    public function test_native_variant_exact(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->mapVariant($v1, 8001);
        $this->mapVariant($v2, 8002);
        $this->seedLoc($p, 10, $v1);
        $this->seedLoc($p, 20, $v2);

        $order = $this->order(700007, 0, 4);
        $order['line_items'][0]['variation_id'] = 8001;

        $this->process($this->service(), $order);

        $this->assertSame(6.0, $this->locStock($this->loc, $p, $v1));
        $this->assertSame(20.0, $this->locStock($this->loc, $p, $v2), 'untouched — exact variant only');
    }

    public function test_two_line_items_same_product_stay_distinct_effects(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9008);
        $this->seedLoc($p, 20);

        $order = $this->order(700008, 9008, 2);
        $order['line_items'][] = [
            'product_id' => 9008, 'variation_id' => 0, 'sku' => '',
            'quantity' => 3, 'price' => '10.00', 'subtotal' => '30.00', 'total' => '30.00',
        ];
        $order['total'] = '50.00';

        $result = $this->process($this->service(), $order);

        $this->assertSame(15.0, $this->locStock($this->loc, $p)); // 20 - 2 - 3
        $detailIds = DB::table('sale_details')->where('sale_id', $result['sale_id'])->pluck('id');
        $this->assertCount(2, $detailIds);

        $snapshot = json_decode(DB::table('sales')->where('id', $result['sale_id'])->value('inventory_effect_snapshot'), true);
        $this->assertCount(2, $snapshot['effects']);
        $sourceIds = array_map(fn ($e) => $e['source_detail_id'], $snapshot['effects']);
        $this->assertEqualsCanonicalizing($detailIds->all(), $sourceIds, 'each effect must carry the REAL persisted detail id, and stay distinct per line');
    }

    public function test_unmapped_product_aborts_order_and_writes_nothing(): void
    {
        $this->setLocationPrimary();
        // No product mapped to woo id 9999.
        try {
            $this->process($this->service(), $this->order(700009, 9999, 1));
            $this->fail('Expected a RuntimeException for an unmapped product.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unmapped', $e->getMessage());
        }

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('sale_details')->count());
    }

    // ==================================================================
    // TRACKED — unit conversion fed into the native engine
    // ==================================================================

    public function test_native_unit_conversion_divisor_operator_reflected_in_quantity_base(): void
    {
        $this->setLocationPrimary();
        // Selling unit is a "half" (operator '/', value 2): selling 6 units
        // consumes 3 base units.
        $unitId = $this->makeUnit('/', 2.0);
        $p = $this->makeProduct(['unit_sale_id' => $unitId]);
        $this->mapProduct($p, 9010);
        $this->seedLoc($p, 10);

        $this->process($this->service(), $this->order(700010, 9010, 6));

        $this->assertSame(7.0, $this->locStock($this->loc, $p)); // 10 - (6/2)
    }

    // ==================================================================
    // REPLAY / STATUS
    // ==================================================================

    public function test_replay_same_status_is_metadata_only_no_second_decrement(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9011);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $r1 = $this->process($svc, $this->order(700011, 9011, 5));
        $r2 = $this->process($svc, $this->order(700011, 9011, 5)); // re-pull, same status

        $this->assertSame('imported', $r1['action']);
        $this->assertSame('updated', $r2['action']);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
    }

    public function test_status_progression_processing_then_completed_does_not_retroactively_decrement(): void
    {
        // §17 — existing-imported-order-update behaviour preserved: once
        // imported, a later re-pull only ever updates statut/status
        // metadata, it NEVER re-decrements stock, native or legacy.
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9012);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $this->process($svc, $this->order(700012, 9012, 5, ['status' => 'processing']));
        $this->assertSame(20.0, $this->locStock($this->loc, $p));

        $r2 = $this->process($svc, $this->order(700012, 9012, 5, ['status' => 'completed']));
        $this->assertSame('updated', $r2['action']);
        $this->assertSame(20.0, $this->locStock($this->loc, $p), 'a later completed re-pull must not decrement retroactively — matches legacy');

        $sale = DB::table('sales')->where('woocommerce_order_id', 700012)->first();
        $this->assertSame('completed', $sale->statut);
    }

    // ==================================================================
    // REVERSE
    // ==================================================================
    //
    // MS7-B2-2B.1 characterization note — before this hotfix,
    // reverseImportedSale() queried `SaleDetail::whereNull('deleted_at')`,
    // a column sale_details has never had. On real MySQL (confirmed via
    // MySQL 8.4 QA against prodex_prueba) this THROWS
    // "Unknown column 'deleted_at' in 'where clause'", rolling back the
    // WHOLE reversal transaction (stock included) — the reversal simply
    // never commits. SQLite cannot reproduce that exact failure mode here:
    // its query grammar double-quotes identifiers, and SQLite's legacy
    // "double-quoted string literal" fallback for an unresolvable quoted
    // identifier silently turns `"deleted_at" IS NULL` into an
    // always-false string comparison instead of an error — so the OLD code
    // ran the delete against zero (visible) rows... which is exactly why
    // the tests below assert the CORRECT end state (sale_details actually
    // empty, sales row survives with deleted_at set) rather than an
    // exception: verified against old code, that observable end state is
    // what changes — pre-fix, the old code hard-deleted the Sale itself
    // (`$sale->delete()`, no SoftDeletes trait) while leaving the
    // SaleDetail row silently un-deleted (orphaned), which these
    // assertions would have caught. Post-fix, the Sale survives
    // (soft-deleted via update) and SaleDetail rows are genuinely gone.

    public function test_native_reverse_restores_exact_location_stock(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9013);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $imported = $this->process($svc, $this->order(700013, 9013, 5));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $reversed = $this->process($svc, $this->order(700013, 9013, 5, ['status' => 'cancelled']));

        $this->assertSame('reversed', $reversed['action']);
        $this->assertSame(20.0, $this->locStock($this->loc, $p), 'cancellation must restore the exact location it decremented');

        // MS7-B2-2B.1 — reverseImportedSale now marks the Sale via
        // update(['deleted_at' => ...]) (canonical soft-delete, matching
        // SalesController::destroy()), never a real row delete: the
        // document — and its inventory_effect_snapshot audit trail —
        // survives the reversal.
        $sale = DB::table('sales')->where('id', $imported['sale_id'])->first();
        $this->assertNotNull($sale, 'the Sale row must survive its own reversal');
        $this->assertNotNull($sale->deleted_at);
        $this->assertNotNull($sale->inventory_effect_snapshot, 'the snapshot audit trail must not be destroyed');
        $this->assertSame('cancelled', $sale->woocommerce_order_status);

        // SaleDetail has no soft-delete concept anywhere in this codebase —
        // its rows are genuinely gone, matching SalesController::destroy()'s
        // own `$current->details()->delete()`.
        $this->assertSame(0, DB::table('sale_details')->where('sale_id', $imported['sale_id'])->count());
    }

    public function test_native_reverse_with_multiple_details_completes(): void
    {
        // MS7-B2-2B.1 — the fetch-all-details fix must handle >1 row, not
        // just the trivial single-row case.
        $this->setLocationPrimary();
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $this->mapProduct($p1, 9021);
        $this->mapProduct($p2, 9022);
        $this->seedLoc($p1, 20);
        $this->seedLoc($p2, 30);

        $order = $this->order(700021, 9021, 3);
        $order['line_items'][] = [
            'product_id' => 9022, 'variation_id' => 0, 'sku' => '',
            'quantity' => 4, 'price' => '10.00', 'subtotal' => '40.00', 'total' => '40.00',
        ];
        $order['total'] = '70.00';

        $svc = $this->service();
        $imported = $this->process($svc, $order);
        $this->assertSame(17.0, $this->locStock($this->loc, $p1));
        $this->assertSame(26.0, $this->locStock($this->loc, $p2));
        $this->assertSame(2, DB::table('sale_details')->where('sale_id', $imported['sale_id'])->count());

        $reversed = $this->process($svc, $this->order(700021, 9021, 3, ['status' => 'cancelled']));

        $this->assertSame('reversed', $reversed['action']);
        $this->assertSame(20.0, $this->locStock($this->loc, $p1));
        $this->assertSame(30.0, $this->locStock($this->loc, $p2));
        $this->assertSame(0, DB::table('sale_details')->where('sale_id', $imported['sale_id'])->count());
    }

    public function test_legacy_reverse_restores_product_warehouse(): void
    {
        $unitId = $this->makeUnit();
        $p = $this->makeProduct(['unit_sale_id' => $unitId]);
        $this->mapProduct($p, 9014);
        DB::table('product_warehouse')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'qte' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = $this->service();
        $legacyImported = $this->process($svc, $this->order(700014, 9014, 5));
        $this->assertSame(15.0, $this->stockOf($this->wh, $p));

        $this->process($svc, $this->order(700014, 9014, 5, ['status' => 'refunded']));
        $this->assertSame(20.0, $this->stockOf($this->wh, $p));

        // MS7-B2-2B.1 — the legacy branch shares the exact same broken
        // SaleDetail::whereNull('deleted_at') fetch this hotfix corrects;
        // prove it completes without the "Unknown column" crash and ends in
        // the same canonical state as native (Sale soft-deleted via
        // deleted_at, details gone).
        $sale = DB::table('sales')->where('id', $legacyImported['sale_id'])->first();
        $this->assertNotNull($sale->deleted_at);
        $this->assertSame(0, DB::table('sale_details')->where('sale_id', $legacyImported['sale_id'])->count());
    }

    public function test_reverse_idempotency_replaying_reversal_status_does_not_double_restore(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9015);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $this->process($svc, $this->order(700015, 9015, 5));
        $r1 = $this->process($svc, $this->order(700015, 9015, 5, ['status' => 'cancelled']));
        $r2 = $this->process($svc, $this->order(700015, 9015, 5, ['status' => 'cancelled'])); // replay

        $this->assertSame('reversed', $r1['action']);
        $this->assertSame('skipped', $r2['action'], 'the reversed sale is soft-deleted (invisible to the active-sale lookup), so this becomes a fresh non-sale-status skip, never a second restore');
        $this->assertSame(20.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, DB::table('sales')->count(), 'the reversed sale row still exists, just soft-deleted');
    }

    public function test_reimport_after_reverse_creates_a_fresh_sale_with_new_revision(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9016);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $first = $this->process($svc, $this->order(700016, 9016, 5));
        $this->process($svc, $this->order(700016, 9016, 5, ['status' => 'cancelled']));
        $this->assertSame(20.0, $this->locStock($this->loc, $p));

        $second = $this->process($svc, $this->order(700016, 9016, 5)); // back to completed

        $this->assertSame('imported', $second['action']);
        $this->assertNotSame($first['sale_id'], $second['sale_id'], 'a reimport is a fresh row, never a resurrection of the reversed one');
        $this->assertSame(2, DB::table('sales')->count(), 'the old (soft-deleted) row and the fresh one coexist — exactly what sales_woo_order_id_deleted_at_unique is for');
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $snapshot = json_decode(DB::table('sales')->where('id', $second['sale_id'])->value('inventory_effect_snapshot'), true);
        $this->assertSame(1, $snapshot['revision'], 'a fresh sale starts a fresh revision, it never resumes the reversed one');
    }

    public function test_skipped_non_sale_status_creates_no_sale(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9017);

        $result = $this->process($this->service(), $this->order(700017, 9017, 1, ['status' => 'failed']));

        $this->assertSame('skipped', $result['action']);
        $this->assertSame(0, DB::table('sales')->count());
    }

    // ==================================================================
    // ATOMICITY
    // ==================================================================

    public function test_batch_failure_leaves_zero_sale_and_zero_detail_rows(): void
    {
        $this->setLocationPrimary();
        $p = $this->makeProduct(['is_batch_tracked' => true]);
        $this->mapProduct($p, 9018);

        try {
            $this->process($this->service(), $this->order(700018, 9018, 1));
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('sale_details')->count());
        $this->assertSame(0, DB::table('payment_sales')->count());
    }

    public function test_failure_during_document_cleanup_rolls_back_the_entire_reversal(): void
    {
        // MS7-B2-2B.1 (§4/§7-H) — reverseImportedSale() is one single
        // transaction: if step 4 (SaleDetail cleanup) throws AFTER step 1
        // already reversed the inventory snapshot, EVERYTHING must roll
        // back together — never a physically-reversed-but-undocumented
        // sale. Forced via a SQLite trigger that blocks the DELETE, since
        // there is no other way to fail a step this deep without production
        // test-hooks.
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9023);
        $this->seedLoc($p, 20);

        $svc = $this->service();
        $imported = $this->process($svc, $this->order(700023, 9023, 5));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        DB::unprepared('
            CREATE TRIGGER block_sale_detail_delete
            BEFORE DELETE ON sale_details
            BEGIN
                SELECT RAISE(ABORT, "simulated cleanup failure");
            END;
        ');

        try {
            try {
                $this->process($svc, $this->order(700023, 9023, 5, ['status' => 'cancelled']));
                $this->fail('Expected the simulated cleanup failure to propagate.');
            } catch (\Throwable $e) {
                // expected
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS block_sale_detail_delete');
        }

        $this->assertSame(15.0, $this->locStock($this->loc, $p), 'the inventory reversal must roll back if document cleanup fails downstream');
        $sale = DB::table('sales')->where('id', $imported['sale_id'])->first();
        $this->assertNull($sale->deleted_at, 'the sale must remain active — the reversal never committed');
        $this->assertSame(1, DB::table('sale_details')->where('sale_id', $imported['sale_id'])->count(), 'details must remain — the failed cleanup never committed either');
    }

    // ==================================================================
    // INTEROP
    // ==================================================================

    public function test_native_snapshot_is_reversible_through_the_shared_engine_directly(): void
    {
        // Proves the snapshot processWooOrder() builds is byte-compatible
        // with the SAME engine MS7-B1/B2-1/B2-3/B2-4 already reverse
        // through (SalesController::destroy et al.), not a lookalike shape.
        $this->setLocationPrimary();
        $p = $this->makeProduct();
        $this->mapProduct($p, 9019);
        $this->seedLoc($p, 20);

        $result = $this->process($this->service(), $this->order(700019, 9019, 5));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));

        $sale = DB::table('sales')->where('id', $result['sale_id'])->first();
        $svc = app(LocationAwareSaleStockService::class);
        $snapshot = $svc->normalizeSnapshot($sale->inventory_effect_snapshot);
        DB::transaction(function () use ($svc, $snapshot, $sale) {
            $svc->reverseSnapshot($snapshot, $sale->id);
        });

        $this->assertSame(20.0, $this->locStock($this->loc, $p));
    }

    public function test_out_of_scope_pull_products_and_stock_push_untouched(): void
    {
        foreach ([
            'app/Jobs/WooCommerceStockSyncJob.php',
            'app/Console/Commands/WooCommercePushProducts.php',
            'app/Console/Commands/WooCommerceSyncStock.php',
        ] as $rel) {
            $path = dirname(__DIR__, 2).'/'.$rel;
            $src = file_get_contents($path);
            $this->assertStringNotContainsString('MS7-B2-2B', $src, "{$rel} must stay untouched by MS7-B2-2B.");
        }
    }
}
