<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBatchLocationMovement;
use App\Models\ProductBatchLocationStock;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Services\TransferLocationDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Hardening C3.26 — dispatch de lotes en traslados location-aware.
 *
 * Reglas verificadas contra TransferLocationDispatchService::dispatchBatches:
 *  - una asignación explícita se respeta EXACTAMENTE (se descuenta A, no B);
 *  - reparto entre 2 lotes;
 *  - lote vencido (por fecha o por status) rechazado;
 *  - lote en cuarentena rechazado;
 *  - lote dado de baja rechazado;
 *  - lote de otro producto/variante rechazado;
 *  - suma de lotes != cantidad de línea rechazada;
 *  - cantidad elegible insuficiente => falla atómica (sin descuento parcial);
 *  - FEFO fallback SOLO entre lotes elegibles (salta el vencido);
 *  - idempotencia: un segundo despacho no vuelve a descontar.
 */
class TransferLocationDispatchBatchTest extends TestCase
{
    private int $fromLoc = 10;
    private int $toLoc = 20;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->integer('unit_purchase_id')->nullable();
            $t->boolean('is_batch_tracked')->default(true);
            $t->string('type')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('units', function ($t) {
            $t->increments('id');
            $t->string('ShortName')->nullable();
            $t->string('operator')->nullable();
            $t->decimal('operator_value', 20, 6)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->string('Ref')->nullable();
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->string('statut')->nullable();
            $t->string('approval_status')->nullable();
            $t->string('logistics_status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->integer('purchase_unit_id')->nullable();
            $t->decimal('quantity', 20, 6)->default(0);
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
            $t->string('status')->default('active');
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
            $t->unique(['product_batch_id', 'inventory_location_id'], 'pbls_uq_test');
        });
        Schema::create('product_batch_location_movements', function ($t) {
            $t->increments('id');
            $t->integer('product_batch_id');
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->decimal('quantity', 12, 3);
            $t->integer('user_id')->nullable();
            $t->string('reference_type')->nullable();
            $t->string('reference_id')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->string('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('transfer_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('transfer_detail_id');
            $t->integer('source_batch_id');
            $t->integer('dest_batch_id')->nullable();
            $t->double('qty')->default(0);
            $t->double('unit_cost')->nullable();
            $t->timestamps();
        });

        DB::table('units')->insert([
            'id' => 1, 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------- helpers ----------

    private function product(): Product
    {
        return Product::create(['name' => 'Med', 'code' => 'MED', 'unit_purchase_id' => 1, 'is_batch_tracked' => true]);
    }

    private function transfer(): Transfer
    {
        return Transfer::create([
            'Ref' => 'TR_1', 'from_warehouse_id' => 1, 'to_warehouse_id' => 2,
            'from_inventory_location_id' => $this->fromLoc, 'to_inventory_location_id' => $this->toLoc,
            'statut' => 'sent', 'approval_status' => 'approved', 'logistics_status' => 'pending',
        ]);
    }

    private function detail(Transfer $transfer, int $productId, float $qty, ?int $variantId = null): TransferDetail
    {
        return TransferDetail::create([
            'transfer_id' => $transfer->id, 'product_id' => $productId,
            'product_variant_id' => $variantId, 'purchase_unit_id' => 1, 'quantity' => $qty,
        ]);
    }

    private function batch(int $productId, string $no, float $qty, ?string $expiry, string $status = 'active', ?int $variantId = null): ProductBatch
    {
        $b = ProductBatch::create([
            'product_id' => $productId, 'product_variant_id' => $variantId, 'warehouse_id' => 1,
            'batch_no' => $no, 'expiry_date' => $expiry, 'qty' => $qty, 'unit_cost' => 5, 'status' => $status,
        ]);
        ProductBatchLocationStock::create([
            'product_batch_id' => $b->id, 'inventory_location_id' => $this->fromLoc,
            'quantity' => $qty, 'reserved_quantity' => 0,
        ]);
        return $b;
    }

    private function runDispatchBatches(Transfer $transfer, TransferDetail $detail, Product $product, float $required, ?int $variantId, ?array $plan): void
    {
        $m = new ReflectionMethod(TransferLocationDispatchService::class, 'dispatchBatches');
        $m->setAccessible(true);
        $m->invoke(app(TransferLocationDispatchService::class), $transfer, $detail, $product, $required, $variantId, $plan);
    }

    private function stockOf(int $batchId): float
    {
        return (float) ProductBatchLocationStock::where('product_batch_id', $batchId)
            ->where('inventory_location_id', $this->fromLoc)->value('quantity');
    }

    // ---------- tests ----------

    public function test_explicit_pick_debits_chosen_batch_not_the_fefo_one(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 8);
        $a = $this->batch($p->id, 'A-FAR', 50, '2030-01-01');   // later expiry
        $b = $this->batch($p->id, 'B-SOON', 50, '2027-01-01');  // earlier expiry -> FEFO would pick this

        $this->runDispatchBatches($tr, $d, $p, 8, null, [
            ['product_batch_id' => $a->id, 'qty' => 8],
        ]);

        $this->assertSame(42.0, $this->stockOf($a->id), 'El lote elegido A debe descontarse.');
        $this->assertSame(50.0, $this->stockOf($b->id), 'El lote B no debe tocarse.');
        $this->assertSame(8.0, (float) TransferDetailBatch::where('source_batch_id', $a->id)->value('qty'));
        $this->assertSame(0, TransferDetailBatch::where('source_batch_id', $b->id)->count());
        $this->assertSame(1, ProductBatchLocationMovement::where('product_batch_id', $a->id)->count());
    }

    public function test_explicit_split_across_two_batches(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 12);
        $a = $this->batch($p->id, 'A', 10, '2030-01-01');
        $b = $this->batch($p->id, 'B', 10, '2030-06-01');

        $this->runDispatchBatches($tr, $d, $p, 12, null, [
            ['product_batch_id' => $a->id, 'qty' => 7],
            ['product_batch_id' => $b->id, 'qty' => 5],
        ]);

        $this->assertSame(3.0, $this->stockOf($a->id));
        $this->assertSame(5.0, $this->stockOf($b->id));
        $this->assertSame(12.0, (float) TransferDetailBatch::sum('qty'));
    }

    public function test_expired_batch_by_date_is_rejected_when_picked(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 5);
        $expired = $this->batch($p->id, 'EXP', 20, now()->subDay()->toDateString(), 'active');

        $this->expectException(ValidationException::class);
        try {
            $this->runDispatchBatches($tr, $d, $p, 5, null, [['product_batch_id' => $expired->id, 'qty' => 5]]);
        } finally {
            $this->assertSame(20.0, $this->stockOf($expired->id), 'Sin descuento parcial.');
            $this->assertSame(0, TransferDetailBatch::count());
        }
    }

    public function test_quarantined_batch_is_rejected_when_picked(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 5);
        $q = $this->batch($p->id, 'QUAR', 20, '2030-01-01', 'quarantined');

        $this->expectException(ValidationException::class);
        $this->runDispatchBatches($tr, $d, $p, 5, null, [['product_batch_id' => $q->id, 'qty' => 5]]);
    }

