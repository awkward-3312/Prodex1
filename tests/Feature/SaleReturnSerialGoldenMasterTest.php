<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Services\SerialNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — sale-return serial characterization (§18): both the LEGACY
 * (warehouse-scoped) and the LOCATION-NATIVE branches of
 * SerialNumberService::returnFromSale, plus reverseForSaleReturn.
 *
 *  LEGACY   : sold -> available, warehouse_id := return.warehouse_id,
 *             inventory_location_id untouched.
 *  LOCATION : sold -> available, inventory_location_id := return location,
 *             warehouse_id untouched; sale_id / sale_detail_id / client_id
 *             are KEPT.
 *  REVERSE  : available -> sold (status_changed movement).
 */
class SaleReturnSerialGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $returnWh;
    private int $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SR-WH');
        $this->returnWh = $this->makeWarehouse('SR-RETURN-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function service(): SerialNumberService
    {
        return app(SerialNumberService::class);
    }

    private function imeiProduct(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1, 'cost' => 2]);
    }

    private function seedSoldSerial(string $sn, int $productId, int $saleId = 900, ?int $locationId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn,
            'product_id' => $productId,
            'warehouse_id' => $this->wh,
            'inventory_location_id' => $locationId,
            'status' => ProductSerial::STATUS_SOLD,
            'sale_id' => $saleId,
            'sale_detail_id' => 5000,
            'client_id' => 77,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyReturn(int $id = 1, ?int $saleId = 900): SaleReturn
    {
        return (new SaleReturn)->forceFill([
            'id' => $id,
            'warehouse_id' => $this->returnWh,
            'sale_id' => $saleId,
            'inventory_location_id' => null,
        ]);
    }

    private function locationReturn(int $id = 1, int $saleId = 900): SaleReturn
    {
        return (new SaleReturn)->forceFill([
            'id' => $id,
            'warehouse_id' => $this->wh,
            'sale_id' => $saleId,
            'inventory_location_id' => $this->loc,
        ]);
    }

    private function detail(int $productId, float $qty, int $id = 20): SaleReturnDetails
    {
        return (new SaleReturnDetails)->forceFill([
            'id' => $id,
            'product_id' => $productId,
            'product_variant_id' => null,
            'quantity' => $qty,
        ]);
    }

    // =====================================================================
    // LEGACY branch
    // =====================================================================

    public function test_legacy_return_moves_serial_to_available_in_the_return_warehouse(): void
    {
        $p = $this->imeiProduct('SR-L');
        $this->seedSoldSerial('L-1', $p, 900);

        $this->service()->returnFromSale($this->legacyReturn(1, 900), $this->detail($p, 1), ['L-1']);

        $row = $this->serialRow('L-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($this->returnWh, (int) $row->warehouse_id, 'warehouse_id := return.warehouse_id');
        $this->assertNull($row->inventory_location_id);
        // sale linkage retained.
        $this->assertSame(900, (int) $row->sale_id);
        $this->assertSame(5000, (int) $row->sale_detail_id);

        $moves = $this->serialMovements('L-1');
        $last = $moves[count($moves) - 1];
        $this->assertSame(ProductSerialMovement::ACTION_SALE_RETURNED, $last['action']);
        $this->assertSame(ProductSerial::STATUS_SOLD, $last['from_status']);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $last['to_status']);
        $this->assertSame('SaleReturn', $last['reference_type']);
        $this->assertSame(1, (int) $last['reference_id']);
    }

    public function test_legacy_return_rejects_a_serial_not_from_the_referenced_sale(): void
    {
        $p = $this->imeiProduct('SR-LX');
        $this->seedSoldSerial('LX-1', $p, 111); // sold on sale 111, not 900

        try {
            $this->service()->returnFromSale($this->legacyReturn(1, 900), $this->detail($p, 1), ['LX-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('not sold on the referenced sale', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('LX-1')->status);
    }

    public function test_legacy_return_rejects_a_non_sold_serial(): void
    {
        $p = $this->imeiProduct('SR-LN');
        DB::table('product_serials')->insert([
            'serial_number' => 'LN-1', 'product_id' => $p, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_AVAILABLE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->service()->returnFromSale($this->legacyReturn(1, null), $this->detail($p, 1), ['LN-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('was not sold', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
    }

    // =====================================================================
    // LOCATION-NATIVE branch
    // =====================================================================

    public function test_location_return_moves_serial_to_available_at_the_return_location(): void
    {
        $p = $this->imeiProduct('SR-N');
        $this->seedSoldSerial('N-1', $p, 900);

        $this->service()->returnFromSale($this->locationReturn(7, 900), $this->detail($p, 1), ['N-1']);

        $row = $this->serialRow('N-1');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        $this->assertSame($this->loc, (int) $row->inventory_location_id, 'inventory_location_id := return location');
        $this->assertSame($this->wh, (int) $row->warehouse_id, 'warehouse_id untouched on the location branch');
        // sale linkage retained.
        $this->assertSame(900, (int) $row->sale_id);
        $this->assertSame(77, (int) $row->client_id);

        $moves = $this->serialMovements('N-1');
        $last = $moves[count($moves) - 1];
        $this->assertSame(ProductSerialMovement::ACTION_SALE_RETURNED, $last['action']);
        $this->assertSame($this->loc, (int) $last['to_inventory_location_id']);
    }

    public function test_location_return_count_must_match_quantity(): void
    {
        $p = $this->imeiProduct('SR-NC');
        $this->seedSoldSerial('NC-1', $p, 900);
        $this->seedSoldSerial('NC-2', $p, 900);

        try {
            $this->service()->returnFromSale($this->locationReturn(7, 900), $this->detail($p, 2), ['NC-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('serial_numbers', $e->errors());
        }
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('NC-1')->status);
    }

    // =====================================================================
    // REVERSE a sale return
    // =====================================================================

    public function test_reverse_sale_return_re_marks_serials_sold(): void
    {
        $p = $this->imeiProduct('SR-REV');
        $this->seedSoldSerial('RV-1', $p, 900);

        $return = $this->legacyReturn(9, 900);
        $this->service()->returnFromSale($return, $this->detail($p, 1), ['RV-1']);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RV-1')->status);

        $this->service()->reverseForSaleReturn($return);

        $row = $this->serialRow('RV-1');
        $this->assertSame(ProductSerial::STATUS_SOLD, $row->status, 'reverse re-marks it sold');
        $moves = $this->serialMovements('RV-1');
        $last = $moves[count($moves) - 1];
        $this->assertSame(ProductSerialMovement::ACTION_STATUS_CHANGED, $last['action']);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $last['from_status']);
        $this->assertSame(ProductSerial::STATUS_SOLD, $last['to_status']);
    }

    public function test_reverse_sale_return_is_best_effort_and_skips_a_serial_that_moved_on(): void
    {
        $p = $this->imeiProduct('SR-REVBE');
        $this->seedSoldSerial('RB-1', $p, 900);

        $return = $this->legacyReturn(9, 900);
        $this->service()->returnFromSale($return, $this->detail($p, 1), ['RB-1']);

        // Something re-sold RB-1 before the return was reversed.
        DB::table('product_serials')->where('serial_number', 'RB-1')->update([
            'status' => ProductSerial::STATUS_SOLD, 'updated_at' => now(),
        ]);
        $movesBefore = count($this->serialMovements('RB-1'));

        $this->service()->reverseForSaleReturn($return);

        // Left untouched (already not `available`), no new movement.
        $this->assertSame($movesBefore, count($this->serialMovements('RB-1')));
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('RB-1')->status);
    }
}
