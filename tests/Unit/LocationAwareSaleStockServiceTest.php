<?php

namespace Tests\Unit;

use App\Services\InventoryService;
use App\Services\LocationAwareSaleStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B1 — LocationAwareSaleStockService: the GENERAL-only leg of a
 * location-native Admin Sale / SaleReturn (BATCH and SERIAL are owned by the
 * already-proven LocationAwareBatchService / LocationAwareSerialNumberService
 * and are NOT exercised here — see SalesController's own artifact wiring).
 */
class LocationAwareSaleStockServiceTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;
    private int $unitEach;
    private int $unitDozen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SL-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
        $this->unitEach = $this->makeUnit('*', 1);
        $this->unitDozen = $this->makeUnit('*', 12);
    }

    private function svc(): LocationAwareSaleStockService
    {
        return app(LocationAwareSaleStockService::class);
    }

    private function seedStock(int $productId, float $qty, ?int $variantId = null): void
    {
        app(InventoryService::class)->increase($this->loc, $productId, $qty, $variantId, [
            'reference_type' => 'TestSeed',
            'reference_id' => 1,
            'user_id' => null,
        ]);
    }

    // ---------------------------------------------------------------- validateAndLock

    public function test_quantity_base_uses_pack_multiplier_and_unit_conversion(): void
    {
        $p = $this->makeProduct();
        $lines = [[
            'product_id' => $p, 'product_variant_id' => null,
            'quantity' => 2, 'sale_unit_id' => $this->unitDozen, 'pack_multiplier' => 1,
        ]];

        $out = $this->svc()->validateAndLock($lines)['lines'];

        $this->assertCount(1, $out);
        $this->assertSame(24.0, $out[0]['quantity_base']); // 2 dozens * 12
        $this->assertSame(0, $out[0]['_line_index']);
        $this->assertFalse($out[0]['requires_batch']);
        $this->assertFalse($out[0]['requires_serial']);
    }

    public function test_unknown_product_is_422(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->validateAndLock([[
            'product_id' => 999999, 'product_variant_id' => null,
            'quantity' => 1, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1,
        ]]);
    }

    public function test_variant_not_belonging_to_product_is_422(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $v2 = $this->makeVariant($p2);

        $this->expectException(ValidationException::class);
        $this->svc()->validateAndLock([[
            'product_id' => $p1, 'product_variant_id' => $v2,
            'quantity' => 1, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1,
        ]]);
    }

    public function test_batch_plus_imei_line_is_422(): void
    {
        $p = $this->makeProduct(['is_batch_tracked' => true, 'is_imei' => 1]);

        $this->expectException(ValidationException::class);
        $this->svc()->validateAndLock([[
            'product_id' => $p, 'product_variant_id' => null,
            'quantity' => 1, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1,
        ]]);
    }

    public function test_imei_line_requires_integer_base_quantity(): void
    {
        $p = $this->makeProduct(['is_imei' => 1]);
        $thirds = $this->makeUnit('/', 3); // 1 / 3 = 0.333... — fractional base.

        $this->expectException(ValidationException::class);
        $this->svc()->validateAndLock([[
            'product_id' => $p, 'product_variant_id' => null,
            'quantity' => 1, 'sale_unit_id' => $thirds, 'pack_multiplier' => 1,
        ]]);
    }

    public function test_service_type_line_is_skipped_and_keeps_no_line_index_gap_confusion(): void
    {
        $service = $this->makeProduct(['type' => 'is_service']);
        $single = $this->makeProduct();

        $out = $this->svc()->validateAndLock([
            ['product_id' => $service, 'product_variant_id' => null, 'quantity' => 1, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1],
            ['product_id' => $single, 'product_variant_id' => null, 'quantity' => 3, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1],
        ])['lines'];

        $this->assertCount(1, $out);
        // The surviving line still carries the ORIGINAL raw-line index (1),
        // not its re-indexed position (0) — this is the exact invariant
        // withSourceDetailIds()/SalesController rely on to stay aligned.
        $this->assertSame(1, $out[0]['_line_index']);
        $this->assertSame(3.0, $out[0]['quantity_base']);
    }

    public function test_with_source_detail_ids_keys_off_line_index_not_position(): void
    {
        $service = $this->makeProduct(['type' => 'is_service']);
        $single = $this->makeProduct();

        $validated = $this->svc()->validateAndLock([
            ['product_id' => $service, 'product_variant_id' => null, 'quantity' => 1, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1],
            ['product_id' => $single, 'product_variant_id' => null, 'quantity' => 3, 'sale_unit_id' => $this->unitEach, 'pack_multiplier' => 1],
        ])['lines'];

        $withIds = $this->svc()->withSourceDetailIds($validated, [0 => 501, 1 => 777]);

        $this->assertSame(777, $withIds[0]['source_detail_id']);
    }

    // ---------------------------------------------------------------- buildSnapshot / normalizeSnapshot

    public function test_build_snapshot_sale_delta_is_negative_and_return_is_positive(): void
    {
        $effects = [[
            'source_detail_id' => 10, 'product_id' => 1, 'product_variant_id' => null,
            'quantity_base' => 5,
        ]];

        $sale = $this->svc()->buildSnapshot(LocationAwareSaleStockService::DOC_SALE, $this->wh, $this->loc, $effects, 1);
        $return = $this->svc()->buildSnapshot(LocationAwareSaleStockService::DOC_SALE_RETURN, $this->wh, $this->loc, $effects, 1);

        $this->assertSame(-5.0, $sale['effects'][0]['delta']);
        $this->assertSame(5.0, $return['effects'][0]['delta']);
    }

    public function test_normalize_snapshot_rejects_corrupt_payload(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->normalizeSnapshot(['document_type' => 'sale', 'revision' => 1]); // no effects
    }

    public function test_idempotency_key_format(): void
    {
        $key = $this->svc()->idempotencyKey(LocationAwareSaleStockService::DOC_SALE, 42, 2, 0, 'apply');
        $this->assertSame('sale:42:rev:2:effect:0:apply', $key);
    }

    // ---------------------------------------------------------------- apply / reverse (GENERAL)

    public function test_apply_snapshot_decreases_location_stock_and_reverse_restores_it(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($p, 10);

        $effects = [[
            'source_detail_id' => 1, 'product_id' => $p, 'product_variant_id' => null,
            'quantity_base' => 4,
        ]];
        $snapshot = $this->svc()->buildSnapshot(LocationAwareSaleStockService::DOC_SALE, $this->wh, $this->loc, $effects, 1);

        DB::transaction(function () use ($snapshot) {
            $this->svc()->applySnapshot($snapshot, 555);
        });

        $qty = DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(6.0, (float) $qty);

        DB::transaction(function () use ($snapshot) {
            $this->svc()->reverseSnapshot($snapshot, 555);
        });

        $qty = DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(10.0, (float) $qty);
    }

    public function test_apply_snapshot_fails_closed_when_insufficient_stock(): void
    {
        $p = $this->makeProduct();
        $this->seedStock($p, 2);

        $effects = [[
            'source_detail_id' => 1, 'product_id' => $p, 'product_variant_id' => null,
            'quantity_base' => 5,
        ]];
        $snapshot = $this->svc()->buildSnapshot(LocationAwareSaleStockService::DOC_SALE, $this->wh, $this->loc, $effects, 1);

        $this->expectException(ValidationException::class);
        DB::transaction(function () use ($snapshot) {
            $this->svc()->applySnapshot($snapshot, 556);
        });
    }

    public function test_sale_return_apply_increases_location_stock(): void
    {
        $p = $this->makeProduct();

        $effects = [[
            'source_detail_id' => 1, 'product_id' => $p, 'product_variant_id' => null,
            'quantity_base' => 3,
        ]];
        $snapshot = $this->svc()->buildSnapshot(LocationAwareSaleStockService::DOC_SALE_RETURN, $this->wh, $this->loc, $effects, 1);

        DB::transaction(function () use ($snapshot) {
            $this->svc()->applySnapshot($snapshot, 900);
        });

        $qty = DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->loc)->where('product_id', $p)->value('quantity');
        $this->assertSame(3.0, (float) $qty);
    }
}
