<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Services\BatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * LEGACY CHARACTERIZATION — batch unit semantics on a purchase receipt.
 *
 * Pins EXACTLY what `main` does today when a batch-tracked purchase is received
 * in a purchase unit whose conversion factor is NOT 1:
 *
 *   - product_warehouse.qte      is written in BASE UNIT  (quantity * operator_value)
 *   - product_batches.qty        is written in the ENTERED (purchase) UNIT — the
 *                                controller passes batches[].qty straight through
 *                                BatchService::applyBatchesToDetail with NO conversion
 *   - purchase_detail_batches.qty likewise carries the ENTERED unit
 *
 * => SUM(product_batches.qty) != product_warehouse.qte whenever operator_value != 1.
 *
 * This is NOT a bug fix. MS5 location-native batch will use BASE UNIT for every
 * batch quantity (product_batches.qty, product_batch_location_stocks.quantity,
 * product_batch_location_movements.quantity, batch_allocation.quantity_base).
 * This test exists so the divergence between the LEGACY contract and the new
 * NATIVE contract is explicit and guarded.
 */
class PurchaseBatchLegacyCharacterizationTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLegacyBatchSchema();
        $this->legacyOwner();
    }

    /** Batch tables the golden LegacyPurchaseTestSchema intentionally omits. */
    private function buildLegacyBatchSchema(): void
    {
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

        Schema::create('purchase_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function line(array $o = []): array
    {
        return array_merge([
            'product_id' => null,
            'product_variant_id' => null,
            'purchase_unit_id' => null,
            'quantity' => 1,
            'Unit_cost' => 1,
            'tax_percent' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_Method' => '2',
            'subtotal' => 1,
            'no_unit' => 1,
            'batches' => [],
        ], $o);
    }

    public function test_legacy_batch_receipt_10_boxes_of_12_writes_base_stock_but_purchase_unit_batches(): void
    {
        $this->assertTrue(app(BatchService::class)->isSupported());

        $wh = $this->makeWarehouse();
        $unit = $this->makeUnit('*', 12);                 // 1 box = 12 base units
        $product = $this->makeProduct(['is_batch_tracked' => true, 'unit_purchase_id' => $unit, 'cost' => 5]);
        $this->seedStock($wh, $product, 0);

        $payload = [
            'supplier_id' => 1,
            'warehouse_id' => $wh,
            'date' => '2026-09-05',
            'statut' => 'received',
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 600,
            'details' => [
                $this->line([
                    'product_id' => $product,
                    'purchase_unit_id' => $unit,
                    'quantity' => 10,            // 10 BOXES
                    'Unit_cost' => 5,
                    'subtotal' => 50,
                    'batches' => [
                        ['batch_no' => 'LOT-A', 'qty' => 6, 'expiry_date' => '2027-01-31', 'mfg_date' => null, 'unit_cost' => 5],
                        ['batch_no' => 'LOT-B', 'qty' => 4, 'expiry_date' => '2027-03-31', 'mfg_date' => null, 'unit_cost' => 5],
                    ],
                ]),
            ],
        ];

        $req = $this->makeRequest($payload);
        $this->controller()->store($req);

        // GENERAL stock: BASE UNIT — 10 boxes * 12 = 120.
        $this->assertSame(120.0, $this->stockOf($wh, $product));

        // AGGREGATE batch: ENTERED (purchase) unit — 6 and 4, NOT 72 and 48.
        $batches = DB::table('product_batches')->orderBy('batch_no')->get();
        $this->assertCount(2, $batches);
        $this->assertSame('LOT-A', $batches[0]->batch_no);
        $this->assertSame(6.0, (float) $batches[0]->qty);
        $this->assertSame('LOT-B', $batches[1]->batch_no);
        $this->assertSame(4.0, (float) $batches[1]->qty);

        // PIVOT: ENTERED unit — 6 + 4.
        $pivotQties = DB::table('purchase_detail_batches')->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all();
        $this->assertSame([6.0, 4.0], $pivotQties);

        // THE DIVERGENCE, made explicit:
        $this->assertSame(120.0, $this->stockOf($wh, $product));
        $this->assertSame(10.0, (float) DB::table('product_batches')->sum('qty'));
        $this->assertNotEqualsWithDelta(
            $this->stockOf($wh, $product),
            (float) DB::table('product_batches')->sum('qty'),
            0.0005,
            'LEGACY: SUM(product_batches.qty) diverges from product_warehouse.qte by operator_value. MS5 native uses BASE UNIT.'
        );
    }
}
