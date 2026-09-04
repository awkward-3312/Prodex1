<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Tests\Support\SerialTestSchema;
use Tests\TestCase;

/**
 * MS6-B0A — source / schema contracts for the serial legacy baseline.
 *
 * These pin structural facts that MS6-B0 foundation will DELIBERATELY change:
 *   §20 Transfer RECEIVE currently increases GENERAL stock BEFORE it touches
 *       the SERIAL ledger (opposite of dispatch / POS B1 / InternalMove).
 *   §22 legacy Purchase runs BatchService and SerialNumberService as two
 *       INDEPENDENT writers, with no product-level mutual-exclusion.
 *   §24 product_serial_movements has NO idempotency_key / unique constraint.
 *   §25 ProductSerial has NO SoftDeletes; the legacy purchase reverse
 *       hard-deletes both the serial rows and their movements.
 *
 * Pattern / structure based — never line numbers.
 */
class SerialLegacyContractTest extends TestCase
{
    use SerialTestSchema;

    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    /** Body of `function <name>(` up to the next `function ` at the same-ish depth. */
    private function fn(string $src, string $name): string
    {
        if (! preg_match('/\n\s*(?:public|private|protected)\s+function '.preg_quote($name, '/').'\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("method {$name}() not found");
        }
        $start = $m[0][1];
        $rest = substr($src, $start + strlen($m[0][0]));
        $end = preg_match('/\n\s*(?:public|private|protected)\s+function /', $rest, $mm, PREG_OFFSET_CAPTURE)
            ? $mm[0][1] : strlen($rest);

        return substr($src, $start, strlen($m[0][0]) + $end);
    }

    // =====================================================================
    // §20 — Transfer RECEIVE lock order: GENERAL before SERIAL (current)
    // =====================================================================

    public function test_transfer_receive_currently_increases_general_before_serial(): void
    {
        $src = $this->read('app/Services/LocationAwareTransferLogisticsService.php');
        $body = $this->fn($src, 'creditGoodStock');

        $generalPos = strpos($body, 'InventoryService::class)->increase(');
        $serialPos = strpos($body, 'TransferSerialLocationService::class)->receiveGood(');

        $this->assertNotFalse($generalPos, 'expected a general increase in the receive-good path');
        $this->assertNotFalse($serialPos, 'expected a serial receiveGood in the receive-good path');
        $this->assertLessThan(
            $serialPos,
            $generalPos,
            'CURRENT behaviour: GENERAL increase precedes the SERIAL step on transfer receive. '
            .'MS6 (next milestone) flips this to SERIAL -> GENERAL.'
        );
    }

    public function test_transfer_DISPATCH_already_does_serial_before_general(): void
    {
        // Contrast: dispatch is already canonical (BATCH/SERIAL then GENERAL).
        $src = $this->read('app/Services/TransferLocationDispatchService.php');
        $body = $this->fn($src, 'ensureDispatched');
        $serialPos = strpos($body, 'TransferSerialLocationService::class)->dispatchDetail(');
        $generalPos = strpos($body, 'InventoryService::class)->decrease(');
        $this->assertNotFalse($serialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($generalPos, $serialPos);
    }

    // =====================================================================
    // §21 — InternalInventoryMove already locks SERIAL before GENERAL
    //       (covered by PosArtifactLockOrderArchitectureTest; re-pinned here)
    // =====================================================================

    public function test_internal_inventory_move_serial_precedes_general(): void
    {
        $src = $this->read('app/Services/InternalInventoryMoveService.php');
        $body = $this->fn($src, 'move');
        $serialPos = strpos($body, 'SerialLocationService::class)->moveSerials(');
        $generalPos = strpos($body, 'InventoryService::class)->move(');
        $this->assertNotFalse($serialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($generalPos, $serialPos, 'internal move: SERIAL before GENERAL (canonical)');
    }

    // =====================================================================
    // §17 — POS B1 serial preflight: deterministic ASC lock, full validation
    //       (behavioural coverage lives in PosArtifactPreflightTest /
    //        PosArtifactLockOrderArchitectureTest — this only pins the sort)
    // =====================================================================

    public function test_pos_serial_preflight_locks_ids_in_deterministic_ascending_order(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareSerialNumberService.php'), 'preflightSaleSerials');
        $this->assertStringContainsString('sort($allSerialIds, SORT_NUMERIC);', $body);
        $sortPos = strpos($body, 'sort($allSerialIds, SORT_NUMERIC);');
        $lockPos = strpos($body, '->lockForUpdate()');
        $this->assertNotFalse($lockPos);
        $this->assertLessThan($lockPos, $sortPos, 'ids are sorted BEFORE the whole-cart lock');
        // full per-serial validation: product, variant, location, availability.
        $this->assertStringContainsString("->where('inventory_location_id', \$locationId)", $body);
        $this->assertStringContainsString('STATUS_AVAILABLE', $body);
        $this->assertStringContainsString('product_variant_id', $body);
    }

    // =====================================================================
    // §22 — legacy Purchase: Batch + Serial are independent writers
    // =====================================================================

    public function test_legacy_purchase_store_invokes_batch_and_serial_independently(): void
    {
        $store = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'store');

        $this->assertStringContainsString('app(BatchService::class)', $store);
        $this->assertStringContainsString('->applyForPurchase(', $store);
        $this->assertStringContainsString('app(SerialNumberService::class)', $store);
        $this->assertStringContainsString('->receiveOnPurchase(', $store);

        // Each is gated ONLY on isSupported() + statut === 'received' — there is
        // no batch<->serial consistency check on the legacy path.
        $this->assertStringContainsString("\$batchService->isSupported() && \$order->statut == 'received'", $store);
        $this->assertStringContainsString("\$serialService->isSupported() && \$order->statut == 'received'", $store);
        $this->assertStringNotContainsString('is_batch_tracked && ', $store);
    }

    public function test_product_flags_have_no_mutual_exclusion_validation(): void
    {
        $src = $this->read('app/Http/Controllers/ProductsController.php');
        // is_variant / is_imei / is_batch_tracked are each set from an
        // independent request flag; nothing forbids the combinations.
        $this->assertMatchesRegularExpression("/\\\$Product->is_imei\s*=\s*\\\$request\['is_imei'\]\s*==\s*'true'/", $src);
        $this->assertStringContainsString("\$Product->is_batch_tracked = filter_var(\$request->input('is_batch_tracked'", $src);
        $this->assertStringNotContainsString('is_imei.*cannot.*batch', $src);
        $this->assertStringNotContainsString('mutually exclusive', $src);
    }

    // =====================================================================
    // §24 — movement ledger has NO idempotency key
    // =====================================================================

    public function test_product_serial_movements_has_no_idempotency_key_column(): void
    {
        $this->buildSerialSchema();
        $this->assertTrue(Schema::hasTable('product_serial_movements'));
        $this->assertFalse(
            Schema::hasColumn('product_serial_movements', 'idempotency_key'),
            'MS6-B0 foundation will ADD product_serial_movements.idempotency_key (nullable unique)'
        );
    }

    public function test_production_migration_does_not_define_a_serial_movement_idempotency_key(): void
    {
        $mig = $this->read('database/migrations/tenant/2026_06_22_000002_create_product_serial_movements_table.php');
        $this->assertStringNotContainsString('idempotency_key', $mig);
        $this->assertStringNotContainsString('->unique(', $mig, 'only plain indexes today, no unique constraint');
    }

    // =====================================================================
    // §25 — hard-delete contract
    // =====================================================================

    public function test_product_serial_model_has_no_soft_deletes(): void
    {
        $src = $this->read('app/Models/ProductSerial.php');
        $this->assertStringNotContainsString('use Illuminate\Database\Eloquent\SoftDeletes;', $src);
        $this->assertStringNotContainsString('use SoftDeletes;', $src);
        $this->buildSerialSchema();
        $this->assertFalse(Schema::hasColumn('product_serials', 'deleted_at'));
    }

    public function test_legacy_purchase_reverse_hard_deletes_serials_and_movements(): void
    {
        $body = $this->fn($this->read('app/Services/SerialNumberService.php'), 'reverseForPurchaseDetails');
        // guard: only `available` serials may be reversed.
        $this->assertStringContainsString("\$s->status !== ProductSerial::STATUS_AVAILABLE", $body);
        $this->assertStringContainsString('already moved', $body);
        // then a HARD delete of both the movements and the serial rows.
        $this->assertStringContainsString('ProductSerialMovement::whereIn(\'product_serial_id\', $ids)->delete();', $body);
        $this->assertStringContainsString('ProductSerial::whereIn(\'id\', $ids)->delete();', $body);
    }

    public function test_bulk_delete_by_selection_never_touches_the_serial_ledger(): void
    {
        // LATENT: single destroy() guards + hard-deletes serials; the bulk path
        // does neither (characterized behaviourally in
        // PurchaseSerialLegacyDestroyGoldenMasterTest).
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'delete_by_selection');
        $this->assertStringNotContainsString('SerialNumberService', $body);
        $this->assertStringNotContainsString('reverseForPurchaseDetails', $body === '' ? 'x' : str_replace('$batchService->reverseForPurchaseDetails', '', $body));
    }
}
