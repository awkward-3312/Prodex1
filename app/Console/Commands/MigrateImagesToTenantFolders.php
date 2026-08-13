<?php

namespace App\Console\Commands;

use App\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MigrateImagesToTenantFolders extends Command
{
    protected $signature = 'images:migrate-to-tenants';

    protected $description = 'Migrate existing images from public/images/ into per-tenant and super-admin folders';

    /**
     * Subfolders inside public/images/ that contain tenant-uploaded files.
     * Each entry maps: subfolder => [ DB tables that store full relative paths ].
     * Tables not listed here store only filenames (no DB update needed, just file copy).
     */
    private const TENANT_SUBFOLDERS = [
        'products'            => [],
        'brands'              => [],
        'avatar'              => [],
        'banners'             => [],
        'store'               => [],
        'leaves'              => [],
        'flags'               => [],
        'count_stock'         => [],
        'purchase_documents'  => [],
        'sale_documents'      => [],
        'expense_documents'   => [],
    ];

    /**
     * Tables that store full relative paths (e.g. "images/store/file.png").
     * These need DB updates after files are moved.
     */
    private const PATH_COLUMNS = [
        'store_settings' => ['logo_path', 'favicon_path', 'hero_image_path'],
        'store_banners'  => ['image'],
        'purchase_documents' => ['path'],
        'sale_documents' => ['path'],
        'expense_documents' => ['path'],
    ];

    /**
     * Subfolders that go under images/super/ (central/super admin uploads).
     */
    private const SUPER_SUBFOLDERS = [
        'avatars'  => 'avatars',
        'settings' => 'settings',
    ];

    public function handle(): int
    {
        $this->info('=== Image Migration to Tenant Folders ===');
        $this->newLine();

        // 1. Migrate super admin images
        $this->migrateSuperAdmin();

        // 2. Migrate tenant images
        $this->migrateTenants();

        // 3. Copy default/shared assets to each tenant folder
        $this->copyDefaults();

        $this->newLine();
        $this->info('Migration complete!');

        return self::SUCCESS;
    }

    private function migrateSuperAdmin(): void
    {
        $this->info('[Super Admin] Migrating images to images/super/...');

        $base = public_path('images');

        // Move avatars
        $src = $base . '/avatars';
        $dst = public_path('images/super/avatars');
        if (is_dir($src)) {
            $this->copyDirectory($src, $dst);
            $this->info('  Copied avatars/ -> images/super/avatars/');
        }

        // Move settings
        $src = $base . '/settings';
        $dst = public_path('images/super/settings');
        if (is_dir($src)) {
            $this->copyDirectory($src, $dst);
            $this->info('  Copied settings/ -> images/super/settings/');
        }

        // Update central_users.avatar paths
        $users = DB::connection('central')
            ->table('central_users')
            ->whereNotNull('avatar')
            ->where('avatar', '!=', '')
            ->get(['id', 'avatar']);

        foreach ($users as $user) {
            $newPath = str_replace('images/avatars/', 'images/super/avatars/', $user->avatar);
            if ($newPath !== $user->avatar) {
                DB::connection('central')
                    ->table('central_users')
                    ->where('id', $user->id)
                    ->update(['avatar' => $newPath]);
                $this->line("  Updated central_user #{$user->id} avatar: {$user->avatar} -> {$newPath}");
            }
        }

        // Update general_settings paths
        $gs = DB::connection('central')->table('general_settings')->first();
        if ($gs) {
            $updates = [];
            foreach (['logo_path', 'favicon_path'] as $col) {
                if (!empty($gs->$col) && str_starts_with($gs->$col, 'images/settings/')) {
                    $updates[$col] = str_replace('images/settings/', 'images/super/settings/', $gs->$col);
                }
            }
            if ($updates) {
                DB::connection('central')->table('general_settings')->where('id', $gs->id)->update($updates);
                $this->line('  Updated general_settings paths');
            }
        }
    }

    private function migrateTenants(): void
    {
        $tenants = Tenant::all();
        $this->info("[Tenants] Found {$tenants->count()} tenants");
        $this->newLine();

        $base = public_path('images');

        foreach ($tenants as $tenant) {
            $tenantDir = public_path("images/tenants/{$tenant->id}");

            $this->info("[{$tenant->id}] Migrating...");

            // Copy each subfolder
            foreach (array_keys(self::TENANT_SUBFOLDERS) as $subfolder) {
                $src = $base . '/' . $subfolder;
                $dst = $tenantDir . '/' . $subfolder;

                if (is_dir($src)) {
                    $this->copyDirectory($src, $dst);
                    $this->line("  Copied {$subfolder}/");
                }
            }

            // Copy root-level files that tenants reference (logo, defaults)
            $rootFiles = ['logo-default.png', 'no-image.png', 'no_avatar.png', 'favicon.ico'];
            foreach ($rootFiles as $file) {
                $src = $base . '/' . $file;
                $dstDir = $tenantDir . '/settings';
                if (is_file($src)) {
                    File::ensureDirectoryExists($dstDir);
                    File::copy($src, $dstDir . '/' . $file);
                }
            }

            // Update DB paths for this tenant
            try {
                tenancy()->initialize($tenant);
                $this->updateTenantDbPaths($tenant->id);
                tenancy()->end();
            } catch (\Throwable $e) {
                $this->warn("  Skipping DB update: {$e->getMessage()}");
                try { tenancy()->end(); } catch (\Throwable $ex) {}
            }

            $this->newLine();
        }
    }

    private function updateTenantDbPaths(string $tenantId): void
    {
        $prefix = "images/tenants/{$tenantId}";

        foreach (self::PATH_COLUMNS as $table => $columns) {
            try {
                $rows = DB::table($table)->get();
            } catch (\Throwable $e) {
                continue; // Table might not exist
            }

            foreach ($rows as $row) {
                $updates = [];
                foreach ($columns as $col) {
                    $value = $row->$col ?? null;
                    if (!$value || !is_string($value)) continue;

                    // Only update paths that start with old "images/" prefix
                    // and haven't been updated yet
                    if (str_starts_with($value, 'images/') && !str_starts_with($value, 'images/tenants/')) {
                        // e.g. "images/store/file.png" -> "images/tenants/{id}/store/file.png"
                        $updates[$col] = str_replace('images/', $prefix . '/', $value);
                    }
                }

                if ($updates) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                    $this->line("  Updated {$table} #{$row->id}: " . json_encode($updates));
                }
            }
        }
    }

    private function copyDefaults(): void
    {
        $this->info('[Defaults] Ensuring default images exist in each tenant folder...');

        $defaults = [
            'no-image.png',
            'no_avatar.png',
            'logo-default.png',
        ];

        $base = public_path('images');

        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            $tenantBase = public_path("images/tenants/{$tenant->id}");

            foreach (['products', 'brands', 'banners'] as $sub) {
                $dir = $tenantBase . '/' . $sub;
                File::ensureDirectoryExists($dir);
                $src = $base . '/no-image.png';
                if (is_file($src) && !is_file($dir . '/no-image.png')) {
                    File::copy($src, $dir . '/no-image.png');
                }
            }

            $avatarDir = $tenantBase . '/avatar';
            File::ensureDirectoryExists($avatarDir);
            $src = $base . '/no_avatar.png';
            if (is_file($src) && !is_file($avatarDir . '/no_avatar.png')) {
                File::copy($src, $avatarDir . '/no_avatar.png');
            }
        }
    }

    private function copyDirectory(string $src, string $dst): void
    {
        File::ensureDirectoryExists($dst);

        $files = File::files($src);
        foreach ($files as $file) {
            $destFile = $dst . '/' . $file->getFilename();
            if (!is_file($destFile)) {
                File::copy($file->getPathname(), $destFile);
            }
        }

        // Recurse into subdirectories
        $dirs = File::directories($src);
        foreach ($dirs as $dir) {
            $this->copyDirectory($dir, $dst . '/' . basename($dir));
        }
    }
}
