<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class CentralDatabaseBackup extends Command
{
    protected $signature = 'central:backup';

    protected $description = 'Create a secured backup of the central database (retained, stored outside public).';

    public function handle()
    {
        $backupDir = storage_path('app/backups/central');
        if (! is_dir($backupDir)) {
            @mkdir($backupDir, 0700, true);
        }

        // Protect the folder against direct web access even if the path is exposed
        $htaccess = $backupDir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }
        $indexHtml = $backupDir . '/index.html';
        if (! file_exists($indexHtml)) {
            @file_put_contents($indexHtml, '');
        }

        $timestamp = Carbon::now()->format('Y-m-d_His');
        $filename  = 'central-backup-' . $timestamp . '.sql';
        $outputPath = $backupDir . '/' . $filename;

        $conn = config('database.default');
        $dumpPath = config("database.connections.$conn.dump_path", 'mysqldump');
        $user     = config("database.connections.$conn.username");
        $password = config("database.connections.$conn.password");
        $host     = config("database.connections.$conn.host", '127.0.0.1');
        $port     = config("database.connections.$conn.port", '3306');
        $database = config("database.connections.$conn.database");

        $cmd  = escapeshellarg($dumpPath);
        $cmd .= ' --user=' . escapeshellarg($user);
        if ($password !== '' && $password !== null) {
            $cmd .= ' --password=' . escapeshellarg($password);
        }
        $cmd .= ' --host=' . escapeshellarg($host);
        $cmd .= ' --port=' . escapeshellarg($port);
        $cmd .= ' --single-transaction --skip-lock-tables --routines --triggers';
        $cmd .= ' ' . escapeshellarg($database);
        $cmd .= ' > ' . escapeshellarg($outputPath);

        $output = [];
        $returnVar = null;
        \exec($cmd . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $err = 'Exit code: ' . $returnVar;
            if (! empty($output)) {
                $err .= "\n" . implode("\n", $output);
            } elseif (file_exists($outputPath) && filesize($outputPath) > 0) {
                $err .= "\n" . trim((string) @file_get_contents($outputPath));
            }
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            $this->line('ERROR_DETAILS: ' . $err);
            return $returnVar;
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            $this->line('ERROR_DETAILS: Backup file is empty or missing after mysqldump completed.');
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            return 1;
        }

        @chmod($outputPath, 0600);

        $this->line('BACKUP_FILE: ' . $filename);
        return 0;
    }
}
