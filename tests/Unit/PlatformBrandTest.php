<?php

namespace Tests\Unit;

use App\Models\Central\GeneralSetting;
use App\Support\PlatformBrand;
use App\Tenant;
use Tests\TestCase;

class PlatformBrandTest extends TestCase
{
    public function test_login_fallback_uses_official_logo(): void
    {
        $this->assertSame(PlatformBrand::url(), (new Tenant())->loginLogoUrl());
    }

    public function test_tenant_login_logo_has_priority_over_platform_defaults(): void
    {
        $general = GeneralSetting::instance();
        $general->tenant_logo_path = 'images/tenant-custom-default.png';
        $general->save();
        $tenant = new Tenant(['login_logo_path' => 'images/tenant-custom-login.png']);
        $this->assertSame(global_asset('images/tenant-custom-login.png'), $tenant->loginLogoUrl());
        $tenant->login_logo_path = null;
        $this->assertSame(global_asset('images/tenant-custom-default.png'), $tenant->loginLogoUrl());
    }

    public function test_known_legacy_bytes_resolve_to_brand_but_custom_images_are_preserved(): void
    {
        $this->assertSame(PlatformBrand::path(), PlatformBrand::resolvePath('images/tenant-default/settings/logo-default.png'));
        $this->assertSame(PlatformBrand::path(), PlatformBrand::resolvePath(null));
        $this->assertSame(PlatformBrand::path('icon'), PlatformBrand::resolvePath(null, 'icon'));
        $this->assertSame(PlatformBrand::path('icon'), PlatformBrand::resolvePath('images/tenant-default/settings/favicon.ico', 'icon'));

        // Different bytes, even under the legacy filename, represent a tenant customization.
        $directory = 'images/tenants/brand-regression/settings';
        mkdir(public_path($directory), 0755, true);
        $path = $directory.'/logo-default.png';
        copy(public_path(PlatformBrand::path('black')), public_path($path));
        try {
            $this->assertSame($path, PlatformBrand::resolvePath($path));
        } finally {
            unlink(public_path($path));
            rmdir(public_path($directory));
            rmdir(public_path('images/tenants/brand-regression'));
        }
    }
}
