<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AccessControlSecurityArchitectureTest extends TestCase
{
    public function test_owner_escalation_and_permission_catalog_are_guarded(): void
    {
        $root = dirname(__DIR__, 2);
        $permissions = file_get_contents($root.'/app/Http/Controllers/PermissionsController.php');
        $catalog = file_get_contents($root.'/app/Services/PermissionCatalogService.php');
        $middleware = file_get_contents($root.'/app/Http/Middleware/ProtectOwnerPrivilegeEscalation.php');
        $kernel = file_get_contents($root.'/app/Http/Kernel.php');
        $this->assertStringNotContainsString('Permission::firstOrCreate', $permissions);
        $this->assertStringContainsString('OWNER_ROLE_ID', $permissions);
        $this->assertStringContainsString('normalizeSelection', $permissions);
        $this->assertStringContainsString('Solo el propietario puede asignar el rol propietario', $middleware);
        $this->assertStringContainsString('UserController@store', $middleware);
        $this->assertStringContainsString('UserController@update', $middleware);
        $this->assertStringContainsString('Organization\\\\UserAccessController@store', $middleware);
        $this->assertStringContainsString('Organization\\\\EmployeeAccessController@create', $middleware);
        $this->assertStringContainsString('ProtectOwnerPrivilegeEscalation::class', $kernel);
        $this->assertStringContainsString('Se enviaron permisos no reconocidos por PRODEX', $catalog);
        $this->assertStringContainsString('transfer_receive', $catalog);
        $this->assertStringContainsString('transfer_view', $catalog);
    }

    public function test_permission_editor_uses_human_language_instead_of_technical_codes(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = file_get_contents($root.'/app/Services/PermissionCatalogService.php');
        $editor = file_get_contents($root.'/resources/src/views/app/pages/settings/permissions/RoleEditor.vue');
        $compact = str_replace(' ', '', $catalog);

        $this->assertStringContainsString("'description'=>\$this->descriptionFor", $compact);
        $this->assertStringContainsString("'dependency_labels'", $catalog);
        $this->assertStringContainsString("'payment_sales_add'=>'Registrarpagosdeventas'", $compact);
        $this->assertStringContainsString("'Pos_view'=>'Usarpuntodeventa(POS)'", $compact);
        $this->assertStringContainsString("'manager'=>['label'=>'Accesocompleto'", $compact);
        $this->assertStringContainsString("'bookings'=>'reservas'", $compact);
        $this->assertStringContainsString("'commissions'=>'comisiones'", $compact);
        $this->assertStringContainsString("'tax'=>'impuestos'", $compact);
        $this->assertStringContainsString("'discount'=>'descuentos'", $compact);
        $this->assertStringContainsString("'cashdrawers'=>'cajasdeefectivo'", $compact);
        $this->assertStringContainsString('Configuración rápida', $editor);
        $this->assertStringContainsString('Acceso completo', $editor);
        $this->assertStringContainsString('También activará:', $editor);
        $this->assertStringContainsString('permission.description', $editor);
        $this->assertStringNotContainsString('<small>{{ permission.name }}</small>', $editor);
    }
}
