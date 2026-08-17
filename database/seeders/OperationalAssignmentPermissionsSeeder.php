<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalAssignmentPermissionsSeeder extends Seeder
{
    private array $permissions = [
        'cash_drawers_view' => 'Ver cajas físicas',
        'cash_drawers_add' => 'Crear cajas físicas',
        'cash_drawers_edit' => 'Editar cajas físicas',
        'cash_drawers_delete' => 'Eliminar cajas físicas',
        'user_operational_assignment' => 'Asignar operación habitual de usuarios',
        'user_temporary_assignment' => 'Asignar sustituciones temporales',
        'cash_register_view_all' => 'Ver todas las sesiones de caja',
        'cash_register_override_assignment' => 'Cambiar caja/warehouse asignado en POS',
    ];

    public function run(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->permissions as $name => $label) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'label' => $label,
                    'description' => $label,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            if (! $permissionId || ! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
                continue;
            }

            $ownerRoleIds = DB::table('roles')
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->where('id', 1)
                        ->orWhereIn('name', ['Owner', 'Admin', 'Administrador']);
                })
                ->pluck('id');

            foreach ($ownerRoleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }
}
