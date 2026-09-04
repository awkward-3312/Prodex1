<?php

namespace Tests\Feature;

use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Services\SerialNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — LEGACY sale serial characterization: SerialNumberService::sellOnSale
 * on a warehouse-scoped (no inventory_location_id) sale.
 *
 * Driven directly through the service with unsaved Sale / SaleDetail models —
 * PosController / SalesController wire the same call. Pins:
 *   available -> sold; sale_id / sale_detail_id / client_id stamped;
 *   `sold` movement; and the four rejection paths (unknown, wrong product /
 *   variant, wrong warehouse, not-available, count mismatch).
 */
class SaleSerialLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $otherWh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('SALE-WH');
        $this->otherWh = $this->makeWarehouse('OTHER-WH');
    }

    private function service(): SerialNumberService
    {
        return app(SerialNumberService::class);
    }

    private function imeiProduct(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1, 'cost' => 2]);
    }

    private function seedSerial(string $sn, int $productId, string $status = ProductSerial::STATUS_AVAILABLE, ?int $warehouseId = null, ?int $variantId = null): int
    {
        return (int) DB::table('product_serials')->insertGetId([
            'serial_number' => $sn,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId ?? $this->wh,
            'inventory_location_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sale(int $id = 1, ?int $clientId = 42): Sale
    {
        return (new Sale)->forceFill([
            'id' => $id,
            'warehouse_id' => $this->wh,
            'client_id' => $clientId,
            'inventory_location_id' => null, // => legacy parent::sellOnSale
        ]);
    }

    private function detail(int $productId, float $qty, int $id = 10, ?int $variantId = null): SaleDetail
    {
        return (new SaleDetail)->forceFill([
            'id' => $id,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'quantity' => $qty,
        ]);
    }

    // =====================================================================
    // §16 — happy path
    // =====================================================================

    public function test_sell_marks_serial_sold_and_stamps_sale_linkage(): void
    {
        $p = $this->imeiProduct('S-A');
        $this->seedSerial('SA-1', $p);
        $this->seedSerial('SA-2', $p);

        $this->service()->sellOnSale($this->sale(101, 42), $this->detail($p, 2, 999), ['SA-1', 'SA-2']);

        foreach (['SA-1', 'SA-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame(ProductSerial::STATUS_SOLD, $row->status);
            $this->assertSame(101, (int) $row->sale_id);
            $this->assertSame(999, (int) $row->sale_detail_id);
            $this->assertSame(42, (int) $row->client_id);
            $this->assertSame($this->wh, (int) $row->warehouse_id, 'warehouse_id unchanged');

            $moves = $this->serialMovements($sn);
            $last = $moves[count($moves) - 1];
            $this->assertSame(ProductSerialMovement::ACTION_SOLD, $last['action']);
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $last['from_status']);
            $this->assertSame(ProductSerial::STATUS_SOLD, $last['to_status']);
            $this->assertSame('Sale', $last['reference_type']);
            $this->assertSame(101, (int) $last['reference_id']);
        }
    }

    public function test_non_imei_product_is_a_no_op(): void
    {
        $p = (int) $this->makeProduct(['code' => 'S-PLAIN', 'is_imei' => 0]);
        $this->seedSerial('SP-1', $p); // stray row, must be ignored

        $this->service()->sellOnSale($this->sale(), $this->detail($p, 1), ['SP-1']);

        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('SP-1')->status);
        $this->assertSame(0, $this->serialMovementCount());
    }

    // =====================================================================
    // §16 — rejection paths (each rolls nothing back because there is no
    // surrounding transaction here; the controllers wrap it in one).
    // =====================================================================

    public function test_unknown_serial_is_422(): void
    {
        $p = $this->imeiProduct('S-U');
        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 1), ['GHOST']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('not found', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
    }

    public function test_serial_of_another_product_is_422(): void
    {
        $p = $this->imeiProduct('S-P1');
        $other = $this->imeiProduct('S-P2');
        $this->seedSerial('X-1', $other);

        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 1), ['X-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('does not belong to this product', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
    }

    public function test_serial_in_another_warehouse_is_422(): void
    {
        $p = $this->imeiProduct('S-W');
        $this->seedSerial('W-1', $p, ProductSerial::STATUS_AVAILABLE, $this->otherWh);

        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 1), ['W-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('not in the selected warehouse', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('W-1')->status);
    }

    public function test_non_available_serial_is_422(): void
    {
        $p = $this->imeiProduct('S-NA');
        $this->seedSerial('NA-1', $p, ProductSerial::STATUS_SOLD);

        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 1), ['NA-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('not available', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
    }

    public function test_serial_count_must_match_the_line_quantity(): void
    {
        $p = $this->imeiProduct('S-CNT');
        $this->seedSerial('C-1', $p);
        $this->seedSerial('C-2', $p);

        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 2), ['C-1']); // 1 serial, qty 2
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('must match the quantity', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('C-1')->status);
    }

    public function test_variant_mismatch_is_422(): void
    {
        $p = $this->imeiProduct('S-VAR');
        $v1 = $this->makeVariant($p, 'V1');
        $v2 = $this->makeVariant($p, 'V2');
        $this->seedSerial('V-1', $p, ProductSerial::STATUS_AVAILABLE, $this->wh, $v2);

        try {
            $this->service()->sellOnSale($this->sale(), $this->detail($p, 1, 10, $v1), ['V-1']);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('does not belong to this product', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
    }
}
