<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Services\LocationAwareSerialNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0 — §37: LocationAwareSerialNumberService atomic SET operations.
 *
 *   receivePurchaseMany / voidPurchaseMany / returnToSupplierMany /
 *   reversePurchaseReturnMany
 *
 * validate-all-then-mutate-all, lock ids ASC, set-level replay
 * (none/all/partial), FAIL CLOSED.
 */
class LocationAwareSerialSetOperationsTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SET-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function svc(): LocationAwareSerialNumberService
    {
        return app(LocationAwareSerialNumberService::class);
    }

    private function imei(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1]);
    }

    private function seedSerial(string $sn, int $productId, string $status, ?int $locationId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn, 'product_id' => $productId, 'warehouse_id' => $this->wh,
            'inventory_location_id' => $locationId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function alloc(int $id, string $sn, string $key, int $pid): array
    {
        return ['product_serial_id' => $id, 'serial_number' => $sn, 'idempotency_key' => $key,
            'expected_product_id' => $pid, 'expected_variant_id' => null];
    }

    private function tx(callable $fn)
    {
        return DB::transaction($fn);
    }

    private function generalStock(int $productId, float $qty): void
    {
        DB::table('inventory_location_stocks')->updateOrInsert(
            ['inventory_location_id' => $this->loc, 'product_id' => $productId, 'variant_key' => 0],
            ['product_variant_id' => null, 'quantity' => $qty, 'reserved_quantity' => 0, 'manage_stock' => 1,
                'created_at' => now(), 'updated_at' => now()]
        );
    }

    // ===================== receivePurchaseMany =====================

    public function test_receive_moves_voided_to_available_and_stamps_linkage(): void
    {
        $p = $this->imei('RC1');
        $a = $this->seedSerial('RC-1', $p, ProductSerial::STATUS_VOIDED);
        $b = $this->seedSerial('RC-2', $p, ProductSerial::STATUS_VOIDED);

        $this->tx(fn () => $this->svc()->receivePurchaseMany([
            $this->alloc($a, 'RC-1', 'k:a', $p) + ['link' => ['purchase_detail_id' => 77]],
            $this->alloc($b, 'RC-2', 'k:b', $p) + ['link' => ['purchase_detail_id' => 77]],
        ], ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 900, 'purchase_id' => 900]));

        foreach (['RC-1', 'RC-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($this->loc, (int) $row->inventory_location_id);
            $this->assertSame(900, (int) $row->purchase_id);
            $this->assertSame(77, (int) $row->purchase_detail_id);

            $mv = DB::table('product_serial_movements')->where('serial_number', $sn)->first();
            $this->assertSame(ProductSerialMovement::ACTION_PURCHASED, $mv->action);
            $this->assertSame(ProductSerial::STATUS_VOIDED, $mv->from_status);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $mv->to_status);
            $this->assertSame('Purchase', $mv->reference_type);
            $this->assertSame(900, (int) $mv->reference_id);
            $this->assertNotNull($mv->idempotency_key);
            $this->assertNotNull($mv->idempotency_fingerprint);
        }
    }

    public function test_receive_full_replay_is_a_noop(): void
    {
        $p = $this->imei('RC2');
        $a = $this->seedSerial('RP-1', $p, ProductSerial::STATUS_VOIDED);
        $ctx = ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 901];

        $this->tx(fn () => $this->svc()->receivePurchaseMany([$this->alloc($a, 'RP-1', 'rp:1', $p)], $ctx));
        $this->tx(fn () => $this->svc()->receivePurchaseMany([$this->alloc($a, 'RP-1', 'rp:1', $p)], $ctx)); // replay

        $this->assertSame(1, DB::table('product_serial_movements')->count(), 'no duplicate movement');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RP-1')->status);
    }

    public function test_receive_partial_replay_is_422(): void
    {
        $p = $this->imei('RC3');
        $a = $this->seedSerial('PP-1', $p, ProductSerial::STATUS_VOIDED);
        $b = $this->seedSerial('PP-2', $p, ProductSerial::STATUS_VOIDED);
        $ctx = ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 902];

        // first: only PP-1 (key pp:1)
        $this->tx(fn () => $this->svc()->receivePurchaseMany([$this->alloc($a, 'PP-1', 'pp:1', $p)], $ctx));

        // now a set carrying pp:1 (exists) + pp:2 (new) => partial replay.
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->receivePurchaseMany([
            $this->alloc($a, 'PP-1', 'pp:1', $p),
            $this->alloc($b, 'PP-2', 'pp:2', $p),
        ], $ctx));
    }

    public function test_receive_fingerprint_conflict_is_422(): void
    {
        $p = $this->imei('RC4');
        $a = $this->seedSerial('FP-1', $p, ProductSerial::STATUS_VOIDED);
        $ctx = ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 903];
        $this->tx(fn () => $this->svc()->receivePurchaseMany([$this->alloc($a, 'FP-1', 'fp:1', $p)], $ctx));

        // same key, different reference_id => different fingerprint => 422.
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->receivePurchaseMany(
            [$this->alloc($a, 'FP-1', 'fp:1', $p)],
            ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 999]
        ));
    }

    public function test_receive_one_invalid_serial_mutates_nothing(): void
    {
        $p = $this->imei('RC5');
        $a = $this->seedSerial('OK-1', $p, ProductSerial::STATUS_VOIDED);
        $b = $this->seedSerial('BAD-1', $p, ProductSerial::STATUS_AVAILABLE); // wrong pre-state

        try {
            $this->tx(fn () => $this->svc()->receivePurchaseMany([
                $this->alloc($a, 'OK-1', 'x:1', $p),
                $this->alloc($b, 'BAD-1', 'x:2', $p),
            ], ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 904]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_transition', $e->errors());
        }
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('OK-1')->status, 'no partial mutation');
        $this->assertSame(0, DB::table('product_serial_movements')->count());
    }

    public function test_receive_id_ok_but_serial_number_mismatch_is_422(): void
    {
        $p = $this->imei('RC6');
        $a = $this->seedSerial('REAL-1', $p, ProductSerial::STATUS_VOIDED);
        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->receivePurchaseMany(
            [$this->alloc($a, 'DIFFERENT', 'y:1', $p)],
            ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 905]
        ));
    }

    // ===================== voidPurchaseMany =====================

    public function test_void_moves_available_at_old_location_to_voided_null(): void
    {
        $p = $this->imei('VD1');
        $a = $this->seedSerial('VD-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        $this->generalStock($p, 1); // coverage: 1 general == 1 available

        $this->tx(fn () => $this->svc()->voidPurchaseMany(
            [$this->alloc($a, 'VD-1', 'v:1', $p)],
            ['inventory_location_id' => $this->loc, 'reference_id' => 910]
        ));

        $row = $this->serialRow('VD-1');
        $this->assertSame(ProductSerial::STATUS_VOIDED, $row->status);
        $this->assertNull($row->inventory_location_id);
        $mv = DB::table('product_serial_movements')->where('serial_number', 'VD-1')->first();
        $this->assertSame(ProductSerialMovement::ACTION_STATUS_CHANGED, $mv->action);
        $this->assertSame('PurchaseReversal', $mv->reference_type);
    }

    public function test_void_rolls_back_all_when_one_serial_is_sold(): void
    {
        $p = $this->imei('VD2');
        $a = $this->seedSerial('VA-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        $b = $this->seedSerial('VA-2', $p, ProductSerial::STATUS_SOLD, $this->loc);
        $this->generalStock($p, 2);

        try {
            $this->tx(fn () => $this->svc()->voidPurchaseMany([
                $this->alloc($a, 'VA-1', 'w:1', $p),
                $this->alloc($b, 'VA-2', 'w:2', $p),
            ], ['inventory_location_id' => $this->loc, 'reference_id' => 911]));
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_transition', $e->errors());
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('VA-1')->status);
        $this->assertSame(0, DB::table('product_serial_movements')->count());
    }

    public function test_void_rolls_back_when_a_serial_moved_location(): void
    {
        $p = $this->imei('VD3');
        $otherLoc = $this->makeInventoryLocation($this->wh);
        $a = $this->seedSerial('VL-1', $p, ProductSerial::STATUS_AVAILABLE, $otherLoc); // not the OLD snapshot location
        $this->generalStock($p, 0);

        $this->expectException(ValidationException::class);
        $this->tx(fn () => $this->svc()->voidPurchaseMany(
            [$this->alloc($a, 'VL-1', 'q:1', $p)],
            ['inventory_location_id' => $this->loc, 'reference_id' => 912]
        ));
    }

    public function test_void_full_replay_is_a_noop(): void
    {
        $p = $this->imei('VD4');
        $a = $this->seedSerial('VR-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        $this->generalStock($p, 1);
        $ctx = ['inventory_location_id' => $this->loc, 'reference_id' => 913];

        $this->tx(fn () => $this->svc()->voidPurchaseMany([$this->alloc($a, 'VR-1', 'vr:1', $p)], $ctx));
        $this->tx(fn () => $this->svc()->voidPurchaseMany([$this->alloc($a, 'VR-1', 'vr:1', $p)], $ctx));

        $this->assertSame(1, DB::table('product_serial_movements')->count());
        $this->assertSame(ProductSerial::STATUS_VOIDED, $this->serialRow('VR-1')->status);
    }

    // ===================== returnToSupplierMany =====================

    public function test_return_to_supplier_keeps_location_on_the_serial(): void
    {
        $p = $this->imei('RT1');
        $a = $this->seedSerial('RT-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        $this->generalStock($p, 1);

        $this->tx(fn () => $this->svc()->returnToSupplierMany(
            [$this->alloc($a, 'RT-1', 'rt:1', $p)],
            ['inventory_location_id' => $this->loc, 'reference_id' => 920]
        ));

        $row = $this->serialRow('RT-1');
        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id, 'location RETAINED for a later restore');
        $mv = DB::table('product_serial_movements')->where('serial_number', 'RT-1')->first();
        $this->assertSame(ProductSerialMovement::ACTION_PURCHASE_RETURNED, $mv->action);
        $this->assertSame($this->loc, (int) $mv->from_inventory_location_id);
    }

    public function test_return_to_supplier_one_invalid_rolls_back_all(): void
    {
        $p = $this->imei('RT2');
        $a = $this->seedSerial('RA-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        $b = $this->seedSerial('RA-2', $p, ProductSerial::STATUS_SOLD, $this->loc);
        $this->generalStock($p, 2);

        $this->expectException(ValidationException::class);
        try {
            $this->tx(fn () => $this->svc()->returnToSupplierMany([
                $this->alloc($a, 'RA-1', 'ra:1', $p),
                $this->alloc($b, 'RA-2', 'ra:2', $p),
            ], ['inventory_location_id' => $this->loc, 'reference_id' => 921]));
        } finally {
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RA-1')->status);
        }
    }

    // ===================== reversePurchaseReturnMany =====================

    public function test_reverse_return_restores_returned_supplier_to_available(): void
    {
        $p = $this->imei('RV1');
        $a = $this->seedSerial('RV-1', $p, ProductSerial::STATUS_RETURNED_SUPPLIER, $this->loc);

        $this->tx(fn () => $this->svc()->reversePurchaseReturnMany(
            [$this->alloc($a, 'RV-1', 'rev:1', $p)],
            ['inventory_location_id' => $this->loc, 'reference_id' => 930]
        ));

        $row = $this->serialRow('RV-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id);
        $mv = DB::table('product_serial_movements')->where('serial_number', 'RV-1')->first();
        $this->assertSame('PurchaseReturnReversal', $mv->reference_type);
    }

    public function test_reverse_return_wrong_status_rolls_back_all(): void
    {
        $p = $this->imei('RV2');
        $a = $this->seedSerial('RW-1', $p, ProductSerial::STATUS_RETURNED_SUPPLIER, $this->loc);
        $b = $this->seedSerial('RW-2', $p, ProductSerial::STATUS_AVAILABLE, $this->loc); // not returned_supplier

        $this->expectException(ValidationException::class);
        try {
            $this->tx(fn () => $this->svc()->reversePurchaseReturnMany([
                $this->alloc($a, 'RW-1', 'rw:1', $p),
                $this->alloc($b, 'RW-2', 'rw:2', $p),
            ], ['inventory_location_id' => $this->loc, 'reference_id' => 931]));
        } finally {
            $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('RW-1')->status);
        }
    }

    public function test_set_operation_requires_outer_transaction(): void
    {
        $p = $this->imei('NOTX');
        $a = $this->seedSerial('NT-1', $p, ProductSerial::STATUS_VOIDED);
        $this->expectException(\LogicException::class);
        $this->svc()->receivePurchaseMany(
            [$this->alloc($a, 'NT-1', 'nt:1', $p)],
            ['warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc, 'reference_id' => 1]
        );
    }
}
