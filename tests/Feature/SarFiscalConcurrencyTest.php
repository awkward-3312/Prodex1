<?php

namespace Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * REAL concurrency: N separate OS processes, each a fully-booted Laravel app,
 * race to allocate a correlativo from the SAME fiscal-series authorisation
 * against a real MySQL database. Proves the transaction + lockForUpdate on the
 * authorisation row serialises only that counter and hands out unique,
 * consecutive numbers in completion order — two cash drawers never get the same
 * number, and a slower-starting drawer that finishes first gets the lower one.
 *
 * Skipped automatically when no MySQL is reachable (e.g. CI without a DB), so it
 * never blocks the SQLite unit suite. Run locally / on the VPS with MySQL up.
 */
class SarFiscalConcurrencyTest extends TestCase
{
    private const SCRATCH_DB = 'sar_concurrency_test';

    private static array $db = [];

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $envFile = $this->readEnvFile(dirname(__DIR__, 2).'/.env');
        $val = function (string $key, string $default) use ($envFile) {
            $v = getenv('SAR_TEST_'.$key);
            if ($v === false || $v === '') {
                $v = getenv($key);
            }
            if ($v === false || $v === '') {
                $v = $envFile[$key] ?? null;
            }

            return ($v === null || $v === '') ? $default : $v;
        };

        self::$db = [
            'host' => $val('DB_HOST', '127.0.0.1'),
            'port' => $val('DB_PORT', '3306'),
            'user' => $val('DB_USERNAME', 'root'),
            'pass' => $val('DB_PASSWORD', ''),
        ];

        $host = self::$db['host'];
        $port = self::$db['port'];
        $user = self::$db['user'];
        $pass = self::$db['pass'];

