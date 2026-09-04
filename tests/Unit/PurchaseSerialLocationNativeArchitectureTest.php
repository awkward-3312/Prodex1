<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS6-B1 — §44: architecture contract for the ACTIVATION of location-native
 * serials/IMEI in MANUAL purchases only.
 *
 * Pattern / structure based, never line numbers.
 *
 * After MS6-B1:
 *   - Purchase MANUAL (store/update/destroy/delete_by_selection) IMEI = ACTIVE
 *     in location_primary (serial planner + snapshot serial phase).
 *   - PurchaseReturn IMEI = INACTIVE (MS6-B2).
 *   - Purchase IMPORT IMEI = INACTIVE (MS6-B3).
 *   - Legacy Purchase / POS B1 / Transfer receive / D2 = UNCHANGED.
 */
class PurchaseSerialLocationNativeArchitectureTest extends TestCase
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

    // ===================== manual store: serial ACTIVE =====================

    public function test_store_location_aware_activates_batch_and_serial(): void
    {
        $body = $this->fn($this->controller(), 'storeLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $body);
        $this->assertStringContainsString("'allow_serial' => true", $body);
        // the composed planner (batch THEN serial) is used, not the batch-only one.
        $this->assertStringContainsString('planLocationAwarePurchaseArtifacts(', $body);

        // planner runs ONLY for a received purchase.
        $receivedGuard = strpos($body, "\$statut === 'received'");
        $plannerCall = strpos($body, 'planLocationAwarePurchaseArtifacts(');
        $this->assertNotFalse($receivedGuard);
        $this->assertNotFalse($plannerCall);
        $this->assertLessThan($plannerCall, $receivedGuard, 'planner is inside the received branch');
    }

    public function test_update_location_aware_activates_batch_and_serial_both_sides(): void
    {
        $body = $this->fn($this->controller(), 'updateLocationAware');
        // the OLD-snapshot reverse and the NEW-lines validate BOTH opt in.
        $this->assertSame(2, substr_count($body, "'allow_serial' => true"), 'reverse + validate both pass allow_serial');
        $this->assertStringContainsString('assertSnapshotArtifactSafeAndLock(', $body);
        $this->assertStringContainsString('planLocationAwarePurchaseArtifacts(', $body);

        $receivedGuard = strpos($body, "\$newStatut === 'received'");
        $plannerCall = strpos($body, 'planLocationAwarePurchaseArtifacts(');
        $this->assertNotFalse($receivedGuard);
        $this->assertLessThan($plannerCall, $receivedGuard, 'planner is inside the received branch');
    }

    public function test_reverse_helper_covers_batch_and_serial(): void
    {
        $body = $this->fn($this->controller(), 'reverseLocationNativePurchaseStock');
        $this->assertStringContainsString('assertSnapshotArtifactSafeAndLock(', $body);
        $this->assertStringContainsString("'allow_serial' => true", $body);
        // reverse reads the SNAPSHOT, never the request / imei_number text.
        $this->assertStringContainsString('inventory_effect_snapshot', $body);
        $this->assertStringNotContainsString('$request', $body);
    }

    public function test_composed_planner_is_batch_then_serial(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseArtifacts');
        $batch = strpos($body, 'planLocationAwarePurchaseBatches(');
        $serial = strpos($body, 'planLocationAwarePurchaseSerials(');
        $this->assertNotFalse($batch);
        $this->assertNotFalse($serial);
        $this->assertLessThan($serial, $batch, 'batch plan folded first, serial plan second (ordinal map)');
    }

    public function test_native_serial_planner_is_the_location_aware_one(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseSerials');
        $this->assertStringContainsString('LocationAwarePurchaseSerialPlanner', $body);
        $this->assertStringContainsString('planPurchaseReceipt(', $body);
        // never the legacy physical serial writer on this path.
        $this->assertStringNotContainsString('SerialNumberService', $body);
        $this->assertStringNotContainsString('receiveOnPurchase', $body);
    }

    public function test_native_manual_paths_never_touch_product_warehouse(): void
    {
        foreach (['storeLocationAware', 'updateLocationAware', 'destroyLocationAware', 'reverseLocationNativePurchaseStock', 'planLocationAwarePurchaseSerials'] as $m) {
            $body = $this->fn($this->controller(), $m);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$m}() must not touch product_warehouse");
            $this->assertStringNotContainsString('product_Warehouse', $body);
        }
    }

    // ===================== import: MS6-B3 activates it =====================
    // (full wiring assertions live in PurchaseImportSerialLocationNativeArchitectureTest)

    // ===================== purchase return: MS6-B2 activates it ==========
    // (full wiring assertions live in PurchaseReturnSerialLocationNativeArchitectureTest)

    // ===================== legacy Purchase serial: UNCHANGED =============

    public function test_legacy_purchase_still_uses_serial_number_service(): void
    {
        $src = $this->controller();
        // legacy store / update / destroy still resolve the legacy writer.
        $this->assertStringContainsString('app(SerialNumberService::class)', $src);
        $legacyStore = $this->fn($src, 'store');
        $this->assertStringContainsString('storeLocationAware', $legacyStore, 'store() still routes to the native path when eligible');

        // legacy bulk-delete branch keeps its own (pre-existing) behaviour: the
        // native branch is a SEPARATE `inventory_location_id !== null` block.
        $bulk = $this->read('app/Http/Controllers/PurchasesController.php');
        $this->assertMatchesRegularExpression(
            '/inventory_location_id !== null\)\s*\{\s*\$this->assertLocationNativePurchaseTransitionSafe.*?reverseLocationNativePurchaseStock/s',
            $bulk,
            'bulk delete has a dedicated native reverse branch'
        );
    }

    // ===================== planner: physically inert ====================

    public function test_serial_planner_never_moves_physical_stock(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseSerialPlanner.php');
        $this->assertStringContainsString('class LocationAwarePurchaseSerialPlanner', $src);
        $this->assertStringNotContainsString('InventoryService', $src);
        $this->assertStringNotContainsString('product_warehouse', $src);
        $this->assertStringNotContainsString('ProductSerialMovement', $src);
        $this->assertStringNotContainsString('receivePurchaseMany', $src);
        // it must run inside the caller's transaction.
        $this->assertStringContainsString('DB::transactionLevel()', $src);
        // batch + serial on one line => 422.
        $this->assertStringContainsString("requires_batch", $src);
        $this->assertStringContainsString('lote+serie no está soportada', $src);
        // integer base + count == base (NOT document unit).
        $this->assertStringContainsString('cantidad base entera', $src);
        $this->assertStringContainsString('número(s) de serie', $src);
    }

    // ===================== snapshot engine: order + exclusivity =========

    public function test_snapshot_phase_order_is_batch_serial_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'runSnapshot');
        $batch = strpos($body, 'PHASE A');
        $serial = strpos($body, 'PHASE B — ALL SERIAL');
        $general = strpos($body, 'PHASE C');
        $this->assertNotFalse($batch);
        $this->assertNotFalse($serial);
        $this->assertNotFalse($general);
        $this->assertLessThan($serial, $batch);
        $this->assertLessThan($general, $serial);

        $inv = strpos($body, '$this->inventory->increase(');
        $this->assertNotFalse($inv);
        $this->assertGreaterThan($serial, $inv, 'general InventoryService runs AFTER the serial phase');
    }

    public function test_snapshot_effect_cannot_carry_both_batch_and_serial(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        $this->assertStringContainsString('no puede llevar asignación de lotes Y de series', $src);
        $this->assertStringContainsString('lleva asignación de lotes Y de series a la vez', $src);
    }

    public function test_variant_plus_imei_is_allowed_on_native_path(): void
    {
        // validateAndLock must NOT 422 an is_imei line that is also a variant
        // when allow_serial is on — the batch+IMEI fence is the only combo ban.
        $vlock = $this->fn($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'validateAndLock');
        $this->assertStringContainsString('$allowSerial', $vlock);
        $this->assertStringContainsString("if (\$isImei && \$isBatch)", $vlock);
        $this->assertStringNotContainsString("\$isImei && \$isVariant", $vlock);
    }

    // ===================== POS B1 + Transfer + D2: UNCHANGED ============

    public function test_pos_b1_serial_preflight_untouched(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareSerialNumberService.php'), 'preflightSaleSerials');
        $this->assertStringContainsString('sort($allSerialIds, SORT_NUMERIC);', $body);
        $this->assertStringNotContainsString('receivePurchaseMany', $body);
        $this->assertStringNotContainsString('ProductSerialMovement::create', $body);
    }

    public function test_transfer_receive_order_still_serial_before_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditGoodStock');
        $general = strpos($body, 'InventoryService::class)->increase(');
        $serial = strpos($body, 'TransferSerialLocationService::class)->receiveGood(');
        $this->assertNotFalse($general);
        $this->assertNotFalse($serial);
        $this->assertLessThan($general, $serial, 'B0.1 order preserved: SERIAL -> GENERAL');
    }

    public function test_d2_inventory_location_auto_select_helper_intact(): void
    {
        $src = $this->read('resources/src/utils/inventoryLocationAutoSelect.js');
        $this->assertStringContainsString('resolveAutoInventoryLocation', $src);
        // D2 quarantine short-circuit still present.
        $this->assertStringContainsString('quarantine', $src);
    }

    // ===================== frontend contract (create + edit forms) ======

    public function test_purchase_forms_require_base_quantity_serials_and_fence_batch_imei(): void
    {
        foreach (['create_purchase.vue', 'edit_purchase.vue'] as $f) {
            $src = $this->read('resources/src/views/app/pages/purchases/'.$f);

            // mode-dependent required count: quantity_BASE for a location_primary
            // (location_meta.requires) warehouse, the entered document quantity
            // for a legacy one — must NOT silently change legacy tenants.
            $this->assertStringContainsString(':required-count="serialRequiredCount(detail)"', $src);
            $this->assertStringContainsString('this.location_meta.requires', $src);
            $this->assertStringContainsString('detailBaseQty(detail)', $src);
            $this->assertMatchesRegularExpression('/serialRequiredCount\s*\(detail\)\s*\{[^}]*location_meta\.requires[^}]*detailBaseQty/s', $src);

            // batch + serial/IMEI on one product: message + submit block.
            $this->assertStringContainsString('Serial_Batch_Incompatible', $src);
            $this->assertStringContainsString('serialBatchConflictDetail', $src);
            // the serial entry row is suppressed when the product is also batch-tracked.
            $this->assertStringContainsString('detail.is_imei && !detail.is_batch_tracked', $src);

            // pending note.
            $this->assertStringContainsString('Serials_Assigned_On_Receive', $src);

            // still the shared widget — no bespoke serial UI / redesign.
            $this->assertStringContainsString('<serial-numbers-field', $src);
        }
    }
}
