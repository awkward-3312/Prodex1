<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS5-C — batch location-native ACTIVATED in PurchasesController (manual
 * purchases only). PurchaseReturn (MS5-D), Import (MS5-E) and serial/IMEI (MS6)
 * remain fail-closed.
 */
class PurchaseBatchActivationArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

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

    public function test_purchases_controller_uses_the_batch_planner(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $this->assertStringContainsString('use App\Services\LocationAwarePurchaseBatchPlanner;', $src);
        $this->assertStringContainsString('LocationAwarePurchaseBatchPlanner::class)->planPurchaseReceipt(', $src);
        $this->assertStringContainsString('use App\Models\PurchaseDetailBatch;', $src);
    }

    public function test_native_store_and_update_pass_allow_batch(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach (['storeLocationAware', 'updateLocationAware'] as $m) {
            $body = $this->fn($src, $m);
            $this->assertStringContainsString("'allow_batch' => true", $body, "{$m}() must pass allow_batch");
            $this->assertStringContainsString('->validateAndLock(', $body);
        }
    }

    public function test_native_reverse_is_artifact_safe_with_allow_batch(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        // update reverse of the OLD snapshot + shared reverse helper + destroy.
        $update = $this->fn($src, 'updateLocationAware');
        $this->assertMatchesRegularExpression(
            "/assertSnapshotArtifactSafeAndLock\(\\\$oldSnapshot, \['allow_batch' => true, 'allow_serial' => true\]\)/",
            $update
        );
        $reverse = $this->fn($src, 'reverseLocationNativePurchaseStock');
        $this->assertStringContainsString("assertSnapshotArtifactSafeAndLock(\$snapshot, ['allow_batch' => true, 'allow_serial' => true])", $reverse);
        $this->assertStringContainsString('->reverseSnapshot($snapshot, $purchase->id);', $reverse);
    }

    public function test_native_methods_never_call_batch_service_or_product_warehouse(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach ([
            'storeLocationAware', 'updateLocationAware', 'destroyLocationAware',
            'reverseLocationNativePurchaseStock', 'persistLocationAwarePurchaseDetailBatches',
            'planLocationAwarePurchaseBatches',
        ] as $m) {
            $body = $this->fn($src, $m);
            $this->assertStringNotContainsString('BatchService', $body, "{$m}() must not use the legacy BatchService");
            $this->assertStringNotContainsString('product_warehouse', $body, "{$m}() must not touch product_warehouse");
            $this->assertStringNotContainsString('SerialNumberService', $body);
        }
        // the legacy writer count is unchanged (native added an alternative path).
        $this->assertSame(11, substr_count($src, '$product_warehouse->save();'));
    }

    public function test_native_pending_never_runs_the_planner(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        foreach (['storeLocationAware', 'updateLocationAware'] as $m) {
            $body = $this->fn($src, $m);
            // planner + snapshot apply + pivots are all inside a `=== 'received'` guard.
            // MS6-B1 — the composed batch+serial planner is what store/update call.
            $plannerPos = strpos($body, 'planLocationAwarePurchaseArtifacts(');
            $this->assertNotFalse($plannerPos, "{$m}() should invoke the planner for received");
            $guardPos = strpos($body, "=== 'received'");
            $this->assertNotFalse($guardPos);
            $this->assertLessThan($plannerPos, $guardPos, "{$m}() planner must be gated behind === 'received'");
        }
    }

    public function test_snapshot_is_the_source_of_reverse_not_pivots(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $reverse = $this->fn($src, 'reverseLocationNativePurchaseStock');
        // reverse reads normalizeSnapshot + reverseSnapshot; it never queries pivots.
        $this->assertStringContainsString('normalizeSnapshot($purchase->inventory_effect_snapshot)', $reverse);
        $this->assertStringNotContainsString('PurchaseDetailBatch', $reverse);
        $this->assertStringNotContainsString('purchase_detail_batches', $reverse);

        // update/destroy delete pivots only AFTER the physical reverse ran.
        foreach (['updateLocationAware', 'destroyLocationAware'] as $m) {
            $body = $this->fn($src, $m);
            $revPos = strpos($body, $m === 'updateLocationAware' ? 'reverseSnapshot($oldSnapshot' : 'reverseLocationNativePurchaseStock($locked)');
            $pivotDelPos = strpos($body, 'PurchaseDetailBatch::whereIn(');
            if ($revPos !== false && $pivotDelPos !== false) {
                $this->assertLessThan($pivotDelPos, $revPos, "{$m}(): physical reverse must precede pivot deletion");
            }
        }
    }

    public function test_imei_still_fails_closed_and_import_is_activated(): void
    {
        $stock = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        // IMEI bucket throws regardless of allow_batch.
        $this->assertStringContainsString('$imeiIds', $stock);

        // MS5-E — import IS batch-activated now: storeImportLocationAware passes
        // allow_batch and folds the shared planner for a RECEIVED import.
        $src = $this->read('app/Http/Controllers/PurchasesController.php');
        $import = $this->fn($src, 'storeImportLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $import);
        $this->assertStringContainsString('planLocationAwarePurchaseBatches(', $import);
        $this->assertStringContainsString('persistLocationAwarePurchaseDetailBatches(', $import);
        // planner + snapshot + pivots gated behind === 'received'.
        $plannerPos = strpos($import, 'planLocationAwarePurchaseBatches(');
        $guardPos = strpos($import, "=== 'received'");
        $this->assertNotFalse($plannerPos);
        $this->assertNotFalse($guardPos);
        $this->assertLessThan($plannerPos, $guardPos, 'import planner must be gated behind === \'received\'');

        // still location-native pure.
        $this->assertStringNotContainsString('BatchService', $import);
        $this->assertStringNotContainsString('product_warehouse', $import);
        $this->assertStringNotContainsString('SerialNumberService', $import);
    }

    public function test_ms5d_purchase_returns_controller_is_batch_activated(): void
    {
        $ret = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        // allow_batch on validateAndLock (store + update) and the two reverse
        // safety asserts (update + shared reverse helper).
        $this->assertGreaterThanOrEqual(4, substr_count($ret, "'allow_batch' => true"));
        $this->assertStringContainsString('LocationAwarePurchaseBatchPlanner', $ret);
        $this->assertStringContainsString('planPurchaseReturnIssue', $ret);
        $this->assertStringContainsString('PurchaseReturnDetailBatch', $ret);

        // Planner + pivots run ONLY inside the completed branch.
        foreach (['storeLocationAware', 'updateLocationAware'] as $m) {
            $body = $this->fn($ret, $m);
            $plannerPos = strpos($body, 'planLocationAwarePurchaseReturnBatches(');
            $completedPos = strpos($body, "=== 'completed'");
            $this->assertNotFalse($plannerPos, "{$m}(): must call the return batch planner");
            $this->assertNotFalse($completedPos);
            $this->assertGreaterThan($completedPos, $plannerPos, "{$m}(): planner must be gated behind statut === 'completed'");
        }

        // Pivot deletes are schema-guarded (MS3 suites omit the batch tables).
        $this->assertStringContainsString("Schema::hasTable('purchase_return_detail_batches')", $ret);
        // The physical reverse precedes pivot deletion in update/destroy/bulk.
        foreach (['updateLocationAware', 'destroyLocationAware'] as $m) {
            $body = $this->fn($ret, $m);
            $revPos = strpos($body, $m === 'updateLocationAware' ? 'reverseSnapshot($oldSnapshot' : 'reverseLocationNativePurchaseReturnStock($locked)');
            $pivotDelPos = strpos($body, 'PurchaseReturnDetailBatch::whereIn(');
            if ($revPos !== false && $pivotDelPos !== false) {
                $this->assertLessThan($pivotDelPos, $revPos, "{$m}(): physical reverse must precede pivot deletion");
            }
        }
    }

    public function test_pos_b1_services_untouched(): void
    {
        foreach ([
            'app/Services/PosLocationSaleStockService.php',
            'app/Services/PosLocationArtifactPreflightService.php',
            'app/Services/LocationAwareBatchService.php',
        ] as $rel) {
            $this->assertStringNotContainsString('LocationAwarePurchaseBatchPlanner', $this->read($rel));
        }
    }

    public function test_frontend_defers_batches_to_receipt(): void
    {
        foreach ([
            'resources/src/views/app/pages/purchases/create_purchase.vue',
            'resources/src/views/app/pages/purchases/edit_purchase.vue',
        ] as $rel) {
            $src = $this->read($rel);
            $this->assertStringContainsString("purchase.statut !== 'received'", $src, "$rel must gate the batch allocator on statut");
            $this->assertStringContainsString("this.purchase.statut !== 'received'", $src, "$rel must not require batches for a pending purchase");
        }
    }
}
