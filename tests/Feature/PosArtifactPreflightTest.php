<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\LocationAwareBatchService;
use App\Services\LocationAwareSerialNumberService;
use App\Services\PosLocationSaleStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * MS5-B1 — POS artifact preflight: batch + serial artifacts are resolved,
 * validated and row-locked BEFORE the aggregate InventoryService::decrease.
 * The batch apply after SaleDetail insert consumes the FROZEN plan and never
 * re-runs FEFO.
 *
 * These tests drive the exact CreatePOS sequence by hand (Sale header ->
 * PosLocationSaleStockService::apply -> SaleDetail insert ->
 * applyForSaleWithAutoFallback -> sellOnSale). Row locking is a no-op under
 * SQLite; the lock ORDER is covered by PosArtifactLockOrderArchitectureTest.
 */
class PosArtifactPreflightTest extends TestCase
{
    private int $loc;
    private int $nextBatchExpiryDay = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();

        $this->loc = (int) DB::table('inventory_locations')->insertGetId([
            'branch_id' => 10, 'warehouse_id' => null,
            'code' => 'PISO', 'name' => 'Piso', 'type' => 'sales_floor',
            'is_sellable' => 1, 'is_default_sales' => 1, 'is_quarantine' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('ShortName')->nullable();
            $t->string('operator')->nullable();
            $t->decimal('operator_value', 12, 3)->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('type')->default('is_single');
            $t->integer('unit_sale_id')->nullable();
            $t->boolean('is_batch_tracked')->default(false);
            $t->integer('is_imei')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->string('name');
            $t->string('type')->default('sales_floor');
            $t->boolean('is_sellable')->default(true);
            $t->boolean('is_default_sales')->default(false);
            $t->boolean('is_quarantine')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('inventory_location_stocks', function ($t) {
            $t->increments('id');
            $t->integer('inventory_location_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('variant_key')->default(0);
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('reserved_quantity', 12, 3)->default(0);
            $t->boolean('manage_stock')->default(true);
            $t->timestamps();
            $t->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_uq');
        });
        Schema::create('inventory_location_movements', function ($t) {
            $t->increments('id');
            $t->string('movement_type');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('idempotency_fingerprint', 64)->nullable();
            $t->string('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('product_batches', function ($t) {
            $t->increments('id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('batch_no');
            $t->date('expiry_date')->nullable();
            $t->date('mfg_date')->nullable();
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->integer('provider_id')->nullable();
            $t->integer('source_purchase_id')->nullable();
            $t->string('status')->default('active');
            $t->string('barcode')->nullable();
            $t->text('notes')->nullable();
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
            $t->unique(['product_batch_id', 'inventory_location_id'], 'pbls_uq');
        });
        Schema::create('sale_details', function ($t) {
            $t->increments('id');
            $t->integer('sale_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('sale_unit_id')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->decimal('price', 12, 3)->default(0);
            $t->decimal('total', 12, 3)->default(0);
            $t->decimal('pack_multiplier', 12, 3)->default(1);
            $t->timestamps();
        });
        Schema::create('sale_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('sale_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_price', 12, 3)->nullable();
            $t->timestamps();
        });
        // BatchService::isSupported() also requires this table to exist.
        Schema::create('purchase_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
        Schema::create('product_serials', function ($t) {
            $t->increments('id');
            $t->string('serial_number');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->string('status')->default('available');
            $t->integer('sale_id')->nullable();
            $t->integer('sale_detail_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('product_serial_movements', function ($t) {
            $t->increments('id');
            $t->integer('product_serial_id');
            $t->string('serial_number');
            $t->string('action');
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->integer('reference_id')->nullable();
            $t->integer('user_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    // ---- helpers --------------------------------------------------------

    private function product(array $o = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'P', 'type' => 'is_single', 'is_batch_tracked' => false, 'is_imei' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function generalStock(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->loc, 'product_id' => $productId,
            'product_variant_id' => $variantId, 'variant_key' => (int) ($variantId ?: 0),
            'quantity' => $qty, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Create an active batch + its slice in the POS location. Expiry auto-increments. */
    private function batch(int $productId, float $qty, ?string $expiry = null, ?int $variantId = null): int
    {
        $expiry = $expiry ?? '2027-01-'.str_pad((string) $this->nextBatchExpiryDay++, 2, '0', STR_PAD_LEFT);
        $id = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $productId, 'product_variant_id' => $variantId, 'warehouse_id' => 1,
            'batch_no' => 'B'.\Illuminate\Support\Str::random(5), 'expiry_date' => $expiry,
            'qty' => $qty, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert([
            'product_batch_id' => $id, 'inventory_location_id' => $this->loc,
            'quantity' => $qty, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function serial(int $productId, string $sn, string $status = 'available', ?int $locationId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn, 'product_id' => $productId, 'warehouse_id' => 1,
            'inventory_location_id' => $locationId ?? $this->loc, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sale(): Sale
    {
        $sale = new Sale();
        $sale->id = 700;
        $sale->inventory_location_id = $this->loc;
        $sale->branch_id = 10;
        $sale->user_id = 9;
        $sale->warehouse_id = 1;
        $sale->client_id = null;
        $sale->sale_uuid = 'uuid-700';

        return $sale;
    }

    private function request(array $lines): Request
    {
        $req = Request::create('/api/pos/create_pos', 'POST', ['details' => array_values($lines)]);
        app()->instance('request', $req);

        return $req;
    }

    private function line(int $productId, float $qty, array $o = []): array
    {
        return array_merge([
            'product_id' => $productId,
            'product_variant_id' => null,
            'product_type' => 'is_single',
            'quantity' => $qty,
            'pack_multiplier' => 1,
        ], $o);
    }

    /** Run the CreatePOS artifact sequence by hand and return the persisted details. */
    private function runPos(Sale $sale, Request $request)
    {
        app(PosLocationSaleStockService::class)->apply($sale, $request);

        $details = array_values($request->input('details'));
        $rows = [];
        foreach ($details as $d) {
            $rows[] = [
                'sale_id' => $sale->id,
                'product_id' => $d['product_id'],
                'product_variant_id' => $d['product_variant_id'] ?? null,
                'sale_unit_id' => $d['sale_unit_id'] ?? null,
                'quantity' => $d['quantity'],
                'pack_multiplier' => $d['pack_multiplier'] ?? 1,
                'price' => 0, 'total' => 0, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('sale_details')->insert($rows);
        $persisted = SaleDetail::where('sale_id', $sale->id)->orderBy('id')->get();

        app(LocationAwareBatchService::class)->applyForSaleWithAutoFallback($sale, $details, $persisted);

        foreach ($details as $i => $row) {
            $detail = $persisted->get($i);
            if ($detail) {
                app(LocationAwareSerialNumberService::class)->sellOnSale($sale, $detail, $row['serial_numbers'] ?? null);
            }
        }

        return $persisted;
    }

    private function posMovements(): int
    {
        return (int) DB::table('inventory_location_movements')->where('reference_type', 'pos_sale')->count();
    }

    private function slice(int $batchId): float
    {
        return (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $batchId)->value('quantity');
    }

    private function batchQty(int $batchId): float
    {
        return (float) DB::table('product_batches')->where('id', $batchId)->value('qty');
    }

    // ================================================================
    // BATCH
    // ================================================================

    public function test_explicit_single_batch_is_frozen_in_preflight_and_consumed_exactly(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $b = $this->batch($p, 20, '2027-05-01');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 5, ['batches' => [['product_batch_id' => $b, 'qty' => 5]]]),
        ]);

        // preflight has run and frozen the plan BEFORE the general decrease.
        app(PosLocationSaleStockService::class)->apply($sale, $request);
        $plan = $request->attributes->get(LocationAwareBatchService::POS_BATCH_PREFLIGHT_ATTR.':'.$sale->id);
        $this->assertSame($b, (int) $plan[0]['allocations'][0]['product_batch_id']);
        $this->assertSame(5.0, (float) $plan[0]['allocations'][0]['quantity']);
        $this->assertSame(1, $this->posMovements());

        // finish the flow — apply consumes the frozen plan.
        DB::table('sale_details')->insert([
            'sale_id' => 700, 'product_id' => $p, 'quantity' => 5, 'price' => 0, 'total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $persisted = SaleDetail::where('sale_id', 700)->orderBy('id')->get();
        app(LocationAwareBatchService::class)->applyForSaleWithAutoFallback($sale, array_values($request->input('details')), $persisted);

        $this->assertSame(15.0, $this->slice($b));
        $this->assertSame(15.0, $this->batchQty($b));
        $this->assertSame(95.0, (float) DB::table('inventory_location_stocks')->where('product_id', $p)->value('quantity'));
        $row = DB::table('sale_detail_batches')->first();
        $this->assertSame($b, (int) $row->product_batch_id);
        $this->assertSame(5.0, (float) $row->qty);
    }

    public function test_explicit_multiple_batches(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $b1 = $this->batch($p, 10, '2027-05-01');
        $b2 = $this->batch($p, 10, '2027-06-01');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 8, ['batches' => [
                ['product_batch_id' => $b1, 'qty' => 5],
                ['product_batch_id' => $b2, 'qty' => 3],
            ]]),
        ]);
        $this->runPos($sale, $request);

        $this->assertSame(5.0, $this->slice($b1));
        $this->assertSame(7.0, $this->slice($b2));
        $this->assertSame(2, DB::table('sale_detail_batches')->count());
    }

    public function test_fefo_freezes_earliest_expiry_first(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $late = $this->batch($p, 10, '2027-12-01');
        $early = $this->batch($p, 10, '2027-03-01');

        $sale = $this->sale();
        $request = $this->request([$this->line($p, 6)]);   // no explicit batches -> FEFO

        app(PosLocationSaleStockService::class)->apply($sale, $request);
        $plan = $request->attributes->get(LocationAwareBatchService::POS_BATCH_PREFLIGHT_ATTR.':'.$sale->id);

        $this->assertSame('fefo', $plan[0]['mode']);
        $this->assertSame($early, (int) $plan[0]['allocations'][0]['product_batch_id']);
        $this->assertSame(6.0, (float) $plan[0]['allocations'][0]['quantity']);

        $this->finishFlow($sale, $request);
        $this->assertSame(4.0, $this->slice($early));
        $this->assertSame(10.0, $this->slice($late));
        $this->assertSame($early, (int) DB::table('sale_detail_batches')->value('product_batch_id'));
    }

    public function test_fefo_spans_multiple_batches_in_expiry_order(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $a = $this->batch($p, 4, '2027-03-01');
        $b = $this->batch($p, 4, '2027-04-01');
        $c = $this->batch($p, 4, '2027-05-01');

        $sale = $this->sale();
        $request = $this->request([$this->line($p, 7)]);
        $this->runPos($sale, $request);

        $this->assertSame(0.0, $this->slice($a));
        $this->assertSame(1.0, $this->slice($b));
        $this->assertSame(4.0, $this->slice($c));
        $qties = DB::table('sale_detail_batches')->orderBy('id')->pluck('product_batch_id')->map(fn ($x) => (int) $x)->all();
        $this->assertSame([$a, $b], $qties);
    }

    public function test_insufficient_batch_fails_in_preflight_with_zero_general_movement(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);           // general has plenty
        $this->batch($p, 3, '2027-03-01');      // only 3 in batch slices

        $sale = $this->sale();
        $request = $this->request([$this->line($p, 5)]);

        try {
            app(PosLocationSaleStockService::class)->apply($sale, $request);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batches', $e->errors());
        }
        $this->assertSame(0, $this->posMovements());
        $this->assertSame(100.0, (float) DB::table('inventory_location_stocks')->where('product_id', $p)->value('quantity'));
        $this->assertSame(0, DB::table('sale_detail_batches')->count());
    }

    public function test_explicit_wrong_batch_fails_before_general(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $other = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $wrong = $this->batch($other, 20, '2027-03-01');   // belongs to a different product

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 5, ['batches' => [['product_batch_id' => $wrong, 'qty' => 5]]]),
        ]);

        $this->expectException(ValidationException::class);
        try {
            app(PosLocationSaleStockService::class)->apply($sale, $request);
        } finally {
            $this->assertSame(0, $this->posMovements());
        }
    }

    public function test_mixed_cart_batch_and_simple_line_both_deducted(): void
    {
        $batched = $this->product(['is_batch_tracked' => true]);
        $simple = $this->product();
        $this->generalStock($batched, 50);
        $this->generalStock($simple, 50);
        $b = $this->batch($batched, 20, '2027-03-01');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($batched, 4),
            $this->line($simple, 6),
        ]);
        $this->runPos($sale, $request);

        $this->assertSame(2, $this->posMovements());   // one general decrease per line
        $this->assertSame(16.0, $this->slice($b));
        $this->assertSame(46.0, (float) DB::table('inventory_location_stocks')->where('product_id', $batched)->value('quantity'));
        $this->assertSame(44.0, (float) DB::table('inventory_location_stocks')->where('product_id', $simple)->value('quantity'));
    }

    public function test_frozen_plan_is_not_re_fefo_even_if_a_better_batch_appears_after_preflight(): void
    {
        $p = $this->product(['is_batch_tracked' => true]);
        $this->generalStock($p, 100);
        $planned = $this->batch($p, 10, '2027-06-01');

        $sale = $this->sale();
        $request = $this->request([$this->line($p, 4)]);

        // preflight freezes the plan against $planned.
        app(PosLocationSaleStockService::class)->apply($sale, $request);
        $plan = $request->attributes->get(LocationAwareBatchService::POS_BATCH_PREFLIGHT_ATTR.':'.$sale->id);
        $this->assertSame($planned, (int) $plan[0]['allocations'][0]['product_batch_id']);

        // a batch with an EARLIER expiry is created between preflight and apply.
        $earlier = $this->batch($p, 10, '2027-01-01');

        $this->finishFlow($sale, $request);

        // apply consumed the FROZEN plan, not the newly-better FEFO candidate.
        $this->assertSame($planned, (int) DB::table('sale_detail_batches')->value('product_batch_id'));
        $this->assertSame(6.0, $this->slice($planned));
        $this->assertSame(10.0, $this->slice($earlier));   // untouched
    }

    // ================================================================
    // SERIAL
    // ================================================================

    public function test_serial_is_prelocked_then_sold_after_general(): void
    {
        $p = $this->product(['is_imei' => 1]);
        $this->generalStock($p, 10);
        $this->serial($p, 'SN-1');
        $this->serial($p, 'SN-2');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 2, ['serial_numbers' => ['SN-1', 'SN-2']]),
        ]);

        app(PosLocationSaleStockService::class)->apply($sale, $request);
        $serialPlan = $request->attributes->get(LocationAwareSerialNumberService::POS_SERIAL_PREFLIGHT_ATTR.':'.$sale->id);
        $this->assertSame(['SN-1', 'SN-2'], $serialPlan[0]['serial_numbers']);
        // preflight did NOT change status.
        $this->assertSame(2, (int) DB::table('product_serials')->where('status', 'available')->count());
        $this->assertSame(1, $this->posMovements());

        $this->finishFlow($sale, $request);

        $this->assertSame(2, (int) DB::table('product_serials')->where('status', 'sold')->count());
        $this->assertSame(2, (int) DB::table('product_serial_movements')->where('action', 'sold')->count());
        $this->assertSame(8.0, (float) DB::table('inventory_location_stocks')->where('product_id', $p)->value('quantity'));
    }

    public function test_unavailable_serial_fails_in_preflight_with_zero_general_movement(): void
    {
        $p = $this->product(['is_imei' => 1]);
        $this->generalStock($p, 10);
        $this->serial($p, 'SN-OK');
        $this->serial($p, 'SN-SOLD', 'sold');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 2, ['serial_numbers' => ['SN-OK', 'SN-SOLD']]),
        ]);

        try {
            app(PosLocationSaleStockService::class)->apply($sale, $request);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(0, $this->posMovements());
        $this->assertSame(0, (int) DB::table('product_serial_movements')->count());
    }

    public function test_serial_from_another_location_fails_before_general(): void
    {
        $p = $this->product(['is_imei' => 1]);
        $this->generalStock($p, 10);
        $this->serial($p, 'SN-ELSEWHERE', 'available', 99999);   // different location

        $sale = $this->sale();
        $request = $this->request([
            $this->line($p, 1, ['serial_numbers' => ['SN-ELSEWHERE']]),
        ]);

        $this->expectException(ValidationException::class);
        try {
            app(PosLocationSaleStockService::class)->apply($sale, $request);
        } finally {
            $this->assertSame(0, $this->posMovements());
        }
    }

    public function test_mixed_batch_serial_and_simple_cart(): void
    {
        $batched = $this->product(['is_batch_tracked' => true]);
        $serialized = $this->product(['is_imei' => 1]);
        $simple = $this->product();
        $this->generalStock($batched, 50);
        $this->generalStock($serialized, 50);
        $this->generalStock($simple, 50);
        $b = $this->batch($batched, 20, '2027-03-01');
        $this->serial($serialized, 'MX-1');

        $sale = $this->sale();
        $request = $this->request([
            $this->line($batched, 3),
            $this->line($serialized, 1, ['serial_numbers' => ['MX-1']]),
            $this->line($simple, 2),
        ]);
        $this->runPos($sale, $request);

        $this->assertSame(3, $this->posMovements());
        $this->assertSame(17.0, $this->slice($b));
        $this->assertSame('sold', (string) DB::table('product_serials')->where('serial_number', 'MX-1')->value('status'));
        $this->assertSame(48.0, (float) DB::table('inventory_location_stocks')->where('product_id', $simple)->value('quantity'));
    }

    /** Finish a flow whose apply() already ran: insert details, batch apply, serial apply. */
    private function finishFlow(Sale $sale, Request $request): void
    {
        $details = array_values($request->input('details'));
        $rows = [];
        foreach ($details as $d) {
            $rows[] = [
                'sale_id' => $sale->id, 'product_id' => $d['product_id'],
                'product_variant_id' => $d['product_variant_id'] ?? null,
                'sale_unit_id' => $d['sale_unit_id'] ?? null, 'quantity' => $d['quantity'],
                'pack_multiplier' => $d['pack_multiplier'] ?? 1, 'price' => 0, 'total' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('sale_details')->insert($rows);
        $persisted = SaleDetail::where('sale_id', $sale->id)->orderBy('id')->get();

        app(LocationAwareBatchService::class)->applyForSaleWithAutoFallback($sale, $details, $persisted);
        foreach ($details as $i => $row) {
            $detail = $persisted->get($i);
            if ($detail) {
                app(LocationAwareSerialNumberService::class)->sellOnSale($sale, $detail, $row['serial_numbers'] ?? null);
            }
        }
    }
}
