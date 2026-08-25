<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AccessControlSecurityArchitectureTest extends TestCase
{
    public function test_owner_escalation_and_permission_catalog_are_guarded(): void
    {
        $root = dirname(__DIR__, 2);
        $permissions = file_get_contents($root.'/app/Http/Controllers/PermissionsController.php');
        $user = file_get_contents($root.'/app/Models/User.php');
        $pivot = file_get_contents($root.'/app/Models/role_user.php');
        $catalog = file_get_contents($root.'/app/Services/PermissionCatalogService.php');
        $middleware = file_get_contents($root.'/app/Http/Middleware/ProtectOwnerPrivilegeEscalation.php');
        $kernel = file_get_contents($root.'/app/Http/Kernel.php');

        $this->assertStringNotContainsString('Permission::firstOrCreate', $permissions);
        $this->assertStringContainsString('OWNER_ROLE_ID', $permissions);
        $this->assertStringContainsString('normalizeSelection', $permissions);
        $this->assertStringContainsString('role_id === 1', $user);
        $this->assertStringContainsString('role_id === 1', $pivot);
        $this->assertStringContainsString('Solo el propietario puede asignar el rol propietario', $middleware);
        $this->assertStringContainsString('ProtectOwnerPrivilegeEscalation::class', $kernel);
        $this->assertStringContainsString('Se enviaron permisos no reconocidos por PRODEX', $catalog);
        $this->assertStringContainsString('transfer_receive', $catalog);
        $this->assertStringContainsString('transfer_view', $catalog);
    }
}
