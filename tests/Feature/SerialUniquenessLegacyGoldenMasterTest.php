<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\ProductSerial;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — serial IDENTITY characterization.
 *
 *  - serial_number is GLOBALLY unique (DB constraint + assertGloballyUnique):
 *    it can be "born" exactly once, across all products and all flows.
 *  - there is NO soft delete: a legacy purchase edit/delete HARD-DELETES the
 *    row, which FREES the string for a later receipt (id changes).
 *  - a product may be BOTH is_variant and is_imei; the purchased serial then
 *    persists product_variant_id (§23 — do NOT declare the combo illegal).
 */
class SerialUniquenessLegacyGoldenMasterTest extends TestCase
{
    use LegacyPurchaseTestSchema;
    use SerialTestSchema;

    private int $wh;
    private int $unit1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLegacyPurchaseSchema();
        $this->buildSerialSchema();
        $this->legacyOwner();

        $this->wh = $this->makeWarehouse('UNIQ-WH');
        $this->unit1 = $this->makeUnit('*', 1);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function line(int $productId, float $qty, array $serials, ?int $variantId = null): array
    {
        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'purchase_unit_id' => $this->unit1,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
            'serial_numbers' => $serials,
        ];
    }

    private function storePayload(array $details): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $this->wh,
            'date' => '2026-09-06',
            'statut' => 'received',
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function store(array $details): void
    {
        $this->controller()->store($this->makeRequest($this->storePayload($details)));
    }

    private function imeiProduct(string $code, string $type = 'is_single'): int
    {
        return (int) $this->makeProduct(['code' => $code, 'type' => $type, 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 2]);
    }

    // =====================================================================
    // Global uniqueness
    // =====================================================================

    public function test_serial_string_cannot_be_reused_for_a_different_product_via_purchase(): void
    {
        $a = $this->imeiProduct('U-A');
        $b = $this->imeiProduct('U-B');
        $this->seedStock($this->wh, $a, 0);
        $this->seedStock($this->wh, $b, 0);

        $this->store([$this->line($a, 1, ['SHARED'])]);
        $this->assertSame(1, $this->serialCount());

        try {
            $this->store([$this->line($b, 1, ['SHARED'])]);
            $this->fail('expected ValidationException — serial_number is global');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already exist', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }
        $this->assertSame(1, $this->serialCount());
        $this->assertSame($a, (int) $this->serialRow('SHARED')->product_id);
    }

    public function test_db_constraint_also_blocks_a_raw_duplicate_insert(): void
    {
        $a = $this->imeiProduct('U-DB');
        DB::table('product_serials')->insert([
            'serial_number' => 'DBUNIQ', 'product_id' => $a, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_AVAILABLE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('product_serials')->insert([
            'serial_number' => 'DBUNIQ', 'product_id' => $a, 'warehouse_id' => $this->wh,
            'status' => ProductSerial::STATUS_SOLD, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // =====================================================================
    // Hard-delete FREES the string
    // =====================================================================

    public function test_deleting_the_origin_purchase_frees_the_serial_string_for_a_new_receipt(): void
    {
        $p = $this->imeiProduct('U-FREE');
        $this->seedStock($this->wh, $p, 0);

        $this->store([$this->line($p, 1, ['RECYCLE'])]);
        $firstId = (int) $this->serialRow('RECYCLE')->id;
        $pid = (int) DB::table('purchases')->latest('id')->value('id');

        // Delete the origin purchase — all its serials are still `available`.
        $this->controller()->destroy($this->makeRequest([], 'DELETE'), $pid);
        $this->assertNull($this->serialRow('RECYCLE'), 'row hard-deleted, string freed');

        // The SAME string can now be received again — as a brand-new row.
        $this->store([$this->line($p, 1, ['RECYCLE'])]);
        $secondId = (int) $this->serialRow('RECYCLE')->id;
        $this->assertNotSame($firstId, $secondId, 'a NEW product_serial id — identity is not stable across delete+recreate');
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('RECYCLE')->status);
    }

    // =====================================================================
    // §23 — variant + IMEI persists product_variant_id
    // =====================================================================

    public function test_variant_and_imei_product_persists_the_variant_on_the_serial(): void
    {
        $p = $this->imeiProduct('U-VARIMEI', 'is_variant');
        $v = $this->makeVariant($p, 'V1');
        $this->seedStock($this->wh, $p, 0, $v);

        $this->store([$this->line($p, 2, ['VI-1', 'VI-2'], $v)]);

        $this->assertSame(2, $this->serialCount(['product_id' => $p]));
        foreach (['VI-1', 'VI-2'] as $sn) {
            $row = $this->serialRow($sn);
            $this->assertSame($p, (int) $row->product_id);
            $this->assertSame($v, (int) $row->product_variant_id, 'variant id persisted on the serial');
            $this->assertSame(ProductSerial::STATUS_AVAILABLE, $row->status);
        }
    }
}
