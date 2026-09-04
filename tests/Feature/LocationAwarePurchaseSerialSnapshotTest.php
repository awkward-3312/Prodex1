<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Services\LocationAwarePurchaseStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0 — §39: serial snapshot format / normalize / artifact-safe locking.
 *
 * The snapshot version is NOT bumped: serial_allocation is an optional field
 * (same policy as MS5-B2 batch_allocation).
 */
class LocationAwarePurchaseSerialSnapshotTest extends TestCase
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

        $this->wh = $this->makeWarehouse('SNAP-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function svc(): LocationAwarePurchaseStockService
    {
        return app(LocationAwarePurchaseStockService::class);
    }

    private function validated(array $lines): array
    {
        return [
            'document_type' => LocationAwarePurchaseStockService::DOC_PURCHASE,
            'warehouse_id' => $this->wh,
            'inventory_location_id' => $this->loc,
            'lines' => $lines,
        ];
    }

    private function serialLine(int $productId, int $base, array $allocation, ?int $variantId = null): array
    {
        return [
            'source_detail_id' => 10,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $base,
            'quantity_base' => (float) $base,
            'requires_batch' => false,
            'requires_serial' => true,
            'serial_allocation' => $allocation,
        ];
    }

    private function alloc(int $sidx, int $psid, string $sn): array
    {
        return ['sidx' => $sidx, 'product_serial_id' => $psid, 'serial_number' => $sn];
    }

    // ===================== A — build =====================

    public function test_build_serial_snapshot_effect(): void
    {
        $p = (int) $this->makeProduct(['code' => 'S', 'is_imei' => 1]);
        $snap = $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 2, [$this->alloc(0, 11, 'A'), $this->alloc(1, 12, 'B')]),
        ]), 1);

        $this->assertSame(LocationAwarePurchaseStockService::SNAPSHOT_VERSION, $snap['version']);
        $eff = $snap['effects'][0];
        $this->assertSame(2.0, $eff['quantity_base']);
        $this->assertCount(2, $eff['serial_allocation']);
        $this->assertSame([0, 1], array_column($eff['serial_allocation'], 'sidx'));
        $this->assertArrayNotHasKey('batch_allocation', $eff);
    }

    // ===================== B/C/D — normalize compatibility =====================

    public function test_normalize_serial_snapshot_roundtrips(): void
    {
        $p = (int) $this->makeProduct(['code' => 'S2', 'is_imei' => 1]);
        $built = $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 1, [$this->alloc(0, 21, 'X')]),
        ]), 3);
        $norm = $this->svc()->normalizeSnapshot(json_encode($built));
        $this->assertSame(3, $norm['revision']);
        $this->assertSame('X', $norm['effects'][0]['serial_allocation'][0]['serial_number']);
    }

    public function test_old_quantity_only_snapshot_still_normalizes(): void
    {
        $old = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [['source_detail_id' => 5, 'product_id' => 1, 'product_variant_id' => null,
                'quantity_base' => 4.0, 'delta' => 4.0]],
        ];
        $norm = $this->svc()->normalizeSnapshot($old);
        $this->assertArrayNotHasKey('serial_allocation', $norm['effects'][0]);
    }

    public function test_ms5_batch_snapshot_still_normalizes(): void
    {
        $batch = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [[
                'source_detail_id' => 5, 'product_id' => 1, 'product_variant_id' => null,
                'quantity_base' => 6.0, 'delta' => 6.0,
                'batch_allocation' => [['bidx' => 0, 'product_batch_id' => 3, 'batch_no' => 'L', 'expiry_date' => null, 'mfg_date' => null, 'quantity_base' => 6.0, 'unit_cost' => null]],
            ]],
        ];
        $norm = $this->svc()->normalizeSnapshot($batch);
        $this->assertCount(1, $norm['effects'][0]['batch_allocation']);
    }

    // ===================== E..J — validation FAIL CLOSED =====================

    public function test_batch_and_serial_on_the_same_effect_is_rejected(): void
    {
        $p = (int) $this->makeProduct(['code' => 'BS', 'is_imei' => 1]);
        $line = $this->serialLine($p, 1, [$this->alloc(0, 1, 'A')]);
        $line['requires_batch'] = true;
        $this->expectException(ValidationException::class);
        $this->svc()->buildSnapshot($this->validated([$line]), 1);
    }

    public function test_serial_count_not_equal_to_quantity_base_is_rejected(): void
    {
        $p = (int) $this->makeProduct(['code' => 'CB', 'is_imei' => 1]);
        $this->expectException(ValidationException::class);
        $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 3, [$this->alloc(0, 1, 'A'), $this->alloc(1, 2, 'B')]),
        ]), 1);
    }

    public function test_duplicate_product_serial_id_within_effect_is_rejected(): void
    {
        $p = (int) $this->makeProduct(['code' => 'DI', 'is_imei' => 1]);
        // same sidx re-used
        $this->expectException(ValidationException::class);
        $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 2, [$this->alloc(0, 5, 'A'), $this->alloc(0, 6, 'B')]),
        ]), 1);
    }

    public function test_duplicate_serial_number_document_wide_is_rejected(): void
    {
        $p1 = (int) $this->makeProduct(['code' => 'DW1', 'is_imei' => 1]);
        $p2 = (int) $this->makeProduct(['code' => 'DW2', 'is_imei' => 1]);
        $l1 = $this->serialLine($p1, 1, [$this->alloc(0, 1, 'SAME')]);
        $l2 = $this->serialLine($p2, 1, [$this->alloc(0, 2, 'SAME')]);
        $l2['source_detail_id'] = 20;
        $this->expectException(ValidationException::class);
        $this->svc()->buildSnapshot($this->validated([$l1, $l2]), 1);
    }

    public function test_non_serial_line_carrying_serial_allocation_is_rejected(): void
    {
        $p = (int) $this->makeProduct(['code' => 'NS']);
        $line = $this->serialLine($p, 1, [$this->alloc(0, 1, 'A')]);
        $line['requires_serial'] = false;
        $this->expectException(ValidationException::class);
        $this->svc()->buildSnapshot($this->validated([$line]), 1);
    }

    public function test_artifact_safe_reverse_fails_closed_when_serial_row_missing(): void
    {
        $p = (int) $this->makeProduct(['code' => 'MISS', 'is_imei' => 1]);
        $this->seedInvLocationStock($p);
        $snap = $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 1, [$this->alloc(0, 999999, 'GHOST')]),
        ]), 1);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]));
    }

    public function test_artifact_safe_reverse_fails_closed_when_id_resolves_but_serial_number_differs(): void
    {
        $p = (int) $this->makeProduct(['code' => 'MM', 'is_imei' => 1]);
        $id = (int) DB::table('product_serials')->insertGetId([
            'serial_number' => 'REAL', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_AVAILABLE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snap = $this->svc()->buildSnapshot($this->validated([
            $this->serialLine($p, 1, [$this->alloc(0, $id, 'DIFFERENT')]),
        ]), 1);

        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($snap, ['allow_serial' => true]));
    }

    public function test_now_imei_product_with_old_quantity_only_snapshot_under_allow_serial_is_422(): void
    {
        $p = (int) $this->makeProduct(['code' => 'BECAME', 'is_imei' => 1]);
        $old = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [['source_detail_id' => 5, 'product_id' => $p, 'product_variant_id' => null,
                'quantity_base' => 4.0, 'delta' => 4.0]], // NO serial_allocation
        ];
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($old, ['allow_serial' => true]));
    }

    public function test_now_imei_product_without_allow_serial_still_422(): void
    {
        $p = (int) $this->makeProduct(['code' => 'STILL', 'is_imei' => 1]);
        $old = [
            'version' => 1, 'revision' => 1, 'document_type' => 'purchase',
            'warehouse_id' => $this->wh, 'inventory_location_id' => $this->loc,
            'effects' => [['source_detail_id' => 5, 'product_id' => $p, 'product_variant_id' => null,
                'quantity_base' => 4.0, 'delta' => 4.0]],
        ];
        try {
            DB::transaction(fn () => $this->svc()->assertSnapshotArtifactSafeAndLock($old)); // no options
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('serie', json_encode($e->errors(), JSON_UNESCAPED_SLASHES));
            $this->assertStringContainsStringIgnoringCase('artifact-aware', json_encode($e->errors()));
        }
    }

    private function seedInvLocationStock(int $productId): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->loc, 'product_id' => $productId, 'product_variant_id' => null,
            'variant_key' => 0, 'quantity' => 1, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
