<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MS6-B0.1 — canonical artifact lock order for every location-native flow that
 * touches BATCH and/or SERIAL artifacts plus GENERAL stock:
 *
 *     PHASE A  batch artifacts
 *     PHASE B  serial artifacts
 *     PHASE C  general InventoryService
 *
 * Never GENERAL -> SERIAL, never GENERAL -> BATCH, within a concurrent path.
 *
 * B0.1 fixes the RECEIVE side of location-native transfers (creditGoodStock /
 * creditDefectiveToQuarantine / creditIssueResolution), which previously did
 * GENERAL -> SERIAL (-> BATCH). Dispatch, InternalInventoryMove and POS B1 were
 * already canonical and are re-pinned here as the matrix.
 *
 * Structure / position based, never line numbers.
 */
class TransferReceiptLockOrderArchitectureTest extends TestCase
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

    private function assertOrder(string $body, array $markers, string $why): void
    {
        $prev = -1;
        $prevLabel = 'start';
        foreach ($markers as [$label, $needle]) {
            $pos = strpos($body, $needle);
            $this->assertNotFalse($pos, "{$why}: marker '{$label}' not found");
            $this->assertGreaterThan($prev, $pos, "{$why}: '{$label}' must come after '{$prevLabel}'");
            $prev = $pos;
            $prevLabel = $label;
        }
    }

    // ===================== TRANSFER RECEIVE (fixed in B0.1) =====================

    public function test_receive_good_is_batch_then_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditGoodStock');
        $this->assertOrder($body, [
            ['BATCH', 'creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);'],
            ['SERIAL', 'TransferSerialLocationService::class)->receiveGood('],
            ['GENERAL', 'InventoryService::class)->increase('],
        ], 'transfer receive-good');
    }

    public function test_receive_defective_is_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditDefectiveToQuarantine');
        $this->assertOrder($body, [
            ['SERIAL', 'TransferSerialLocationService::class)->receiveDefective('],
            ['GENERAL', 'InventoryService::class)->increase('],
        ], 'transfer receive-defective');
    }

    public function test_issue_resolution_is_batch_then_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwareTransferLogisticsService.php'), 'creditIssueResolution');
        $this->assertOrder($body, [
            ['BATCH', 'creditBatchStockIfApplicable($transfer, $detail, $stockQty, $receiptItem);'],
            ['SERIAL', 'TransferSerialLocationService::class)->reclassifyIssueToGood('],
        ], 'transfer issue-resolution artifacts');
        // both general branches come after the serial step.
        $serialPos = strpos($body, 'TransferSerialLocationService::class)->reclassifyIssueToGood(');
        $movePos = strpos($body, 'InventoryService::class)->move(');
        $incPos = strpos($body, 'InventoryService::class)->increase(');
        $this->assertNotFalse($serialPos);
        $this->assertGreaterThan($serialPos, $movePos, 'defective general move after serial');
        $this->assertGreaterThan($serialPos, $incPos, 'missing general increase after serial');
    }

    public function test_no_general_before_serial_anywhere_in_the_receive_side(): void
    {
        $src = $this->read('app/Services/LocationAwareTransferLogisticsService.php');
        foreach (['creditGoodStock', 'creditDefectiveToQuarantine', 'creditIssueResolution'] as $m) {
            $body = $this->fn($src, $m);
            $firstGeneral = min(array_filter([
                strpos($body, 'InventoryService::class)->increase('),
                strpos($body, 'InventoryService::class)->move('),
                strpos($body, 'InventoryService::class)->decrease('),
            ], fn ($v) => $v !== false) ?: [PHP_INT_MAX]);
            foreach (['receiveGood', 'receiveDefective', 'reclassifyIssueToGood'] as $serialCall) {
                $sp = strpos($body, 'TransferSerialLocationService::class)->'.$serialCall.'(');
                if ($sp !== false) {
                    $this->assertLessThan($firstGeneral, $sp, "{$m}(): serial {$serialCall} must precede the first general InventoryService call");
                }
            }
        }
    }

    // ===================== MATRIX — already-canonical flows =====================

    public function test_transfer_dispatch_is_batch_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/TransferLocationDispatchService.php'), 'ensureDispatched');
        $this->assertOrder($body, [
            ['SERIAL', 'TransferSerialLocationService::class)->dispatchDetail('],
            ['GENERAL', 'InventoryService::class)->decrease('],
        ], 'transfer dispatch');
        // batch dispatch happens per-detail before the serial call.
        $batchPos = strpos($body, '$this->dispatchBatches(');
        $serialPos = strpos($body, 'TransferSerialLocationService::class)->dispatchDetail(');
        $this->assertNotFalse($batchPos);
        $this->assertLessThan($serialPos, $batchPos);
    }

    public function test_internal_inventory_move_is_batch_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/InternalInventoryMoveService.php'), 'move');
        $this->assertOrder($body, [
            ['BATCH', 'BatchLocationService::class)->move('],
            ['SERIAL', 'SerialLocationService::class)->moveSerials('],
            ['GENERAL', 'InventoryService::class)->move('],
        ], 'internal inventory move');
    }

    public function test_pos_b1_preflights_batch_then_serial_before_the_general_decrease(): void
    {
        $preflight = $this->fn($this->read('app/Services/PosLocationArtifactPreflightService.php'), 'preflight');
        $this->assertOrder($preflight, [
            ['BATCH', 'LocationAwareBatchService::class)->preflightSaleAllocations('],
            ['SERIAL', 'LocationAwareSerialNumberService::class)->preflightSaleSerials('],
        ], 'POS B1 preflight');

        // the coordinator runs BEFORE the general decrease loop in the caller.
        $apply = $this->fn($this->read('app/Services/PosLocationSaleStockService.php'), 'apply');
        $preflightCall = strpos($apply, 'PosLocationArtifactPreflightService::class)->preflight(');
        $decrease = strpos($apply, '$inventory->decrease(');
        $this->assertNotFalse($preflightCall);
        $this->assertNotFalse($decrease);
        $this->assertLessThan($decrease, $preflightCall, 'POS B1 preflight (batch+serial) before general decrease');
    }

    public function test_ms6_purchase_engine_runs_batch_then_serial_then_general(): void
    {
        $body = $this->fn($this->read('app/Services/LocationAwarePurchaseStockService.php'), 'runSnapshot');
        $batchPos = strpos($body, 'PHASE A');
        $serialPos = strpos($body, 'PHASE B — ALL SERIAL');
        $generalPos = strpos($body, 'PHASE C');
        $this->assertNotFalse($batchPos);
        $this->assertNotFalse($serialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($serialPos, $batchPos);
        $this->assertLessThan($generalPos, $serialPos);
    }

    // ===================== status semantics unchanged (§7) =====================

    public function test_transfer_status_vocabulary_is_unchanged_no_voided_leak(): void
    {
        $src = $this->read('app/Services/TransferSerialLocationService.php');
        $this->assertStringNotContainsString('STATUS_VOIDED', $src, 'voided is a Purchase-native status, never Transfer');
        $this->assertStringNotContainsString("'returned_supplier'", $src);
        // the historical transitions are still there.
        $this->assertStringContainsString('ProductSerial::STATUS_RESERVED', $src);
        $this->assertStringContainsString('ProductSerial::STATUS_AVAILABLE', $src);
        $this->assertStringContainsString('ProductSerial::STATUS_DAMAGED', $src);
    }

    public function test_transfer_serial_locks_are_still_deterministic(): void
    {
        $src = $this->read('app/Services/TransferSerialLocationService.php');
        $this->assertStringContainsString("->orderBy('id')", $src);
        $this->assertStringContainsString('->lockForUpdate()', $src);
    }
}
