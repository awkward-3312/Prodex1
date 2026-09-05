<?php

namespace Tests\Unit;

use App\Console\Commands\ProdexBackfillDefaultAvatars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * prodex:backfill-default-avatars — assigns a random default avatar to
 * existing TENANT users with no avatar of their own, idempotently.
 *
 * backfillCurrentConnection() is exercised directly (it has no tenancy/IO of
 * its own — the real command wraps it with tenancy()->initialize()/end() per
 * tenant) so this suite doesn't need a real multi-tenant DB switch to prove
 * the actual chunk/bucket/persist logic.
 */
class ProdexBackfillDefaultAvatarsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('avatar')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    private function insertUser(?string $avatar, bool $deleted = false): int
    {
        return (int) DB::table('users')->insertGetId([
            'avatar' => $avatar,
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    public function test_null_avatar_receives_a_default(): void
    {
        $id = $this->insertUser(null);

        ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertContains(DB::table('users')->find($id)->avatar, default_tenant_avatar_filenames());
    }

    public function test_empty_string_avatar_receives_a_default(): void
    {
        $id = $this->insertUser('');

        ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertContains(DB::table('users')->find($id)->avatar, default_tenant_avatar_filenames());
    }

    public function test_no_avatar_png_receives_a_default(): void
    {
        $id = $this->insertUser('no_avatar.png');

        ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertContains(DB::table('users')->find($id)->avatar, default_tenant_avatar_filenames());
    }

    public function test_custom_avatar_is_left_untouched(): void
    {
        $id = $this->insertUser('87654321photo.jpg');

        [$updated, $skipped] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertSame('87654321photo.jpg', DB::table('users')->find($id)->avatar);
        $this->assertSame(0, $updated);
        $this->assertSame(1, $skipped);
    }

    public function test_user_already_on_a_default_avatar_is_left_untouched(): void
    {
        $id = $this->insertUser('default_avatar_2.png');

        [$updated, $skipped] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertSame('default_avatar_2.png', DB::table('users')->find($id)->avatar);
        $this->assertSame(0, $updated);
        $this->assertSame(1, $skipped);
    }

    public function test_running_backfill_twice_does_not_change_the_already_assigned_avatar(): void
    {
        $id = $this->insertUser(null);

        ProdexBackfillDefaultAvatars::backfillCurrentConnection();
        $firstAssigned = DB::table('users')->find($id)->avatar;
        $this->assertContains($firstAssigned, default_tenant_avatar_filenames());

        [$updatedSecondRun, $skippedSecondRun] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertSame($firstAssigned, DB::table('users')->find($id)->avatar);
        $this->assertSame(0, $updatedSecondRun);
        $this->assertSame(1, $skippedSecondRun);
    }

    public function test_soft_deleted_users_are_not_touched(): void
    {
        $id = $this->insertUser(null, deleted: true);

        ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertNull(DB::table('users')->find($id)->avatar);
    }

    public function test_summary_counts_updated_and_skipped_correctly(): void
    {
        $this->insertUser(null);
        $this->insertUser('');
        $this->insertUser('no_avatar.png');
        $this->insertUser('custom_upload.jpg');
        $this->insertUser('default_avatar_1.png');

        [$updated, $skipped] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();

        $this->assertSame(3, $updated);
        $this->assertSame(2, $skipped);
    }

    /**
     * Simulates the per-tenant loop: each tenant's connection is processed
     * independently, so a fresh "tenant" (fresh users table state) must not
     * leak counters or state from a previous one.
     */
    public function test_each_tenant_like_dataset_is_processed_independently(): void
    {
        $this->insertUser(null);
        $this->insertUser('no_avatar.png');
        [$updatedTenantA, $skippedTenantA] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();
        $this->assertSame(2, $updatedTenantA);
        $this->assertSame(0, $skippedTenantA);

        // Simulate switching to a different tenant's own (empty) database.
        DB::table('users')->truncate();
        $this->insertUser('custom.jpg');
        $this->insertUser(null);

        [$updatedTenantB, $skippedTenantB] = ProdexBackfillDefaultAvatars::backfillCurrentConnection();
        $this->assertSame(1, $updatedTenantB);
        $this->assertSame(1, $skippedTenantB);
    }

    public function test_command_never_references_the_central_users_table(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ProdexBackfillDefaultAvatars.php'));

        $this->assertStringNotContainsString('central_users', $source);
        // No import/usage of the CentralUser model (a bare mention in an
        // explanatory doc-comment, as above, is fine — only a real `use`/
        // static-call reference would matter).
        $this->assertStringNotContainsString('Models\\Central\\CentralUser', $source);
        $this->assertStringNotContainsString('CentralUser::', $source);
        $this->assertStringContainsString("DB::table('users')", $source);
        // The central-side loop is only ever App\Tenant (the tenants list),
        // never a central user model.
        $this->assertStringContainsString('use App\\Tenant;', $source);
    }
}
