<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Intervention\Image\ImageManagerStatic as Image;

/**
 * Generates a tenant's PWA icons (192 / 512) from a source logo.
 *
 * Icons are written to {tenantPublicDir}/pwa/pwa-icon-{192,512}.png and served
 * by PwaManifestController. When no usable source exists the caller keeps the
 * shared /pwa_images defaults (pwa_icon_url() falls back automatically), so
 * generation is always best-effort and never required for a valid manifest.
 */
class PwaIconService
{
    public const SIZES = [192, 512];

    /**
     * Generate the PWA icons from $source into {$tenantPublicDir}/pwa/.
     *
     * @return int[] The sizes actually written (empty when the source is missing).
     */
    public static function generate(?string $source, string $tenantPublicDir): array
    {
        if (! $source || ! is_file($source)) {
            return [];
        }

        $pwaDir = rtrim($tenantPublicDir, '/\\') . DIRECTORY_SEPARATOR . 'pwa';
        if (! File::isDirectory($pwaDir)) {
            File::makeDirectory($pwaDir, 0755, true);
        }

        $written = [];
        foreach (self::SIZES as $size) {
            // No upsize() guard: the manifest promises exact 192/512 squares, so
            // small logos are scaled up rather than left undersized (which would
            // fail the browser's PWA installability check for a 512 icon).
            Image::make($source)
                ->fit($size, $size)
                ->encode('png')
                ->save($pwaDir . DIRECTORY_SEPARATOR . 'pwa-icon-' . $size . '.png');
            $written[] = $size;
        }

        return $written;
    }
}
