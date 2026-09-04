<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B1 — serial / IMEI location-native ACTIVATED in PurchasesController for
 * MANUAL purchases (store / update / destroy / delete_by_selection).
 *
 * Contract vs the legacy golden master (PurchaseSerialLegacyGoldenMasterTest):
 * NATIVE requires count(serials) == quantity_BASE (10 boxes x12 => 120
 * serials); the reverse drops a serial to `voided` (never hard-delete); the
 * `inventory_effect_snapshot` is the only physical authority.
 *
 * Modeled on PurchasesBatchLocationNativeTest (MS5-C).
 */
class PurchasesSerialLocationNativeTest extends TestCase
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

        $this->wh = $this->makeWarehouse('CD-SERIAL');
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
        $this->unitDiv = $this->makeUnit('/', 4);
        $this->loc = $this->makeInventoryLocation($this->wh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);
    }

    /** Local copy — the batch+IMEI 422 fence + the mixed-document case need it. */
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

    private function lp(string $status = 'healthy', ?int $wh = null): void
    {
        $this->setTransitionMode($wh ?? $this->wh, Mode::MODE_LOCATION_PRIMARY, $this->loc, $status);
    }

    private function imei(string $code, ?int $unit = null, string $type = 'is_single'): int
    {
        return (int) $this->makeProduct([
            'code' => $code, 'type' => $type, 'is_imei' => 1,
            'unit_purchase_id' => $unit ?? $this->unit1, 'cost' => 2,
        ]);
    }

    /** @param  array<int,string>|string|null  $serials */
    private function line(int $productId, int $unitId, float $qty, $serials = null, ?int $variantId = null, array $batches = []): array
    {
        $row = [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $unitId,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
        ];
        if ($serials !== null) {
            $row['serial_numbers'] = $serials;
        }
        if ($batches) {
            $row['batches'] = $batches;
        }

        return $row;
    }

    private function payload(array $details, string $statut = 'received', $wh = null, $loc = 'DEFAULT'): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $wh ?? $this->wh,
            'inventory_location_id' => $loc === 'DEFAULT' ? $this->loc : $loc,
            'date' => '2026-09-12',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function store(array $payload): void
    {
        $this->controller()->store($this->makeRequest($payload));
    }

    private function doUpdate(int $id, array $payload): void
    {
        $this->controller()->update($this->makeRequest($payload), $id);
    }

    private function doDestroy(int $id): void
    {
        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $id);
    }

    private function lastPurchaseId(): int
    {
        return (int) DB::table('purchases')->orderByDesc('id')->value('id');
    }

    /** Store a single-line RECEIVED native purchase; return its id. */
    private function received(int $productId, float $qty, array $serials, ?int $variantId = null): int
    {
        $this->store($this->payload([$this->line($productId, $this->unit1, $qty, $serials, $variantId)]));

        return $this->lastPurchaseId();
    }

    private function snap(int $id): array
    {
        return json_decode((string) DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'), true) ?: [];
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

    // =====================================================================
    // STORE — RECEIVED
    // =====================================================================

    public function test_store_received_single_imei(): void
    {
        $this->lp();
        $p = $this->imei('S1');
        $this->seedLocationStock($this->loc, $p, 0);

        $id = $this->received($p, 1, ['SN-1']);
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');

        $row = $this->serialRow('SN-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id);
        $this->assertSame($this->wh, (int) $row->warehouse_id);
        $this->assertSame($p, (int) $row->product_id);
        $this->assertSame($id, (int) $row->purchase_id);
        $this->assertSame($did, (int) $row->purchase_detail_id);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));
        $this->assertSame(0, $this->pw(), 'product_warehouse untouched');

        $mv = DB::table('product_serial_movements')->where('serial_number', 'SN-1')->first();
        $this->assertSame(ProductSerialMovement::ACTION_PURCHASED, $mv->action);
        $this->assertStringContainsString('purchase:'.$id.':rev:1:detail:'.$did.':s:0:apply', (string) $mv->idempotency_key);

        $eff = $this->snap($id)['effects'][0];
        $this->assertCount(1, $eff['serial_allocation']);
        $this->assertSame('SN-1', $eff['serial_allocation'][0]['serial_number']);
        $this->assertSame((int) $row->id, $eff['serial_allocation'][0]['product_serial_id']);
        $this->assertSame(1.0, (float) $eff['quantity_base']);
    }

    public function test_store_received_multiple_serials(): void
    {
        $this->lp();
        $p = $this->imei('S2');
        $this->seedLocationStock($this->loc, $p, 0);

        $id = $this->received($p, 3, ['A', 'B', 'C']);

        $this->assertSame(3, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(3.0, $this->locStock($this->loc, $p));
        $this->assertSame(3, DB::table('product_serial_movements')->where('action', 'purchased')->count());
        $this->assertCount(3, $this->snap($id)['effects'][0]['serial_allocation']);
    }

    public function test_store_10_boxes_of_12_needs_120_serials(): void
    {
        $this->lp();
        $p = $this->imei('BOX', $this->unit12);
        $this->seedStock($this->wh, $p, 0);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->store($this->payload([$this->line($p, $this->unit12, 10, array_map(fn ($i) => 'X'.$i, range(1, 10)))]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('120', json_encode($e->errors()));
        }
        $this->assertSame(0, DB::table('purchases')->count());

        $this->store($this->payload([$this->line($p, $this->unit12, 10, array_map(fn ($i) => 'X'.$i, range(1, 120)))]));
        $id = $this->lastPurchaseId();

        $this->assertSame(10.0, (float) DB::table('purchase_details')->value('quantity'));
        $this->assertSame(120, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(120.0, (float) $this->snap($id)['effects'][0]['quantity_base']);
        $this->assertCount(120, $this->snap($id)['effects'][0]['serial_allocation']);
        $this->assertSame(120.0, $this->locStock($this->loc, $p));
        $this->assertSame(0.0, $this->stockOf($this->wh, $p), 'product_warehouse unchanged');
    }

    public function test_store_operator_divide_integer_base(): void
    {
        $this->lp();
        $p = $this->imei('DIV1', $this->unitDiv);   // '/' 4 : qty 8 -> base 2
        $this->seedLocationStock($this->loc, $p, 0);

        $this->store($this->payload([$this->line($p, $this->unitDiv, 8, ['D1', 'D2'])]));

        $this->assertSame(2, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
    }

    public function test_store_fractional_base_is_422(): void
    {
        $this->lp();
        $p = $this->imei('DIV2', $this->unitDiv);   // '/' 4 : qty 2 -> base 0.5
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->store($this->payload([$this->line($p, $this->unitDiv, 2, ['F1'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('entera', json_encode($e->errors()));
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('product_serials')->count());
    }

    public function test_store_variant_plus_imei(): void
    {
        $this->lp();
        $p = $this->imei('VAR', $this->unit1, 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $this->seedLocationStock($this->loc, $p, 0, $v);

        $id = $this->received($p, 2, ['VV-1', 'VV-2'], $v);

        foreach (['VV-1', 'VV-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame($v, (int) $row->product_variant_id);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($this->loc, (int) $row->inventory_location_id);
        }
        $this->assertSame(2.0, $this->locStock($this->loc, $p, $v));
        $this->assertSame($v, (int) $this->snap($id)['effects'][0]['product_variant_id']);
    }

    public function test_store_batch_plus_imei_is_422_no_writes(): void
    {
        $this->lp();
        $p = (int) $this->makeProduct([
            'code' => 'BI', 'is_imei' => 1, 'is_batch_tracked' => true,
            'unit_purchase_id' => $this->unit1, 'cost' => 2,
        ]);
        $this->seedLocationStock($this->loc, $p, 0);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['BI-1'], null, [['batch_no' => 'L', 'qty' => 1]])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // batch + serial on the same product is not supported.
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0, DB::table('product_batches')->count());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(0, $this->serialMovAll());
    }

    public function test_store_duplicate_serial_same_line_and_cross_line(): void
    {
        $this->lp();
        $a = $this->imei('DL-A');
        $b = $this->imei('DL-B');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);

        try {
            $this->store($this->payload([$this->line($a, $this->unit1, 2, ['SAME', 'SAME'])]));
            $this->fail('same-line dup');
        } catch (ValidationException $e) {
        }
        try {
            $this->store($this->payload([
                $this->line($a, $this->unit1, 1, ['DUP']),
                $this->line($b, $this->unit1, 1, ['DUP']),
            ]));
            $this->fail('cross-line dup');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('product_serials')->count());
    }

    public function test_store_existing_active_serial_is_422(): void
    {
        $this->lp();
        $p = $this->imei('EX');
        $this->seedLocationStock($this->loc, $p, 0);

        foreach ([ProductSerial::STATUS_AVAILABLE, ProductSerial::STATUS_SOLD] as $st) {
            DB::table('product_serials')->insert([
                'serial_number' => 'LIVE-'.$st, 'product_id' => $p, 'warehouse_id' => $this->wh,
                'status' => $st, 'created_at' => now(), 'updated_at' => now(),
            ]);
            try {
                $this->store($this->payload([$this->line($p, $this->unit1, 1, ['LIVE-'.$st])]));
                $this->fail('expected 422 for '.$st);
            } catch (ValidationException $e) {
            }
        }
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_store_reuses_existing_voided_serial_same_id(): void
    {
        $this->lp();
        $p = $this->imei('RC');
        $this->seedLocationStock($this->loc, $p, 0);
        $vid = (int) DB::table('product_serials')->insertGetId([
            'serial_number' => 'RECYCLE', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_VOIDED, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->received($p, 1, ['RECYCLE']);

        $this->assertSame($vid, (int) $this->serialRow('RECYCLE')->id, 'same product_serial_id reused');
        $this->assertSame(1, DB::table('product_serials')->count(), 'no new row');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RECYCLE')->status);
    }

    public function test_store_coverage_drift_is_422_serial_transition(): void
    {
        $this->lp();
        $p = $this->imei('DRIFT');
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
            $this->store($this->payload([$this->line($p, $this->unit1, 2, ['DR-NEW-1', 'DR-NEW-2'])]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_transition', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertNull($this->serialRow('DR-NEW-1'), 'placeholder rolled back');
    }

    public function test_store_healthy_topup_is_allowed(): void
    {
        $this->lp();
        $p = $this->imei('TOPUP');
        $this->seedLocationStock($this->loc, $p, 5);
        for ($i = 1; $i <= 5; $i++) {
            DB::table('product_serials')->insert([
                'serial_number' => "TU-$i", 'product_id' => $p, 'warehouse_id' => $this->wh,
                'inventory_location_id' => $this->loc, 'status' => ProductSerial::STATUS_AVAILABLE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->received($p, 2, ['TU-6', 'TU-7']);

        $this->assertSame(7, $this->serialsBy(['product_id' => $p, 'status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(7.0, $this->locStock($this->loc, $p));
    }

    public function test_store_late_dup_rolls_back_everything(): void
    {
        $this->lp();
        $simple = (int) $this->makeProduct(['code' => 'LS', 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $s1 = $this->imei('LI1');
        $s2 = $this->imei('LI2');
        foreach ([$simple, $s1, $s2] as $x) {
            $this->seedLocationStock($this->loc, $x, 0);
        }

        try {
            $this->store($this->payload([
                $this->line($simple, $this->unit1, 3),
                $this->line($s1, $this->unit1, 1, ['GOOD']),
                $this->line($s2, $this->unit1, 1, ['GOOD']),   // document-wide dup on the LAST line
            ]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_details')->count());
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0, $this->movementCount());
    }

    public function test_store_general_failure_after_serial_rolls_back(): void
    {
        $this->lp();
        $p = $this->imei('GF');
        $this->seedLocationStock($this->loc, $p, 0);
        // Pre-conflict the general movement key the receipt will use (id 1, rev 1, effect 0).
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase', 'product_id' => $p, 'quantity' => 999,
            'reference_type' => 'Purchase', 'reference_id' => '1',
            'idempotency_key' => 'purchase:1:rev:1:effect:0:apply',
            'idempotency_fingerprint' => 'CONFLICT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->store($this->payload([$this->line($p, $this->unit1, 1, ['GF-1'])]));
            $this->fail('expected ValidationException from the general phase');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertNull($this->serialRow('GF-1'), 'placeholder + apply rolled back');
        $this->assertSame(1, DB::table('inventory_location_movements')->count(), 'only the pre-seeded conflict row');
    }

    public function test_store_mixed_document_simple_batch_serial_variant(): void
    {
        $this->lp();
        $simple = (int) $this->makeProduct(['code' => 'MX-S', 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $batch = (int) $this->makeProduct(['code' => 'MX-B', 'is_batch_tracked' => true, 'unit_purchase_id' => $this->unit1, 'cost' => 1]);
        $serial = $this->imei('MX-I');
        $vserial = $this->imei('MX-V', $this->unit1, 'is_variant');
        $v = $this->makeVariant($vserial, 'V1');
        foreach ([$simple, $batch, $serial] as $x) {
            $this->seedLocationStock($this->loc, $x, 0);
        }
        $this->seedLocationStock($this->loc, $vserial, 0, $v);

        $this->store($this->payload([
            $this->line($simple, $this->unit1, 3),
            $this->line($batch, $this->unit1, 5, null, null, [['batch_no' => 'L', 'qty' => 5]]),
            $this->line($serial, $this->unit1, 2, ['MI-1', 'MI-2']),
            $this->line($vserial, $this->unit1, 1, ['MV-1'], $v),
        ]));
        $id = $this->lastPurchaseId();

        $effects = $this->snap($id)['effects'];
        $this->assertCount(4, $effects);
        $withBatch = array_filter($effects, fn ($e) => ! empty($e['batch_allocation']));
        $withSerial = array_filter($effects, fn ($e) => ! empty($e['serial_allocation']));
        $this->assertCount(1, $withBatch);
        $this->assertCount(2, $withSerial);
        foreach ($effects as $e) {
            $this->assertFalse(! empty($e['batch_allocation']) && ! empty($e['serial_allocation']), 'no effect carries both');
        }
        $this->assertSame(3.0, $this->locStock($this->loc, $simple));
        $this->assertSame(5.0, $this->locStock($this->loc, $batch));
        $this->assertSame(2.0, $this->locStock($this->loc, $serial));
        $this->assertSame(1.0, $this->locStock($this->loc, $vserial, $v));
        $this->assertSame(0, $this->pw(), 'product_warehouse untouched for the whole document');
    }

    // =====================================================================
    // STORE — PENDING
    // =====================================================================

    public function test_pending_creates_no_serial_artifacts(): void
    {
        $this->lp();
        $p = $this->imei('P1');
        $this->seedLocationStock($this->loc, $p, 0);

        $this->store($this->payload([$this->line($p, $this->unit1, 2, ['P-1', 'P-2'])], 'pending'));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertNull(DB::table('purchases')->value('inventory_effect_snapshot'));
        $this->assertSame('P-1,P-2', DB::table('purchase_details')->value('imei_number'), 'text metadata kept');
        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0, $this->serialMovAll());
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    // =====================================================================
    // UPDATE — state machine
    // =====================================================================

    public function test_update_pending_to_received_rev1(): void
    {
        $this->lp();
        $p = $this->imei('U-P2R');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->store($this->payload([$this->line($p, $this->unit1, 2, ['PR-1', 'PR-2'])], 'pending'));
        $id = $this->lastPurchaseId();
        $this->assertSame(0, DB::table('product_serials')->count());

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 2, ['PR-1', 'PR-2'])], 'received'));

        $this->assertSame(2, $this->serialsBy(['status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(1, (int) $this->snap($id)['revision']);
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
    }

    public function test_update_received_to_received_same_serials_preserve_ids(): void
    {
        $this->lp();
        $p = $this->imei('U-SAME');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 2, ['SA', 'SB']);
        $ids = DB::table('product_serials')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 2, ['SA', 'SB'])], 'received'));

        $newIds = DB::table('product_serials')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame($ids, $newIds, 'SAME product_serial_id — no identity churn');
        $this->assertSame(2, $this->serialsBy(['status' => ProductSerial::STATUS_AVAILABLE]));
        $this->assertSame(2, (int) $this->snap($id)['revision']);
        $this->assertGreaterThanOrEqual(3, DB::table('product_serial_movements')->where('serial_number', 'SA')->count());
    }

    public function test_update_received_to_received_changed_serial_set(): void
    {
        $this->lp();
        $p = $this->imei('U-CH');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 2, ['C1', 'C2']);

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 2, ['C3', 'C4'])], 'received'));

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('C1')->status);
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('C2')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('C3')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('C4')->status);
        $this->assertNotNull($this->serialRow('C1'), 'old serials NOT hard-deleted');
        $this->assertSame(2.0, $this->locStock($this->loc, $p));
    }

    public function test_update_received_to_pending_voids_serials_keeps_snapshot(): void
    {
        $this->lp();
        $p = $this->imei('U-R2P');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 2, ['RP1', 'RP2']);

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 2, ['RP1', 'RP2'])], 'pending'));

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('RP1')->status);
        $this->assertNull($this->serialRow('RP1')->inventory_location_id);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        // D — the historical snapshot is KEPT (revision preserved, not bumped)
        // so a later pending->received continues the revision sequence.
        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'));
        $this->assertSame(1, (int) $this->snap($id)['revision']);
    }

    public function test_update_revision_progression(): void
    {
        $this->lp();
        $p = $this->imei('U-REV');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'pending'));
        $id = $this->lastPurchaseId();
        $this->assertNull(DB::table('purchases')->where('id', $id)->value('inventory_effect_snapshot'));

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'received'));
        $this->assertSame(1, (int) $this->snap($id)['revision']);

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'received'));
        $this->assertSame(2, (int) $this->snap($id)['revision']);

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'pending'));
        $this->assertSame(2, (int) $this->snap($id)['revision'], 'preserved on received->pending');

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['RV'])], 'received'));
        $this->assertSame(3, (int) $this->snap($id)['revision']);
    }

    public function test_update_change_location_reverses_old_and_reapplies(): void
    {
        $this->lp();
        $locB = $this->makeInventoryLocation($this->wh, ['code' => 'LB']);
        $p = $this->imei('U-LOC');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->seedLocationStock($locB, $p, 0);
        $id = $this->received($p, 1, ['LX']);
        $this->assertSame($this->loc, (int) $this->serialRow('LX')->inventory_location_id);

        $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['LX'])], 'received', null, $locB));

        $row = $this->serialRow('LX');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($locB, (int) $row->inventory_location_id, 'moved via reverse + re-receive');
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertSame(1.0, $this->locStock($locB, $p));
    }

    public function test_update_primary_to_legacy_is_422(): void
    {
        $this->lp();
        $p = $this->imei('U-TG');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 1, ['TG-1']);

        $this->setTransitionMode($this->wh, Mode::MODE_LEGACY_ONLY, null, 'healthy');
        try {
            $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['TG-1'])], 'received'));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_transition', $e->errors());
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('TG-1')->status);
    }

    // =====================================================================
    // DOWNSTREAM GUARDS
    // =====================================================================

    /** @dataProvider downstreamStatuses */
    public function test_downstream_serial_blocks_update_and_destroy(string $status): void
    {
        $this->lp();
        $p = $this->imei('DS-'.$status);
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 1, ['SN-DS']);
        $this->assertSame(1.0, $this->locStock($this->loc, $p));

        DB::table('product_serials')->where('serial_number', 'SN-DS')->update(['status' => $status]);

        try {
            $this->doUpdate($id, $this->payload([$this->line($p, $this->unit1, 1, ['SN-DS'])], 'received'));
            $this->fail('expected update 422');
        } catch (ValidationException $e) {
        }
        try {
            $this->doDestroy($id);
            $this->fail('expected destroy 422');
        } catch (ValidationException $e) {
        }

        $this->assertNull(DB::table('purchases')->where('id', $id)->value('deleted_at'), 'purchase not deleted');
        $this->assertSame($status, $this->serialRow('SN-DS')->status, 'serial untouched');
        $this->assertSame(1.0, $this->locStock($this->loc, $p), 'general not reverted');
    }

    public static function downstreamStatuses(): array
    {
        return [
            'sold' => [ProductSerial::STATUS_SOLD],
            'reserved' => [ProductSerial::STATUS_RESERVED],
            'damaged' => [ProductSerial::STATUS_DAMAGED],
            'returned_supplier' => [ProductSerial::STATUS_RETURNED_SUPPLIER],
        ];
    }

    public function test_downstream_moved_location_blocks_reverse(): void
    {
        $this->lp();
        $locB = $this->makeInventoryLocation($this->wh, ['code' => 'MB']);
        $p = $this->imei('DM');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 1, ['MOVED']);

        DB::table('product_serials')->where('serial_number', 'MOVED')->update(['inventory_location_id' => $locB]);

        $this->expectException(ValidationException::class);
        $this->doDestroy($id);
    }

    // =====================================================================
    // SNAPSHOT AUTHORITY
    // =====================================================================

    public function test_reverse_uses_snapshot_not_imei_number(): void
    {
        $this->lp();
        $p = $this->imei('AUTH');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 2, ['AU-1', 'AU-2']);
        $did = (int) DB::table('purchase_details')->where('purchase_id', $id)->value('id');

        DB::table('purchase_details')->where('id', $did)->update(['imei_number' => 'GARBAGE,NONSENSE,EXTRA']);

        $this->doDestroy($id);

        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('AU-1')->status);
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('AU-2')->status);
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
    }

    public function test_reverse_fails_closed_on_corrupt_snapshot_serial_number(): void
    {
        $this->lp();
        $p = $this->imei('CORR');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 1, ['REAL']);

        $snap = $this->snap($id);
        $snap['effects'][0]['serial_allocation'][0]['serial_number'] = 'DIFFERENT';
        DB::table('purchases')->where('id', $id)->update(['inventory_effect_snapshot' => json_encode($snap)]);

        $this->expectException(ValidationException::class);
        $this->doDestroy($id);
    }

    // =====================================================================
    // DESTROY
    // =====================================================================

    public function test_destroy_received_voids_serials_and_reverses_general(): void
    {
        $this->lp();
        $p = $this->imei('DEL');
        $this->seedLocationStock($this->loc, $p, 0);
        $id = $this->received($p, 2, ['DL-1', 'DL-2']);
        $this->assertSame(2.0, $this->locStock($this->loc, $p));

        $this->doDestroy($id);

        foreach (['DL-1', 'DL-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_VOIDED, $row->status);
            $this->assertNull($row->inventory_location_id);
        }
        $this->assertSame(0.0, $this->locStock($this->loc, $p));
        $this->assertNotNull(DB::table('purchases')->where('id', $id)->value('deleted_at'));
        $this->assertGreaterThanOrEqual(2, $this->serialMovAll(), 'movements PRESERVED');
    }

    public function test_destroy_pending_no_serial_effect(): void
    {
        $this->lp();
        $p = $this->imei('DELP');
        $this->seedLocationStock($this->loc, $p, 0);
        $this->store($this->payload([$this->line($p, $this->unit1, 1, ['DP-1'])], 'pending'));
        $id = $this->lastPurchaseId();

        $this->doDestroy($id);

        $this->assertSame(0, DB::table('product_serials')->count());
        $this->assertSame(0, $this->serialMovAll());
    }

    // =====================================================================
    // BULK — delete_by_selection
    // =====================================================================

    public function test_bulk_delete_native_serials_success(): void
    {
        $this->lp();
        $a = $this->imei('OKA');
        $b = $this->imei('OKB');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);
        $idA = $this->received($a, 1, ['OKA-1']);
        $idB = $this->received($b, 1, ['OKB-1']);

        $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$idA, $idB]]));

        $this->assertNotNull(DB::table('purchases')->where('id', $idA)->value('deleted_at'));
        $this->assertNotNull(DB::table('purchases')->where('id', $idB)->value('deleted_at'));
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('OKA-1')->status);
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('OKB-1')->status);
        $this->assertSame(0.0, $this->locStock($this->loc, $a));
    }

    public function test_bulk_delete_aborts_all_when_one_serial_moved_downstream(): void
    {
        $this->lp();
        $a = $this->imei('BK-A');
        $b = $this->imei('BK-B');
        $this->seedLocationStock($this->loc, $a, 0);
        $this->seedLocationStock($this->loc, $b, 0);
        $idA = $this->received($a, 1, ['BKA-1']);
        $idB = $this->received($b, 1, ['BKB-1']);

        DB::table('product_serials')->where('serial_number', 'BKB-1')->update(['status' => ProductSerial::STATUS_SOLD]);

        try {
            $this->controller()->delete_by_selection($this->makeRequest(['selectedIds' => [$idA, $idB]]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
        }

        $this->assertNull(DB::table('purchases')->where('id', $idA)->value('deleted_at'));
        $this->assertNull(DB::table('purchases')->where('id', $idB)->value('deleted_at'));
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('BKA-1')->status);
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('BKB-1')->status);
        $this->assertSame(1.0, $this->locStock($this->loc, $a));
    }

    // =====================================================================
    // INTEROP — POS B1 can resolve a natively-received serial
    // =====================================================================

    public function test_interop_variant_imei_snapshot_matches_pos_b1_resolution_shape(): void
    {
        $this->lp();
        $p = $this->imei('IOP', $this->unit1, 'is_variant');
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->seedLocationStock($this->loc, $p, 0, $v1);
        $this->seedLocationStock($this->loc, $p, 0, $v2);

        $this->received($p, 1, ['IOP-V1'], $v1);
        $this->received($p, 1, ['IOP-V2'], $v2);

        // POS B1 preflight resolves an available serial by (product, variant,
        // location, status). Both natively-received serials satisfy that shape
        // with the CORRECT variant.
        $r1 = $this->serialRow('IOP-V1');
        $r2 = $this->serialRow('IOP-V2');
        $this->assertSame($v1, (int) $r1->product_variant_id);
        $this->assertSame($v2, (int) $r2->product_variant_id);
        foreach ([$r1, $r2] as $r) {
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $r->status);
            $this->assertSame($this->loc, (int) $r->inventory_location_id);
            $this->assertSame($p, (int) $r->product_id);
        }
        $this->assertSame(1.0, $this->locStock($this->loc, $p, $v1));
        $this->assertSame(1.0, $this->locStock($this->loc, $p, $v2));
    }
}
