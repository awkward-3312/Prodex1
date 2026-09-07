<?php

/**
 * Concurrency worker for SarFiscalConcurrencyTest.
 *
 * Boots the real Laravel application (default DB connection pointed at the
 * scratch MySQL database via env vars set by the parent test) and runs the real
 * SarFiscalNumberService::issue() for one pre-created sale. Prints the allocated
 * fiscal number on stdout, or an error on stderr with a non-zero exit.
 *
 *   php tests/support/sar_allocate_worker.php <saleId> <pointOfIssueId>
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$saleId = (int) ($argv[1] ?? 0);
$pointId = (int) ($argv[2] ?? 0);

if ($saleId <= 0 || $pointId <= 0) {
    fwrite(STDERR, "usage: sar_allocate_worker.php <saleId> <pointOfIssueId>\n");
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// A tiny stagger point: every worker waits on the same wall-clock tick so the
// row lock is genuinely contended.
$startAt = (float) (getenv('SAR_WORKER_START_AT') ?: 0);
if ($startAt > 0) {
    $now = microtime(true);
    if ($startAt > $now) {
        usleep((int) (($startAt - $now) * 1_000_000));
    }
}

try {
    $doc = app(\App\Services\SarFiscalNumberService::class)->issue(
        \App\Models\Sale::findOrFail($saleId),
        $pointId,
        '01',
        [],
        []
    );

    // A machine-readable marker so the parent can ignore any stray boot output.
    fwrite(STDOUT, 'FISCAL_NUMBER='.$doc->fiscal_number."\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
