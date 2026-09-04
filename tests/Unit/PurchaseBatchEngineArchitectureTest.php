<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS5-B2 — batch-aware purchase snapshot engine. Architecture contract.
 *
 * The engine (planner + stock service) can represent / apply / reverse
 * location-native batch effects. As of MS5-C/-D/-E every purchase-family
 * controller path (manual purchase, purchase return, import) is activated;
 * this suite keeps the ENGINE-level invariants (planner is its own service,
 * allow_batch is opt-in, IMEI always fails closed).
 */
class PurchaseBatchEngineArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    private function body(string $src, string $name): string
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

    // ---- Controller activation is asserted elsewhere -----------------
    // Manual PurchasesController (MS5-C), PurchasesReturnController (MS5-D) and
    // the IMPORT (MS5-E) are all batch-activated; their wiring is asserted by
    // PurchaseBatchActivationArchitectureTest and
    // PurchaseImportBatchLocationNativeArchitectureTest.

    public function test_store_import_is_batch_activated(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $import = $this->body($src, 'storeImportLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $import);
        // MS6-B3 — the import now calls the composed batch+serial planner.
        $this->assertStringContainsString('planLocationAwarePurchaseArtifacts(', $import);
    }

    // ---- Planner is its OWN service, no InventoryService --------------

    public function test_planner_is_a_separate_service_that_never_mutates_physical_stock(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseBatchPlanner.php');
        $this->assertStringContainsString('class LocationAwarePurchaseBatchPlanner', $src);
        $this->assertStringContainsString('public function planPurchaseReceipt(', $src);
        $this->assertStringContainsString('public function planPurchaseReturnIssue(', $src);

        // it resolves identity + freezes a plan; it never applies physical qty.
        $this->assertStringNotContainsString('InventoryService', $src);
        $this->assertStringNotContainsString('receiveMany(', $src);
        $this->assertStringNotContainsString('issueMany(', $src);
        // and it requires an outer transaction.
        $this->assertStringContainsString('DB::transactionLevel()', $src);
        $this->assertStringContainsString('LogicException', $src);
    }

    // ---- allow_batch is backward-compatible + IMEI invariant ---------

    public function test_validate_and_lock_allow_batch_is_opt_in_and_imei_always_fails(): void
    {
        $body = $this->body($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'validateAndLock');
        $this->assertStringContainsString("\$options['allow_batch'] ?? false", $body);
        $this->assertStringContainsString('requires_batch', $body);
        // IMEI has its own bucket and always throws (independent of allow_batch).
        $this->assertStringContainsString('$imeiIds', $body);
        $this->assertMatchesRegularExpression('/if \(\$imeiIds\) \{.*?throw ValidationException/s', $body);
    }

    // ---- LOCK ORDER: batch artifacts before general inventory --------

    public function test_run_snapshot_does_all_batch_artifacts_before_any_general(): void
    {
        $body = $this->body($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'runSnapshot');

        $batchPhase = strpos($body, 'PHASE A');
        $generalPhase = strpos($body, 'PHASE B');
        $receive = strpos($body, '->receiveMany(');
        $issue = strpos($body, '->issueMany(');
        $increase = strpos($body, '$this->inventory->increase(');
        $decrease = strpos($body, '$this->inventory->decrease(');

        $this->assertNotFalse($batchPhase);
        $this->assertNotFalse($generalPhase);
        $this->assertLessThan($generalPhase, $batchPhase);
        // the batch calls precede BOTH general calls.
        $this->assertLessThan($increase, min(array_filter([$receive, $issue], fn ($v) => $v !== false)));
        $this->assertLessThan($decrease, min(array_filter([$receive, $issue], fn ($v) => $v !== false)));
    }

    public function test_batch_artifact_safety_locks_product_batches_before_slices(): void
    {
        $body = $this->body($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'assertSnapshotBatchAllocationSafeAndLock');
        $batchLock = strpos($body, "DB::table('product_batches')");
        $sliceLock = strpos($body, "DB::table('product_batch_location_stocks')");
        $this->assertNotFalse($batchLock);
        $this->assertNotFalse($sliceLock);
        $this->assertLessThan($sliceLock, $batchLock, 'product_batches must be locked before its slices');
        $this->assertStringContainsString('->lockForUpdate()', $body);
        $this->assertStringContainsString('sort($batchIds, SORT_NUMERIC)', $body);
    }

    public function test_batch_reference_types_and_key_shape(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        foreach (['PurchaseBatch', 'PurchaseBatchReversal', 'PurchaseReturnBatch', 'PurchaseReturnBatchReversal'] as $ref) {
            $this->assertStringContainsString("'".$ref."'", $src);
        }
        $body = $this->body($src, 'batchIdempotencyKey');
        // key shape: {doc}:{id}:rev:{r}:detail:{sdid}:b:{bidx}:{apply|reverse}
        foreach ([':rev:', ':detail:', ':b:'] as $seg) {
            $this->assertStringContainsString($seg, $body);
        }
        $this->assertStringContainsString('$sourceDetailId', $body);
        $this->assertStringContainsString('$bidx', $body);
    }

    // ---- POS B1 untouched -------------------------------------------

    public function test_pos_b1_services_are_untouched(): void
    {
        // These files must not gain any purchase-batch coupling.
        foreach ([
            'app/Services/PosLocationSaleStockService.php',
            'app/Services/PosLocationArtifactPreflightService.php',
            'app/Services/LocationAwareBatchService.php',
            'app/Services/LocationAwareSerialNumberService.php',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringNotContainsString('LocationAwarePurchaseBatchPlanner', $src);
            $this->assertStringNotContainsString('planPurchaseReceipt', $src);
        }
    }
}
