<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
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

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        // Fresh tenant databases are migrated before their base seeders run.
        // Defer these records until OperationalAssignmentPermissionsSeeder so
        // they cannot consume the fixed IDs 1-244 used by PermissionsSeeder.
        if (! DB::table('permissions')->exists() || ! DB::table('roles')->exists()) {
            return;
        }

        $now = now();
        foreach ($this->permissions as $name => $label) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'label' => $label,
                    'description' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $ownerRoleIds = DB::table('roles')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->where('id', 1)
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

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        if (Schema::hasTable('permission_role') && $ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
