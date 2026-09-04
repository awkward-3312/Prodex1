<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS6-B0 — §46: architecture contract for the serial location-native
 * FOUNDATION. The engine exists; NO controller activates it.
 *
 * Pattern / structure based, never line numbers.
 */
class SerialNativeFoundationArchitectureTest extends TestCase
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

    // ===================== the new pieces exist =====================

    public function test_serial_planner_exists_and_is_physically_inert(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseSerialPlanner.php');
        $this->assertStringContainsString('class LocationAwarePurchaseSerialPlanner', $src);
        $this->assertStringContainsString('public function planPurchaseReceipt(', $src);
        $this->assertStringContainsString('public function planPurchaseReturnIssue(', $src);
        $this->assertStringContainsString('DB::transactionLevel()', $src);
        $this->assertStringContainsString('LogicException', $src);
        // never touches physical stock.
        $this->assertStringNotContainsString('InventoryService', $src);
        $this->assertStringNotContainsString('product_warehouse', $src);
        $this->assertStringNotContainsString('receiveMany', $src);
        $this->assertStringNotContainsString('ProductSerialMovement', $src);
    }

    public function test_serial_set_service_never_touches_general_stock(): void
    {
        $src = $this->read('app/Services/LocationAwareSerialNumberService.php');
        foreach (['receivePurchaseMany', 'voidPurchaseMany', 'returnToSupplierMany', 'reversePurchaseReturnMany', 'runSerialSet'] as $m) {
            $body = $this->fn($src, $m);
            $this->assertStringNotContainsString('InventoryService', $body, "{$m}() must not touch general stock");
            $this->assertStringNotContainsString('product_warehouse', $body);
        }
        // set ops require the caller's transaction.
        $this->assertStringContainsString('DB::transactionLevel()', $src);
        $this->assertStringContainsString('idempotency_key', $src);
        $this->assertStringContainsString('idempotency_fingerprint', $src);
    }

    public function test_coverage_service_exists_and_reads_only(): void
    {
        $src = $this->read('app/Services/SerialInventoryCoverageService.php');
        $this->assertStringContainsString('class SerialInventoryCoverageService', $src);
        $this->assertStringContainsString('public function coverageForLocation(', $src);
        $this->assertStringContainsString('unmigratedLegacySerialCount', $src);
        $this->assertStringNotContainsString('->save(', $src);
        $this->assertStringNotContainsString('->update(', $src);
        $this->assertStringNotContainsString('->insert(', $src);
    }

    public function test_product_serial_has_voided_status_and_no_soft_deletes(): void
    {
        $src = $this->read('app/Models/ProductSerial.php');
        $this->assertStringContainsString("STATUS_VOIDED = 'voided'", $src);
        $this->assertStringNotContainsString('use SoftDeletes;', $src);
        // available() scope must NOT include voided.
        $scope = $this->fn($src, 'scopeAvailable');
        $this->assertStringContainsString('STATUS_AVAILABLE', $scope);
        $this->assertStringNotContainsString('STATUS_VOIDED', $scope);
    }

    // ===================== allow_serial default false =====================

    public function test_allow_serial_defaults_to_false_in_the_stock_service(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        $this->assertStringContainsString("\$options['allow_serial'] ?? false", $src);
        // IMEI still fails closed without allow_serial.
        $vlock = $this->fn($src, 'validateAndLock');
        $this->assertStringContainsString('$imeiIds[$pid] = true;', $vlock);
        $this->assertStringContainsString("if (\$isImei && \$isBatch)", $vlock);
    }

    public function test_run_snapshot_order_is_batch_then_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'runSnapshot');
        $batchPos = strpos($body, 'PHASE A');
        $serialPos = strpos($body, 'PHASE B — ALL SERIAL');
        $generalPos = strpos($body, 'PHASE C');
        $this->assertNotFalse($batchPos);
        $this->assertNotFalse($serialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($serialPos, $batchPos, 'batch before serial');
        $this->assertLessThan($generalPos, $serialPos, 'serial before general');

        // the serial phase dispatches to the 4 set operations.
        foreach (['receivePurchaseMany', 'voidPurchaseMany', 'returnToSupplierMany', 'reversePurchaseReturnMany'] as $m) {
            $this->assertStringContainsString($m.'(', $body);
        }
        // and it runs the batch phase's InventoryService AFTER (general).
        $invPos = strpos($body, '$this->inventory->increase(');
        $this->assertNotFalse($invPos);
        $this->assertGreaterThan($serialPos, $invPos, 'general InventoryService runs after the serial phase');
    }

    public function test_batch_and_serial_are_mutually_exclusive_in_the_snapshot(): void
    {
        $src = $this->read('app/Services/LocationAwarePurchaseStockService.php');
        // buildSnapshot guard.
        $this->assertStringContainsString('no puede llevar asignación de lotes Y de series', $src);
        // normalizeSnapshot guard.
        $this->assertStringContainsString('lleva asignación de lotes Y de series a la vez', $src);
    }

    // ===================== ACTIVATION SCOPE — B1 = manual Purchase only ========
    // (full B1 wiring assertions live in PurchaseSerialLocationNativeArchitectureTest)

    public function test_purchase_return_controller_still_does_not_activate_serial(): void
    {
        $src = $this->read('app/Http/Controllers/PurchasesReturnController.php');
        $this->assertStringNotContainsString("'allow_serial' => true", $src, 'PurchaseReturn serial-native is MS6-B2, not yet');
        $this->assertStringNotContainsString('LocationAwarePurchaseSerialPlanner', $src);
        $this->assertStringNotContainsString('receivePurchaseMany', $src);
    }

    public function test_import_native_path_does_not_activate_serial(): void
    {
        $import = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'storeImportLocationAware');
        $this->assertStringNotContainsString('allow_serial', $import);
        $this->assertStringNotContainsString('LocationAwarePurchaseSerialPlanner', $import);
        // the MS4/MS5-E resolver still 422s an IMEI row.
        $resolver = $this->fn($this->read('app/Http/Controllers/PurchasesController.php'), 'resolveImportLinesForLocationNative');
        $this->assertStringContainsString("(int) (\$product->is_imei ?? 0) === 1", $resolver);
        $this->assertStringContainsString('serializado (IMEI)', $resolver);
    }

    // ===================== legacy + POS + transfer untouched =====================

    public function test_legacy_serial_number_service_is_unchanged(): void
    {
        $src = $this->read('app/Services/SerialNumberService.php');
        // legacy purchase count still uses document quantity + round().
        $recv = $this->fn($src, 'receiveOnPurchase');
        $this->assertStringContainsString('assertCountMatches($serials, $detail->quantity)', $recv);
        // legacy reverse still HARD-deletes.
        $rev = $this->fn($src, 'reverseForPurchaseDetails');
        $this->assertStringContainsString('->delete();', $rev);
        $this->assertStringNotContainsString('STATUS_VOIDED', $rev);
        // legacy logMovement never sets an idempotency key.
        $log = $this->fn($src, 'logMovement');
        $this->assertStringNotContainsString('idempotency_key', $log);
    }

    public function test_pos_b1_serial_preflight_is_unchanged(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareSerialNumberService.php'), 'preflightSaleSerials');
        $this->assertStringContainsString('sort($allSerialIds, SORT_NUMERIC);', $body);
        $this->assertStringNotContainsString('ProductSerialMovement::create', $body);
        // still no set-op leakage into POS.
        $this->assertStringNotContainsString('receivePurchaseMany', $body);
    }

    public function test_transfer_receive_is_now_serial_before_general(): void
    {
        // MS6-B0.1 flipped this. Full matrix lives in
        // TransferReceiptLockOrderArchitectureTest.
        $body = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditGoodStock');
        $generalPos = strpos($body, 'InventoryService::class)->increase(');
        $serialPos = strpos($body, 'TransferSerialLocationService::class)->receiveGood(');
        $this->assertNotFalse($generalPos);
        $this->assertNotFalse($serialPos);
        $this->assertLessThan($generalPos, $serialPos, 'MS6-B0.1: transfer receive is SERIAL -> GENERAL');
    }
}
