<?php

namespace Tests\Unit;

use App\Http\Controllers\Central\Super\TenantBrandingController;
use App\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TenantBrandingControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['tenant-up', 'tenant-replace', 'tenant-del', 'tenant-bad'] as $id) {
            File::deleteDirectory(public_path('images/tenants/' . $id));
        }

        parent::tearDown();
    }

    public function test_super_admin_can_upload_a_login_logo_for_a_tenant(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-up']);
        $controller = new TenantBrandingController();

        $request = Request::create('/super/tenants/tenant-up/login-logo', 'POST');
        $request->files->set('login_logo', UploadedFile::fake()->image('logo.png', 100, 100));

        $controller->update($request, $tenant);

        $tenant->refresh();
        $this->assertTrue($tenant->hasCustomLoginLogo());
        $this->assertStringStartsWith('images/tenants/tenant-up/branding/login_logo_', $tenant->login_logo_path);
        $this->assertFileExists(public_path($tenant->login_logo_path));
    }

    public function test_uploading_a_new_logo_replaces_and_deletes_the_previous_file(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-replace']);
        $controller = new TenantBrandingController();
        $dir = public_path('images/tenants/tenant-replace/branding');

        $first = Request::create('/x', 'POST');
        $first->files->set('login_logo', UploadedFile::fake()->image('one.png'));
        $controller->update($first, $tenant);
        $tenant->refresh();
        $this->assertFileExists(public_path($tenant->login_logo_path));

        $second = Request::create('/x', 'POST');
        $second->files->set('login_logo', UploadedFile::fake()->image('two.png'));
        $controller->update($second, $tenant);
        $tenant->refresh();

        // The replace must never leave more than the current logo behind —
        // whether or not the two timestamped filenames happened to collide.
        $this->assertFileExists(public_path($tenant->login_logo_path));
        $this->assertCount(1, File::files($dir));
    }

    public function test_super_admin_can_remove_a_tenants_login_logo(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-del']);
        $controller = new TenantBrandingController();

        $request = Request::create('/x', 'POST');
        $request->files->set('login_logo', UploadedFile::fake()->image('logo.png'));
        $controller->update($request, $tenant);
        $tenant->refresh();
        $storedPath = public_path($tenant->login_logo_path);
        $this->assertFileExists($storedPath);

        $controller->destroy($tenant);
        $tenant->refresh();

        $this->assertFalse($tenant->hasCustomLoginLogo());
        $this->assertNull($tenant->login_logo_path);
        $this->assertFileDoesNotExist($storedPath);
    }

    public function test_upload_is_rejected_for_a_disallowed_file_type(): void
    {
        $tenant = Tenant::create(['id' => 'tenant-bad']);
        $controller = new TenantBrandingController();

        $request = Request::create('/x', 'POST');
        $request->files->set('login_logo', UploadedFile::fake()->create('malware.php', 10));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->update($request, $tenant);
    }
}
