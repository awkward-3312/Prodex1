<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  KNOWN LOCK-ORDER INVERSION — batch/serial artifacts vs general inventory
 * ============================================================================
 *
 *  Two existing productive flows acquire the batch layer and the general
 *  inventory layer in OPPOSITE order inside a single business transaction:
 *
 *    Flow A — POS location-native sale
 *      PosLocationSaleStockService::apply()
 *        -> InventoryService::decrease()      (locks inventory_location_stocks)
 *      ...later, in SalesController, LocationAwareBatchService runs and locks
 *         product_batches -> product_batch_location_stocks
 *      => GENERAL first, then BATCH.
 *
 *    Flow B — InternalInventoryMove
 *      InternalInventoryMoveService (one DB::transaction)
 *        -> BatchLocationService::move()      (product_batches -> product_batch_location_stocks)
 *        -> InventoryService::move()          (inventory_location_stocks)
 *      => BATCH first, then GENERAL.
 *
 *  If the same product+location is touched by both concurrently, MySQL can
 *  deadlock (SQLSTATE 40001).
 *
 *  CANONICAL TARGET (approved MS5-A): BATCH / SERIAL ARTIFACTS BEFORE GENERAL
 *  INVENTORY — the direction Flow B and the whole transfer chain already use.
 *
 *  This test is a CHARACTERIZATION: it PASSES while the inversion exists and
 *  fails the day it is removed, forcing a conscious update.
 *
 *  >>> MS5-B1 MUST REMOVE THIS INVERSION <<<
 *  MS5-B1 (POS LOCK ORDER HARDENING) will reorder PosLocationSaleStockService
 *  so the batch/serial layer runs before InventoryService::decrease. When that
 *  lands, delete this test (or flip it to assert the canonical order).
 *
 *  MS5-B0 does NOT touch either flow — foundation only.
 */
class BatchGeneralLockOrderInversionArchitectureTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$rel);
    }

    public function test_pos_sale_locks_general_inventory_with_no_batch_layer_in_the_same_service(): void
    {
        $src = $this->read('app/Services/PosLocationSaleStockService.php');

        // Flow A: the POS aggregate decrease goes straight to InventoryService.
        $this->assertStringContainsString('app(InventoryService::class)', $src);
        $this->assertStringContainsString('->decrease(', $src);

        // ...and the batch layer is NOT invoked here — it runs later in the
        // request (SalesController -> LocationAwareBatchService), i.e. AFTER the
        // general lock is already held.
        $this->assertStringNotContainsString('BatchLocationService', $src);
        $this->assertStringNotContainsString('LocationAwareBatchService', $src);
        $this->assertStringNotContainsString('product_batch', $src);
    }

    public function test_internal_inventory_move_locks_batch_layer_before_general(): void
    {
        $src = $this->read('app/Services/InternalInventoryMoveService.php');

        $batchPos = strpos($src, 'BatchLocationService::class)->move(');
        $generalPos = strpos($src, 'InventoryService::class)->move(');

        $this->assertNotFalse($batchPos, 'expected a BatchLocationService::move() call');
        $this->assertNotFalse($generalPos, 'expected an InventoryService::move() call');

        // Flow B: BATCH first, then GENERAL — the opposite of Flow A.
        $this->assertLessThan(
            $generalPos,
            $batchPos,
            'InternalInventoryMoveService should lock the batch layer before the general layer'
        );
    }

    public function test_the_inversion_is_still_present_ms5_b1_must_remove_it(): void
    {
        $pos = $this->read('app/Services/PosLocationSaleStockService.php');
        $move = $this->read('app/Services/InternalInventoryMoveService.php');

        $flowA_generalFirst = str_contains($pos, '->decrease(')
            && ! str_contains($pos, 'BatchLocationService');

        $flowB_batchFirst = strpos($move, 'BatchLocationService::class)->move(')
            < strpos($move, 'InventoryService::class)->move(');

        $this->assertTrue(
            $flowA_generalFirst && $flowB_batchFirst,
            'The POS(general-first) vs InternalInventoryMove(batch-first) lock-order inversion no longer holds. '
            .'If MS5-B1 fixed it, delete this characterization test.'
        );
    }

    public function test_batch_foundation_primitives_lock_batch_before_slice(): void
    {
        $src = $this->read('app/Services/BatchLocationService.php');

        foreach (['receive', 'issue'] as $method) {
            $this->assertMatchesRegularExpression(
                '/function '.$method.'\(.*?ProductBatch::.*?->lockForUpdate\(\).*?ProductBatchLocationStock::.*?->lockForUpdate\(\)/s',
                $src,
                $method.'() must lock ProductBatch before ProductBatchLocationStock'
            );
        }
    }
}
