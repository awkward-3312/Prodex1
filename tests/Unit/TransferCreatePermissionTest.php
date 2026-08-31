<?php

namespace Tests\Unit;

use App\Http\Controllers\FinalTransferController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Hardening — modelo de permisos de creación / aprobación de traslados.
 *
 * Decisión de producto:
 *   - transfer_add SIN transfer_edit  -> crea el traslado como "pending",
 *     SIN auto-aprobar ni auto-despachar. (No es 403 crear.)
 *   - transfer_add CON transfer_edit  -> flujo rápido crear -> aprobar ->
 *     despachar en una operación.
 *   - Aprobar/despachar un pendiente sigue requiriendo transfer_edit (workflow).
 *
 * Este test cubre:
 *   1) contrato: store() envuelve approve()+dispatch() en el gate;
 *   2) comportamiento del gate creatorMayAutoDispatch() con permisos reales;
 *   3) contrato del workflow: can_approve exige transfer_edit
 *      (=> el creador sin transfer_edit no puede autoaprobar después).
 */
class TransferCreatePermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('username')->nullable();
            $t->integer('role_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('roles', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->timestamps();
        });
        Schema::create('permissions', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('role_user', function ($t) {
            $t->integer('role_id');
            $t->integer('user_id');
        });
        Schema::create('permission_role', function ($t) {
            $t->integer('permission_id');
            $t->integer('role_id');
        });
    }

    private function userWithPermissions(array $names): User
    {
        $role = Role::create(['name' => 'r'.uniqid()]);
        foreach ($names as $n) {
            $perm = Permission::firstOrCreate(['name' => $n]);
            $role->permissions()->attach($perm->id);
        }
        $user = User::create(['username' => 'u'.uniqid(), 'role_id' => 99]); // not owner (role_id 1)
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    private function mayAutoDispatch(User $user): bool
    {
        $m = new ReflectionMethod(FinalTransferController::class, 'creatorMayAutoDispatch');
        $m->setAccessible(true);

        return (bool) $m->invoke(app(FinalTransferController::class), $user);
    }

    // ---------- contrato ----------

    public function test_store_wraps_auto_dispatch_in_a_permission_gate(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/FinalTransferController.php');

        $this->assertStringContainsString('$mayAutoDispatch = $this->creatorMayAutoDispatch($user);', $src);
        $this->assertStringContainsString('if ($mayAutoDispatch) {', $src);
        $this->assertStringContainsString('$transfer = $workflow->approve($transfer, $user);', $src);
        $this->assertStringContainsString('$transfer = $workflow->dispatch($transfer, $user, $batchPlan);', $src);
        $this->assertStringContainsString("return \$user && \$user->hasPermissionName('transfer_edit');", $src);
        // approve()/dispatch() must NOT be called unconditionally.
        $this->assertStringNotContainsString(
            "->firstOrFail();\n\n            \$workflow = app(TransferWorkflowService::class);",
            $src
        );
        // Creating without transfer_edit is never turned into a 403.
        $this->assertStringNotContainsString("abort(403", $src);
    }

    public function test_workflow_can_approve_requires_transfer_edit(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/TransferWorkflowController.php');
        $this->assertStringContainsString("'can_approve' => \$pending && \$canOperateSource && \$user->hasPermissionName('transfer_edit')", $controller);
        $this->assertStringContainsString("'can_dispatch' => \$transfer->isApproved() && \$canOperateSource", $controller);
        $this->assertStringContainsString("\$user->hasPermissionName('transfer_edit')", $controller);
    }

    // ---------- comportamiento del gate ----------

    public function test_transfer_add_only_may_not_auto_dispatch(): void
    {
        $user = $this->userWithPermissions(['transfer_add']);
        $this->assertFalse($this->mayAutoDispatch($user));
    }

    public function test_transfer_add_plus_edit_may_auto_dispatch(): void
    {
        $user = $this->userWithPermissions(['transfer_add', 'transfer_edit']);
        $this->assertTrue($this->mayAutoDispatch($user));
    }

    public function test_transfer_receive_alone_does_not_grant_auto_dispatch(): void
    {
        $user = $this->userWithPermissions(['transfer_add', 'transfer_receive']);
        $this->assertFalse($this->mayAutoDispatch($user));
    }
}
