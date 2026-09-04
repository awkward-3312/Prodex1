<?php

namespace Tests\Unit;

use App\Http\Controllers\SalesReturnController;
use App\Services\LocationAwareSaleStockService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B1 §27-29 — SalesReturnController::assertReturnWithinSoldQuantity:
 * a native SaleReturn must never credit back more of a product/variant than
 * the referenced (native) Sale actually sold, cumulative across every OTHER
 * active SaleReturn against the same sale. Exercised directly (private
 * method, via reflection) since the full sales/sale_returns HTTP schema
 * isn't part of the shared test fixtures.
 */
class SalesReturnQuantityGuardTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        Schema::create('sales', function ($t) {
            $t->increments('id');
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->json('inventory_effect_snapshot')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('sale_returns', function ($t) {
            $t->increments('id');
            $t->integer('sale_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->integer('inventory_location_id')->nullable();
            $t->json('inventory_effect_snapshot')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function invokeGuard(?int $saleId, ?int $excludeReturnId, array $validatedLines): void
    {
        $controller = new SalesReturnController;
        $m = new ReflectionMethod($controller, 'assertReturnWithinSoldQuantity');
        $m->setAccessible(true);
        $m->invoke($controller, $saleId ?? 0, $excludeReturnId, $validatedLines);
    }

    private function makeSale(int $productId, float $qtyBase, ?int $variantId = null): int
    {
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'warehouse_id' => 1, 'inventory_location_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = app(LocationAwareSaleStockService::class)->buildSnapshot(
            LocationAwareSaleStockService::DOC_SALE, 1, 1,
            [['source_detail_id' => 1, 'product_id' => $productId, 'product_variant_id' => $variantId, 'quantity_base' => $qtyBase]],
            1
        );
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $saleId)->update(['inventory_effect_snapshot' => json_encode($snapshot)]);

        return $saleId;
    }

    private function makeReturn(int $saleId, int $productId, float $qtyBase, ?int $variantId = null): int
    {
        $snapshot = app(LocationAwareSaleStockService::class)->buildSnapshot(
            LocationAwareSaleStockService::DOC_SALE_RETURN, 1, 1,
            [['source_detail_id' => 1, 'product_id' => $productId, 'product_variant_id' => $variantId, 'quantity_base' => $qtyBase]],
            1
        );

        return \Illuminate\Support\Facades\DB::table('sale_returns')->insertGetId([
            'sale_id' => $saleId, 'warehouse_id' => 1, 'inventory_location_id' => 1,
            'inventory_effect_snapshot' => json_encode($snapshot),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_no_sale_id_skips_the_guard(): void
    {
        $this->invokeGuard(null, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 999]]);
        $this->addToAssertionCount(1); // no exception => pass
    }

    public function test_legacy_sale_without_snapshot_skips_the_guard(): void
    {
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'warehouse_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 999]]);
        $this->addToAssertionCount(1);
    }

    public function test_return_within_sold_quantity_is_allowed(): void
    {
        $saleId = $this->makeSale(1, 10);
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 10]]);
        $this->addToAssertionCount(1);
    }

    public function test_return_exceeding_sold_quantity_is_422(): void
    {
        $saleId = $this->makeSale(1, 10);
        $this->expectException(ValidationException::class);
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 11]]);
    }

    public function test_partial_returns_reduce_remaining_returnable(): void
    {
        $saleId = $this->makeSale(1, 10);
        $this->makeReturn($saleId, 1, 3);
        $this->makeReturn($saleId, 1, 2);
        // 10 sold - 5 already returned = 5 left. Exactly 5 is fine.
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 5]]);
        $this->addToAssertionCount(1);
    }

    public function test_cumulative_over_return_across_multiple_returns_is_422(): void
    {
        $saleId = $this->makeSale(1, 10);
        $this->makeReturn($saleId, 1, 3);
        $this->makeReturn($saleId, 1, 2);
        // 10 sold - 5 already returned = 5 left. Asking for 6 must fail.
        $this->expectException(ValidationException::class);
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 6]]);
    }

    public function test_editing_a_return_excludes_its_own_prior_effect(): void
    {
        $saleId = $this->makeSale(1, 10);
        $thisReturnId = $this->makeReturn($saleId, 1, 3);
        // This same return being edited from qty 3 -> 4: must NOT double-count
        // its own current 3 against the 10 sold (would wrongly read as 3+4=7,
        // which is still <= 10 here, so pick a tighter case below too).
        $this->invokeGuard($saleId, $thisReturnId, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 4]]);
        $this->addToAssertionCount(1);

        // Now push it right up against the sold ceiling (10) while excluding
        // itself — must be allowed even though its own snapshot still says 3.
        $this->invokeGuard($saleId, $thisReturnId, [['product_id' => 1, 'product_variant_id' => null, 'quantity_base' => 10]]);
        $this->addToAssertionCount(1);
    }

    public function test_variant_lines_are_tracked_independently(): void
    {
        $saleId = \Illuminate\Support\Facades\DB::table('sales')->insertGetId([
            'warehouse_id' => 1, 'inventory_location_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $snapshot = app(LocationAwareSaleStockService::class)->buildSnapshot(
            LocationAwareSaleStockService::DOC_SALE, 1, 1,
            [
                ['source_detail_id' => 1, 'product_id' => 1, 'product_variant_id' => 5, 'quantity_base' => 4],
                ['source_detail_id' => 2, 'product_id' => 1, 'product_variant_id' => 6, 'quantity_base' => 4],
            ],
            1
        );
        \Illuminate\Support\Facades\DB::table('sales')->where('id', $saleId)->update(['inventory_effect_snapshot' => json_encode($snapshot)]);

        // Variant 5 sold 4 — asking to return 4 of variant 5 must NOT be
        // blocked by variant 6's independent 4.
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => 5, 'quantity_base' => 4]]);
        $this->addToAssertionCount(1);

        $this->expectException(ValidationException::class);
        $this->invokeGuard($saleId, null, [['product_id' => 1, 'product_variant_id' => 5, 'quantity_base' => 5]]);
    }
}