    public function test_written_off_batch_is_rejected_when_picked(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 5);
        $w = $this->batch($p->id, 'WO', 20, '2030-01-01', 'written_off');

        $this->expectException(ValidationException::class);
        $this->runDispatchBatches($tr, $d, $p, 5, null, [['product_batch_id' => $w->id, 'qty' => 5]]);
    }

    public function test_batch_of_wrong_product_is_rejected(): void
    {
        $p = $this->product();
        $other = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 5);
        $foreign = $this->batch($other->id, 'FOREIGN', 20, '2030-01-01');

        $this->expectException(ValidationException::class);
        $this->runDispatchBatches($tr, $d, $p, 5, null, [['product_batch_id' => $foreign->id, 'qty' => 5]]);
    }

    public function test_explicit_sum_must_match_line_quantity(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 10);
        $a = $this->batch($p->id, 'A', 50, '2030-01-01');

        $this->expectException(ValidationException::class);
        try {
            $this->runDispatchBatches($tr, $d, $p, 10, null, [['product_batch_id' => $a->id, 'qty' => 7]]);
        } finally {
            $this->assertSame(50.0, $this->stockOf($a->id));
        }
    }

    public function test_explicit_pick_over_available_is_rejected_atomically(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 30);
        $a = $this->batch($p->id, 'A', 10, '2030-01-01');
        $b = $this->batch($p->id, 'B', 10, '2030-01-01');

        $this->expectException(ValidationException::class);
        try {
            $this->runDispatchBatches($tr, $d, $p, 30, null, [
                ['product_batch_id' => $a->id, 'qty' => 20],
                ['product_batch_id' => $b->id, 'qty' => 10],
            ]);
        } finally {
            $this->assertSame(10.0, $this->stockOf($a->id));
            $this->assertSame(10.0, $this->stockOf($b->id));
            $this->assertSame(0, TransferDetailBatch::count());
        }
    }

    public function test_fefo_fallback_skips_expired_and_uses_only_eligible(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 15);
        $expired = $this->batch($p->id, 'EXP', 100, now()->subDay()->toDateString(), 'active'); // earliest date, ineligible
        $good = $this->batch($p->id, 'GOOD', 100, '2030-01-01');

        $this->runDispatchBatches($tr, $d, $p, 15, null, null);

        $this->assertSame(100.0, $this->stockOf($expired->id), 'El vencido no se usa nunca.');
        $this->assertSame(85.0, $this->stockOf($good->id));
    }

    public function test_fefo_fallback_fails_when_eligible_quantity_is_insufficient(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 30);
        $expired = $this->batch($p->id, 'EXP', 100, now()->subDay()->toDateString(), 'active');
        $good = $this->batch($p->id, 'GOOD', 10, '2030-01-01');

        $this->expectException(ValidationException::class);
        try {
            $this->runDispatchBatches($tr, $d, $p, 30, null, null);
        } finally {
            $this->assertSame(10.0, $this->stockOf($good->id), 'Sin descuento parcial.');
            $this->assertSame(0, TransferDetailBatch::count());
        }
    }

    public function test_to_base_quantity_converts_purchase_unit_factor(): void
    {
        $m = new ReflectionMethod(TransferLocationDispatchService::class, 'toBaseQuantity');
        $m->setAccessible(true);
        $svc = app(TransferLocationDispatchService::class);

        $box = \App\Models\Unit::create(['ShortName' => 'box', 'operator' => '*', 'operator_value' => 12]);
        $frac = \App\Models\Unit::create(['ShortName' => 'half', 'operator' => '/', 'operator_value' => 6]);
        $one = \App\Models\Unit::find(1);

        $this->assertSame(24.0, $m->invoke($svc, 2.0, $box, 'p'));   // 2 boxes -> 24 base
        $this->assertSame(2.0, $m->invoke($svc, 12.0, $frac, 'p'));  // 12 -> /6 = 2 base
        $this->assertSame(5.0, $m->invoke($svc, 5.0, $one, 'p'));    // 1:1
    }

    public function test_non_one_to_one_unit_explicit_picks_must_sum_to_base_quantity(): void
    {
        // Line = 2 boxes; unit box = *12 -> required base = 24.
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 2);
        $a = $this->batch($p->id, 'A', 50, '2030-01-01');
        $b = $this->batch($p->id, 'B', 50, '2030-06-01');

        // picks that sum to 24 (base) -> honoured
        $this->runDispatchBatches($tr, $d, $p, 24, null, [
            ['product_batch_id' => $a->id, 'qty' => 20],
            ['product_batch_id' => $b->id, 'qty' => 4],
        ]);
        $this->assertSame(30.0, $this->stockOf($a->id));
        $this->assertSame(46.0, $this->stockOf($b->id));
        $this->assertSame(24.0, (float) TransferDetailBatch::sum('qty'));
    }

    public function test_non_one_to_one_unit_picks_summing_to_line_qty_are_rejected(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 2);
        $a = $this->batch($p->id, 'A', 50, '2030-01-01');

        // picks sum to 2 (line qty, NOT base 24) -> 422, nothing debited
        $this->expectException(ValidationException::class);
        try {
            $this->runDispatchBatches($tr, $d, $p, 24, null, [['product_batch_id' => $a->id, 'qty' => 2]]);
        } finally {
            $this->assertSame(50.0, $this->stockOf($a->id));
            $this->assertSame(0, TransferDetailBatch::count());
        }
    }

    public function test_non_one_to_one_unit_fefo_fallback_uses_base_quantity(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 2); // 2 boxes -> 24 base
        $b = $this->batch($p->id, 'ONLY', 30, '2030-01-01');

        $this->runDispatchBatches($tr, $d, $p, 24, null, null);

        $this->assertSame(6.0, $this->stockOf($b->id)); // 30 - 24
        $this->assertSame(24.0, (float) TransferDetailBatch::sum('qty'));
    }

    public function test_second_dispatch_is_idempotent(): void
    {
        $p = $this->product();
        $tr = $this->transfer();
        $d = $this->detail($tr, $p->id, 8);
        $a = $this->batch($p->id, 'A', 50, '2030-01-01');

        $this->runDispatchBatches($tr, $d, $p, 8, null, [['product_batch_id' => $a->id, 'qty' => 8]]);
        $this->assertSame(42.0, $this->stockOf($a->id));

        // Re-run: existing TransferDetailBatch rows match -> no-op.
        $this->runDispatchBatches($tr, $d, $p, 8, null, [['product_batch_id' => $a->id, 'qty' => 8]]);

        $this->assertSame(42.0, $this->stockOf($a->id), 'No debe volver a descontar.');
        $this->assertSame(1, TransferDetailBatch::count());
        $this->assertSame(1, ProductBatchLocationMovement::count());
    }
}
