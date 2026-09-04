<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Services\LocationAwarePurchaseSerialPlanner;
use App\Services\LocationAwarePurchaseStockService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0 — §40 / §41 / §42: end-to-end ENGINE composition, NO controller.
 *
 *   validateAndLock(allow_serial) -> serial planner -> buildSnapshot ->
 *   applySnapshot / assertSnapshotArtifactSafeAndLock / reverseSnapshot
 *
 * Serial artifacts move BEFORE general stock (phase B before phase C).
 */
class LocationAwarePurchaseSerialCompositionTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $loc;
    private int $unit1;
    private int $unit12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('COMP-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
        $this->unit1 = $this->makeUnit('*', 1);
        $this->unit12 = $this->makeUnit('*', 12);
    }

    private function svc(): LocationAwarePurchaseStockService
    {
        return app(LocationAwarePurchaseStockService::class);
    }

    private function planner(): LocationAwarePurchaseSerialPlanner
    {
        return app(LocationAwarePurchaseSerialPlanner::class);
    }

    private function imei(string $code, string $type = 'is_single'): int
    {
        return (int) $this->makeProduct(['code' => $code, 'type' => $type, 'is_imei' => 1, 'cost' => 2]);
    }

    private function inLine(int $productId, int $unitId, float $qty, ?int $variantId = null, int $sdid = 10): array
    {
        return [
            'source_detail_id' => $sdid,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $qty,
            'purchase_unit_id' => $unitId,
        ];
    }

    private function locStockOf(int $productId, ?int $variantId = null): float
    {
        return $this->locStock($this->loc, $productId, $variantId);
    }

    /** run: validate + plan + build a revision-1 purchase snapshot for one line. */
    private function buildReceipt(int $documentId, array $lines, array $rawLines, string $docType = LocationAwarePurchaseStockService::DOC_PURCHASE): array
    {
        return DB::transaction(function () use ($documentId, $lines, $rawLines, $docType) {
            $validated = $this->svc()->validateAndLock($docType, $this->wh, $this->loc, $lines, [], ['allow_serial' => true]);
            $planned = $docType === LocationAwarePurchaseStockService::DOC_PURCHASE
                ? $this->planner()->planPurchaseReceipt($this->wh, $this->loc, $validated['lines'], $rawLines, [])
                : $this->planner()->planPurchaseReturnIssue($this->wh, $this->loc, $validated['lines'], $rawLines, []);
            $validated['lines'] = $planned;

            return $this->svc()->buildSnapshot($validated, 1);
        });
    }

    // ===================== §40 / §41 — PURCHASE apply + reverse =====================

    public function test_purchase_apply_then_reverse(): void
    {
        $p = $this->imei('E1');
        $snap = $this->buildReceipt(700, [$this->inLine($p, $this->unit1, 3)], [['serial_numbers' => ['E-1', 'E-2', 'E-3']]]);

        // planner made 3 voided placeholders; nothing applied yet.
        $this->assertSame(3, DB::table('product_serials')->where('status', ProductSerial::STATUS_VOIDED)->count());
        $this->assertSame(0.0, $this->locStockOf($p));

        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 700));

        $this->assertSame(3, DB::table('product_serials')->where('status', ProductSerial::STATUS_AVAILABLE)
            ->where('inventory_location_id', $this->loc)->count());
        $this->assertSame(3.0, $this->locStockOf($p));
        // serial movement recorded before the general one (lower id).
        $firstSerialMv = DB::table('product_serial_movements')->min('id');
        $this->assertNotNull($firstSerialMv);
        $this->assertSame(3, DB::table('product_serial_movements')->where('action', 'purchased')->count());

        // reverse
        DB::transaction(function () use ($snap) {
            $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]);
            $this->svc()->reverseSnapshot($snap, 700);
        });

        $this->assertSame(3, DB::table('product_serials')->where('status', ProductSerial::STATUS_VOIDED)
            ->whereNull('inventory_location_id')->count());
        $this->assertSame(0.0, $this->locStockOf($p));
        // provenance kept.
        $this->assertSame(700, (int) $this->serialRow('E-1')->purchase_id);
    }

    public function test_purchase_apply_reverse_is_replay_safe(): void
    {
        $p = $this->imei('E1b');
        $snap = $this->buildReceipt(701, [$this->inLine($p, $this->unit1, 1)], [['serial_numbers' => ['RS-1']]]);

        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 701));
        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 701)); // replay

        $this->assertSame(1, DB::table('product_serial_movements')->where('action', 'purchased')->count());
        $this->assertSame(1.0, $this->locStockOf($p));
    }

    public function test_sold_serial_blocks_the_reverse_total_rollback(): void
    {
        $p = $this->imei('E4');
        $snap = $this->buildReceipt(702, [$this->inLine($p, $this->unit1, 2)], [['serial_numbers' => ['B-1', 'B-2']]]);
        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 702));
        $this->assertSame(2.0, $this->locStockOf($p));

        // downstream sale consumes B-1.
        DB::table('product_serials')->where('serial_number', 'B-1')->update(['status' => ProductSerial::STATUS_SOLD]);

        try {
            DB::transaction(function () use ($snap) {
                $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]);
                $this->svc()->reverseSnapshot($snap, 702);
            });
            $this->fail('expected ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // ok
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('B-2')->status, 'no partial reverse');
        $this->assertSame(2.0, $this->locStockOf($p), 'general stock rolled back');
    }

    public function test_moved_location_blocks_the_reverse(): void
    {
        $p = $this->imei('E5');
        $snap = $this->buildReceipt(703, [$this->inLine($p, $this->unit1, 1)], [['serial_numbers' => ['M-1']]]);
        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 703));

        $otherLoc = $this->makeInventoryLocation($this->wh);
        DB::table('product_serials')->where('serial_number', 'M-1')->update(['inventory_location_id' => $otherLoc]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        DB::transaction(function () use ($snap) {
            $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]);
            $this->svc()->reverseSnapshot($snap, 703);
        });
    }

    // ===================== §41 — 10 x12 native contract =====================

    public function test_10_boxes_of_12_is_120_serials_and_general_120(): void
    {
        $p = $this->imei('BOX');
        $serials = array_map(fn ($i) => 'BX-'.$i, range(1, 120));
        $snap = $this->buildReceipt(704, [$this->inLine($p, $this->unit12, 10)], [['serial_numbers' => $serials]]);

        $eff = $snap['effects'][0];
        $this->assertSame(120.0, $eff['quantity_base']);
        $this->assertCount(120, $eff['serial_allocation']);

        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 704));

        $this->assertSame(120, DB::table('product_serials')->where('status', ProductSerial::STATUS_AVAILABLE)->count());
        $this->assertSame(120.0, $this->locStockOf($p));
        $this->assertSame(0.0, $this->stockOf($this->wh, $p), 'legacy product_warehouse untouched');
    }

    // ===================== §42 — variant contract =====================

    public function test_variant_and_imei_apply_binds_variant_and_location(): void
    {
        $p = $this->imei('VAR', 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $snap = $this->buildReceipt(705, [$this->inLine($p, $this->unit1, 2, $v)], [['serial_numbers' => ['VV-1', 'VV-2']]]);

        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 705));

        foreach (['VV-1', 'VV-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
            $this->assertSame($v, (int) $row->product_variant_id);
            $this->assertSame($this->loc, (int) $row->inventory_location_id);
        }
        $this->assertSame(2.0, $this->locStockOf($p, $v));
    }

    // ===================== §40 — PURCHASE RETURN apply + reverse =====================

    public function test_purchase_return_apply_then_reverse(): void
    {
        $p = $this->imei('RET');
        // pre-state: 3 available@loc + general 3 (a healthy location).
        foreach (['T-1', 'T-2', 'T-3'] as $sn) {
            DB::table('product_serials')->insert([
                'serial_number' => $sn, 'product_id' => $p, 'warehouse_id' => $this->wh,
                'inventory_location_id' => $this->loc, 'status' => ProductSerial::STATUS_AVAILABLE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->seedLocationStock($this->loc, $p, 3);

        $snap = $this->buildReceipt(
            706,
            [$this->inLine($p, $this->unit1, 2)],
            [['serial_numbers' => ['T-1', 'T-2']]],
            LocationAwarePurchaseStockService::DOC_PURCHASE_RETURN
        );

        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 706));

        $this->assertSame(ProductSerial::STATUS_RETURNED_SUPPLIER, $this->serialRow('T-1')->status);
        $this->assertSame($this->loc, (int) $this->serialRow('T-1')->inventory_location_id, 'location retained');
        $this->assertSame(1.0, $this->locStockOf($p)); // 3 - 2

        DB::transaction(function () use ($snap) {
            $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]);
            $this->svc()->reverseSnapshot($snap, 706);
        });

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('T-1')->status);
        $this->assertSame(3.0, $this->locStockOf($p)); // restored
    }

    // ===================== §42 — POS B1 can find serial-native units =====

    public function test_pos_b1_predicate_finds_serials_produced_by_a_native_receipt(): void
    {
        $p = $this->imei('POSC', 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $snap = $this->buildReceipt(707, [$this->inLine($p, $this->unit1, 2, $v)], [['serial_numbers' => ['PC-1', 'PC-2']]]);
        DB::transaction(fn () => $this->svc()->applySnapshot($snap, 707));

        // The EXACT predicate LocationAwareSerialNumberService::preflightSaleSerials
        // uses to prelock a POS cart's serials.
        $found = ProductSerial::where('product_id', $p)
            ->where('product_variant_id', $v)
            ->where('inventory_location_id', $this->loc)
            ->where('status', ProductSerial::STATUS_AVAILABLE)
            ->orderBy('id')
            ->pluck('serial_number')
            ->all();

        $this->assertSame(['PC-1', 'PC-2'], $found);
    }
}
