<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    private const FILENAME_REGEX = '/^central-backup-\d{4}-\d{2}-\d{2}_\d{6}\.sql$/';

    public function index()
    {
        return response()->json([
            'backups' => $this->listBackups(),
        ]);
    }

    public function store(Request $request)
    {
        $exitCode = Artisan::call('central:backup');
        $output   = Artisan::output();

        if ($exitCode !== 0) {
            $errorMsg = trim($output);
            if (preg_match('/ERROR_DETAILS:\s*(.+)/s', $output, $m)) {
                $errorMsg = trim($m[1]);
            }
            return response()->json([
                'success' => false,
                'message' => __('super.general_settings.backup_failed'),
                'error'   => $errorMsg !== '' ? $errorMsg : 'Unknown error.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __('super.general_settings.backup_created'),
            'backups' => $this->listBackups(),
        ]);
    }

    public function download(Request $request, string $filename): BinaryFileResponse
    {
        $path = $this->resolveSecurePath($filename);
        abort_unless($path !== null, 404);

        return response()->download($path, $filename, [
            'Content-Type'        => 'application/sql',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $filename)
    {
        $path = $this->resolveSecurePath($filename);
        abort_unless($path !== null, 404);

        @unlink($path);

        return response()->json([
            'success' => true,
            'message' => __('super.general_settings.backup_deleted'),
            'backups' => $this->listBackups(),
        ]);
    }

    private function backupDir(): string
    {
        $dir = storage_path('app/backups/central');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    public function listBackups(): array
    {
        $dir   = $this->backupDir();
        $files = glob($dir . '/*.sql') ?: [];

        $data = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (! preg_match(self::FILENAME_REGEX, $name)) {
                continue;
            }
            $data[] = [
                'name' => $name,
                'size' => $this->formatSize((int) @filesize($path)),
                'date' => date('Y-m-d H:i:s', (int) @filemtime($path)),
                'mtime' => (int) @filemtime($path),
            ];
        }

        usort($data, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return array_map(fn ($r) => array_diff_key($r, ['mtime' => null]), $data);
    }

    private function resolveSecurePath(string $filename): ?string
    {
        // Strict: whitelist the filename format, reject anything else
        $filename = basename($filename);
        if (! preg_match(self::FILENAME_REGEX, $filename)) {
            return null;
        }

        $dir  = realpath($this->backupDir());
        $path = realpath($dir . DIRECTORY_SEPARATOR . $filename);

        if ($dir === false || $path === false) {
            return null;
        }
        // Ensure resolved path is actually inside the backup dir
        if (strncmp($path, $dir . DIRECTORY_SEPARATOR, strlen($dir) + 1) !== 0) {
            return null;
        }
        if (! is_file($path)) {
            return null;
        }
        return $path;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
