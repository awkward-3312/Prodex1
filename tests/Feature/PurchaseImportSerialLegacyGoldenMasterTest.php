<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\InventoryTransitionState as Mode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — the LEGACY IMPORT serial hole.
 *
 * store_import_purchases (CSV: productcode;qty) has NO serial column and NEVER
 * calls SerialNumberService. On a legacy warehouse a received IMEI import
 * increments product_warehouse.qte with ZERO ProductSerial rows — historical
 * drift, characterized here, NOT fixed.
 *
 * The MS5-E native import (location_primary) still 422s any IMEI row — that
 * behaviour is asserted here too and must not change.
 */
class PurchaseImportSerialLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $legacyWh;
    private int $unit1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildLocationNativeInventorySchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->legacyWh = $this->makeWarehouse('LEGACY-IMP-SN');   // NO transition state
        $this->unit1 = $this->makeUnit('*', 1);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    /** @param array<int,array{0:string,1:int|float}> $rows */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'impsn').'.csv';
        $fh = fopen($path, 'w');
        fwrite($fh, "productcode;qty\n");
        foreach ($rows as [$code, $qty]) {
            fwrite($fh, "{$code};{$qty}\n");
        }
        fclose($fh);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    private function payload(int $wh, string $statut = 'received', $loc = null): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $wh,
            'inventory_location_id' => $loc,
            'date' => '2026-09-06',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
        ];
    }

    private function runImport(UploadedFile $file, array $payload)
    {
        return $this->controller()->store_import_purchases($this->makeRequest($payload, 'POST', ['products' => $file]));
    }

    // =====================================================================
    // §15 — legacy import creates NO serial rows for an IMEI product
    // =====================================================================

    public function test_legacy_import_received_imei_increments_stock_but_creates_zero_serials(): void
    {
        $p = (int) $this->makeProduct(['code' => 'IMP-IMEI', 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 3]);
        $this->seedStock($this->legacyWh, $p, 0);

        $res = $this->runImport($this->csv([['IMP-IMEI', 4]]), $this->payload($this->legacyWh));
        $body = json_decode($res->getContent(), true);

        $this->assertTrue($body['success']);
        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(1, DB::table('purchase_details')->count());
        $this->assertSame(4.0, $this->stockOf($this->legacyWh, $p), 'product_warehouse incremented');

        // The hole: zero serial ledger despite an IMEI product being received.
        $this->assertSame(0, $this->serialCount());
        $this->assertSame(0, $this->serialMovementCount());
        // legacy imei_number text column is null (import never fills it).
        $this->assertNull(DB::table('purchase_details')->value('imei_number'));
    }

    public function test_legacy_import_pending_imei_also_creates_no_serials(): void
    {
        $p = (int) $this->makeProduct(['code' => 'IMP-IMEI-P', 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 3]);
        $this->seedStock($this->legacyWh, $p, 0);

        $this->runImport($this->csv([['IMP-IMEI-P', 5]]), $this->payload($this->legacyWh, 'pending'));

        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(0.0, $this->stockOf($this->legacyWh, $p));
        $this->assertSame(0, $this->serialCount());
    }

    // =====================================================================
    // MS5-E native import still FAILS CLOSED on IMEI — must not change
    // =====================================================================

    public function test_native_import_still_fails_closed_on_an_imei_row(): void
    {
        $loc = $this->makeInventoryLocation($this->legacyWh);
        DB::table('warehouses')->where('id', $this->legacyWh)->update(['default_inventory_location_id' => $loc]);
        $this->setTransitionMode($this->legacyWh, Mode::MODE_LOCATION_PRIMARY, $loc, 'healthy');

        $p = (int) $this->makeProduct(['code' => 'IMP-NAT', 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 3]);
        $this->seedLocationStock($loc, $p, 0);

        try {
            $this->runImport($this->csv([['IMP-NAT', 3]]), $this->payload($this->legacyWh, 'received', $loc));
            $this->fail('expected ValidationException — IMEI still fail-closed on the native import');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('serializado', implode(' ', $e->errors()['products.0'] ?? ['']));
        }
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, $this->serialCount());
    }
}
