<?php

namespace Tests\Unit;

use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresión: aprovisionar un tenant nuevo fallaba con
 * "Duplicate entry '1' for key 'permissions.PRIMARY'".
 *
 * Causa: las migraciones de tenant (transfer_receive, transfer_issue_manage, branches_*) insertan permisos con
 * ids autoincrementales 1..6 ANTES de que corra PermissionsSeeder, que insertaba 244 ids fijos sin comprobar nada.
 * Los seeders deben funcionar con tenant nuevo (con filas previas de migraciones) y con tenants ya sembrados,
 * sin duplicar permisos ni cambiar nombres, y ser idempotentes.
 */
class PermissionsSeederProvisioningTest extends TestCase
{
    private const CATALOG_SIZE = 244;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('roles', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
        });
        Schema::create('permissions', function ($table) {
            $table->integer('id', true);
            $table->string('name', 192);
            $table->string('label', 192)->nullable();
            $table->text('description')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
        Schema::create('permission_role', function ($table) {
            $table->integer('id', true);
            $table->integer('permission_id')->index();
            $table->integer('role_id')->index();
            // Igual que producción: FK RESTRICT hacia permissions.
            $table->foreign('permission_id')->references('id')->on('permissions')->onUpdate('restrict')->onDelete('restrict');
        });
        DB::table('roles')->insert(['id' => 1, 'name' => 'owner']);
    }

    /** Filas que hoy insertan las migraciones de tenant antes de sembrar (ids 1..6, nombres fuera del catálogo base). */
    private function migrationInsertedPermissions(): void
    {
        $now = now();
        foreach (['transfer_receive', 'transfer_issue_manage', 'branches_view', 'branches_add', 'branches_edit', 'branches_delete'] as $name) {
            DB::table('permissions')->insert([
                'name' => $name,
                'label' => "label {$name}",
                'description' => "desc {$name}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function assertNoDuplicateNames(): void
    {
        $dupes = DB::table('permissions')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->pluck('name');
        $this->assertSame([], $dupes->all(), 'Hay permisos duplicados por nombre');
    }

    public function test_fresh_tenant_with_migration_inserted_permissions_seeds_without_id_collision(): void
    {
        $this->migrationInsertedPermissions();
        $this->assertSame([1, 2, 3, 4, 5, 6], DB::table('permissions')->orderBy('id')->pluck('id')->all());

        (new PermissionsSeeder())->run();

        $this->assertSame(self::CATALOG_SIZE + 6, DB::table('permissions')->count());
        $this->assertNoDuplicateNames();

        // El catálogo conserva sus ids fijos (PermissionRoleSeeder los referencia).
        $this->assertSame('users_view', DB::table('permissions')->where('id', 1)->value('name'));
        $this->assertSame('users_edit', DB::table('permissions')->where('id', 2)->value('name'));
        $this->assertSame('promotion', DB::table('permissions')->where('id', self::CATALOG_SIZE)->value('name'));

        // Los permisos de las migraciones siguen existiendo, con el mismo nombre, etiqueta y descripción.
        foreach (['transfer_receive', 'transfer_issue_manage', 'branches_view', 'branches_add', 'branches_edit', 'branches_delete'] as $name) {
            $row = DB::table('permissions')->where('name', $name)->first();
            $this->assertNotNull($row, "Falta {$name}");
            $this->assertGreaterThan(self::CATALOG_SIZE, $row->id);
            $this->assertSame("label {$name}", $row->label);
            $this->assertSame("desc {$name}", $row->description);
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->migrationInsertedPermissions();
        (new PermissionsSeeder())->run();
        $first = DB::table('permissions')->orderBy('id')->get(['id', 'name'])->all();

        (new PermissionsSeeder())->run();
        (new PermissionsSeeder())->run();

        $this->assertEquals($first, DB::table('permissions')->orderBy('id')->get(['id', 'name'])->all());
    }

    public function test_existing_seeded_tenant_is_left_untouched(): void
    {
        // Tenant antiguo: catálogo sembrado primero y permisos de migraciones después (ids 245+).
        (new PermissionsSeeder())->run();
        $this->migrationInsertedPermissions();
        $before = DB::table('permissions')->orderBy('id')->get(['id', 'name'])->all();
        $this->assertSame(self::CATALOG_SIZE + 6, count($before));

        (new PermissionsSeeder())->run();

        $this->assertEquals($before, DB::table('permissions')->orderBy('id')->get(['id', 'name'])->all());
    }

    public function test_relocated_permission_keeps_its_role_assignments(): void
    {
        $this->migrationInsertedPermissions();
        // Alguien ya asignó transfer_receive (id 1) al rol 1 antes de sembrar.
        DB::table('permission_role')->insert(['permission_id' => 1, 'role_id' => 1]);

        (new PermissionsSeeder())->run();

        $newId = DB::table('permissions')->where('name', 'transfer_receive')->value('id');
        $this->assertNotSame(1, $newId);
        $this->assertSame(
            [$newId],
            DB::table('permission_role')->where('role_id', 1)->pluck('permission_id')->all(),
            'La asignación debe seguir a la fila reubicada, no quedar apuntando al permiso users_view'
        );
    }

    public function test_a_catalog_name_that_already_exists_under_another_id_is_not_duplicated(): void
    {
        DB::table('permissions')->insert(['id' => 500, 'name' => 'users_view', 'label' => 'custom']);

        (new PermissionsSeeder())->run();

        $this->assertNoDuplicateNames();
        $this->assertSame(1, DB::table('permissions')->where('name', 'users_view')->count());
        $this->assertSame(500, (int) DB::table('permissions')->where('name', 'users_view')->value('id'));
        $this->assertSame('custom', DB::table('permissions')->where('id', 500)->value('label'));
    }

    public function test_permission_role_seeder_grants_owner_all_catalog_permissions_and_is_idempotent(): void
    {
        $this->migrationInsertedPermissions();
        (new PermissionsSeeder())->run();

        (new PermissionRoleSeeder())->run();
        $this->assertSame(self::CATALOG_SIZE, DB::table('permission_role')->where('role_id', 1)->count());

        (new PermissionRoleSeeder())->run();
        $this->assertSame(self::CATALOG_SIZE, DB::table('permission_role')->where('role_id', 1)->count());
        $this->assertSame(
            self::CATALOG_SIZE,
            DB::table('permission_role')->where('role_id', 1)->distinct()->count('permission_id'),
            'No debe haber asignaciones (permiso, rol) repetidas'
        );
    }

    public function test_permission_role_seeder_tolerates_rows_that_use_its_fixed_ids(): void
    {
        (new PermissionsSeeder())->run();
        DB::table('permission_role')->insert(['id' => 5, 'permission_id' => 100, 'role_id' => 1]);

        (new PermissionRoleSeeder())->run();

        $this->assertSame(self::CATALOG_SIZE, DB::table('permission_role')->where('role_id', 1)->distinct()->count('permission_id'));
    }
}
