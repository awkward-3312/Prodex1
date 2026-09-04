<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS6-B2 — §34: architecture contract for the ACTIVATION of location-native
 * serials/IMEI in MANUAL purchase returns.
 *
 * Pattern / structure based, never line numbers.
 *
 * After MS6-B2:
 *   - Purchase MANUAL (MS6-B1) IMEI remains ACTIVE.
 *   - PurchaseReturn MANUAL (store/update/destroy/delete_by_selection) IMEI = ACTIVE.
 *   - Purchase IMPORT IMEI = still INACTIVE (MS6-B3).
 *   - Legacy Return / POS B1 / Transfer receive / D2 = UNCHANGED.
 */
class PurchaseReturnSerialLocationNativeArchitectureTest extends TestCase
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
        return $this->read('app/Http/Controllers/PurchasesReturnController.php');
    }

    // ===================== manual return: serial ACTIVE =====================

    public function test_store_location_aware_activates_batch_and_serial(): void
    {
        $body = $this->fn($this->controller(), 'storeLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $body);
        $this->assertStringContainsString("'allow_serial' => true", $body);
        $this->assertStringContainsString('planLocationAwarePurchaseReturnArtifacts(', $body);

        // planner runs ONLY for a completed return.
        $completedGuard = strpos($body, "\$statut === 'completed'");
        $plannerCall = strpos($body, 'planLocationAwarePurchaseReturnArtifacts(');
        $this->assertNotFalse($completedGuard);
        $this->assertNotFalse($plannerCall);
        $this->assertLessThan($plannerCall, $completedGuard, 'planner is inside the completed branch');
    }

    public function test_update_location_aware_activates_batch_and_serial_both_sides(): void
    {
        $body = $this->fn($this->controller(), 'updateLocationAware');
        $this->assertSame(2, substr_count($body, "'allow_serial' => true"), 'reverse + validate both pass allow_serial');
        $this->assertStringContainsString('assertSnapshotArtifactSafeAndLock(', $body);
        $this->assertStringContainsString('planLocationAwarePurchaseReturnArtifacts(', $body);

        $completedGuard = strpos($body, "\$newStatut === 'completed'");
        $plannerCall = strpos($body, 'planLocationAwarePurchaseReturnArtifacts(');
        $this->assertNotFalse($completedGuard);
        $this->assertLessThan($plannerCall, $completedGuard, 'planner is inside the completed branch');
    }

    public function test_reverse_helper_covers_batch_and_serial(): void
    {
        $body = $this->fn($this->controller(), 'reverseLocationNativePurchaseReturnStock');
        $this->assertStringContainsString('assertSnapshotArtifactSafeAndLock(', $body);
        $this->assertStringContainsString("'allow_serial' => true", $body);
        $this->assertStringContainsString('inventory_effect_snapshot', $body);
        $this->assertStringNotContainsString('$request', $body);
    }

    public function test_composed_planner_is_batch_then_serial(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseReturnArtifacts');
        $batch = strpos($body, 'planLocationAwarePurchaseReturnBatches(');
        $serial = strpos($body, 'planLocationAwarePurchaseReturnSerials(');
        $this->assertNotFalse($batch);
        $this->assertNotFalse($serial);
        $this->assertLessThan($serial, $batch, 'batch plan folded first, serial plan second (ordinal map)');
    }

    public function test_native_serial_planner_is_the_location_aware_one(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseReturnSerials');
        $this->assertStringContainsString('LocationAwarePurchaseSerialPlanner', $body);
        $this->assertStringContainsString('planPurchaseReturnIssue(', $body);
        // never the legacy physical serial writer on this path.
        $this->assertStringNotContainsString('SerialNumberService', $body);
        $this->assertStringNotContainsString('returnToSupplier(', $body);
    }

    // ===================== linked-purchase provenance is explicit =========

    public function test_linked_purchase_provenance_is_an_explicit_opt_in_context(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseReturnSerials');
        $this->assertStringContainsString('require_source_purchase', $body);
        $this->assertStringContainsString('source_purchase_id', $body);
        // it is derived from THIS Return's own purchase_id, never a global flag.
        $this->assertStringContainsString('$sourcePurchaseId !== null', $body);

        // the planner itself only imposes the origin check when told to.
        $planner = $this->fn($this->read('app/Services/LocationAwarePurchaseSerialPlanner.php'), 'planPurchaseReturnIssue');
        $this->assertStringContainsString("\$context['require_source_purchase'] ?? false", $planner);
        $this->assertStringContainsString('no proviene de la compra referenciada', $planner);

        // both call sites (store completed, update completed) resolve it from
        // THIS document's own purchase_id — never a hardcoded true/false.
        $src = $this->controller();
        $this->assertStringContainsString('$order->purchase_id ? (int) $order->purchase_id : null', $src);
        $this->assertStringContainsString('$locked->purchase_id ? (int) $locked->purchase_id : null', $src);
    }

    public function test_native_return_paths_never_touch_product_warehouse(): void
    {
        foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware', 'reverseLocationNativePurchaseReturnStock', 'planLocationAwarePurchaseReturnSerials'] as $m) {
            $body = $this->fn($this->controller(), $m);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$m}() must not touch product_warehouse");
        }
    }

    // ===================== import: MS6-B3 activates it (unchanged by B2) ==
    // (full wiring assertions live in PurchaseImportSerialLocationNativeArchitectureTest)

    // ===================== manual Purchase (B1): still active =============

    public function test_purchase_manual_b1_still_activates_serial(): void
    {
        $body = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'storeLocationAware');
        $this->assertStringContainsString("'allow_serial' => true", $body);
    }

    // ===================== legacy Return serial: UNCHANGED =================

    public function test_legacy_return_still_uses_serial_number_service(): void
    {
        $src = $this->read('app/Services/SerialNumberService.php');
        // legacy return count still uses document quantity (not base).
        $recv = $this->fn($src, 'returnToSupplier');
        $this->assertStringContainsString('assertCountMatches($serials, $detail->quantity)', $recv);
        // legacy provenance rule (unconditional — no opt-in context needed there).
        $this->assertStringContainsString('$return->purchase_id && (int) $row->purchase_id !== (int) $return->purchase_id', $recv);

        $ctrl = $this->controller();
        $this->assertStringContainsString('app(SerialNumberService::class)', $ctrl);
        $legacyStore = $this->fn($ctrl, 'store');
        $this->assertStringContainsString('storeLocationAware', $legacyStore);
    }

    // ===================== serial planner: physically inert ===============

    public function test_serial_planner_return_path_never_moves_physical_stock(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseSerialPlanner.php');
        $body = $this->fn($src, 'planPurchaseReturnIssue');
        $this->assertStringNotContainsString('InventoryService', $body);
        $this->assertStringNotContainsString('->save(', $body);
        $this->assertStringNotContainsString('->update(', $body);
        $this->assertStringContainsString('lockForUpdate()', $body);
        // NO FEFO — every serial in the request must be explicit.
        $this->assertStringNotContainsString('orderBy(\'expiry_date\'', $body);
    }

    // ===================== snapshot engine: unchanged, reused =============

    public function test_snapshot_dispatches_purchase_return_to_the_return_set_ops(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'runSnapshot');
        $this->assertStringContainsString('returnToSupplierMany', $body);
        $this->assertStringContainsString('reversePurchaseReturnMany', $body);
        $this->assertStringContainsString('self::DOC_PURCHASE', $body);
    }

    // ===================== candidates endpoint: audited + extended ========

    public function test_for_purchase_endpoint_supports_optional_location_and_unlinked(): void
    {
        $src = $this->read('app/Http/Controllers/SerialNumberController.php');
        $body = $this->fn($src, 'forPurchase');
        $this->assertStringContainsString("filled('inventory_location_id')", $body);
        $this->assertStringContainsString("filled('purchase_id') && (int) \$request->purchase_id > 0", $body, 'purchase_id=0 must not be treated as a real filter');
        $this->assertStringContainsString("whereNull('product_variant_id')", $body, 'a non-variant line must not leak another variant\'s serials');
        $this->assertStringContainsString('STATUS_AVAILABLE', $body);
    }

    // ===================== frontend contract (create + edit forms) ========

    public function test_return_forms_require_base_quantity_serials_and_fence_batch_imei(): void
    {
        foreach (['create_purchase_return.vue', 'edit_purchase_return.vue'] as $f) {
            $src = $this->read('resources/src/views/app/pages/purchase_return/'.$f);

            $this->assertStringContainsString(':required-count="serialRequiredCount(detail)"', $src);
            $this->assertStringContainsString('this.location_meta.requires', $src);
            $this->assertStringContainsString('detailBaseQty(detail)', $src);

            $this->assertStringContainsString('Serial_Batch_Incompatible', $src);
            $this->assertStringContainsString('serialBatchConflictDetail', $src);
            $this->assertStringContainsString('detail.is_imei && !detail.is_batch_tracked', $src);

            $this->assertStringContainsString('Serials_Assigned_On_Return_Complete', $src);

            // reuses the shared select-mode widget against the SAME endpoint,
            // now also passing the selected inventory_location_id.
            $this->assertStringContainsString('mode="select"', $src);
            $this->assertStringContainsString('fetch-url="serial_numbers/for_purchase"', $src);
            $this->assertStringContainsString('inventory_location_id: location_meta.requires ? purchase_return.inventory_location_id : null', $src);

            // a location CHANGE clears a stale selection (no silent teleport).
            $this->assertStringContainsString('serial_numbers', $src);
        }
    }

    /**
     * Extract one JS object-method DEFINITION body (`name(params) { ... }`) by
     * brace matching — matches only the definition site (a `{` immediately
     * follows the closing paren), never a `this.name(...)` call site.
     */
    private function jsFn(string $src, string $name): string
    {
        $pattern = '/\b'.preg_quote($name, '/').'\s*\([^)]*\)\s*\{/';
        $this->assertMatchesRegularExpression($pattern, $src, "JS method {$name}() definition not found");
        preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE);
        $start = $m[0][1];
        $braceStart = $start + strlen($m[0][0]) - 1;
        $depth = 0;
        for ($i = $braceStart; $i < strlen($src); $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }
        $this->fail("unbalanced braces reading JS method {$name}()");
    }

    public function test_location_change_clears_stale_serial_selection(): void
    {
        $create = $this->jsFn($this->read('resources/src/views/app/pages/purchase_return/create_purchase_return.vue'), 'Selected_Inventory_Location');
        $this->assertStringContainsString('serial_numbers', $create);
        $this->assertStringContainsString('[]', $create);

        $edit = $this->jsFn($this->read('resources/src/views/app/pages/purchase_return/edit_purchase_return.vue'), 'Selected_Inventory_Location');
        $this->assertStringContainsString('previousInventoryLocationId', $edit, 'edit must not wipe the prefilled serials on initial load');
        $this->assertStringContainsString('serial_numbers', $edit);
    }

    // ===================== D2 / POS B1 / Transfer: UNCHANGED ==============

    public function test_d2_inventory_location_auto_select_helper_intact(): void
    {
        $src = $this->read('resources/src/utils/inventoryLocationAutoSelect.js');
        $this->assertStringContainsString('resolveAutoInventoryLocation', $src);
        $this->assertStringContainsString('quarantine', $src);
    }

    public function test_pos_b1_and_transfer_untouched(): void
    {
        $pos = $this->fn($this->read('app/Services/LocationAwareSerialNumberService.php'), 'preflightSaleSerials');
        $this->assertStringContainsString('sort($allSerialIds, SORT_NUMERIC);', $pos);

        $transfer = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditGoodStock');
        $general = strpos($transfer, 'InventoryService::class)->increase(');
        $serial = strpos($transfer, 'TransferSerialLocationService::class)->receiveGood(');
        $this->assertNotFalse($general);
        $this->assertNotFalse($serial);
        $this->assertLessThan($general, $serial, 'B0.1 order preserved: SERIAL -> GENERAL');
    }
}