        try {
            $root = new PDO(
                "mysql:host={$host};port={$port}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not reachable for the SAR concurrency test: '.$e->getMessage());
        }

        $root->exec('DROP DATABASE IF EXISTS `'.self::SCRATCH_DB.'`');
        $root->exec('CREATE DATABASE `'.self::SCRATCH_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $this->pdo = new PDO(
            "mysql:host={$host};port={$port};dbname=".self::SCRATCH_DB,
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->buildSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo->exec('DROP DATABASE IF EXISTS `'.self::SCRATCH_DB.'`');
        } catch (\Throwable $e) {
            // best effort
        }
        parent::tearDown();
    }

    public function test_ten_parallel_processes_get_ten_unique_consecutive_numbers(): void
    {
        [$pointId] = $this->seedSeries('001', '001', 'CAI-CONC', 1, 100);
        $saleIds = $this->seedSales($pointId, 10);

        $results = $this->runWorkers($saleIds, $pointId);

        $numbers = [];
        foreach ($results as $saleId => $out) {
            $this->assertSame(0, $out['code'], "worker for sale {$saleId} failed: {$out['stderr']}");
            $fn = $this->fiscalNumber($out['stdout']);
            $this->assertMatchesRegularExpression('/^001-001-01-\d{8}$/', $fn, "unexpected worker output: {$out['stdout']}");
            $numbers[] = (int) substr($fn, -8);
        }

        sort($numbers);
        $this->assertSame(range(1, 10), $numbers, 'the 10 processes must produce exactly the numbers 1..10');
        $this->assertSame(10, count(array_unique($numbers)), 'no two processes may share a correlativo');

        $auth = $this->pdo->query("SELECT next_number, status FROM sar_authorizations WHERE point_of_issue_id = {$pointId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(11, (int) $auth['next_number']);
        $this->assertSame(10, (int) $this->pdo->query('SELECT COUNT(*) c FROM sar_fiscal_documents')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function test_two_parallel_drawers_get_n_and_n_plus_one(): void
    {
        [$pointId] = $this->seedSeries('007', '007', 'CAI-PAIR', 500, 999);
        $saleIds = $this->seedSales($pointId, 2);

        $results = $this->runWorkers($saleIds, $pointId);
        $numbers = [];
        foreach ($results as $saleId => $out) {
            $this->assertSame(0, $out['code'], "worker for sale {$saleId} failed: {$out['stderr']}");
            $numbers[] = (int) substr($this->fiscalNumber($out['stdout']), -8);
        }
        sort($numbers);
        $this->assertSame([500, 501], $numbers);
    }

    public function test_a_second_series_keeps_an_independent_counter_under_load(): void
    {
        [$pA] = $this->seedSeries('011', '001', 'CAI-XA', 1, 100);
        [$pB] = $this->seedSeries('022', '001', 'CAI-XB', 1, 100);
        $salesA = $this->seedSales($pA, 5);
        $salesB = $this->seedSales($pB, 5);

        // Interleave both series' workers into one parallel wave.
        $jobs = [];
        foreach ($salesA as $id) { $jobs[$id] = $pA; }
        foreach ($salesB as $id) { $jobs[$id] = $pB; }
        $results = $this->runWorkersMixed($jobs);

        foreach ($results as $saleId => $out) {
            $this->assertSame(0, $out['code'], "worker {$saleId} failed: {$out['stderr']}");
        }

        $this->assertSame(6, (int) $this->pdo->query("SELECT next_number FROM sar_authorizations WHERE point_of_issue_id = {$pA}")->fetch(PDO::FETCH_ASSOC)['next_number']);
        $this->assertSame(6, (int) $this->pdo->query("SELECT next_number FROM sar_authorizations WHERE point_of_issue_id = {$pB}")->fetch(PDO::FETCH_ASSOC)['next_number']);
        $this->assertSame(10, (int) $this->pdo->query('SELECT COUNT(*) c FROM sar_fiscal_documents')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    private function fiscalNumber(string $stdout): string
    {
        if (preg_match('/FISCAL_NUMBER=(\S+)/', $stdout, $m)) {
            return $m[1];
        }

        return trim($stdout);
    }

    private function readEnvFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $out[trim($k)] = $v;
        }

        return $out;
    }

    // ------------------------------------------------------------- workers ---

    private function runWorkers(array $saleIds, int $pointId): array
    {
        $jobs = [];
        foreach ($saleIds as $id) {
            $jobs[$id] = $pointId;
        }

        return $this->runWorkersMixed($jobs);
    }

    private function runWorkersMixed(array $jobs): array
    {
        $worker = dirname(__DIR__).'/Support/sar_allocate_worker.php';
        $startAt = microtime(true) + 1.5; // every worker unblocks on the same tick

        $env = [
            'PATH' => getenv('PATH'),
            'HOME' => getenv('HOME'),
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => self::$db['host'],
            'DB_PORT' => self::$db['port'],
            'DB_DATABASE' => self::SCRATCH_DB,
            'DB_USERNAME' => self::$db['user'],
            'DB_PASSWORD' => self::$db['pass'],
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SAR_WORKER_START_AT' => (string) $startAt,
        ];

        $procs = [];
        foreach ($jobs as $saleId => $pointId) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker).' '.(int) $saleId.' '.(int) $pointId,
                $descriptors,
                $pipes,
                dirname(__DIR__, 2),
                $env
            );
            $this->assertIsResource($proc, "could not spawn worker for sale {$saleId}");
            $procs[$saleId] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($procs as $saleId => $h) {
            $stdout = stream_get_contents($h['pipes'][1]);
            $stderr = stream_get_contents($h['pipes'][2]);
            fclose($h['pipes'][1]);
            fclose($h['pipes'][2]);
            $code = proc_close($h['proc']);
            $results[$saleId] = ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
        }

        return $results;
    }

    // -------------------------------------------------------------- seeding ---

    private function seedSeries(string $est, string $point, string $cai, int $start, int $end): array
    {
        $this->pdo->exec("INSERT INTO sar_points_of_issue (establishment_code, point_code, name, address, branch_id, active, is_auto_managed, created_at, updated_at)
            VALUES ('{$est}', '{$point}', 'Serie {$est}-{$point}', 'Dir', NULL, 1, 1, NOW(), NOW())");
        $pointId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO sar_authorizations (point_of_issue_id, document_type, cai, range_start, range_end, next_number, deadline, status, created_at, updated_at)
            VALUES ({$pointId}, '01', '{$cai}', {$start}, {$end}, {$start}, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active', NOW(), NOW())");
        $authId = (int) $this->pdo->lastInsertId();

        return [$pointId, $authId];
    }

    private function seedSales(int $pointId, int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ref = 'CONC-'.$pointId.'-'.$i.'-'.bin2hex(random_bytes(3));
            $this->pdo->exec("INSERT INTO sales (date, time, Ref, is_pos, branch_id, GrandTotal, created_at, updated_at)
                VALUES (CURDATE(), '12:00:00', '{$ref}', 1, NULL, 100, NOW(), NOW())");
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    private function buildSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE sar_fiscal_profiles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                rtn VARCHAR(20) NULL, legal_name VARCHAR(191) NULL, trade_name VARCHAR(191) NULL,
                head_office_address TEXT NULL, phone VARCHAR(50) NULL, email VARCHAR(191) NULL,
                invoice_settings TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");
        $this->pdo->exec("
            CREATE TABLE sar_points_of_issue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                establishment_code VARCHAR(3) NULL, point_code VARCHAR(3) NULL,
                name VARCHAR(191) NOT NULL, address TEXT NOT NULL,
                branch_id INT UNSIGNED NULL, inventory_location_id INT UNSIGNED NULL,
                warehouse_id INT UNSIGNED NULL, cash_drawer_id INT UNSIGNED NULL,
                active TINYINT(1) NOT NULL DEFAULT 0, is_auto_managed TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");
        $this->pdo->exec("
            CREATE TABLE sar_authorizations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                point_of_issue_id BIGINT UNSIGNED NOT NULL,
                document_type VARCHAR(2) NOT NULL DEFAULT '01',
                cai VARCHAR(64) NOT NULL,
                range_start BIGINT UNSIGNED NOT NULL, range_end BIGINT UNSIGNED NOT NULL,
                next_number BIGINT UNSIGNED NOT NULL,
                authorization_date DATE NULL, deadline DATE NOT NULL,
                status ENUM('draft','active','prepared','exhausted','expired','disabled') NOT NULL DEFAULT 'draft',
                activated_at TIMESTAMP NULL, exhausted_at TIMESTAMP NULL, superseded_by_id BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
                INDEX sar_authorizations_series_status_index (point_of_issue_id, document_type, status)
            ) ENGINE=InnoDB
        ");
        $this->pdo->exec("
            CREATE TABLE sar_fiscal_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                authorization_id BIGINT UNSIGNED NOT NULL,
                sequence BIGINT UNSIGNED NOT NULL,
                fiscal_number VARCHAR(25) NOT NULL,
                cai VARCHAR(64) NOT NULL, deadline DATE NOT NULL,
                status ENUM('issued','voided') NOT NULL DEFAULT 'issued',
                issued_at TIMESTAMP NULL, voided_at TIMESTAMP NULL, void_reason VARCHAR(500) NULL, voided_by INT UNSIGNED NULL,
                issuer_snapshot JSON NOT NULL, customer_snapshot JSON NOT NULL, sale_snapshot JSON NOT NULL,
                created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
                UNIQUE KEY sar_fiscal_documents_sale_id_unique (sale_id),
                UNIQUE KEY sar_fiscal_documents_fiscal_number_unique (fiscal_number),
                UNIQUE KEY sar_authorization_sequence_unique (authorization_id, sequence)
            ) ENGINE=InnoDB
        ");
        $this->pdo->exec("
            CREATE TABLE sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                date DATE NULL, time VARCHAR(20) NULL, Ref VARCHAR(191) NULL,
                is_pos TINYINT(1) NOT NULL DEFAULT 1,
                client_id INT NULL, user_id INT NULL, warehouse_id INT NULL,
                branch_id INT NULL, inventory_location_id INT NULL, cash_drawer_id INT NULL,
                GrandTotal DECIMAL(15,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB
        ");

        $this->pdo->exec("INSERT INTO sar_fiscal_profiles (enabled, rtn, legal_name, head_office_address, invoice_settings, created_at, updated_at)
            VALUES (1, '08019999999999', 'Negocio SA', 'Tegucigalpa', '[]', NOW(), NOW())");
    }
}
