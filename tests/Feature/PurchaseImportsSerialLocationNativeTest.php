<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B3 — serial / IMEI location-native ACTIVATED in the purchase IMPORT
 * (store_import_purchases, MODE_LOCATION_PRIMARY).
 *
 * The CSV contract (productcode;qty) is UNCHANGED — no serial column. Physical
 * serial distribution rides entirely in `serials_by_code` (map of productcode
 * => [serial_number,...]), out of band from the CSV. RECEIVED folds the shared
 * LocationAwarePurchaseSerialPlanner (batch THEN serial, ordinal-mapped) into a
 * revision-1 snapshot; PENDING creates no artifact even with a full payload.
 * is_variant still 422 (no variant column); batch+IMEI on one product still 422.
 * After creation the Purchase is physically indistinguishable from one created
 * by MS6-B1 manual entry — no import-only flag anywhere.
 */
class PurchaseImportsSerialLocationNativeTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

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
        $this->buildSerialSchema();
        $this->buildBatchSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CD-IMP-SERIAL');
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
    // Harness
    // ------------------------------------------------------------------

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function lp(string $status = 'healthy'): void
    {
        $this->setTransitionMode($this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    /** @param  array<int,array{0:string,1:int|float}>  $rows */
    private function csvFile(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imps').'.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "productcode;qty\n");
        foreach ($rows as [$code, $qty]) {
            fwrite($fh, "{$code};{$qty}\n");
        }
        fclose($fh);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    private function payload(string $statut = 'received', $batchesByCode = [], $serialsByCode = [], $locationId = 'DEFAULT', $warehouseId = null): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => $locationId === 'DEFAULT' ? $this->loc : $locationId,
            'date' => '2026-09-13',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'batches_by_code' => is_string($batchesByCode) ? $batchesByCode : json_encode((object) $batchesByCode),
            'serials_by_code' => is_string($serialsByCode) ? $serialsByCode : json_encode((object) $serialsByCode),
        ];
    }

    private function runImport(UploadedFile $file, array $payload)
    {
        return $this->controller()->store_import_purchases(
            $this->makeRequest($payload, 'POST', ['products' => $file])
        );
    }

    private function imeiProduct(string $code, ?int $unit = null, float $cost = 2): int
    {
        return (int) $this->makeProduct([
            'code' => $code, 'is_imei' => 1, 'unit_purchase_id' => $unit ?? $this->unit1, 'cost' => $cost,
        ]);
    }

    private function simpleProduct(string $code, ?int $unit = null, float $cost = 1): int
    {
        return (int) $this->makeProduct(['code' => $code, 'unit_purchase_id' => $unit ?? $this->unit1, 'cost' => $cost]);
    }

    private function batchProduct(string $code, ?int $unit = null, float $cost = 1): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_batch_tracked' => true, 'unit_purchase_id' => $unit ?? $this->unit1, 'cost' => $cost]);
    }

    private function lastPurchaseId(): int
    {
        return (int) DB::table('purchases')->orderByDesc('id')->value('id');
    }

    private function snap(?int $id = null): array
    {
        $raw = $id
            ? DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot')
            : DB::table('purchases')->value('inventory_effect_snapshot');

        return json_decode((string) $raw, true) ?: [];
    }

    private function serialsBy(array $where): int
    {
        $q = DB::table('product_serials');
        foreach ($where as $k => $v) {
            $q->where($k, $v);
        }

        return (int) $q->count();
    }

    private function pw(): int
    {
        return (int) DB::table('product_warehouse')->count();
    }

    private function serialMovAll(): int
    {
        return (int) DB::table('product_serial_movements')->count();
    }

    private function batchMovements(): int
    {
        return (int) DB::table('product_batch_location_movements')->count();
    }

    private function assertNoWrites(): void
    {
        $this->assertSame(0, DB::table('purchases')->count(), 'no Purchase');
        $this->assertSame(0, DB::table('purchase_details')->count(), 'no PurchaseDetail');
        $this->assertSame(0, DB::table('product_serials')->count(), 'no ProductSerial (incl. placeholders)');
        $this->assertSame(0, $this->serialMovAll(), 'no serial movements');
        $this->assertSame(0, $this->movementCount(), 'no general movements');
        $this->assertSame(0, $this->batchMovements(), 'no batch movements');
    }

    // =====================================================================
    // RECEIVED — happy paths
    // =====================================================================

    public function test_1_single_serial(): void
    {
        $this->lp();
        $p = $this->imeiProduct('S1');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['S1', 1]]), $this->payload('received', [], ['S1' => ['SN-1']]));
        $id = $this->lastPurchaseId();
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');

        $row = $this->serialRow('SN-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id);
        $this->assertSame($id, (int) $row->purchase_id);
        $this->assertSame($did, (int) $row->purchase_detail_id);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));
        $this->assertSame('SN-1', DB::table('purchase_details')->where('id', $did)->value('imei_number'));
    }

    public function test_2_multiple_serials(): void
    {
        $this->lp();
        $p = $this->imeiProduct('S2');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['S2', 3]]), $this->payload('received', [], ['S2' => ['A', 'B', 'C']]));

        $this->assertSame(3, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(3.0, $this->locStock($this->loc, $p));
        $this->assertSame('A,B,C', DB::table('purchase_details')->value('imei_number'));
    }

    public function test_3_10_boxes_of_12_needs_120_serials(): void
    {
        $this->lp();
        $p = $this->imeiProduct('BOX', $this->unit12);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['BOX', 10]]), $this->payload('received', [], ['BOX' => array_map(fn ($i) => "X$i", range(1, 10))]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('120', json_encode($e->errors()));
        }
        $this->assertNoWrites();

        $this->runImport($this->csvFile([['BOX', 10]]), $this->payload('received', [], ['BOX' => array_map(fn ($i) => "X$i", range(1, 120))]));

        $this->assertSame(10.0, (float) DB::table('purchase_details')->value('quantity'));
        $this->assertSame(120, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(120.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->pw());
    }

    public function test_4_operator_divide_integer_base(): void
    {
        $this->lp();
        $p = $this->imeiProduct('DIV1', $this->unitDiv);   // '/' 4 : qty 8 -> base 2
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['DIV1', 8]]), $this->payload('received', [], ['DIV1' => ['D1', 'D2']]));

        $this->assertSame(2, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
    }

    public function test_5_fractional_base_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('DIV2', $this->unitDiv);   // '/' 4 : qty 2 -> base 0.5
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['DIV2', 2]]), $this->payload('received', [], ['DIV2' => ['F1']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('entera', json_encode($e->errors()));
        }
        $this->assertNoWrites();
    }

    public function test_6_mixed_simple_plus_serial(): void
    {
        $this->lp();
        $simple = $this->simpleProduct('MX-S');
        $serial = $this->imeiProduct('MX-I');
        $this->seedLocationStock($this->loc, $simple, 0);
        $this->seedLocationStock($this->loc, $serial, 0);

        $this->runImport($this->csvFile([['MX-S', 3], ['MX-I', 2]]), $this->payload('received', [], ['MX-I' => ['MI-1', 'MI-2']]));

        $this->assertSame(3.0, $this->locStock($this->loc, $simple));
        $this->assertSame(2.0, $this->locStock($this->loc, $serial));
        $this->assertSame(2, $this->serialsBy(['product_id' => $serial, 'status' => ProductSerial::STATUS_AVAILABLE]));
    }

    public function test_7_mixed_simple_batch_serial(): void
    {
        $this->lp();
        $simple = $this->simpleProduct('MXB-S');
        $batch = $this->batchProduct('MXB-B');
        $serial = $this->imeiProduct('MXB-I');
        $this->seedLocationStock($this->loc, $simple, 0);
        $this->seedLocationStock($this->loc, $batch, 0);
        $this->seedLocationStock($this->loc, $serial, 0);

        $this->runImport(
            $this->csvFile([['MXB-S', 3], ['MXB-B', 5], ['MXB-I', 2]]),
            $this->payload('received', ['MXB-B' => [['batch_no' => 'L', 'qty' => 5]]], ['MXB-I' => ['MB-1', 'MB-2']])
        );
        $id = $this->lastPurchaseId();

        $effects = $this->snap($id)['effects'];
        $this->assertCount(3, $effects);
        $withBatch = array_filter($effects, fn ($e) => ! empty($e['batch_allocation']));
        $withSerial = array_filter($effects, fn ($e) => ! empty($e['serial_allocation']));
        $this->assertCount(1, $withBatch);
        $this->assertCount(1, $withSerial);
        $this->assertSame(3.0, $this->locStock($this->loc, $simple));
        $this->assertSame(5.0, $this->locStock($this->loc, $batch));
        $this->assertSame(2.0, $this->locStock($this->loc, $serial));
        $this->assertSame(0, $this->pw());
    }

    public function test_8_multiple_serial_products(): void
    {
        $this->lp();
        $a = $this->imeiProduct('MP-A');
        $b = $this->imeiProduct('MP-B');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);

        $this->runImport($this->csvFile([['MP-A', 2], ['MP-B', 1]]), $this->payload('received', [], [
            'MP-A' => ['PA-1', 'PA-2'],
            'MP-B' => ['PB-1'],
        ]));

        $this->assertSame(2, $this->serialsBy(['product_id' => $a, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(1, $this->serialsBy(['product_id' => $b, 'status' => ProductSerial::STATUS_AVAILABLE]));
    }

    public function test_9_duplicate_serial_same_code_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('DUPS');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        $this->runImport($this->csvFile([['DUPS', 2]]), $this->payload('received', [], ['DUPS' => ['SAME', 'SAME']]));
    }

    public function test_10_duplicate_serial_across_codes_is_422(): void
    {
        $this->lp();
        $a = $this->imeiProduct('DUPA');
        $b = $this->imeiProduct('DUPB');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);

        try {
            $this->runImport($this->csvFile([['DUPA', 1], ['DUPB', 1]]), $this->payload('received', [], [
                'DUPA' => ['SAME-X'], 'DUPB' => ['SAME-X'],
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_11_missing_serials_by_code_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('MISS');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->expectException(ValidationException::class);
        $this->runImport($this->csvFile([['MISS', 2]]), $this->payload('received', [], []));
    }

    public function test_12_malformed_json_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('BADJSON');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['BADJSON', 1]]), $this->payload('received', [], '{not-json'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_12b_flat_list_is_422_not_a_map(): void
    {
        $this->lp();
        $p = $this->imeiProduct('FLATLIST');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['FLATLIST', 1]]), $this->payload('received', [], '["SN-1","SN-2"]'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_13_extra_code_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('EXTRA');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['EXTRA', 1]]), $this->payload('received', [], [
                'EXTRA' => ['E-1'], 'GHOST-CODE' => ['G-1'],
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials_by_code', $e->errors());
            $this->assertStringContainsString('GHOST-CODE', json_encode($e->errors()));
        }
        $this->assertNoWrites();
    }

    public function test_14_serial_allocation_for_non_imei_is_422(): void
    {
        $this->lp();
        $p = $this->simpleProduct('NOTIMEI');
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport($this->csvFile([['NOTIMEI', 1]]), $this->payload('received', [], ['NOTIMEI' => ['X-1']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serials_by_code', $e->errors());
        }
        $this->assertNoWrites();
    }

    public function test_15_variant_plus_imei_is_422(): void
    {
        $this->lp();
        $p = (int) $this->makeProduct(['code' => 'VARIMEI', 'type' => 'is_variant', 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $v = $this->makeVariant($p, 'V1');
        $this->seedLocationStock($this->loc, $p, 0, $v);

        try {
            $this->runImport($this->csvFile([['VARIMEI', 1]]), $this->payload('received', [], ['VARIMEI' => ['VX-1']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('variante', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertNoWrites();
    }

    public function test_16_batch_plus_imei_is_422(): void
    {
        $this->lp();
        $p = (int) $this->makeProduct(['code' => 'BIMEI', 'is_batch_tracked' => true, 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->runImport(
                $this->csvFile([['BIMEI', 1]]),
                $this->payload('received', ['BIMEI' => [['batch_no' => 'L', 'qty' => 1]]], ['BIMEI' => ['BX-1']])
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('lote', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertNoWrites();
    }

    public function test_17_existing_available_serial_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('EXAVAIL');
        $this->seedLocationStock($this->loc, $p, 0);
        DB::table('product_serials')->insert([
            'serial_number' => 'LIVE-1', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_AVAILABLE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->runImport($this->csvFile([['EXAVAIL', 1]]), $this->payload('received', [], ['EXAVAIL' => ['LIVE-1']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_18_existing_sold_serial_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('EXSOLD');
        $this->seedLocationStock($this->loc, $p, 0);
        DB::table('product_serials')->insert([
            'serial_number' => 'SOLD-1', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_SOLD, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->runImport($this->csvFile([['EXSOLD', 1]]), $this->payload('received', [], ['EXSOLD' => ['SOLD-1']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_19_existing_voided_reused_same_id(): void
    {
        $this->lp();
        $p = $this->imeiProduct('VOIDREUSE');
        $this->seedLocationStock($this->loc, $p, 0);
        $vid = (int) DB::table('product_serials')->insertGetId([
            'serial_number' => 'RECYCLE', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_VOIDED, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runImport($this->csvFile([['VOIDREUSE', 1]]), $this->payload('received', [], ['VOIDREUSE' => ['RECYCLE']]));

        $this->assertSame($vid, (int) $this->serialRow('RECYCLE')->id, 'same product_serial_id reused');
        $this->assertSame(1, DB::table('product_serials')->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RECYCLE')->status);
    }

    public function test_20_coverage_drift_is_422(): void
    {
        $this->lp();
        $p = $this->imeiProduct('DRIFT');
        // general 5 but only 4 available serials => not serial-ready.
        $this->seedLocationStock($this->loc, $p, 5);
        for ($i = 1; $i <= 4; $i++) {
            DB::table('product_serials')->insert([
                'serial_number' => "DR-$i", 'product_id' => $p, 'warehouse_id' => $this->wh,
                'inventory_location_id' => $this->loc, 'status' => ProductSerial::STATUS_AVAILABLE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $this->runImport($this->csvFile([['DRIFT', 2]]), $this->payload('received', [], ['DRIFT' => ['DR-NEW-1', 'DR-NEW-2']]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertNull($this->serialRow('DR-NEW-1'), 'placeholder rolled back');
    }

    public function test_21_product_warehouse_unchanged(): void
    {
        $this->lp();
        $p = $this->imeiProduct('PWX');
        $this->seedStock($this->wh, $p, 50);
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PWX', 2]]), $this->payload('received', [], ['PWX' => ['PW-1', 'PW-2']]));

        $this->assertSame(50.0, $this->stockOf($this->wh, $p), 'unchanged');
    }

    public function test_22_imei_number_persisted(): void
    {
        $this->lp();
        $p = $this->imeiProduct('TXT');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['TXT', 2]]), $this->payload('received', [], ['TXT' => ['T-1', 'T-2']]));

        $this->assertSame('T-1,T-2', DB::table('purchase_details')->value('imei_number'));
    }

    public function test_23_snapshot_exact(): void
    {
        $this->lp();
        $p = $this->imeiProduct('SNAP');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['SNAP', 2]]), $this->payload('received', [], ['SNAP' => ['SP-1', 'SP-2']]));
        $id = $this->lastPurchaseId();

        $eff = $this->snap($id)['effects'][0];
        $this->assertSame(1, (int) $this->snap($id)['revision']);
        $this->assertSame(2.0, (float) $eff['quantity_base']);
        $this->assertCount(2, $eff['serial_allocation']);
        $this->assertSame(['SP-1', 'SP-2'], array_column($eff['serial_allocation'], 'serial_number'));
    }

    public function test_24_movement_refs_and_keys(): void
    {
        $this->lp();
        $p = $this->imeiProduct('MOVKEY');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['MOVKEY', 1]]), $this->payload('received', [], ['MOVKEY' => ['MK-1']]));
        $id = $this->lastPurchaseId();
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');

        $mv = DB::table('product_serial_movements')->where('serial_number', 'MK-1')->first();
        $this->assertSame(ProductSerialMovement::ACTION_PURCHASED, $mv->action);
        $this->assertSame('Purchase', $mv->reference_type);
        $this->assertSame($id, (int) $mv->reference_id);
        $this->assertStringContainsString("purchase:{$id}:rev:1:detail:{$did}:s:0:apply", (string) $mv->idempotency_key);
    }

    // =====================================================================
    // PENDING
    // =====================================================================

    public function test_25_pending_serial_product_creates_no_artifact(): void
    {
        $this->lp();
        $p = $this->imeiProduct('PEND1');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PEND1', 2]]), $this->payload('pending', [], []));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_26_pending_serials_by_code_still_no_physical_artifact(): void
    {
        $this->lp();
        $p = $this->imeiProduct('PEND2');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->runImport($this->csvFile([['PEND2', 2]]), $this->payload('pending', [], ['PEND2' => ['PP-1', 'PP-2']]));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame('PP-1,PP-2', DB::table('purchase_details')->value('imei_number'), 'text compatibility kept');
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_27_pending_batch_plus_serial_payload_no_physical_artifact(): void
    {
        $this->lp();
        $batch = $this->batchProduct('PENDB');
        $serial = $this->imeiProduct('PENDS');
        $this->seedLocationStock($this->loc, $batch, 0);
        $this->seedLocationStock($this->loc, $serial, 0);

        $this->runImport(
            $this->csvFile([['PENDB', 5], ['PENDS', 2]]),
            $this->payload('pending', ['PENDB' => [['batch_no' => 'L', 'qty' => 5]]], ['PENDS' => ['PS-1', 'PS-2']])
        );

        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0.0, $this->locStock($this->loc, $batch));
        $this->assertSame(0.0, $this->locStock($this->loc, $serial));
    }

    // =====================================================================
    // ATOMICITY
    // =====================================================================

    public function test_28_valid_first_row_invalid_later_row_zero_writes(): void
    {
        $this->lp();
        $good = $this->imeiProduct('ATOM-GOOD');
        $bad = $this->imeiProduct('ATOM-BAD');
        $this->seedLocationStock($this->loc, $good, 0);
        $this->seedLocationStock($this->loc, $bad, 0);

        try {
            $this->runImport(
                $this->csvFile([['ATOM-GOOD', 1], ['ATOM-BAD', 1]]),
                $this->payload('received', [], ['ATOM-GOOD' => ['AG-1'], 'ATOM-BAD' => []])
            );
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertNoWrites();
        $this->assertNull($this->serialRow('AG-1'));
    }

    public function test_29_batch_valid_serial_invalid_zero_writes(): void
    {
        $this->lp();
        $batch = $this->batchProduct('ATOMB');
        $serial = $this->imeiProduct('ATOMS');
        $this->seedLocationStock($this->loc, $batch, 0);
        $this->seedLocationStock($this->loc, $serial, 0);

        try {
            $this->runImport(
                $this->csvFile([['ATOMB', 5], ['ATOMS', 2]]),
                $this->payload('received', ['ATOMB' => [['batch_no' => 'L', 'qty' => 5]]], ['ATOMS' => ['ONLY-ONE']])
            );
            $this->fail('expected ValidationException (needs 2 serials, got 1)');
        } catch (ValidationException $e) {
        }
        $this->assertNoWrites();
        $this->assertSame(0, DB::table('product_batches')->count());
    }

    public function test_30_serial_placeholders_and_later_general_failure_zero_residue(): void
    {
        $this->lp();
        $p = $this->imeiProduct('RESID');
        $this->seedLocationStock($this->loc, $p, 0);
        // Pre-conflict the general movement key the import will use (id 1, rev 1, effect 0).
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => $p, 'quantity' => 999,
            'reference_type' => 'Purchase', 'reference_id' => '1',
            'idempotency_key' => 'purchase:1:rev:1:effect:0:apply',
            'idempotency_fingerprint' => 'CONFLICT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->runImport($this->csvFile([['RESID', 1]]), $this->payload('received', [], ['RESID' => ['RS-1']]));
            $this->fail('expected ValidationException from the general phase');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertNull($this->serialRow('RS-1'), 'placeholder rolled back — zero residue');
        $this->assertSame(1, DB::table('inventory_location_movements')->count(), 'only the pre-seeded conflict row');
    }

    public function test_31_multiple_serial_products_all_serial_precedes_general(): void
    {
        $this->lp();
        $a = $this->imeiProduct('ORD-A');
        $b = $this->imeiProduct('ORD-B');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);

        $this->runImport($this->csvFile([['ORD-A', 1], ['ORD-B', 1]]), $this->payload('received', [], [
            'ORD-A' => ['OA-1'], 'ORD-B' => ['OB-1'],
        ]));

        // both serials available BEFORE we even look at timestamps — the point
        // is that the whole document (both serial products) completed as ONE
        // batch->serial->general run, not interleaved per-line.
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('OA-1')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('OB-1')->status);
        $this->assertSame(2, DB::table('product_serial_movements')->where('action', 'purchased')->count());
        $this->assertSame(2, $this->movementCount('Purchase'));
    }

    // =====================================================================
    // INTEROP
    // =====================================================================

    public function test_32_interop_import_then_ms6b1_destroy(): void
    {
        $this->lp();
        $p = $this->imeiProduct('IOP-DESTROY');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->runImport($this->csvFile([['IOP-DESTROY', 1]]), $this->payload('received', [], ['IOP-DESTROY' => ['ID-1']]));
        $id = $this->lastPurchaseId();

        // MS6-B1 manual destroy on an import-created Purchase.
        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('ID-1')->status);
        $this->assertNull($this->serialRow('ID-1')->inventory_location_id);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));
    }

    public function test_33_interop_import_then_ms6b1_update_rev2(): void
    {
        $this->lp();
        $p = $this->imeiProduct('IOP-UPDATE');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->runImport($this->csvFile([['IOP-UPDATE', 1]]), $this->payload('received', [], ['IOP-UPDATE' => ['IU-1']]));
        $id = $this->lastPurchaseId();
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');

        // MS6-B1 manual update: received -> received, new serial selection.
        $this->controller()->update($this->makeRequest([
            'supplier_id' => 7, 'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'date' => '2026-09-13', 'statut' => 'received', 'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 10,
            'details' => [[
                'id' => $did, 'product_id' => $p, 'product_variant_id' => null, 'purchase_unit_id' => $this->unit1,
                'quantity' => 1, 'Unit_cost' => 2, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0,
                'discount_Method' => '2', 'subtotal' => 2, 'no_unit' => 1, 'serial_numbers' => ['IU-2'],
            ]],
        ]), $id);

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('IU-1')->status, 'old serial voided by the reverse');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('IU-2')->status, 'new serial available');
        $this->assertSame(2, (int) $this->snap($id)['revision']);
    }

    public function test_34_interop_import_then_pos_b1_sell(): void
    {
        $this->lp();
        $p = $this->imeiProduct('IOP-POS');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->runImport($this->csvFile([['IOP-POS', 1]]), $this->payload('received', [], ['IOP-POS' => ['IP-1']]));

        // POS B1 resolves an available serial by (product, variant NULL,
        // location, status). The import-created row satisfies that shape.
        $row = $this->serialRow('IP-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertNull($row->product_variant_id);
        $this->assertSame($this->loc, (int) $row->inventory_location_id);
        $this->assertSame($p, (int) $row->product_id);
    }

    public function test_35_interop_import_then_ms6b2_purchase_return(): void
    {
        $this->lp();
        $p = $this->imeiProduct('IOP-RETURN');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->runImport($this->csvFile([['IOP-RETURN', 1]]), $this->payload('received', [], ['IOP-RETURN' => ['IR-1']]));
        $purchaseId = $this->lastPurchaseId();

        // MS6-B2 manual PurchaseReturn linked to the import-created Purchase.
        $returnController = new \App\Http\Controllers\PurchasesReturnController;
        $returnController->store($this->makeRequest([
            'supplier_id' => 7, 'purchase_id' => $purchaseId, 'warehouse_id' => $this->wh,
            'inventory_location_id' => $this->loc, 'date' => '2026-09-13', 'statut' => 'completed',
            'notes' => null, 'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0, 'GrandTotal' => 2,
            'details' => [[
                'product_id' => $p, 'product_variant_id' => null, 'purchase_unit_id' => $this->unit1,
                'quantity' => 1, 'Unit_cost' => 2, 'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0,
                'discount_Method' => '2', 'subtotal' => 2, 'no_unit' => 1, 'serial_numbers' => ['IR-1'],
            ]],
        ]));

        $row = $this->serialRow('IR-1');
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
        $this->assertSame($purchaseId, (int) $row->purchase_id, 'provenance preserved');
    }

    public function test_36_imei_number_corruption_does_not_affect_snapshot_reverse(): void
    {
        $this->lp();
        $p = $this->imeiProduct('IOP-CORRUPT');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->runImport($this->csvFile([['IOP-CORRUPT', 1]]), $this->payload('received', [], ['IOP-CORRUPT' => ['IC-1']]));
        $id = $this->lastPurchaseId();
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');
        DB::table('purchase_details')->where('id', $did)->update(['imei_number' => 'GARBAGE,NONSENSE']);

        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('IC-1')->status);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }
}
