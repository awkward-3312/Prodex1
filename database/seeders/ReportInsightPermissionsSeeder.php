<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos de los reportes de inventario avanzados (Kardex valorizado y
 * Rotación de inventario). Idéntico patrón a OperationalAssignmentPermissionsSeeder:
 * idempotente y adjunta al rol Owner/Admin. Se ejecuta para TODOS los tenants
 * (fresh installs); los tenants existentes los reciben vía la migración
 * 2026_09_07_100000_seed_valued_kardex_and_turnover_report_permissions.
 */
class ReportInsightPermissionsSeeder extends Seeder
{
    private array $permissions = [
        'valued_kardex_report' => 'Ver el Kardex valorizado',
        'inventory_turnover_report' => 'Ver el reporte de Rotación de inventario',
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
