<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Services\SerialInventoryCoverageService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0 — §38: SerialInventoryCoverageService.
 *
 * is_ready  <=>  general_quantity is an integer AND equals COUNT(available
 * serials at that exact product+variant+location). Every non-available status
 * and every NULL-location serial is excluded.
 */
class SerialInventoryCoverageServiceTest extends TestCase
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

        $this->wh = $this->makeWarehouse('COV-WH');
        $this->loc = $this->makeInventoryLocation($this->wh);
    }

    private function svc(): SerialInventoryCoverageService
    {
        return app(SerialInventoryCoverageService::class);
    }

    private function general(int $productId, float $qty, ?int $variantId = null): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $this->loc, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'variant_key' => (int) ($variantId ?: 0), 'quantity' => $qty, 'reserved_quantity' => 0, 'manage_stock' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function serial(string $sn, int $productId, string $status, ?int $locationId = null, ?int $variantId = null): void
    {
        DB::table('product_serials')->insert([
            'serial_number' => $sn, 'product_id' => $productId, 'product_variant_id' => $variantId,
            'warehouse_id' => $this->wh, 'inventory_location_id' => $locationId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_A_general_10_serials_10_is_ready(): void
    {
        $p = (int) $this->makeProduct(['code' => 'A', 'is_imei' => 1]);
        $this->general($p, 10);
        for ($i = 1; $i <= 10; $i++) {
            $this->serial("A-$i", $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        }
        $c = $this->svc()->coverageForLocation($this->loc, $p);
        $this->assertSame(10.0, $c['general_quantity']);
        $this->assertSame(10, $c['available_serial_count']);
        $this->assertTrue($c['is_integer']);
        $this->assertTrue($c['is_ready']);
    }

    public function test_B_general_10_serials_9_is_not_ready(): void
    {
        $p = (int) $this->makeProduct(['code' => 'B', 'is_imei' => 1]);
        $this->general($p, 10);
        for ($i = 1; $i <= 9; $i++) {
            $this->serial("B-$i", $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        }
        $this->assertFalse($this->svc()->coverageForLocation($this->loc, $p)['is_ready']);
    }

    public function test_C_general_9_serials_10_is_not_ready(): void
    {
        $p = (int) $this->makeProduct(['code' => 'C', 'is_imei' => 1]);
        $this->general($p, 9);
        for ($i = 1; $i <= 10; $i++) {
            $this->serial("C-$i", $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        }
        $this->assertFalse($this->svc()->coverageForLocation($this->loc, $p)['is_ready']);
    }

    public function test_D_fractional_general_is_not_ready(): void
    {
        $p = (int) $this->makeProduct(['code' => 'D', 'is_imei' => 1]);
        $this->general($p, 10.5);
        for ($i = 1; $i <= 10; $i++) {
            $this->serial("D-$i", $p, ProductSerial::STATUS_AVAILABLE, $this->loc);
        }
        $c = $this->svc()->coverageForLocation($this->loc, $p);
        $this->assertFalse($c['is_integer']);
        $this->assertFalse($c['is_ready']);
    }

    public function test_E_empty_product_is_ready(): void
    {
        $p = (int) $this->makeProduct(['code' => 'E', 'is_imei' => 1]);
        $c = $this->svc()->coverageForLocation($this->loc, $p);
        $this->assertSame(0.0, $c['general_quantity']);
        $this->assertSame(0, $c['available_serial_count']);
        $this->assertTrue($c['is_ready']);
    }

    public function test_FGHI_non_available_and_null_location_serials_are_excluded(): void
    {
        $p = (int) $this->makeProduct(['code' => 'F', 'is_imei' => 1]);
        $this->general($p, 1);
        $this->serial('F-OK', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);      // counted
        $this->serial('F-SOLD', $p, ProductSerial::STATUS_SOLD, $this->loc);          // excluded
        $this->serial('F-RS', $p, ProductSerial::STATUS_RETURNED_SUPPLIER, $this->loc); // excluded
        $this->serial('F-VOID', $p, ProductSerial::STATUS_VOIDED, $this->loc);        // excluded
        $this->serial('F-RES', $p, ProductSerial::STATUS_RESERVED, null);             // excluded (null loc)
        $this->serial('F-NULLLOC', $p, ProductSerial::STATUS_AVAILABLE, null);        // excluded (null loc)

        $c = $this->svc()->coverageForLocation($this->loc, $p);
        $this->assertSame(1, $c['available_serial_count']);
        $this->assertTrue($c['is_ready']);
    }

    public function test_K_variant_pools_are_independent(): void
    {
        $p = (int) $this->makeProduct(['code' => 'K', 'type' => 'is_variant', 'is_imei' => 1]);
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->general($p, 2, $v1);
        $this->general($p, 0, $v2);
        $this->serial('K-1', $p, ProductSerial::STATUS_AVAILABLE, $this->loc, $v1);
        $this->serial('K-2', $p, ProductSerial::STATUS_AVAILABLE, $this->loc, $v1);
        $this->serial('K-3', $p, ProductSerial::STATUS_AVAILABLE, $this->loc, $v2); // v2 general is 0 -> drift

        $this->assertTrue($this->svc()->coverageForLocation($this->loc, $p, $v1)['is_ready']);
        $this->assertFalse($this->svc()->coverageForLocation($this->loc, $p, $v2)['is_ready']);
    }

    public function test_J_unmigrated_legacy_serials_are_detected(): void
    {
        $p = (int) $this->makeProduct(['code' => 'J', 'is_imei' => 1]);
        $this->serial('J-LEGACY', $p, ProductSerial::STATUS_AVAILABLE, null); // warehouse-only, no location
        $this->serial('J-OK', $p, ProductSerial::STATUS_AVAILABLE, $this->loc);

        $this->assertSame(1, $this->svc()->unmigratedLegacySerialCount($this->wh));
        $this->assertSame(1, $this->svc()->unmigratedLegacySerialCount($this->wh, $p));
    }
}
