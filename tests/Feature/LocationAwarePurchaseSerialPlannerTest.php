<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Services\LocationAwarePurchaseSerialPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0 — §35 / §36: LocationAwarePurchaseSerialPlanner.
 *
 * Freezes serial identity for a location-native Purchase / PurchaseReturn.
 * NEVER writes a movement, NEVER touches general stock. A missing serial on a
 * RECEIPT becomes a `voided` placeholder (stable id for the snapshot); a
 * RETURN never creates anything.
 */
class LocationAwarePurchaseSerialPlannerTest extends TestCase
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

        $this->wh = $this->makeWarehouse('SN-PLAN-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function planner(): LocationAwarePurchaseSerialPlanner
    {
        return app(LocationAwarePurchaseSerialPlanner::class);
    }

    /** validated line shape the planner consumes. */
    private function line(int $productId, float $qtyBase, bool $requiresSerial = true, ?int $variantId = null, bool $requiresBatch = false): array
    {
        return [
            'source_detail_id' => null,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $qtyBase,
            'quantity_base' => $qtyBase,
            'requires_batch' => $requiresBatch,
            'requires_serial' => $requiresSerial,
        ];
    }

    private function raw(array $serials): array
    {
        return ['serial_numbers' => $serials];
    }

    private function planReceipt(array $lines, array $rawLines, array $ctx = []): array
    {
        return DB::transaction(fn () => $this->planner()->planPurchaseReceipt($this->wh, $this->loc, $lines, $rawLines, $ctx));
    }

    private function planReturn(array $lines, array $rawLines, array $ctx = []): array
    {
        return DB::transaction(fn () => $this->planner()->planPurchaseReturnIssue($this->wh, $this->loc, $lines, $rawLines, $ctx));
    }

    private function imei(string $code, string $type = 'is_single'): int
    {
        return (int) $this->makeProduct(['code' => $code, 'type' => $type, 'is_imei' => 1]);
    }

    private function seedSerial(string $sn, int $productId, string $status, ?int $variantId = null, ?int $locationId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'warehouse_id' => $this->wh, 'inventory_location_id' => $locationId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ===================== §35 — PURCHASE RECEIPT =====================

    public function test_requires_outer_transaction(): void
    {
        $this->expectException(\LogicException::class);
        $p = $this->imei('T1');
        $this->planner()->planPurchaseReceipt($this->wh, $this->loc, [$this->line($p, 1)], [$this->raw(['X'])]);
    }

    public function test_new_serial_becomes_a_voided_placeholder_and_no_movement_no_stock(): void
    {
        $p = $this->imei('T2');
        $out = $this->planReceipt([$this->line($p, 2)], [$this->raw(['N-1', 'N-2'])]);

        $this->assertCount(1, $out);
        $alloc = $out[0]['serial_allocation'];
        $this->assertCount(2, $alloc);
        $this->assertSame([0, 1], array_column($alloc, 'sidx'));

        foreach ($alloc as $a) {
            $row = DB::table('product_serials')->where('id', $a['product_serial_id'])->first();
            $this->assertSame(ProductSerial::STATUS_VOIDED, $row->status);
            $this->assertNull($row->inventory_location_id);
            $this->assertSame($p, (int) $row->product_id);
            $this->assertSame($this->wh, (int) $row->warehouse_id);
        }
        $this->assertSame(0, DB::table('product_serial_movements')->count(), 'planner writes no movements');
        $this->assertSame(0, DB::table('inventory_location_stocks')->count(), 'planner touches no stock');
    }

    public function test_duplicate_serial_within_one_line_is_422(): void
    {
        $p = $this->imei('T5');
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($p, 2)], [$this->raw(['SAME', 'SAME'])]);
    }

    public function test_same_serial_across_two_lines_is_422(): void
    {
        $a = $this->imei('T6A');
        $b = $this->imei('T6B');
        try {
            $this->planReceipt([$this->line($a, 1), $this->line($b, 1)], [$this->raw(['DUP']), $this->raw(['DUP'])]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('repetido', json_encode($e->errors()));
        }
        $this->assertSame(0, DB::table('product_serials')->count());
    }

    /** @dataProvider liveStatuses */
    public function test_existing_live_serial_is_422(string $status): void
    {
        $p = $this->imei('T'.$status);
        $this->seedSerial('LIVE', $p, $status);
        try {
            $this->planReceipt([$this->line($p, 1)], [$this->raw(['LIVE'])]);
            $this->fail('expected ValidationException for status '.$status);
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('activo', json_encode($e->errors()));
        }
    }

    public static function liveStatuses(): array
    {
        return [
            'available' => [ProductSerial::STATUS_AVAILABLE],
            'sold' => [ProductSerial::STATUS_SOLD],
            'returned_supplier' => [ProductSerial::STATUS_RETURNED_SUPPLIER],
            'damaged' => [ProductSerial::STATUS_DAMAGED],
            'reserved' => [ProductSerial::STATUS_RESERVED],
        ];
    }

    public function test_existing_voided_same_product_is_reused(): void
    {
        $p = $this->imei('T12');
        $vid = $this->seedSerial('RECYCLE', $p, ProductSerial::STATUS_VOIDED);

        $out = $this->planReceipt([$this->line($p, 1)], [$this->raw(['RECYCLE'])]);

        $this->assertSame($vid, $out[0]['serial_allocation'][0]['product_serial_id'], 'reused the SAME row');
        $this->assertSame(1, DB::table('product_serials')->count(), 'no new row');
    }

    public function test_voided_serial_of_another_product_is_422(): void
    {
        $other = $this->imei('T13O');
        $target = $this->imei('T13T');
        $this->seedSerial('WRONGP', $other, ProductSerial::STATUS_VOIDED);
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($target, 1)], [$this->raw(['WRONGP'])]);
    }

    public function test_voided_serial_of_another_variant_is_422(): void
    {
        $p = $this->imei('T14', 'is_variant');
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->seedSerial('WRONGV', $p, ProductSerial::STATUS_VOIDED, $v2);
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($p, 1, true, $v1)], [$this->raw(['WRONGV'])]);
    }

    public function test_variant_and_imei_is_accepted_and_binds_the_variant(): void
    {
        $p = $this->imei('T15', 'is_variant');
        $v = $this->makeVariant($p, 'V1');

        $out = $this->planReceipt([$this->line($p, 2, true, $v)], [$this->raw(['VI-1', 'VI-2'])]);

        foreach ($out[0]['serial_allocation'] as $a) {
            $row = DB::table('product_serials')->where('id', $a['product_serial_id'])->first();
            $this->assertSame($v, (int) $row->product_variant_id);
        }
    }

    public function test_batch_plus_imei_line_is_422(): void
    {
        $p = $this->imei('T16');
        try {
            $this->planReceipt([$this->line($p, 1, true, null, true)], [$this->raw(['B'])]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_tracking', $e->errors());
        }
    }

    public function test_10_boxes_of_12_requires_120_serials_not_10(): void
    {
        $p = $this->imei('T17');
        // 10 serials for quantity_base 120 -> 422
        try {
            $this->planReceipt([$this->line($p, 120)], [$this->raw(array_map(fn ($i) => 'S'.$i, range(1, 10)))]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('120', json_encode($e->errors()));
        }
        // 120 serials -> accepted
        $out = $this->planReceipt([$this->line($p, 120)], [$this->raw(array_map(fn ($i) => 'S'.$i, range(1, 120)))]);
        $this->assertCount(120, $out[0]['serial_allocation']);
        $this->assertSame(120, DB::table('product_serials')->where('status', ProductSerial::STATUS_VOIDED)->count());
    }

    public function test_fractional_base_is_422(): void
    {
        $p = $this->imei('T18');
        $this->expectException(ValidationException::class);
        $this->planReceipt([$this->line($p, 2.5)], [$this->raw(['A', 'B'])]);
    }

    public function test_two_lines_same_product_keep_ordinal_mapping(): void
    {
        $p = $this->imei('T19');
        $l1 = $this->line($p, 1); $l1['source_detail_id'] = 501;
        $l2 = $this->line($p, 1); $l2['source_detail_id'] = 502;

        $out = $this->planReceipt([$l1, $l2], [$this->raw(['ORD-A']), $this->raw(['ORD-B'])]);

        $this->assertSame('ORD-A', $out[0]['serial_allocation'][0]['serial_number']);
        $this->assertSame(501, $out[0]['source_detail_id']);
        $this->assertSame('ORD-B', $out[1]['serial_allocation'][0]['serial_number']);
        $this->assertSame(502, $out[1]['source_detail_id']);
    }

    public function test_non_serial_line_gets_empty_allocation(): void
    {
        $p = $this->imei('T-MIX-S');
        $plain = (int) $this->makeProduct(['code' => 'T-MIX-P']);
        $out = $this->planReceipt(
            [$this->line($p, 1), $this->line($plain, 3, false)],
            [$this->raw(['MIX-1']), []]
        );
        $this->assertCount(1, $out[0]['serial_allocation']);
        $this->assertSame([], $out[1]['serial_allocation']);
    }

    // ===================== §36 — PURCHASE RETURN =====================

    public function test_return_accepts_available_serial_at_exact_location(): void
    {
        $p = $this->imei('R1');
        $id = $this->seedSerial('AV-1', $p, ProductSerial::STATUS_AVAILABLE, null, $this->loc);

        $out = $this->planReturn([$this->line($p, 1)], [$this->raw(['AV-1'])]);

        $this->assertSame($id, $out[0]['serial_allocation'][0]['product_serial_id']);
        $this->assertSame(1, DB::table('product_serials')->count(), 'return never creates a serial');
    }

    /** @dataProvider returnRejections */
    public function test_return_rejections(string $seedStatus, ?int $atLocationOffset, int $wrongProduct, int $wrongVariant): void
    {
        $p = $this->imei('RJ'.$seedStatus.$wrongProduct.$wrongVariant, $wrongVariant ? 'is_variant' : 'is_single');
        $variantId = null;
        if ($wrongVariant) {
            $v1 = $this->makeVariant($p, 'A');
            $v2 = $this->makeVariant($p, 'B');
            $this->seedSerial('RJ', $p, $seedStatus, $v2, $this->loc);
            $variantId = $v1;
        } elseif ($wrongProduct) {
            $other = $this->imei('RJ-OTHER'.$seedStatus);
            $this->seedSerial('RJ', $other, $seedStatus, null, $this->loc);
        } else {
            $loc = $atLocationOffset === null ? $this->loc : $this->makeInventoryLocation($this->wh);
            $this->seedSerial('RJ', $p, $seedStatus, null, $loc);
        }

        $this->expectException(ValidationException::class);
        $this->planReturn([$this->line($p, 1, true, $variantId)], [$this->raw(['RJ'])]);
    }

    public static function returnRejections(): array
    {
        return [
            'sold' => [ProductSerial::STATUS_SOLD, null, 0, 0],
            'returned_supplier' => [ProductSerial::STATUS_RETURNED_SUPPLIER, null, 0, 0],
            'available other location' => [ProductSerial::STATUS_AVAILABLE, 1, 0, 0],
            'wrong product' => [ProductSerial::STATUS_AVAILABLE, null, 1, 0],
            'wrong variant' => [ProductSerial::STATUS_AVAILABLE, null, 0, 1],
        ];
    }

    public function test_return_integer_base_and_count_required(): void
    {
        $p = $this->imei('R7');
        $this->seedSerial('C-1', $p, ProductSerial::STATUS_AVAILABLE, null, $this->loc);
        $this->seedSerial('C-2', $p, ProductSerial::STATUS_AVAILABLE, null, $this->loc);

        // count 1 != base 2
        try {
            $this->planReturn([$this->line($p, 2)], [$this->raw(['C-1'])]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('serie', json_encode($e->errors()));
        }
        // fractional base
        try {
            $this->planReturn([$this->line($p, 1.5)], [$this->raw(['C-1'])]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('entera', json_encode($e->errors()));
        }
    }

    public function test_return_duplicate_serial_document_wide_is_422(): void
    {
        $p = $this->imei('R9');
        $this->seedSerial('D-1', $p, ProductSerial::STATUS_AVAILABLE, null, $this->loc);
        $l1 = $this->line($p, 1); $l1['source_detail_id'] = 1;
        $l2 = $this->line($p, 1); $l2['source_detail_id'] = 2;
        $this->expectException(ValidationException::class);
        $this->planReturn([$l1, $l2], [$this->raw(['D-1']), $this->raw(['D-1'])]);
    }

    public function test_return_require_source_purchase_option(): void
    {
        $p = $this->imei('R13');
        $this->seedSerial('SRC-1', $p, ProductSerial::STATUS_AVAILABLE, null, $this->loc);
        DB::table('product_serials')->where('serial_number', 'SRC-1')->update(['purchase_id' => 999]);

        // default: no source-purchase constraint => accepted.
        $out = $this->planReturn([$this->line($p, 1)], [$this->raw(['SRC-1'])]);
        $this->assertSame('SRC-1', $out[0]['serial_allocation'][0]['serial_number']);

        // opt-in with a non-matching source => 422.
        $this->expectException(ValidationException::class);
        $this->planReturn(
            [$this->line($p, 1)],
            [$this->raw(['SRC-1'])],
            ['require_source_purchase' => true, 'source_purchase_id' => 111]
        );
    }
}
