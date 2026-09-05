<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The login-logo endpoints must stay Super-Admin-only: guarded by the same
 * central.permission:tenants middleware as every other tenant-management
 * action, so a tenant (which never authenticates on the `central` guard at
 * all) has no route/permission to reach them.
 */
class TenantLoginLogoRoutesTest extends TestCase
{
    public function test_login_logo_update_route_requires_super_admin_tenants_permission(): void
    {
        $route = Route::getRoutes()->getByName('super.tenants.login-logo.update');

        $this->assertNotNull($route);
        $this->assertContains('central.permission:tenants', $route->gatherMiddleware());
    }

    public function test_login_logo_destroy_route_requires_super_admin_tenants_permission(): void
    {
        $route = Route::getRoutes()->getByName('super.tenants.login-logo.destroy');

        $this->assertNotNull($route);
        $this->assertContains('central.permission:tenants', $route->gatherMiddleware());
    }
}
