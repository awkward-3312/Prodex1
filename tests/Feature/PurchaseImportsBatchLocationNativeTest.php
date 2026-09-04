<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS5-E — batch location-native ACTIVATED in the purchase IMPORT
 * (store_import_purchases, MODE_LOCATION_PRIMARY).
 *
 * The CSV contract (productcode;qty) is unchanged; the physical batch
 * distribution rides in `batches_by_code`. RECEIVED folds the shared
 * LocationAwarePurchaseBatchPlanner into a revision-1 snapshot; PENDING creates
 * no artifact. is_variant still 422 (no variant column in the CSV); a plain
 * IMEI product is importable since MS6-B3 (PurchaseImportsSerialLocationNativeTest)
 * — batch+IMEI on the same product still 422. product_warehouse never touched.
 */
class PurchaseImportsBatchLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $unit1;
    private int $unit12;
    private int $unitDiv;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildBatchSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-IMP-BATCH');
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
        $this->unitDiv = $this->makeUnit('/', 4);
        $this->loc = $this->makeInventoryLocation($this->wh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    private function buildBatchSchema(): void
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
        Schema::create('purchase_detail_batches', function ($t) {
            $t->increments('id');
            $t->integer('purchase_detail_id');
            $t->integer('product_batch_id');
            $t->decimal('qty', 12, 3)->default(0);
            $t->decimal('unit_cost', 12, 3)->nullable();
            $t->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function lp(string $status = 'healthy'): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    /** @param array<int,array{0:string,1:int|float}> $rows */
    private function csvFile(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'impb').'.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "productcode;qty\n");
        foreach ($rows as [$code, $qty]) {
            fwrite($fh, "{$code};{$qty}\n");
        }
        fclose($fh);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    private function payload(string $statut = 'received', $batchesByCode = [], $locationId = 'DEFAULT', $warehouseId = null): array
    {
        return [
            'supplier_id' => 1,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => $locationId === 'DEFAULT' ? $this->loc : $locationId,
            'date' => '2026-09-03',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'batches_by_code' => is_string($batchesByCode) ? $batchesByCode : json_encode((object) $batchesByCode),
        ];
    }

    private function runImport(UploadedFile $file, array $payload)
    {
        return $this->controller()->store_import_purchases(
            $this->makeRequest($payload, 'POST', ['products' => $file])
        );
    }

    private function batchProduct(string $code, ?int $unit = null, float $cost = 2): int
    {
        return (int) $this->makeProduct([
            'code' => $code,
            'is_batch_tracked' => true,
            'unit_purchase_id' => $unit ?? $this->unit1,
            'cost' => $cost,
        ]);
    }

    private function simpleProduct(string $code, ?int $unit = null, float $cost = 1): int
    {
        return (int) $this->makeProduct([
            'code' => $code,
            'unit_purchase_id' => $unit ?? $this->unit1,
            'cost' => $cost,
        ]);
    }

    private function batchByNo(string $no)
    {
        return DB::table('product_batches')->where('batch_no', $no)->first();
    }

    private function slice(int $batchId): float
    {
        return (float) DB::table('product_batch_location_stocks')->where('product_batch_id', $batchId)->value('quantity');
    }

    private function locMovements(?string $ref = null): int
    {
        $q = DB::table('product_batch_location_movements');
        if ($ref) {
            $q->where('reference_type', $ref);
        }

        return (int) $q->count();
    }

    private function pw(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    private function snapshot()
    {
        return json_decode(DB::table('purchases')->value('inventory_effect_snapshot'), true);
    }

    private function assertNoWrites(): void
    {
        $this->assertSame(0, DB::table('purchases')->count(), 'no Purchase');
        $this->assertSame(0, DB::table('purchase_details')->count(), 'no PurchaseDetail');
        $this->assertSame(0, DB::table('purchase_detail_batches')->count(), 'no pivots');
        $this->assertSame(0, $this->locMovements(), 'no batch movements');
        $this->assertSame(0, $this->movementCount(), 'no general movements');
    }

    // =====================================================================
    // RECEIVED — happy paths
    // =====================================================================

    public function test_received_single_simple_batch(): void
    {
        $this->lp();
        $p = $this->batchProduct('B1');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['B1', 6]]), $this->payload('received', [
            'B1' => [['batch_no' => 'LOT-A', 'qty' => 6, 'unit_cost' => 2]],
        ]));

        $b = $this->batchByNo('LOT-A');
        $this->assertNotNull($b);
        $this->assertSame(6.0, (float) $b->qty);
        $this->assertSame(6.0, $this->slice($b->id));
        $this->assertSame(6.0, $this->locStock($this->loc, $p));
        $this->assertSame(1, $this->locMovements('PurchaseBatch'));
        $this->assertSame(1, $this->movementCount('Purchase'));
        $this->assertSame(6.0, (float) DB::table('purchase_detail_batches')->value('qty'));
        $this->assertSame(0, $this->pw());
        $this->assertSame(1, (int) $this->snapshot()['revision']);
        $this->assertSame(6.0, (float) $this->snapshot()['effects'][0]['batch_allocation'][0]['quantity_base']);
    }

    public function test_received_two_batches_one_line(): void
    {
        $this->lp();
        $p = $this->batchProduct('B2');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['B2', 10]]), $this->payload('received', [
            'B2' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 4]],
        ]));

        $this->assertSame(6.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(4.0, (float) $this->batchByNo('B')->qty);
        $this->assertSame(10.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, $this->locMovements('PurchaseBatch'));
        $this->assertSame(2, DB::table('purchase_detail_batches')->count());
    }

    public function test_received_10_boxes_of_12_unit_contract(): void
    {
        $this->lp();
        $p = $this->batchProduct('BOX-01', $this->unit12, 5);
        $this->seedStock($this->wh, $p, 0);      // legacy row present, must not move
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['BOX-01', 10]]), $this->payload('received', [
            'BOX-01' => [
                ['batch_no' => 'LOT-A', 'qty' => 6, 'expiry_date' => '2027-01-01'],
                ['batch_no' => 'LOT-B', 'qty' => 4],
            ],
        ]));

        // PurchaseDetail.quantity = 10 boxes (purchase unit).
        $this->assertSame(10.0, (float) DB::table('purchase_details')->value('quantity'));
        // pivots keep the COMMERCIAL qty 6 / 4.
        $this->assertSame([6.0, 4.0], DB::table('purchase_detail_batches')->orderBy('id')->pluck('qty')->map(fn ($q) => (float) $q)->all());
        // snapshot + physical = BASE (72 / 48 ; general +120).
        $alloc = $this->snapshot()['effects'][0]['batch_allocation'];
        $this->assertSame([72.0, 48.0], [(float) $alloc[0]['quantity_base'], (float) $alloc[1]['quantity_base']]);
        $this->assertSame(120.0, (float) $this->snapshot()['effects'][0]['quantity_base']);
        $this->assertSame(72.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(48.0, (float) $this->batchByNo('LOT-B')->qty);
        $this->assertSame(72.0, $this->slice((int) $this->batchByNo('LOT-A')->id));
        $this->assertSame(120.0, $this->locStock($this->loc, $p));
        $this->assertSame(0.0, $this->stockOf($this->wh, $p));   // product_warehouse untouched
    }

    public function test_received_operator_divide_conversion(): void
    {
        $this->lp();
        $p = $this->batchProduct('DIV-1', $this->unitDiv, 4);   // operator '/', value 4
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['DIV-1', 8]]), $this->payload('received', [
            'DIV-1' => [['batch_no' => 'D', 'qty' => 8]],
        ]));

        // 8 / 4 = 2 base.
        $this->assertSame(2.0, (float) $this->batchByNo('D')->qty);
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
        $this->assertSame(8.0, (float) DB::table('purchase_detail_batches')->value('qty'));   // pivot = purchase unit
    }

    public function test_received_mixed_batch_and_simple_rows(): void
    {
        $this->lp();
        $b = $this->batchProduct('MB');
        $s = $this->simpleProduct('MS');
        $this->seedLocationStock($this->loc, $b, 0);
        $this->seedLocationStock($this->loc, $s, 0);

        $this->runImport($this->csvFile([['MB', 4], ['MS', 5]]), $this->payload('received', [
            'MB' => [['batch_no' => 'MB-1', 'qty' => 4]],
        ]));

        $this->assertSame(4.0, $this->locStock($this->loc, $b));
        $this->assertSame(5.0, $this->locStock($this->loc, $s));
        $this->assertSame(1, $this->locMovements('PurchaseBatch'));   // only the batch line
        $this->assertSame(2, $this->movementCount('Purchase'));       // both general effects
        $this->assertSame(1, DB::table('purchase_detail_batches')->count());
        $effects = $this->snapshot()['effects'];
        $this->assertCount(2, $effects);
        $this->assertArrayHasKey('batch_allocation', $effects[0]);
        $this->assertArrayNotHasKey('batch_allocation', $effects[1]);
    }

    public function test_received_multiple_batch_products(): void
    {
        $this->lp();
        $p1 = $this->batchProduct('MP1');
        $p2 = $this->batchProduct('MP2');
        $this->seedLocationStock($this->loc, $p1, 0);
        $this->seedLocationStock($this->loc, $p2, 0);

        $this->runImport($this->csvFile([['MP1', 3], ['MP2', 7]]), $this->payload('received', [
            'MP1' => [['batch_no' => 'X', 'qty' => 3]],
            'MP2' => [['batch_no' => 'Y', 'qty' => 5], ['batch_no' => 'Z', 'qty' => 2]],
        ]));

        $this->assertSame(3.0, (float) $this->batchByNo('X')->qty);
        $this->assertSame(5.0, (float) $this->batchByNo('Y')->qty);
        $this->assertSame(2.0, (float) $this->batchByNo('Z')->qty);
        $this->assertSame(3, $this->locMovements('PurchaseBatch'));
        $this->assertSame(3, DB::table('purchase_detail_batches')->count());
    }

    public function test_received_existing_native_ready_batch_top_up(): void
    {
        $this->lp();
        $p = $this->batchProduct('TU');
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 10);

        $this->runImport($this->csvFile([['TU', 5]]), $this->payload('received', [
            'TU' => [['batch_no' => 'LOT-A', 'qty' => 5]],
        ]));

        $this->assertSame(15.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(15.0, $this->slice($b));
        $this->assertSame(15.0, $this->locStock($this->loc, $p));
    }

    // =====================================================================
    // RECEIVED — rollback (planner authoritative, total rollback)
    // =====================================================================

    public function test_received_drifted_existing_batch_rolls_back(): void
    {
        $this->lp();
        $p = $this->batchProduct('DR');
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 120);   // general 120 vs batch slice 10

        try {
            $this->runImport($this->csvFile([['DR', 5]]), $this->payload('received', ['DR' => [['batch_no' => 'LOT-A', 'qty' => 5]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(10.0, (float) $this->batchByNo('LOT-A')->qty);
        $this->assertSame(0, $this->locMovements());
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());
    }

    public function test_received_soft_deleted_identity_rolls_back(): void
    {
        $this->lp();
        $p = $this->batchProduct('SD');
        DB::table('product_batches')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 0,
            'status' => 'active', 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['SD', 3]]), $this->payload('received', ['SD' => [['batch_no' => 'LOT-A', 'qty' => 3]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertNoWrites();
    }

    public function test_received_conflicting_expiry_rolls_back(): void
    {
        $this->lp();
        $p = $this->batchProduct('CE');
        DB::table('product_batches')->insert([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 0,
            'expiry_date' => '2027-01-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['CE', 3]]), $this->payload('received', ['CE' => [['batch_no' => 'LOT-A', 'qty' => 3, 'expiry_date' => '2028-02-02']]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertStringStartsWith('2027-01-01', (string) $this->batchByNo('LOT-A')->expiry_date);
    }

    public function test_received_metadata_completion_rolls_back_on_later_failure(): void
    {
        $this->lp();
        $p = $this->batchProduct('MC');
        $b = (int) DB::table('product_batches')->insertGetId([
            'product_id' => $p, 'warehouse_id' => $this->wh, 'batch_no' => 'LOT-A', 'qty' => 10,
            'expiry_date' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_batch_location_stocks')->insert(['product_batch_id' => $b, 'inventory_location_id' => $this->loc, 'quantity' => 10, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedLocationStock($this->loc, $p, 999);   // drift -> receiveMany fails AFTER metadata completion

        try {
            $this->runImport($this->csvFile([['MC', 5]]), $this->payload('received', ['MC' => [['batch_no' => 'LOT-A', 'qty' => 5, 'expiry_date' => '2027-09-09']]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // ok
        }
        $this->assertNull($this->batchByNo('LOT-A')->expiry_date);
        $this->assertSame(0, DB::table('purchases')->count());
    }

    // =====================================================================
    // RECEIVED — batch payload shape 422 (before any write)
    // =====================================================================

    public function test_received_invalid_date_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('ID');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['ID', 3]]), $this->payload('received', ['ID' => [['batch_no' => 'A', 'qty' => 3, 'expiry_date' => 'not-a-date']]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details.0.batches', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_received_duplicate_batch_no_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('DB');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['DB', 4]]), $this->payload('received', ['DB' => [['batch_no' => 'A', 'qty' => 2], ['batch_no' => 'A', 'qty' => 2]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details.0.batches', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_received_sum_mismatch_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('SM');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['SM', 10]]), $this->payload('received', ['SM' => [['batch_no' => 'A', 'qty' => 6], ['batch_no' => 'B', 'qty' => 3]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details.0.batches', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_received_missing_batches_for_batch_row_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('MI');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['MI', 3]]), $this->payload('received', []));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details.0.batches', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_malformed_batches_by_code_json_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('MJ');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['MJ', 3]]), $this->payload('received', '{ this is not json'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batches_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_extra_product_code_in_batches_by_code_is_422(): void
    {
        $this->lp();
        $p = $this->batchProduct('EX');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['EX', 3]]), $this->payload('received', [
                'EX' => [['batch_no' => 'A', 'qty' => 3]],
                'GHOST' => [['batch_no' => 'G', 'qty' => 1]],
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batches_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_batches_supplied_for_non_batch_product_is_422(): void
    {
        $this->lp();
        $s = $this->simpleProduct('NB');
        $this->seedLocationStock($this->loc, $s, 0);

        try {
            $this->runImport($this->csvFile([['NB', 3]]), $this->payload('received', ['NB' => [['batch_no' => 'A', 'qty' => 3]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batches_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    // =====================================================================
    // CSV contract preserved
    // =====================================================================

    public function test_unknown_csv_product_is_422(): void
    {
        $this->lp();
        $res = $this->runImport($this->csvFile([['NOPE', 2]]), $this->payload('received'));
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['status']);
        $this->assertNoWrites();
    }

    public function test_duplicate_csv_productcode_is_422(): void
    {
        $this->lp();
        $this->batchProduct('DUP');
        $res = $this->runImport($this->csvFile([['DUP', 2], ['DUP', 3]]), $this->payload('received', [
            'DUP' => [['batch_no' => 'A', 'qty' => 5]],
        ]));
        $body = json_decode($res->getContent(), true);
        $this->assertFalse($body['status']);
        $this->assertStringContainsStringIgnoringCase('duplicate', $body['msg']);
        $this->assertNoWrites();
    }

    public function test_variant_product_is_422_even_with_batches(): void
    {
        $this->lp();
        $p = $this->makeProduct(['code' => 'VP', 'type' => 'is_variant', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->makeVariant($p);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['VP', 3]]), $this->payload('received', ['VP' => [['batch_no' => 'A', 'qty' => 3]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('variante', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertNoWrites();
    }

    public function test_batch_plus_imei_product_is_422(): void
    {
        // MS6-B3 — a plain IMEI product is now importable (see
        // PurchaseImportsSerialLocationNativeTest); a product that is BOTH
        // batch AND IMEI tracked still 422s — a line carries only ONE
        // physical artifact tracker.
        $this->lp();
        $p = $this->makeProduct(['code' => 'IM', 'is_imei' => 1, 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['IM', 3]]), $this->payload('received', ['IM' => [['batch_no' => 'A', 'qty' => 3]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('lote', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertNoWrites();
    }

    public function test_product_warehouse_is_never_touched(): void
    {
        $this->lp();
        $p = $this->batchProduct('PWX');
        $this->seedStock($this->wh, $p, 50);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PWX', 4]]), $this->payload('received', ['PWX' => [['batch_no' => 'A', 'qty' => 4]]]));

        $this->assertSame(50.0, $this->stockOf($this->wh, $p));   // unchanged
    }

    // =====================================================================
    // PENDING
    // =====================================================================

    public function test_pending_batch_import_creates_header_and_details_only(): void
    {
        $this->lp();
        $p = $this->batchProduct('PB');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PB', 5]]), $this->payload('pending', []));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertNotNull(DB::table('purchases')->value('inventory_location_id'));
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());
        $this->assertSame(0, $this->locMovements());
        $this->assertSame(0, $this->movementCount());
    }

    public function test_pending_with_batches_by_code_creates_no_physical_artifact(): void
    {
        $this->lp();
        $p = $this->batchProduct('PB2');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PB2', 5]]), $this->payload('pending', [
            'PB2' => [['batch_no' => 'IGN', 'qty' => 5]],
        ]));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());
        $this->assertSame(0, $this->locMovements());
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
    }

    public function test_pending_still_rejects_a_stale_extra_code(): void
    {
        $this->lp();
        $p = $this->batchProduct('PB3');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['PB3', 5]]), $this->payload('pending', ['GHOST' => [['batch_no' => 'G', 'qty' => 1]]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batches_by_code', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    // =====================================================================
    // ATOMICITY
    // =====================================================================

    public function test_valid_first_row_then_invalid_later_batch_writes_nothing(): void
    {
        $this->lp();
        $p1 = $this->batchProduct('AT1');
        $p2 = $this->simpleProduct('AT2');
        $p3 = $this->batchProduct('AT3');
        $this->seedLocationStock($this->loc, $p1, 0);
        $this->seedLocationStock($this->loc, $p2, 0);
        $this->seedLocationStock($this->loc, $p3, 0);

        try {
            $this->runImport($this->csvFile([['AT1', 3], ['AT2', 2], ['AT3', 4]]), $this->payload('received', [
                'AT1' => [['batch_no' => 'A', 'qty' => 3]],
                'AT3' => [['batch_no' => 'C', 'qty' => 999]],   // sum mismatch on the 3rd row
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details.2.batches', $e->errors());
        }
        $this->assertNoWrites();
        $this->assertSame(0.0, $this->locStock($this->loc, $p1));
        $this->assertSame(0.0, $this->locStock($this->loc, $p2));
    }

    public function test_multiple_batch_products_all_receipts_precede_general_increase(): void
    {
        $this->lp();
        $p1 = $this->batchProduct('PH1');
        $p2 = $this->batchProduct('PH2');
        $this->seedLocationStock($this->loc, $p1, 0);
        $this->seedLocationStock($this->loc, $p2, 0);

        $this->runImport($this->csvFile([['PH1', 3], ['PH2', 5]]), $this->payload('received', [
            'PH1' => [['batch_no' => 'A', 'qty' => 3]],
            'PH2' => [['batch_no' => 'B', 'qty' => 5]],
        ]));

        // both batch receipts + both general effects applied.
        $this->assertSame(2, $this->locMovements('PurchaseBatch'));
        $this->assertSame(2, $this->movementCount('Purchase'));
        $this->assertSame(3.0, $this->locStock($this->loc, $p1));
        $this->assertSame(5.0, $this->locStock($this->loc, $p2));
    }

    // =====================================================================
    // INTEROP WITH MS5-C
    // =====================================================================

    public function test_imported_batch_purchase_can_be_destroyed_by_ms5c_native_destroy(): void
    {
        $this->lp();
        $p = $this->batchProduct('IC1');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['IC1', 6]]), $this->payload('received', ['IC1' => [['batch_no' => 'A', 'qty' => 6]]]));
        $id = (int) DB::table('purchases')->value('id');
        $this->assertSame(6.0, (float) $this->batchByNo('A')->qty);

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);

        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));
        $this->assertSame(0, DB::table('purchase_detail_batches')->count());
    }

    public function test_imported_batch_purchase_can_be_updated_received_to_received_by_ms5c(): void
    {
        $this->lp();
        $p = $this->batchProduct('IC2');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['IC2', 6]]), $this->payload('received', ['IC2' => [['batch_no' => 'A', 'qty' => 6]]]));
        $id = (int) DB::table('purchases')->value('id');
        $detailUnit = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('purchase_unit_id');

        // MS5-C manual update: reverse import snapshot (rev 1) + apply rev 2.
        $updatePayload = [
            'supplier_id' => 1,
            'warehouse_id' => $this->wh,
            'inventory_location_id' => $this->loc,
            'date' => '2026-09-04',
            'statut' => 'received',
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 20,
            'details' => [[
                'product_id' => $p,
                'product_variant_id' => null,
                'purchase_unit_id' => $detailUnit,
                'quantity' => 4,
                'Unit_cost' => 2,
                'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
                'subtotal' => 8,
                'no_unit' => 1,
                'batches' => [['batch_no' => 'A', 'qty' => 4]],
            ]],
        ];
        $this->controller()->update($this->makeRequest($updatePayload, 'PUT'), $id);

        $this->assertSame(4.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(4.0, $this->locStock($this->loc, $p));
        $this->assertSame(2, (int) $this->snapshot()['revision']);
    }

    public function test_pivot_corruption_does_not_affect_the_snapshot_reverse(): void
    {
        $this->lp();
        $p = $this->batchProduct('PC');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PC', 6]]), $this->payload('received', ['PC' => [['batch_no' => 'A', 'qty' => 6]]]));
        $id = (int) DB::table('purchases')->value('id');

        // Corrupt / wipe the secondary pivots — the reverse must still work from
        // the snapshot alone.
        DB::table('purchase_detail_batches')->update(['qty' => 99999]);

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);

        $this->assertSame(0.0, (float) $this->batchByNo('A')->qty);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    // =====================================================================
    // LEGACY ISOLATION
    // =====================================================================

    public function test_legacy_warehouse_import_still_uses_the_legacy_batch_writer(): void
    {
        // No transition state => legacy import path. batches_by_code stays
        // lenient; product_warehouse is the writer; no location artifacts.
        $legacyWh = $this->makeWarehouse('LEGACY-IMP');
        $p = (int) $this->makeProduct(['code' => 'LG', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedStock($legacyWh, $p, 0);

        $payload = $this->payload('received', ['LG' => [['batch_no' => 'A', 'qty' => 4]]], null, $legacyWh);
        $res = $this->runImport($this->csvFile([['LG', 4]]), $payload);
        $body = json_decode($res->getContent(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(4.0, $this->stockOf($legacyWh, $p));    // legacy product_warehouse writer
        $this->assertSame(0, $this->locMovements());              // no location-native artifacts
        $this->assertNull(DB::table('purchases')->value('inventory_location_id'));
    }

    public function test_legacy_warehouse_import_tolerates_malformed_batches_json(): void
    {
        // The strict 422 is native-only; legacy keeps its silent lenient decode.
        $legacyWh = $this->makeWarehouse('LEGACY-IMP2');
        $p = (int) $this->makeProduct(['code' => 'LG2', 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedStock($legacyWh, $p, 0);

        $res = $this->runImport($this->csvFile([['LG2', 4]]), $this->payload('received', '{ broken', null, $legacyWh));
        $body = json_decode($res->getContent(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(4.0, $this->stockOf($legacyWh, $p));
    }
}
