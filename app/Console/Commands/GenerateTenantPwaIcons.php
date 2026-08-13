<?php

namespace App\Console\Commands;

use App\Models\Central\GeneralSetting;
use App\Services\PwaIconService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Backfills per-tenant PWA icons for tenants provisioned before icon
 * generation existed. Each tenant's icons are generated from its own logo;
 * tenants with no usable logo are left to fall back to the shared /pwa_images
 * defaults via pwa_icon_url().
 */
class GenerateTenantPwaIcons extends Command
{
    protected $signature = 'tenants:pwa-icons
        {tenant? : A single tenant id; omit to process every tenant directory}
        {--force : Regenerate even when pwa-icon-192.png already exists}';

    protected $description = 'Generate per-tenant PWA icons from each tenant\'s logo (shared /pwa_images stays the fallback).';

    public function handle(): int
    {
        $base = public_path('images/tenants');

        if (! File::isDirectory($base)) {
            $this->warn("No tenant images directory at {$base} — nothing to do.");
            return self::SUCCESS;
        }

        $only = $this->argument('tenant');
        $dirs = $only
            ? [$base . DIRECTORY_SEPARATOR . $only]
            : File::directories($base);

        $generated = 0;
        $skipped = 0;

        foreach ($dirs as $dir) {
            $id = basename($dir);

            if (! File::isDirectory($dir)) {
                $this->error("✗ {$id}: tenant directory not found");
                continue;
            }

            if (is_file($dir . DIRECTORY_SEPARATOR . 'pwa' . DIRECTORY_SEPARATOR . 'pwa-icon-192.png')
                && ! $this->option('force')) {
                $this->line("• {$id}: icons already present (use --force to regenerate)");
                $skipped++;
                continue;
            }

            $source = $this->resolveSource($dir);
            if (! $source) {
                $this->line("• {$id}: no logo found — keeping shared default icons");
                $skipped++;
                continue;
            }

            try {
                $written = PwaIconService::generate($source, $dir);
                if ($written) {
                    $this->info("✓ {$id}: generated " . implode('/', $written) . ' from ' . basename($source));
                    $generated++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $this->error("✗ {$id}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done. Generated for {$generated} tenant(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Find the best logo to derive icons from: the tenant's own settings logo
     * first, then the super-admin's configured tenant default logo.
     */
    protected function resolveSource(string $dir): ?string
    {
        foreach (['logo-default.png', 'logo.png'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        try {
            $configured = GeneralSetting::instance()->tenant_logo_path;
            if ($configured && is_file(public_path($configured))) {
                return public_path($configured);
            }
        } catch (\Throwable) {
            // Central settings unavailable — fall through to no source.
        }

        return null;
    }
}
