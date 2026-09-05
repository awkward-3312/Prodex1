<?php

namespace Tests\Unit;

use App\Models\Central\GeneralSetting;
use App\Tenant;
use Tests\TestCase;

class TenantLoginLogoTest extends TestCase
{
    public function test_tenant_with_its_own_logo_resolves_to_that_url(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-a']);
        $tenant->update(['login_logo_path' => 'images/tenants/tenant-a/branding/login_logo_123.png']);

        $this->assertTrue($tenant->hasCustomLoginLogo());
        $this->assertStringContainsString('images/tenants/tenant-a/branding/login_logo_123.png', $tenant->loginLogoUrl());
    }

    public function test_tenant_without_its_own_logo_falls_back_to_the_platform_default(): void
    {
        GeneralSetting::instance()->update(['tenant_logo_path' => 'images/tenant-default/settings/custom-default.png']);

        $tenant = Tenant::create(['id' => 'tenant-b']);

        $this->assertFalse($tenant->hasCustomLoginLogo());
        $this->assertStringContainsString('images/tenant-default/settings/custom-default.png', $tenant->loginLogoUrl());
    }

    public function test_tenant_falls_back_to_the_bundled_prodex_default_when_nothing_is_configured_anywhere(): void
    {
        GeneralSetting::instance()->update(['tenant_logo_path' => null]);

        $tenant = Tenant::create(['id' => 'tenant-c']);

        $this->assertFalse($tenant->hasCustomLoginLogo());
        $this->assertStringContainsString('images/tenant-default/settings/logo-default.png', $tenant->loginLogoUrl());
    }
}
