<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailSerial;
use App\Models\TransferReceiptItem;
use App\Services\LocationAwareTransferLogisticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0.1 — behavioural proof that the location-native transfer RECEIVE side
 * runs SERIAL artifacts BEFORE the general InventoryService credit, atomically.
 *
 * Drives LocationAwareTransferLogisticsService::creditGoodStock directly
 * (same pattern as TransferLogisticsBatchUnitsTest). Defective / issue paths
 * are pinned by TransferReceiptLockOrderArchitectureTest.
 */
class LocationAwareTransferReceiptOrderTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $srcWh;
    private int $dstWh;
    private int $srcLoc;
    private int $dstLoc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->buildTransferTables();
        $this->legacyOwner();

        $this->srcWh = $this->makeWarehouse('T-SRC');
        $this->dstWh = $this->makeWarehouse('T-DST');
        $this->srcLoc = $this->makeInventoryLocation($this->srcWh);
        $this->dstLoc = $this->makeInventoryLocation($this->dstWh);
    }

    private function buildTransferTables(): void
    {
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->string('Ref')->nullable();
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->string('statut')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('product_id');
            $t->integer('product_variant_id')->nullable();
            $t->decimal('quantity', 12, 3)->default(0);
            $t->integer('purchase_unit_id')->nullable();
            $t->timestamps();
        });
        Schema::create('transfer_receipt_items', function ($t) {
            $t->increments('id');
            $t->integer('transfer_receipt_id')->nullable();
            $t->integer('transfer_detail_id');
            $t->decimal('quantity_good', 12, 3)->default(0);
            $t->decimal('quantity_defective', 12, 3)->default(0);
            $t->decimal('quantity_missing', 12, 3)->default(0);
            $t->timestamps();
        });
        Schema::create('transfer_detail_serials', function ($t) {
            $t->increments('id');
            $t->integer('transfer_detail_id')->index();
            $t->integer('product_serial_id')->index();
            $t->integer('transfer_receipt_item_id')->nullable()->index();
            $t->string('status', 30)->default('in_transit')->index();
            $t->string('issue_type', 30)->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamps();
            $t->unique(['transfer_detail_id', 'product_serial_id'], 'transfer_detail_serial_unique');
        });
    }

    /** subclass exposing the protected credit method. */
    private function service(): object
    {
        return new class extends LocationAwareTransferLogisticsService {
            public function creditGoodForTest(Transfer $tr, TransferDetail $d, float $q, TransferReceiptItem $it): void
            {
                $this->creditGoodStock($tr, $d, $q, $it);
            }
        };
    }

    private function imeiProduct(): int
    {
        $unit = $this->makeUnit('*', 1);

        return (int) $this->makeProduct(['code' => 'T-IMEI', 'is_imei' => 1, 'unit_purchase_id' => $unit]);
    }

    /**
     * Seed a dispatched transfer of $n IMEI units: pivots in_transit, serials
     * reserved@NULL. Returns [transfer, detail, receiptItem].
     */
    private function seedDispatched(int $productId, int $n): array
    {
        $transfer = new Transfer;
        $transfer->forceFill([
            'Ref' => 'TR-1', 'from_warehouse_id' => $this->srcWh, 'to_warehouse_id' => $this->dstWh,
            'from_inventory_location_id' => $this->srcLoc, 'to_inventory_location_id' => $this->dstLoc,
            'statut' => 'sent',
        ])->save();

        $detail = TransferDetail::create([
            'transfer_id' => $transfer->id, 'product_id' => $productId, 'product_variant_id' => null,
            'quantity' => $n, 'purchase_unit_id' => DB::table('units')->value('id'),
        ]);
        $receiptItem = TransferReceiptItem::create([
            'transfer_receipt_id' => 1, 'transfer_detail_id' => $detail->id,
            'quantity_good' => $n, 'quantity_defective' => 0, 'quantity_missing' => 0,
        ]);

        for ($i = 1; $i <= $n; $i++) {
            $sid = DB::table('product_serials')->insertGetId([
                'serial_number' => "TS-$i", 'product_id' => $productId, 'warehouse_id' => $this->srcWh,
                'inventory_location_id' => null, 'status' => ProductSerial::STATUS_RESERVED,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            TransferDetailSerial::create([
                'transfer_detail_id' => $detail->id, 'product_serial_id' => $sid,
                'status' => TransferDetailSerial::STATUS_IN_TRANSIT,
            ]);
        }

        return [$transfer->fresh(), $detail->fresh(), $receiptItem->fresh()];
    }

    private function dstGeneral(int $productId): float
    {
        return round((float) DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->dstLoc)->where('product_id', $productId)
            ->where('variant_key', 0)->value('quantity'), 3);
    }

    // ===================== A — good: serial before general =====================

    public function test_A_good_receipt_moves_serials_available_at_destination_then_credits_general(): void
    {
        $p = $this->imeiProduct();
        [$transfer, $detail, $item] = $this->seedDispatched($p, 2);

        DB::transaction(fn () => $this->service()->creditGoodForTest($transfer, $detail, 2, $item));

        foreach (['TS-1', 'TS-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($this->dstLoc, (int) $row->inventory_location_id);
            $this->assertSame($this->dstWh, (int) $row->warehouse_id);
        }
        $this->assertSame(2, DB::table('transfer_detail_serials')->where('status', TransferDetailSerial::STATUS_RECEIVED)->count());
        $this->assertSame(2.0, $this->dstGeneral($p));

        // both a serial movement and a general movement exist for this receipt.
        $this->assertSame(2, DB::table('product_serial_movements')->where('reference_type', 'TransferReceipt')->count());
        $this->assertSame(1, DB::table('inventory_location_movements')->where('reference_type', 'TransferReceipt')->count());
    }

    // ===================== C — invalid serial => general untouched =============

    public function test_C_invalid_serial_prestate_422_and_destination_general_stays_zero(): void
    {
        $p = $this->imeiProduct();
        [$transfer, $detail, $item] = $this->seedDispatched($p, 2);

        // Break one serial's pre-state: no longer reserved/in-transit.
        DB::table('product_serials')->where('serial_number', 'TS-1')->update([
            'status' => ProductSerial::STATUS_AVAILABLE, 'inventory_location_id' => $this->dstLoc,
        ]);

        $this->assertSame(0.0, $this->dstGeneral($p));
        try {
            DB::transaction(fn () => $this->service()->creditGoodForTest($transfer, $detail, 2, $item));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transfer', $e->errors());
        }

        // Serial error was discovered BEFORE any general credit.
        $this->assertSame(0.0, $this->dstGeneral($p), 'destination general stock still 0');
        $this->assertSame(0, DB::table('inventory_location_movements')->where('reference_type', 'TransferReceipt')->count());
        $this->assertSame(0, DB::table('product_serial_movements')->where('reference_type', 'TransferReceipt')->count());
        $this->assertSame(2, DB::table('transfer_detail_serials')->where('status', TransferDetailSerial::STATUS_IN_TRANSIT)->count());
    }

    // ===================== D — serial ok, general fails => full rollback =======

    public function test_D_serial_succeeds_then_general_fails_whole_transaction_rolls_back(): void
    {
        $p = $this->imeiProduct();
        [$transfer, $detail, $item] = $this->seedDispatched($p, 2);

        // Pre-seed a CONFLICTING inventory movement on the exact key the good
        // receipt will use, with a different fingerprint => InventoryService
        // ::increase throws AFTER the serial step already ran.
        DB::table('inventory_location_movements')->insert([
            'movement_type' => 'increase',
            'product_id' => $p,
            'quantity' => 999,
            'reference_type' => 'TransferReceipt',
            'reference_id' => (string) $item->id,
            'idempotency_key' => 'transfer:receipt:item:'.$item->id.':good',
            'idempotency_fingerprint' => 'CONFLICT-DIFFERENT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::transaction(fn () => $this->service()->creditGoodForTest($transfer, $detail, 2, $item));
            $this->fail('expected ValidationException from the general step');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }

        // Serial mutation + pivot + movement all rolled back by the outer tx.
        foreach (['TS-1', 'TS-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_RESERVED, $row->status, 'serial back to reserved');
            $this->assertNull($row->inventory_location_id);
        }
        $this->assertSame(2, DB::table('transfer_detail_serials')->where('status', TransferDetailSerial::STATUS_IN_TRANSIT)->count());
        $this->assertSame(0, DB::table('product_serial_movements')->where('reference_type', 'TransferReceipt')->count());
        $this->assertSame(0.0, $this->dstGeneral($p));
    }

    // ===================== I — final quantities unchanged by the reorder ======

    public function test_I_final_state_equals_pre_reorder_semantics(): void
    {
        $p = $this->imeiProduct();
        [$transfer, $detail, $item] = $this->seedDispatched($p, 3);
        DB::transaction(fn () => $this->service()->creditGoodForTest($transfer, $detail, 3, $item));

        $this->assertSame(3, DB::table('product_serials')->where('product_id', $p)
            ->where('status', ProductSerial::STATUS_AVAILABLE)->where('inventory_location_id', $this->dstLoc)->count());
        $this->assertSame(3.0, $this->dstGeneral($p));
        // coverage post-condition for a GOOD receipt: general == available serials.
        $c = app(\App\Services\SerialInventoryCoverageService::class)->coverageForLocation($this->dstLoc, $p);
        $this->assertTrue($c['is_ready']);
    }
}
