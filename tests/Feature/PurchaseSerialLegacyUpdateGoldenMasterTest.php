<?php

namespace Tests\Feature;

use App\Http\Controllers\PurchasesController;
use App\Models\ProductSerial;
use App\Models\ProductSerialMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyPurchaseTestSchema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — LEGACY serial characterization for PurchasesController::update
 * (non location_primary warehouse). Pins current behaviour; NOT a fix.
 *
 *  §7  received->received re-apply DESTROYS + RECREATES serial rows (new ids,
 *      old movements gone) — identity churn.
 *  §8  the "serial already moved" guard: if any serial of the purchase is no
 *      longer `available`, the whole edit 422s and rolls back.
 *  §9  payloadHasSerials() == false => the serial ledger is left untouched.
 *  §10 state-transition matrix. NOTE: the resync gate reads the ALREADY-UPDATED
 *      $current_Purchase->statut, so received->pending deletes and does NOT
 *      recreate; pending->received recreates.
 */
class PurchaseSerialLegacyUpdateGoldenMasterTest extends TestCase
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

        $this->wh = $this->makeWarehouse('LEGACY-WH-U');
        $this->unit1 = $this->makeUnit('*', 1);
    }

    private function controller(): PurchasesController
    {
        return new PurchasesController;
    }

    private function imeiProduct(string $code): int
    {
        return (int) $this->makeProduct(['code' => $code, 'is_imei' => 1, 'unit_purchase_id' => $this->unit1, 'cost' => 2]);
    }

    private function line(int $productId, float $qty, $serials, $id = null): array
    {
        $row = [
            'product_id' => $productId,
            'product_variant_id' => null,
            'purchase_unit_id' => $this->unit1,
            'quantity' => $qty,
            'Unit_cost' => 2,
            'tax_percent' => 0, 'tax_method' => '1', 'discount' => 0, 'discount_Method' => '2',
            'subtotal' => $qty * 2,
            'no_unit' => 1,
        ];
        if ($serials !== null) {
            $row['serial_numbers'] = $serials;
        }
        if ($id !== null) {
            $row['id'] = $id;
        }

        return $row;
    }

    private function payload(array $details, string $statut = 'received'): array
    {
        return [
            'supplier_id' => 7,
            'warehouse_id' => $this->wh,
            'date' => '2026-09-05',
            'statut' => $statut,
            'notes' => null,
            'tax_rate' => 0, 'TaxNet' => 0, 'discount' => 0, 'shipping' => 0,
            'GrandTotal' => 100,
            'details' => $details,
        ];
    }

    private function doUpdate(int $pid, array $details, string $statut): void
    {
        $this->controller()->update($this->makeRequest($this->payload($details, $statut), 'PUT'), $pid);
    }

    /** @return array{0:int,1:int} [purchaseId, detailId] */
    private function createReceived(int $productId, float $qty, array $serials, string $statut = 'received'): array
    {
        $this->controller()->store($this->makeRequest($this->payload([$this->line($productId, $qty, $serials)], $statut)));
        $pid = (int) DB::table('purchases')->latest('id')->value('id');
        $did = (int) DB::table('purchase_details')->where('purchase_id', $pid)->value('id');

        return [$pid, $did];
    }

    // =====================================================================
    // §7 — identity churn on received -> received
    // =====================================================================

    public function test_received_to_received_hard_deletes_then_recreates_serial_rows(): void
    {
        $p = $this->imeiProduct('IM-CHURN');
        $this->seedStock($this->wh, $p, 0);
        [$pid, $did] = $this->createReceived($p, 2, ['SN-A', 'SN-B']);

        $oldIds = DB::table('product_serials')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $oldMoveIds = DB::table('product_serial_movements')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertCount(2, $oldIds);

        // Same serial strings, same quantity — a no-op edit from the user's POV.
        $this->doUpdate($pid, [$this->line($p, 2, ['SN-A', 'SN-B'], $did)], 'received');

        $newRows = DB::table('product_serials')->orderBy('id')->get();
        $this->assertCount(2, $newRows);
        $newIds = $newRows->pluck('id')->map(fn ($i) => (int) $i)->all();

        // NEW primary keys — the old rows were hard-deleted, not updated.
        $this->assertEmpty(array_intersect($oldIds, $newIds), 'serial rows were recreated with new ids');
        // serial_number strings are reused.
        $this->assertSame(['SN-A', 'SN-B'], $newRows->pluck('serial_number')->sort()->values()->all());
        // Old movements are gone; fresh 'purchased' movements exist.
        $this->assertSame(0, DB::table('product_serial_movements')->whereIn('id', $oldMoveIds)->count());
        $this->assertSame(2, DB::table('product_serial_movements')->where('action', ProductSerialMovement::ACTION_PURCHASED)->count());
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $newRows->first()->status);
    }

    // =====================================================================
    // §8 — edit BLOCKED once a serial has moved downstream (sold)
    // =====================================================================

    public function test_edit_is_blocked_and_rolled_back_when_a_serial_is_no_longer_available(): void
    {
        $p = $this->imeiProduct('IM-GUARD');
        $this->seedStock($this->wh, $p, 5);
        [$pid, $did] = $this->createReceived($p, 2, ['G-1', 'G-2']);
        $this->assertSame(7.0, $this->stockOf($this->wh, $p)); // 5 seed + 2 received

        // Simulate a downstream POS/Sale consuming G-1.
        DB::table('product_serials')->where('serial_number', 'G-1')->update([
            'status' => ProductSerial::STATUS_SOLD, 'sale_id' => 999, 'updated_at' => now(),
        ]);

        try {
            $this->doUpdate($pid, [$this->line($p, 3, ['G-1', 'G-2', 'G-3'], $did)], 'received');
            $this->fail('expected ValidationException (serial G-1 already moved)');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('already moved', implode(' ', $e->errors()['serial_numbers'] ?? ['']));
        }

        // NOTHING changed: purchase, detail, serials, stock.
        $this->assertSame(2.0, (float) DB::table('purchase_details')->where('id', $did)->value('quantity'));
        $this->assertSame(7.0, $this->stockOf($this->wh, $p), 'stock reverse rolled back');
        $this->assertSame(ProductSerial::STATUS_SOLD, $this->serialRow('G-1')->status);
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('G-2')->status);
        $this->assertNull($this->serialRow('G-3'), 'no new serial was created');
        $this->assertSame(2, $this->serialCount(['purchase_id' => $pid]));
    }

    // =====================================================================
    // §9 — no serial payload => ledger untouched
    // =====================================================================

    public function test_update_without_serial_payload_leaves_the_serial_ledger_untouched(): void
    {
        $p = $this->imeiProduct('IM-NOPAY');
        $this->seedStock($this->wh, $p, 0);
        [$pid, $did] = $this->createReceived($p, 2, ['N-1', 'N-2']);

        $before = DB::table('product_serials')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $beforeMoves = DB::table('product_serial_movements')->count();

        // Edit that carries NO serial_numbers key on any line.
        $this->doUpdate($pid, [$this->line($p, 2, null, $did)], 'received');

        $after = DB::table('product_serials')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame(
            array_map(fn ($r) => [$r['id'], $r['serial_number'], $r['status']], $before),
            array_map(fn ($r) => [$r['id'], $r['serial_number'], $r['status']], $after),
            'same ids, same status — untouched'
        );
        $this->assertSame($beforeMoves, DB::table('product_serial_movements')->count());
    }

    // =====================================================================
    // §10 — state-transition matrix
    // =====================================================================

    public function test_received_to_pending_deletes_serials_and_does_not_recreate(): void
    {
        $p = $this->imeiProduct('IM-R2P');
        $this->seedStock($this->wh, $p, 0);
        [$pid, $did] = $this->createReceived($p, 2, ['R-1', 'R-2']);
        $this->assertSame(2, $this->serialCount());

        $this->doUpdate($pid, [$this->line($p, 2, ['R-1', 'R-2'], $did)], 'pending');

        $this->assertSame('pending', DB::table('purchases')->where('id', $pid)->value('statut'));
        $this->assertSame(0, $this->serialCount(), 'reverse deleted; resync skipped once statut is pending');
        $this->assertSame(0, DB::table('product_serial_movements')->count());
    }

    public function test_pending_to_received_creates_serial_rows(): void
    {
        $p = $this->imeiProduct('IM-P2R');
        $this->seedStock($this->wh, $p, 0);
        [$pid, $did] = $this->createReceived($p, 2, ['PR-1', 'PR-2'], 'pending');
        $this->assertSame(0, $this->serialCount());

        $this->doUpdate($pid, [$this->line($p, 2, ['PR-1', 'PR-2'], $did)], 'received');

        $this->assertSame(2, $this->serialCount(['product_id' => $p]));
        $this->assertSame(ProductSerial::STATUS_AVAILABLE, $this->serialRow('PR-1')->status);
        $this->assertSame(2, DB::table('product_serial_movements')->where('action', ProductSerialMovement::ACTION_PURCHASED)->count());
    }

    public function test_pending_to_pending_creates_no_serial_rows(): void
    {
        $p = $this->imeiProduct('IM-P2P');
        $this->seedStock($this->wh, $p, 0);
        [$pid, $did] = $this->createReceived($p, 2, ['PP-1', 'PP-2'], 'pending');

        $this->doUpdate($pid, [$this->line($p, 3, ['PP-1', 'PP-2', 'PP-3'], $did)], 'pending');

        $this->assertSame(0, $this->serialCount());
        $this->assertSame(0, DB::table('product_serial_movements')->count());
    }
}
