<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  MS5-E — batch location-native ACTIVATED in the PURCHASE IMPORT
 * ============================================================================
 *
 *  store_import_purchases (MODE_LOCATION_PRIMARY) now accepts is_batch_tracked
 *  is_single products. The batch distribution rides in `batches_by_code`; the
 *  CSV contract (productcode;qty) is untouched. For a RECEIVED import the
 *  shared LocationAwarePurchaseBatchPlanner freezes the plan, buildSnapshot
 *  embeds it (revision 1), applySnapshot runs receiveMany BEFORE increase.
 *  PENDING creates no artifact. After creation it is a normal location-native
 *  Purchase — MS5-C update/destroy revert it from that snapshot.
 *
 *  Fail-closed still: is_variant, IMEI.
 *
 *  Pattern / structure based, never line numbers.
 */
class PurchaseImportBatchLocationNativeArchitectureTest extends TestCase
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

    private function controller(): string
    {
        return $this->read('app/Http/Controllers/PurchasesController.php');
    }

    // =====================================================================
    // ENGINE WIRING
    // =====================================================================

    public function test_native_import_passes_allow_batch_and_uses_the_shared_planner(): void
    {
        $import = $this->fn($this->controller(), 'storeImportLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $import);
        $this->assertStringContainsString('->validateAndLock(', $import);
        // reuses the SAME composed wrapper the manual purchase uses (MS6-B3:
        // batch THEN serial), no duplicated formula.
        $this->assertStringContainsString('$this->planLocationAwarePurchaseArtifacts(', $import);
        $this->assertStringContainsString('$this->withSourceDetailIds($planned, $detailIds)', $import);
    }

    public function test_native_import_snapshot_is_identical_shape_to_a_manual_purchase(): void
    {
        $import = $this->fn($this->controller(), 'storeImportLocationAware');
        $this->assertStringContainsString('$svc->buildSnapshot($validated, 1)', $import);
        $this->assertStringContainsString("'inventory_effect_snapshot' => \$snapshot", $import);
        $this->assertStringContainsString('$svc->applySnapshot($snapshot, $order->id)', $import);
        // secondary pivots, same helper as MS5-C.
        $this->assertStringContainsString('$this->persistLocationAwarePurchaseDetailBatches($validated[\'lines\'])', $import);
    }

    public function test_planner_and_artifacts_run_only_for_received(): void
    {
        $import = $this->fn($this->controller(), 'storeImportLocationAware');
        $guard = strpos($import, "if (\$statut === 'received')");
        $planner = strpos($import, 'planLocationAwarePurchaseArtifacts(');
        $apply = strpos($import, 'applySnapshot(');
        $pivots = strpos($import, 'persistLocationAwarePurchaseDetailBatches(');
        $this->assertNotFalse($guard);
        foreach (['planner' => $planner, 'apply' => $apply, 'pivots' => $pivots] as $label => $pos) {
            $this->assertNotFalse($pos, "{$label} call missing");
            $this->assertGreaterThan($guard, $pos, "{$label} must be gated behind statut === 'received'");
        }
    }

    public function test_native_import_is_location_native_pure(): void
    {
        $src = $this->controller();
        foreach (['storeImportLocationAware', 'resolveImportLinesForLocationNative', 'prevalidateImportBatchInput', 'normalizeImportBatchesByCode'] as $name) {
            $body = $this->fn($src, $name);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$name}() must be location-native pure");
            $this->assertStringNotContainsString('BatchService', $body, "{$name}() must not use the legacy BatchService");
            $this->assertStringNotContainsString('SerialNumberService', $body, "{$name}() must not touch the serial ledger");
        }
    }

    // =====================================================================
    // FAIL-CLOSED SURFACE
    // =====================================================================

    public function test_resolver_fails_closed_on_variant_and_imei_but_not_batch(): void
    {
        $body = $this->fn($this->controller(), 'resolveImportLinesForLocationNative');
        $this->assertStringContainsString("(string) \$product->type === 'is_variant'", $body);
        $this->assertStringContainsString("(int) (\$product->is_imei ?? 0) === 1", $body);
        // the old batch throw is gone; the row is only TAGGED now.
        $this->assertStringNotContainsString('La entrada de lote por ubicación llega en un hito posterior', $body);
        $this->assertStringContainsString("'is_batch_tracked' =>", $body);
    }

    public function test_batches_by_code_shape_is_validated_before_any_write(): void
    {
        $src = $this->controller();
        $normalize = $this->fn($src, 'normalizeImportBatchesByCode');
        $this->assertStringContainsString('json_last_error()', $normalize);
        $this->assertStringContainsString("'batches_by_code' =>", $normalize);
        $this->assertStringContainsString('ValidationException::withMessages', $normalize);

        $prevalidate = $this->fn($src, 'prevalidateImportBatchInput');
        // orphan / stale code, batches for a non-batch product, missing / sum
        // mismatch — all raised here.
        $this->assertStringContainsString("'batches_by_code' =>", $prevalidate);
        $this->assertStringContainsString('details.$i.batches', $prevalidate);
        $this->assertStringContainsString("\$statut !== 'received'", $prevalidate, 'pending must skip the physical batch requirement');

        // the pre-flight runs BEFORE the Purchase row is created.
        $import = $this->fn($src, 'storeImportLocationAware');
        $prevPos = strpos($import, 'prevalidateImportBatchInput(');
        $newOrderPos = strpos($import, 'new Purchase');
        $this->assertNotFalse($prevPos);
        $this->assertNotFalse($newOrderPos);
        $this->assertLessThan($newOrderPos, $prevPos, 'batch pre-flight must precede Purchase creation');
    }

    public function test_routing_keeps_legacy_import_lenient_and_native_strict(): void
    {
        $body = $this->fn($this->controller(), 'store_import_purchases');
        // legacy branch: silent lenient decode still there.
        $this->assertStringContainsString('$decoded = json_decode($rawBatches, true);', $body);
        // native branch: strict normalizer.
        $this->assertStringContainsString("\$this->normalizeImportBatchesByCode(\$request->input('batches_by_code'))", $body);
        // legacy physical writer untouched.
        $this->assertStringContainsString('$product_warehouse->qte += ', $body);
    }

    // =====================================================================
    // INTEROP + NON-REGRESSION
    // =====================================================================

    public function test_imported_purchase_is_a_normal_location_native_purchase(): void
    {
        $src = $this->controller();
        // update/destroy route purely by inventory_location_id !== null — an
        // imported batch Purchase is picked up by the MS5-C reverse (snapshot +
        // allow_batch), no "import-only" flag anywhere.
        $this->assertStringContainsString('$routing_purchase->inventory_location_id !== null', $this->fn($src, 'update'));
        $this->assertStringNotContainsString('import_only', $src);
        $this->assertStringNotContainsString('is_import_snapshot', $src);
        $reverse = $this->fn($src, 'reverseLocationNativePurchaseStock');
        // MS6-B1 — the shared reverse helper is batch- AND serial-artifact-safe
        // (the import STORE path still does not activate serials — MS6-B3).
        $this->assertStringContainsString("assertSnapshotArtifactSafeAndLock(\$snapshot, ['allow_batch' => true, 'allow_serial' => true])", $reverse);
    }

    public function test_ms5c_manual_and_ms5d_return_paths_untouched(): void
    {
        $src = $this->controller();
        $this->assertStringContainsString('return $this->storeLocationAware($request);', $this->fn($src, 'store'));
        $this->assertStringContainsString("'allow_batch' => true", $this->fn($src, 'storeLocationAware'));

        $ret = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        $this->assertStringContainsString('LocationAwarePurchaseStockService::DOC_PURCHASE_RETURN', $ret);
        $this->assertStringContainsString('planLocationAwarePurchaseReturnBatches(', $ret);
    }

    public function test_pos_b1_services_do_not_learn_about_the_purchase_planner(): void
    {
        foreach ([
            'app/Services/PosLocationSaleStockService.php',
            'app/Services/PosLocationArtifactPreflightService.php',
        ] as $rel) {
            $this->assertStringNotContainsString('LocationAwarePurchaseBatchPlanner', $this->read($rel));
        }
    }

    public function test_d2_autoselect_helper_still_used_by_the_import_form(): void
    {
        $vue = $this->read('resources/src/views/app/pages/purchases/import_purchases.vue');
        $this->assertStringContainsString('utils/inventoryLocationAutoSelect', $vue);
        $this->assertStringContainsString('resolveAutoInventoryLocation({', $vue);
        // batch activation must not have reintroduced a raw sole-location assign.
        $this->assertStringNotContainsString('ids.length === 1) this.', $vue);
    }

    public function test_import_frontend_treats_batch_rows_as_supported(): void
    {
        $vue = $this->read('resources/src/views/app/pages/purchases/import_purchases.vue');
        // incompatibleRows no longer lists plain batch OR plain IMEI rows —
        // MS6-B3: only variant and batch+IMEI-on-the-same-product remain.
        $this->assertMatchesRegularExpression('/incompatibleRows\(\)\s*\{.*?r\.is_variant \|\| \(r\.is_imei && r\.is_batch_tracked\)/s', $vue);
        $this->assertDoesNotMatchRegularExpression('/r\.is_variant \|\| r\.is_batch_tracked \|\| r\.is_imei/', $vue);
        // batch requirement is gated on a RECEIVED import.
        $this->assertStringContainsString('this.purchase.statut !== "received"', $vue);
    }
}
