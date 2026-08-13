<?php

/**
 * Get the base image upload path for the current context.
 *
 * Tenant context:  images/tenants/{tenant_id}/{subfolder}
 * Super admin:     images/super/{subfolder}
 */
if (!function_exists('upload_path')) {
    function upload_path(string $subfolder = ''): string
    {
        if (function_exists('tenancy') && tenancy()->initialized && tenancy()->tenant) {
            $base = 'images/tenants/' . tenancy()->tenant->id;
        } else {
            $base = 'images/super';
        }

        return $subfolder ? $base . '/' . trim($subfolder, '/') : $base;
    }
}

/**
 * Get the absolute public path for uploads.
 */
if (!function_exists('upload_public_path')) {
    function upload_public_path(string $subfolder = ''): string
    {
        return public_path(upload_path($subfolder));
    }
}

/**
 * Resolve the URL of a tenant's PWA icon (192 or 512).
 *
 * Tenants get their own icons under images/tenants/{id}/pwa/ generated at
 * provisioning from the super admin's Tenant-Settings logo. When a tenant has
 * no custom icon yet (older tenants, or no logo configured) we fall back to the
 * shared defaults in /pwa_images so the manifest is always valid.
 */
if (!function_exists('pwa_icon_url')) {
    function pwa_icon_url(int $size): string
    {
        $relative = upload_path('pwa') . '/pwa-icon-' . $size . '.png';
        $absolute = public_path($relative);

        if (is_file($absolute)) {
            return global_asset($relative) . '?v=' . filemtime($absolute);
        }

        return '/pwa_images/pwa-icon-' . $size . '.png';
    }
}
