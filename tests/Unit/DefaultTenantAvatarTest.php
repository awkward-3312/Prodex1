<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * New tenant users without an uploaded avatar must be randomly assigned one
 * of 4 illustrated defaults (persisted at creation, not chosen per-render),
 * while a shared default file must never be deleted just because a user
 * later uploads their own photo (all 4 + the legacy no_avatar.png are
 * "protected" filenames for the unlink guard).
 */
class DefaultTenantAvatarTest extends TestCase
{
    public function test_exactly_four_default_avatar_files_exist_on_disk(): void
    {
        $defaults = default_tenant_avatar_filenames();

        $this->assertCount(4, $defaults);
        $this->assertSame([
            'default_avatar_1.png', 'default_avatar_2.png', 'default_avatar_3.png', 'default_avatar_4.png',
        ], $defaults);

        foreach ($defaults as $filename) {
            $this->assertFileExists(public_path('images/avatar/'.$filename));
        }
    }

    public function test_random_default_always_picks_from_the_allowed_list(): void
    {
        $defaults = default_tenant_avatar_filenames();

        for ($i = 0; $i < 50; $i++) {
            $this->assertContains(random_default_tenant_avatar_filename(), $defaults);
        }
    }

    public function test_random_default_does_not_always_return_the_same_image(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[random_default_tenant_avatar_filename()] = true;
        }

        // With 200 draws from 4 options, seeing only 1 distinct value would be
        // a ~1-in-4^199 fluke — for all practical purposes this proves the
        // choice is actually random, not a fixed pick.
        $this->assertGreaterThan(1, count($seen));
    }

    public function test_legacy_placeholder_and_all_four_defaults_are_protected_from_deletion(): void
    {
        $this->assertTrue(is_default_tenant_avatar_filename('no_avatar.png'));
        $this->assertTrue(is_default_tenant_avatar_filename(null));
        $this->assertTrue(is_default_tenant_avatar_filename(''));

        foreach (default_tenant_avatar_filenames() as $filename) {
            $this->assertTrue(is_default_tenant_avatar_filename($filename));
        }
    }

    public function test_a_real_uploaded_avatar_filename_is_not_protected(): void
    {
        $this->assertFalse(is_default_tenant_avatar_filename('87654321photo.jpg'));
    }

    // ---- structural: the 3 real tenant-user creation call sites actually use it ----

    public function test_user_controller_store_assigns_a_random_default_when_no_file_is_uploaded(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/UserController.php'));

        $this->assertStringContainsString('$filename = random_default_tenant_avatar_filename();', $source);
        $this->assertStringNotContainsString("\$filename = 'no_avatar.png';", $source);
        // Both unlink guards must treat every default as non-deletable.
        $this->assertSame(2, substr_count($source, 'is_default_tenant_avatar_filename($user->avatar)'));
    }

    public function test_organization_user_access_controller_assigns_a_random_default(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Organization/UserAccessController.php'));

        $this->assertStringContainsString('return random_default_tenant_avatar_filename();', $source);
    }

    public function test_employee_access_controller_assigns_a_random_default(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Organization/EmployeeAccessController.php'));

        $this->assertStringContainsString("'avatar' => random_default_tenant_avatar_filename(),", $source);
    }

    public function test_user_access_edit_controller_unlink_guard_protects_defaults(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Organization/UserAccessEditController.php'));

        $this->assertStringContainsString('is_default_tenant_avatar_filename($user->avatar)', $source);
    }

    // ---- central/super admin must never reference the new tenant-only helper ----

    public function test_central_super_admin_code_never_uses_the_tenant_avatar_helper(): void
    {
        $centralFiles = array_merge(
            File::allFiles(app_path('Http/Controllers/Central')),
            File::allFiles(app_path('Models/Central'))
        );

        foreach ($centralFiles as $file) {
            $this->assertStringNotContainsString(
                'random_default_tenant_avatar_filename',
                file_get_contents($file->getPathname()),
                "Central/Super Admin file unexpectedly references the tenant-only avatar helper: {$file->getPathname()}"
            );
        }
    }
}
