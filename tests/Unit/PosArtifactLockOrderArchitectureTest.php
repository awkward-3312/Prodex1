<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  POS ARTIFACT LOCK ORDER — canonical: BATCH -> SERIAL -> GENERAL
 * ============================================================================
 *
 *  Replaces BatchGeneralLockOrderInversionArchitectureTest (MS5-B0), which
 *  characterized the inversion:
 *
 *      POS sale            : general decrease, THEN batch/serial   (WRONG)
 *      InternalInventoryMove: batch, serial, THEN general          (canonical)
 *
 *  MS5-B1 removed the inversion. PosLocationSaleStockService::apply() now runs a
 *  full-cart ARTIFACT PREFLIGHT (batch -> serial) that row-locks every physical
 *  artifact BEFORE the first InventoryService::decrease. The batch apply after
 *  SaleDetail insert consumes a FROZEN plan and never re-runs FEFO, so no
 *  ProductBatch / ProductSerial lock is ever acquired after the general lock.
 *
 *  Both flows now acquire locks in the SAME direction:
 *      BATCH ARTIFACTS -> SERIAL ARTIFACTS -> GENERAL INVENTORY
 */
class PosArtifactLockOrderArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_pos_sale_runs_artifact_preflight_before_the_general_decrease(): void
    {
        $src = $this->read('app/Services/PosLocationSaleStockService.php');

        $preflightPos = strpos($src, 'PosLocationArtifactPreflightService::class)->preflight(');
        $decreasePos = strpos($src, '->decrease(');

        $this->assertNotFalse($preflightPos, 'apply() must call the artifact preflight coordinator');
        $this->assertNotFalse($decreasePos, 'apply() must still call InventoryService::decrease');
        $this->assertLessThan(
            $decreasePos,
            $preflightPos,
            'the artifact preflight must run BEFORE any InventoryService::decrease'
        );
    }

    public function test_coordinator_preflights_batch_then_serial(): void
    {
        $src = $this->read('app/Services/PosLocationArtifactPreflightService.php');

        $batchPos = strpos($src, 'preflightSaleAllocations(');
        $serialPos = strpos($src, 'preflightSaleSerials(');

        $this->assertNotFalse($batchPos);
        $this->assertNotFalse($serialPos);
        $this->assertLessThan($serialPos, $batchPos, 'batch preflight must run before serial preflight');
    }

    public function test_internal_inventory_move_keeps_batch_then_serial_then_general(): void
    {
        $src = $this->read('app/Services/InternalInventoryMoveService.php');

        $batchPos = strpos($src, 'BatchLocationService::class)->move(');
        $serialPos = strpos($src, 'SerialLocationService::class)->moveSerials(');
        $generalPos = strpos($src, 'InventoryService::class)->move(');

        $this->assertNotFalse($batchPos);
        $this->assertNotFalse($serialPos);
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($serialPos, $batchPos, 'batch before serial');
        $this->assertLessThan($generalPos, $serialPos, 'serial before general');
    }

    public function test_both_flows_lock_artifacts_before_general_same_direction(): void
    {
        // POS: preflight (batch->serial) strictly before the aggregate decrease.
        $pos = $this->read('app/Services/PosLocationSaleStockService.php');
        $this->assertLessThan(
            strpos($pos, '->decrease('),
            strpos($pos, 'PosLocationArtifactPreflightService::class)->preflight('),
        );

        // InternalInventoryMove: batch -> serial -> general.
        $move = $this->read('app/Services/InternalInventoryMoveService.php');
        $this->assertLessThan(
            strpos($move, 'InventoryService::class)->move('),
            strpos($move, 'BatchLocationService::class)->move('),
        );

        // The old "MS5-B1 MUST REMOVE THIS INVERSION" marker is gone.
        $this->assertStringNotContainsString(
            'MUST REMOVE THIS INVERSION',
            $pos.$move.$this->read('app/Services/LocationAwareBatchService.php')
        );
    }

    public function test_batch_apply_consumes_the_frozen_plan_and_skips_fefo(): void
    {
        $src = $this->read('app/Services/LocationAwareBatchService.php');

        // applyForSaleWithAutoFallback: when a plan exists it consumes the frozen
        // allocations and `continue`s before ever reaching consumeFefo().
        $planCheck = strpos($src, 'if ($plan !== null)');
        $consumeFefo = strpos($src, '$this->consumeFefo(');
        $this->assertNotFalse($planCheck);
        $this->assertNotFalse($consumeFefo);
        $this->assertLessThan($consumeFefo, $planCheck, 'the frozen-plan branch must be evaluated before the FEFO fallback');

        $this->assertStringContainsString('public function preflightSaleAllocations(', $src);
        $this->assertStringContainsString('->lockForUpdate()', $src);
        $this->assertStringContainsString('POS_BATCH_PREFLIGHT_ATTR', $src);
    }

    public function test_serial_preflight_locks_without_mutation(): void
    {
        $src = $this->read('app/Services/LocationAwareSerialNumberService.php');
        $body = $this->methodBody($src, 'preflightSaleSerials');

        $this->assertStringContainsString('->lockForUpdate()', $body);
        // No mutation in preflight: no status write, no movement, no sale linkage.
        $this->assertStringNotContainsString('STATUS_SOLD', $body);
        $this->assertStringNotContainsString('ProductSerialMovement::create', $body);
        $this->assertStringNotContainsString('->save()', $body);
    }

    public function test_preflight_is_invoked_from_sale_created_inside_create_pos_transaction(): void
    {
        $sale = $this->read('app/Models/Sale.php');
        $this->assertMatchesRegularExpression(
            '/static::created\(function \(Sale \$sale\).*PosLocationSaleStockService::class\)->apply\(\$sale, \$request\)/s',
            $sale,
            'Sale::created must invoke PosLocationSaleStockService::apply'
        );

        $pos = $this->read('app/Http/Controllers/PosController.php');
        $createPos = $this->methodBody($pos, 'CreatePOS');
        $this->assertStringContainsString('\DB::transaction(', $createPos);
        $this->assertStringContainsString('$order->save();', $createPos);
        // the batch/serial apply steps are also inside that same transaction.
        $txPos = strpos($createPos, '\DB::transaction(');
        $this->assertLessThan(strpos($createPos, '$order->save();'), $txPos);
        $this->assertLessThan(strpos($createPos, 'applyForSaleWithAutoFallback('), $txPos);
    }

    public function test_batch_foundation_primitives_still_lock_batch_before_slice(): void
    {
        // MS5-B0.2: every external mutation flows through one implementation,
        // applyExternalBatchSet(), which locks ProductBatch before the slice.
        $src = $this->read('app/Services/BatchLocationService.php');
        $body = $this->methodBody($src, 'applyExternalBatchSet');
        $this->assertMatchesRegularExpression(
            '/ProductBatch::.*?->lockForUpdate\(\).*?ProductBatchLocationStock::.*?->lockForUpdate\(\)/s',
            $body,
            'applyExternalBatchSet() must lock ProductBatch before ProductBatchLocationStock'
        );
    }

    private function methodBody(string $src, string $name): string
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
}
