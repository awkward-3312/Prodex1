<?php

namespace Tests\Unit;

use Illuminate\Database\Migrations\Migration;
use Tests\TestCase;

class UserPhoneNullableMigrationTest extends TestCase
{
    public function test_user_access_accepts_missing_phone_and_upgrade_runs_nullable_migration(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Organization/UserAccessController.php'));
        $upgrade = file_get_contents(app_path('Console/Commands/ProdexTenantUpgrade.php'));
        $migrationPath = database_path('migrations/tenant/2026_08_22_230000_make_users_phone_nullable.php');
        $migrationSource = file_get_contents($migrationPath);

        $this->assertStringContainsString("'phone' => ['nullable', 'string', 'max:80']", $controller);
        $this->assertStringContainsString("'phone' => \$validated['phone'] ?? null", $controller);
        $this->assertStringContainsString('2026_08_22_230000_make_users_phone_nullable.php', $upgrade);
        $this->assertStringContainsString('MODIFY `phone` VARCHAR(192) NULL', $migrationSource);

        $migration = require $migrationPath;
        $this->assertInstanceOf(Migration::class, $migration);
    }
}
