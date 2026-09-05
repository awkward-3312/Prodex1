<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Super-Admin-only management of a tenant's /login illustration logo.
 *
 * This is deliberately separate from the tenant's own (per-tenant-database)
 * Settings model: the tenant's own admin has no route/UI to touch this — it
 * lives on the CENTRAL tenant record (see Tenant::loginLogoUrl()), matching
 * how country_code/locale/owner_phone are already persisted there.
 *
 * Route protection mirrors every other tenant-management action:
 * central.permission:tenants (see routes/central.php).
 */
class TenantBrandingController extends Controller
{
    /** Matches GeneralSettingsController's existing logo upload limit (2 MB). */
    private const MAX_KB = 2048;

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $request->validate([
            'login_logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:' . self::MAX_KB],
        ]);

        $dir = public_path('images/tenants/' . $tenant->id . '/branding');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $this->deleteExistingFile($tenant);

        $file = $request->file('login_logo');
        // Never trust the original filename — derive our own from a fixed
        // prefix + timestamp + the validated extension.
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = 'login_logo_' . time() . '.' . $extension;
        $file->move($dir, $filename);

        $tenant->update([
            'login_logo_path' => 'images/tenants/' . $tenant->id . '/branding/' . $filename,
        ]);

        return back()->with('success', __('super.tenants.login_logo_updated'));
    }

    public function destroy(Tenant $tenant): RedirectResponse
    {
        $this->deleteExistingFile($tenant);
        $tenant->update(['login_logo_path' => null]);

        return back()->with('success', __('super.tenants.login_logo_removed'));
    }

    private function deleteExistingFile(Tenant $tenant): void
    {
        $existing = $tenant->login_logo_path;
        if (! $existing) {
            return;
        }

        // Defense in depth: the path is only ever written by this
        // controller as 'images/tenants/{id}/branding/{file}', but never
        // trust it blindly — resolve and confirm it stays inside this
        // tenant's own branding directory before unlinking anything.
        $expectedDir = realpath(public_path('images/tenants/' . $tenant->id . '/branding')) ?: '';
        $target = realpath(public_path($existing));

        if ($expectedDir !== '' && $target !== false && str_starts_with($target, $expectedDir) && is_file($target)) {
            @unlink($target);
        }
    }
}
