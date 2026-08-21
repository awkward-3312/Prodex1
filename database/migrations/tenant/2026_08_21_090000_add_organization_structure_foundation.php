<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('code', 40)->nullable()->index();
                $table->string('name', 192);
                $table->string('type', 40)->default('branch')->index();
                $table->string('phone', 80)->nullable();
                $table->string('email', 192)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('address', 255)->nullable();
                $table->integer('manager_employee_id')->nullable()->index();
                $table->integer('default_warehouse_id')->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps(6);
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('warehouses') && ! Schema::hasColumn('warehouses', 'branch_id')) {
            Schema::table('warehouses', function (Blueprint $table) {
                $table->integer('branch_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'branch_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->integer('branch_id')->nullable()->after('company_id')->index();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('employee_id')->nullable()->after('id')->unique();
            });
        }

        if (Schema::hasTable('designations')) {
            Schema::table('designations', function (Blueprint $table) {
                if (! Schema::hasColumn('designations', 'code')) {
                    $table->string('code', 60)->nullable()->after('designation')->index();
                }
                if (! Schema::hasColumn('designations', 'description')) {
                    $table->string('description', 500)->nullable()->after('code');
                }
                if (! Schema::hasColumn('designations', 'is_system_default')) {
                    $table->boolean('is_system_default')->default(false)->after('description')->index();
                }
                if (! Schema::hasColumn('designations', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('is_system_default')->index();
                }
                if (! Schema::hasColumn('designations', 'suggested_role_key')) {
                    $table->string('suggested_role_key', 80)->nullable()->after('is_active')->index();
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            $now = now();
            $permissions = [
                ['name' => 'branches_view', 'label' => 'Ver sucursales', 'description' => 'Permite consultar sucursales y sus bodegas.'],
                ['name' => 'branches_add', 'label' => 'Crear sucursales', 'description' => 'Permite crear nuevas sucursales.'],
                ['name' => 'branches_edit', 'label' => 'Editar sucursales', 'description' => 'Permite modificar sucursales y su bodega predeterminada.'],
                ['name' => 'branches_delete', 'label' => 'Desactivar sucursales', 'description' => 'Permite desactivar sucursales sin borrar su historial.'],
            ];

            foreach ($permissions as $permission) {
                if (! DB::table('permissions')->where('name', $permission['name'])->exists()) {
                    DB::table('permissions')->insert($permission + ['created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                try { $table->dropUnique(['employee_id']); } catch (\Throwable $e) {}
                $table->dropColumn('employee_id');
            });
        }

        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'branch_id')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('branch_id'));
        }

        if (Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'branch_id')) {
            Schema::table('warehouses', fn (Blueprint $table) => $table->dropColumn('branch_id'));
        }

        if (Schema::hasTable('designations')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('designations', 'suggested_role_key') ? 'suggested_role_key' : null,
                Schema::hasColumn('designations', 'is_active') ? 'is_active' : null,
                Schema::hasColumn('designations', 'is_system_default') ? 'is_system_default' : null,
                Schema::hasColumn('designations', 'description') ? 'description' : null,
                Schema::hasColumn('designations', 'code') ? 'code' : null,
            ]));
            if ($columns) {
                Schema::table('designations', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }

        Schema::dropIfExists('branches');
    }
};
