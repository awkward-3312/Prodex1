<?php

namespace App\Support;

final class PlatformBrand
{
    public static function path(string $variant = 'logo'): string
    {
        return config('brand.assets.'.$variant, config('brand.assets.logo'));
    }

    public static function url(string $variant = 'logo'): string
    {
        return global_asset(self::path($variant));
    }

    /** Preserve uploaded tenant images; only replace known bundled legacy bytes. */
    public static function resolvePath(?string $path, string $variant = 'logo'): string
    {
        // Older tenant documents used the shared images directory before uploads
        // became tenant-scoped. Preserve those existing custom files too.
        if ($path && ! is_file(public_path($path)) && str_contains($path, '/settings/')) {
            $shared = 'images/'.basename($path);
            if (is_file(public_path($shared)) && ! self::isLegacy($shared)) {
                return $shared;
            }
        }
        if ($path && is_file(public_path($path)) && ! self::isLegacy($path)) {
            return $path;
        }

        return self::path($variant);
    }

    public static function isLegacy(string $path): bool
    {
        $file = public_path($path);

        return is_file($file) && in_array(hash_file('sha256', $file), config('brand-legacy.hashes', []), true);
    }

    public static function tenantUrl(?string $filename, string $variant = 'logo'): string
    {
        $path = $filename ? upload_path('settings').'/'.$filename : null;

        return global_asset(self::resolvePath($path, $variant));
    }
}
