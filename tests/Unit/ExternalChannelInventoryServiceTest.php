<?php

namespace Tests\Unit;

use App\Services\ExternalChannelInventoryService;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\TestCase;

/**
 * MS7-B2-1 — ExternalChannelInventoryService: deterministic fulfillment-
 * location resolution (§2) + exact-location reserved-aware read (§3/§4/§27)
 * shared by the online store today (OnlineOrdersApiController/
 * CheckoutController/StoreFrontController) and reused by WooCommerce/
 * Shopify/Subscription in their own future milestones.
 */
class ExternalChannelInventoryServiceTest extends TestCase
{
    use LegacyPurchaseTestSchema;

    private int $wh;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('CHANNEL-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function svc(): ExternalChannelInventoryService
    {
        return app(ExternalChannelInventoryService::class);
    }

    public function test_resolves_the_warehouse_default_location(): void
    {
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);

        $location = $this->svc()->resolveFulfillmentLocation($this->wh);

        $this->assertSame($this->loc, $location->id);
    }

    public function test_fails_closed_when_no_default_location_is_set(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->resolveFulfillmentLocation($this->wh);
    }

    public function test_fails_closed_when_default_location_belongs_to_another_warehouse(): void
    {
        $otherWh = $this->makeWarehouse('OTHER-WH');
        $otherLoc = $this->makeInventoryLocation($otherWh);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $otherLoc]);

        $this->expectException(ValidationException::class);
        $this->svc()->resolveFulfillmentLocation($this->wh);
    }

    public function test_fails_closed_when_default_location_is_inactive(): void
    {
        DB::table('inventory_locations')->where('id', $this->loc)->update(['is_active' => 0]);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $this->loc]);

        $this->expectException(ValidationException::class);
        $this->svc()->resolveFulfillmentLocation($this->wh);
    }

    public function test_fails_closed_when_default_location_is_quarantine(): void
    {
        $quarantineLoc = $this->makeInventoryLocation($this->wh, ['type' => 'quarantine', 'is_quarantine' => true]);
        DB::table('warehouses')->where('id', $this->wh)->update(['default_inventory_location_id' => $quarantineLoc]);

        $this->expectException(ValidationException::class);
        $this->svc()->resolveFulfillmentLocation($this->wh);
    }

    public function test_fails_closed_when_warehouse_does_not_exist(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->resolveFulfillmentLocation(999999);
    }

    public function test_available_quantity_excludes_reserved(): void
    {
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 10, null, [
            'reference_type' => 'Test', 'reference_id' => 1, 'user_id' => null,
        ]);
        DB::table('inventory_location_stocks')
            ->where('inventory_location_id', $this->loc)->where('product_id', $p)
            ->update(['reserved_quantity' => 3]);

        $available = $this->svc()->availableQuantity($this->loc, $p, null);

        $this->assertSame(7.0, $available);
    }

    public function test_available_quantity_is_zero_when_no_stock_row_exists(): void
    {
        $p = $this->makeProduct();

        $this->assertSame(0.0, $this->svc()->availableQuantity($this->loc, $p, null));
    }

    public function test_available_quantity_is_exact_to_the_location_not_the_whole_warehouse(): void
    {
        $otherLoc = $this->makeInventoryLocation($this->wh);
        $p = $this->makeProduct();
        app(InventoryService::class)->increase($this->loc, $p, 5, null, [
            'reference_type' => 'Test', 'reference_id' => 1, 'user_id' => null,
        ]);
        app(InventoryService::class)->increase($otherLoc, $p, 100, null, [
            'reference_type' => 'Test', 'reference_id' => 2, 'user_id' => null,
        ]);

        // Only the resolved channel location counts — never the aggregate.
        $this->assertSame(5.0, $this->svc()->availableQuantity($this->loc, $p, null));
    }

    public function test_available_quantity_is_variant_exact(): void
    {
        $p = $this->makeProduct();
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        app(InventoryService::class)->increase($this->loc, $p, 4, $v1, [
            'reference_type' => 'Test', 'reference_id' => 1, 'user_id' => null,
        ]);
        app(InventoryService::class)->increase($this->loc, $p, 9, $v2, [
            'reference_type' => 'Test', 'reference_id' => 2, 'user_id' => null,
        ]);

        $this->assertSame(4.0, $this->svc()->availableQuantity($this->loc, $p, $v1));
        $this->assertSame(9.0, $this->svc()->availableQuantity($this->loc, $p, $v2));
    }
}
