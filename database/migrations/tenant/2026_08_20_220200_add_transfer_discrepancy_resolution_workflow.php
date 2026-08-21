<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transfer_discrepancies')) {
            Schema::table('transfer_discrepancies', function (Blueprint $table) {
                if (! Schema::hasColumn('transfer_discrepancies', 'resolution_code')) {
                    $table->string('resolution_code', 40)->nullable()->index()->after('resolution_status');
                }
                if (! Schema::hasColumn('transfer_discrepancies', 'resolution_reference')) {
                    $table->string('resolution_reference', 120)->nullable()->after('resolution_code');
                }
                if (! Schema::hasColumn('transfer_discrepancies', 'resolution_notes')) {
                    $table->text('resolution_notes')->nullable()->after('resolution_reference');
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'transfer_issue_manage')->value('id');
            if (! $permissionId) {
                $payload = [
                    'name' => 'transfer_issue_manage',
                    'label' => 'Resolver incidencias de transferencias',
                    'description' => 'Permite revisar y cerrar faltantes o productos defectuosos reportados durante recepciones de stock.',
                ];
                if (Schema::hasColumn('permissions', 'created_at')) {
                    $payload['created_at'] = now();
                }
                if (Schema::hasColumn('permissions', 'updated_at')) {
                    $payload['updated_at'] = now();
                }
                $permissionId = DB::table('permissions')->insertGetId($payload);
            }

            // Backward-compatible rollout: roles that can edit transfers initially
            // receive incidence-management permission. Administrators can separate it later.
            if (Schema::hasTable('permission_role')) {
                $editPermissionId = DB::table('permissions')->where('name', 'transfer_edit')->value('id');
                if ($editPermissionId) {
                    foreach (DB::table('permission_role')->where('permission_id', $editPermissionId)->pluck('role_id') as $roleId) {
                        DB::table('permission_role')->updateOrInsert(
                            ['permission_id' => $permissionId, 'role_id' => $roleId],
                            []
                        );
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_role') && Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('name', 'transfer_issue_manage')->value('id');
            if ($permissionId) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        if (Schema::hasTable('transfer_discrepancies')) {
            Schema::table('transfer_discrepancies', function (Blueprint $table) {
                foreach (['resolution_notes', 'resolution_reference', 'resolution_code'] as $column) {
                    if (Schema::hasColumn('transfer_discrepancies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
