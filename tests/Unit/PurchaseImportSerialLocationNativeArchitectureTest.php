<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS6-B3 — §34: architecture contract for the ACTIVATION of location-native
 * serials/IMEI in the purchase IMPORT.
 *
 * Pattern / structure based, never line numbers.
 *
 * After MS6-B3 — MS6 is COMPLETE:
 *   - Purchase manual (MS6-B1) IMEI remains ACTIVE.
 *   - PurchaseReturn manual (MS6-B2) IMEI remains ACTIVE.
 *   - Purchase IMPORT (store_import_purchases / storeImportLocationAware) IMEI
 *     = ACTIVE.
 *   - Legacy import / POS B1 / Transfer receive / D2 = UNCHANGED.
 */
class PurchaseImportSerialLocationNativeArchitectureTest extends TestCase
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

    // ===================== CSV contract unchanged =========================

    public function test_csv_contract_still_productcode_qty_only(): void
    {
        $resolver = $this->fn($this->controller(), 'resolveImportLinesForLocationNative');
        $this->assertStringContainsString("\$row['productcode']", $resolver);
        $this->assertStringContainsString("\$row['qty']", $resolver);
        // Product.code, never ProductVariant.code.
        $this->assertStringContainsString('Product::where', $resolver);
        $this->assertStringNotContainsString('ProductVariant::where', $resolver);
    }

    // ===================== import: serial ACTIVE ===========================

    public function test_store_import_location_aware_activates_batch_and_serial(): void
    {
        $body = $this->fn($this->controller(), 'storeImportLocationAware');
        $this->assertStringContainsString("'allow_batch' => true", $body);
        $this->assertStringContainsString("'allow_serial' => true", $body);
        $this->assertStringContainsString('planLocationAwarePurchaseArtifacts(', $body);

        $receivedGuard = strpos($body, "\$statut === 'received'");
        $plannerCall = strpos($body, 'planLocationAwarePurchaseArtifacts(');
        $this->assertNotFalse($receivedGuard);
        $this->assertNotFalse($plannerCall);
        $this->assertLessThan($plannerCall, $receivedGuard, 'planner is inside the received branch');

        // prevalidation runs BEFORE validateAndLock / any Purchase mutation.
        $prevalidateBatch = strpos($body, 'prevalidateImportBatchInput(');
        $prevalidateSerial = strpos($body, 'prevalidateImportSerialInput(');
        $validateAndLock = strpos($body, '->validateAndLock(');
        $orderSave = strpos($body, '$order->save();');
        $this->assertNotFalse($prevalidateBatch);
        $this->assertNotFalse($prevalidateSerial);
        $this->assertLessThan($validateAndLock, $prevalidateBatch);
        $this->assertLessThan($validateAndLock, $prevalidateSerial);
        $this->assertLessThan($orderSave, $validateAndLock, 'validate+lock happens before the Purchase header is created');
    }

    public function test_import_no_import_only_flags_or_snapshot_format(): void
    {
        $src = $this->controller();
        $this->assertStringNotContainsString('import_only', $src);
        $this->assertStringNotContainsString('is_import_serial', $src);
        $this->assertStringNotContainsString('is_import_snapshot', $src);
        // buildSnapshot/applySnapshot are the SAME calls the manual path uses.
        $body = $this->fn($src, 'storeImportLocationAware');
        $this->assertStringContainsString('$svc->buildSnapshot($validated, 1);', $body);
        $this->assertStringContainsString('$svc->applySnapshot($snapshot, $order->id);', $body);
    }

    public function test_variant_and_batch_serial_fences_are_fail_closed(): void
    {
        $resolver = $this->fn($this->controller(), 'resolveImportLinesForLocationNative');
        $this->assertStringContainsString("type === 'is_variant'", $resolver);
        $this->assertStringContainsString('FAIL CLOSED', $resolver);
        $this->assertStringContainsString('$isBatchTracked && $isImei', $resolver);
        $this->assertStringContainsString('lote+serie no está soportada', $resolver);
        // the variant check runs BEFORE the batch+imei check (fences variant+IMEI too).
        $variantPos = strpos($resolver, "type === 'is_variant'");
        $conflictPos = strpos($resolver, '$isBatchTracked && $isImei');
        $this->assertLessThan($conflictPos, $variantPos);
    }

    public function test_serial_planner_only_for_received(): void
    {
        $body = $this->fn($this->controller(), 'storeImportLocationAware');
        $this->assertStringContainsString('serial_numbers', $body);
        // the raw-lines carrying serial_numbers are built INSIDE the received guard.
        $receivedGuard = strpos($body, "\$statut === 'received'");
        $rawLinesBuild = strpos($body, "'serial_numbers' => \$serialsByCode");
        $this->assertNotFalse($rawLinesBuild);
        $this->assertLessThan($rawLinesBuild, $receivedGuard);
    }

    public function test_no_legacy_serial_writer_or_product_warehouse_on_native_import(): void
    {
        foreach (['storeImportLocationAware', 'resolveImportLinesForLocationNative', 'prevalidateImportSerialInput', 'normalizeImportSerialsByCode'] as $m) {
            $body = $this->fn($this->controller(), $m);
            $this->assertStringNotContainsString('product_warehouse', $body, "{$m}() must not touch product_warehouse");
            $this->assertStringNotContainsString('SerialNumberService', $body, "{$m}() must not use the legacy serial writer");
        }
    }

    public function test_native_import_uses_location_aware_serial_planner(): void
    {
        $body = $this->fn($this->controller(), 'planLocationAwarePurchaseSerials');
        $this->assertStringContainsString('LocationAwarePurchaseSerialPlanner', $body);
        $this->assertStringContainsString('planPurchaseReceipt(', $body);
    }

    public function test_prevalidate_serial_input_never_duplicates_the_unit_formula(): void
    {
        $body = $this->fn($this->controller(), 'prevalidateImportSerialInput');
        $this->assertStringContainsString('toBaseQuantity(', $body, 'must reuse the shared conversion, never a duplicated formula');
        $this->assertStringNotContainsString("=== '/'", $body, 'no local operator branching');
    }

    // ===================== Purchase manual (B1) / Return (B2): still active ===

    public function test_purchase_manual_and_return_still_activate_serial(): void
    {
        $manual = $this->fn($this->controller(), 'storeLocationAware');
        $this->assertStringContainsString("'allow_serial' => true", $manual);

        $ret = $this->fn($this->read('app/Http/Controllers/PurchasesReturnController.php'), 'storeLocationAware');
        $this->assertStringContainsString("'allow_serial' => true", $ret);
    }

    // ===================== legacy import: UNCHANGED =========================

    public function test_legacy_import_unchanged_no_serial_writer(): void
    {
        $src = $this->controller();
        // store_import_purchases: the legacy branch (the DB::transaction BELOW
        // the location_primary routing) never reads serials_by_code and never
        // calls SerialNumberService.
        $legacyBranchStart = strpos($src, 'return $this->storeImportLocationAware(');
        $legacyTxStart = strpos($src, '\DB::transaction(function () use ($request, $data, $batchesByCode) {');
        $this->assertNotFalse($legacyBranchStart);
        $this->assertNotFalse($legacyTxStart);
        $this->assertLessThan($legacyTxStart, $legacyBranchStart, 'native routing happens before the legacy branch');

        $legacyBody = substr($src, $legacyTxStart, strpos($src, "return response()->json(['success' => true, 'message' => 'Purchase Created !!']);", $legacyTxStart) - $legacyTxStart);
        $this->assertStringNotContainsString('serials_by_code', $legacyBody);
        $this->assertStringNotContainsString('SerialNumberService', $legacyBody);
        $this->assertStringContainsString('product_warehouse::where', $legacyBody, 'legacy still writes product_warehouse directly');
    }

    public function test_legacy_import_golden_master_still_covers_the_imei_drift(): void
    {
        $gm = $this->read('tests/Feature/PurchaseImportSerialLegacyGoldenMasterTest.php');
        $this->assertStringContainsString('test_legacy_import_received_imei_increments_stock_but_creates_zero_serials', $gm);
    }

    // ===================== frontend contract ================================

    public function test_import_form_supports_serial_entry_and_fences(): void
    {
        $src = $this->read('resources/src/views/app/pages/purchases/import_purchases.vue');

        // incompatibility now excludes a plain IMEI row; only variant and
        // batch+IMEI remain incompatible.
        $this->assertMatchesRegularExpression('/incompatibleRows\s*\(\)\s*\{[^}]*is_variant[^}]*is_imei[^}]*is_batch_tracked/s', $src);

        // serial panel reuses the shared widget, entry mode, gated to native +
        // is_imei && !is_variant && !is_batch_tracked, and to a RECEIVED import.
        $this->assertStringContainsString('<serial-numbers-field', $src);
        $this->assertStringContainsString('mode="entry"', $src);
        $this->assertStringContainsString('row.is_imei && !row.is_variant && !row.is_batch_tracked && location_meta.requires', $src);

        // required count = quantity_base (mode-dependent helper), never the CSV qty.
        $this->assertStringContainsString('serialRequiredCount(row)', $src);
        $this->assertStringContainsString('detailBaseQty(row)', $src);

        // fractional base blocks received submit, doesn't silently round.
        $this->assertStringContainsString('serialBaseIsFractional', $src);
        $this->assertStringContainsString('hasSerialValidationErrors', $src);

        // pending note, kept separate from the batch one.
        $this->assertStringContainsString('Serials_Assigned_On_Receipt', $src);

        // payload: serials_by_code only sent for a received import.
        $this->assertStringContainsString('serials_by_code', $src);
        $this->assertStringContainsString('serial_numbers', $src);
    }

    public function test_import_preview_emits_correct_warnings(): void
    {
        $body = $this->fn($this->controller(), 'preview_import_purchases');
        $this->assertStringContainsString("'variant_imei'", $body);
        $this->assertStringContainsString("'batch_imei_conflict'", $body);
        $this->assertStringNotContainsString("\$warning = 'imei';", $body, 'a plain IMEI row is no longer a warning');
        $this->assertStringContainsString('serial_required_on_receipt', $body);
        $this->assertStringContainsString('purchase_unit_operator', $body);
    }

    // ===================== D2 / POS B1 / Transfer: UNCHANGED ================

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

    // ===================== snapshot engine: unchanged, reused ===============

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
    }
}
