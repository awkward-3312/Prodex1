<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registra los permisos de los dos reportes de inventario nuevos
 * (Kardex valorizado, Rotación de inventario) en TODOS los tenants existentes,
 * de forma idempotente, y los adjunta al rol Owner/Admin.
 *
 * Fresh installs: los cubre {@see \Database\Seeders\ReportInsightPermissionsSeeder}
 * porque en una migración limpia la tabla `permissions` aún está vacía cuando
 * corre esta migración (se sale abajo sin hacer nada).
 */
return new class extends Migration
{
    private array $permissions = [
        'valued_kardex_report' => 'Ver el Kardex valorizado',
        'inventory_turnover_report' => 'Ver el reporte de Rotación de inventario',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        // Bases de datos de tenant nuevas: se migran ANTES de sus seeders base.
        // Diferir estos registros al seeder para no consumir IDs fijos.
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

            if (! Schema::hasTable('permission_role')) {
                continue;
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
