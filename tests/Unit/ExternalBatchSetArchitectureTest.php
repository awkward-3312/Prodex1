<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 *  MS5-B0.2 — external batch mutations are ATOMIC SETS
 * ============================================================================
 *
 *  BatchLocationService::receive() / issue() are thin wrappers over
 *  receiveMany() / issueMany(), which share ONE physical implementation,
 *  applyExternalBatchSet(). A set is NOT a loop of singles (that would re-run
 *  the coverage guard on a partially mutated state). The implementation:
 *  requires an OUTER business transaction, validates the whole set against the
 *  PRE-STATE (before any mutation), and never touches InventoryService.
 */
class ExternalBatchSetArchitectureTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = file_get_contents(dirname(__DIR__, 2).'/app/Services/BatchLocationService.php');
    }

    private function body(string $name): string
    {
        if (! preg_match('/\n\s*(?:public|private|protected)\s+function '.preg_quote($name, '/').'\s*\(/', $this->src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("method {$name}() not found");
        }
        $start = $m[0][1];
        $rest = substr($this->src, $start + strlen($m[0][0]));
        $end = preg_match('/\n\s*(?:public|private|protected)\s+function /', $rest, $mm, PREG_OFFSET_CAPTURE)
            ? $mm[0][1] : strlen($rest);

        return substr($this->src, $start, strlen($m[0][0]) + $end);
    }

    public function test_single_primitives_delegate_to_the_set_api(): void
    {
        $receive = $this->body('receive');
        $issue = $this->body('issue');

        $this->assertStringContainsString('$this->receiveMany(', $receive);
        $this->assertStringContainsString('$this->issueMany(', $issue);

        // the single methods carry no mutation / locking of their own anymore.
        foreach ([$receive, $issue] as $b) {
            $this->assertStringNotContainsString('->lockForUpdate()', $b);
            $this->assertStringNotContainsString('->save()', $b);
            $this->assertStringNotContainsString('ProductBatchLocationMovement::create', $b);
        }
    }

    public function test_receive_many_and_issue_many_are_not_a_loop_of_singles(): void
    {
        foreach (['receiveMany', 'issueMany'] as $m) {
            $b = $this->body($m);
            $this->assertStringContainsString('applyExternalBatchSet(', $b);
        }

        $impl = $this->body('applyExternalBatchSet');
        $this->assertStringNotContainsString('$this->receive(', $impl);
        $this->assertStringNotContainsString('$this->issue(', $impl);
        $this->assertStringNotContainsString('$this->receiveMany(', $impl);
        $this->assertStringNotContainsString('$this->issueMany(', $impl);
    }

    public function test_external_set_requires_an_outer_transaction(): void
    {
        $impl = $this->body('applyExternalBatchSet');
        $this->assertStringContainsString('DB::transactionLevel()', $impl);
        $this->assertStringContainsString('LogicException', $impl);

        // it must be the FIRST thing — before any query/lock/mutation.
        $txCheck = strpos($impl, 'DB::transactionLevel()');
        $firstLock = strpos($impl, '->lockForUpdate()');
        $this->assertNotFalse($txCheck);
        $this->assertNotFalse($firstLock);
        $this->assertLessThan($firstLock, $txCheck);
    }

    public function test_move_is_unchanged_and_keeps_its_own_transaction(): void
    {
        $move = $this->body('move');
        // move() is the preexisting INTERNAL primitive, outside the B0.2 contract:
        // it opens its OWN transaction and does NOT require an outer one.
        // (body() captures the trailing doc comment too, so only assert on
        // tokens that never appear in prose.)
        $this->assertStringContainsString('return DB::transaction(function ()', $move);
        $this->assertStringNotContainsString('DB::transactionLevel()', $move);
    }

    public function test_batch_location_service_never_touches_inventory_service(): void
    {
        // Only comments may mention it; no import, no call.
        $this->assertStringNotContainsString('use App\Services\InventoryService', $this->src);
        $this->assertStringNotContainsString('InventoryService::class', $this->src);
        $this->assertStringNotContainsString('app(InventoryService', $this->src);
    }

    public function test_pre_state_validation_precedes_every_mutation(): void
    {
        $impl = $this->body('applyExternalBatchSet');

        $reconcilePos = strpos($impl, 'assertBatchAggregateReady(');
        $coveragePos = strpos($impl, 'assertCoverageMatches(');
        $firstSave = strpos($impl, '->save()');
        $firstLedger = strpos($impl, 'ProductBatchLocationMovement::create(');

        $this->assertNotFalse($reconcilePos);
        $this->assertNotFalse($coveragePos);
        $this->assertNotFalse($firstSave);
        $this->assertLessThan($firstSave, $reconcilePos, 'reconcile must be checked before any save');
        $this->assertLessThan($firstSave, $coveragePos, 'coverage must be checked before any save');
        $this->assertLessThan($firstLedger, $firstSave, 'stock is mutated before the ledger rows');
    }

    public function test_lock_order_batch_then_slice_then_one_ledger_per_allocation(): void
    {
        $impl = $this->body('applyExternalBatchSet');

        $batchLock = strpos($impl, 'ProductBatch::whereIn');
        $sliceLock = strpos($impl, 'ProductBatchLocationStock::whereIn');
        $this->assertNotFalse($batchLock);
        $this->assertNotFalse($sliceLock);
        $this->assertLessThan($sliceLock, $batchLock, 'ProductBatch locked before the slices');

        // deterministic ordering of the locks.
        $this->assertStringContainsString("sort(\$batchIds, SORT_NUMERIC)", $impl);
        $this->assertMatchesRegularExpression('/ProductBatch::whereIn.*?->orderBy\(.id.\)/s', $impl);

        // one ledger row per allocation (loop over the normalized rows).
        $this->assertMatchesRegularExpression('/foreach \(\$rows as \$r\).*?ProductBatchLocationMovement::create\(/s', $impl);
    }
}
