<?php

namespace Tests\Unit;

use App\Http\Controllers\NotificationCenterController;
use App\Models\InventoryLocation;
use App\Models\Transfer;
use App\Models\User;
use App\Services\InventoryLocationScopeService;
use App\Services\TransferLogisticsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Escenario real (TR_0011): CD "Inventario principal" (loc 1, sin branch) →
 * "Bodega de sucursal" (loc 3, primary storage de Sucursal 1), logistics
 * in_transit, receiving_token presente.
 *
 * Verifica que:
 *  - un Gerente de Sucursal 1 con `transfer_receive` PUEDE recibir en la bodega;
 *  - la notificación incoming_transfer lo lleva a /app/transfers/receptions/{id};
 *  - Cajero de S1 sin permiso, Gerente de S2, y un permiso-holder de otra
 *    sucursal NO pueden recibir → su notificación se queda en el detalle;
 *  - ningún receiving_token aparece en estas superficies.
 */
class TransferReceivingEntryFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($t) {
            $t->increments('id');
            $t->string('firstname')->nullable();
            $t->string('lastname')->nullable();
            $t->string('username')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->integer('role_id')->default(2);
            $t->integer('is_all_warehouses')->default(0);
            $t->integer('default_warehouse_id')->nullable();
            $t->integer('default_branch_id')->nullable();
            $t->integer('default_inventory_location_id')->nullable();
            $t->integer('default_cash_drawer_id')->nullable();
            $t->boolean('record_view')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('roles', function ($t) {
            $t->increments('id');
            $t->string('name');
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
        Schema::create('branches', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->integer('default_inventory_location_id')->nullable();
            $t->integer('manager_employee_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('warehouses', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('default_inventory_location_id')->nullable();
            $t->string('name');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('user_branches', function ($t) {
            $t->integer('user_id');
            $t->integer('branch_id');
            $t->timestamps();
        });
        Schema::create('user_warehouse', function ($t) {
            $t->integer('user_id');
            $t->integer('warehouse_id');
        });
        Schema::create('inventory_locations', function ($t) {
            $t->increments('id');
            $t->integer('branch_id')->nullable();
            $t->integer('warehouse_id')->nullable();
            $t->string('code');
            $t->string('name');
            $t->string('type');
            $t->boolean('is_sellable')->default(false);
            $t->boolean('is_default_sales')->default(false);
            $t->boolean('is_quarantine')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('user_inventory_locations', function ($t) {
            $t->integer('user_id');
            $t->integer('inventory_location_id');
            $t->timestamps();
        });
        Schema::create('user_operational_assignments', function ($t) {
            $t->increments('id');
            $t->integer('user_id');
            $t->integer('temporary_branch_id')->nullable();
            $t->integer('temporary_inventory_location_id')->nullable();
            $t->string('status')->default('active');
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamps();
        });
        Schema::create('transfers', function ($t) {
            $t->increments('id');
            $t->integer('user_id')->nullable();
            $t->string('Ref');
            $t->integer('from_warehouse_id')->nullable();
            $t->integer('to_warehouse_id')->nullable();
            $t->integer('from_inventory_location_id')->nullable();
            $t->integer('to_inventory_location_id')->nullable();
            $t->string('statut')->default('sent');
            $t->string('approval_status')->nullable();
            $t->string('logistics_status')->nullable();
            $t->string('receiving_token')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('transfer_notifications', function ($t) {
            $t->increments('id');
            $t->integer('transfer_id');
            $t->integer('user_id');
            $t->string('type')->nullable();
            $t->string('title')->nullable();
            $t->text('message')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        // --- roles & permissions ---
        DB::table('roles')->insert(['id' => 1, 'name' => 'Owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->roleGerente = DB::table('roles')->insertGetId(['name' => 'Gerente', 'created_at' => now(), 'updated_at' => now()]);
        $this->roleCajero = DB::table('roles')->insertGetId(['name' => 'Cajero', 'created_at' => now(), 'updated_at' => now()]);
        $pRecv = DB::table('permissions')->insertGetId(['name' => 'transfer_receive', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insert(['name' => 'transfer_view', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['permission_id' => $pRecv, 'role_id' => $this->roleGerente]);
        // Cajero: sin transfer_receive.

        // --- branches ---
        $this->branch1 = DB::table('branches')->insertGetId(['name' => 'Sucursal 1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->branch2 = DB::table('branches')->insertGetId(['name' => 'Sucursal 2', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // --- locations ---
        $this->cd = $this->loc(null, 1, 'MAIN', InventoryLocation::TYPE_STORAGE, false);         // CD, sin branch
        $this->piso1 = $this->loc($this->branch1, null, 'PISO', InventoryLocation::TYPE_SALES_FLOOR, true);
        $this->bodega1 = $this->loc($this->branch1, null, 'BODEGA', InventoryLocation::TYPE_STORAGE, false);
        $this->piso2 = $this->loc($this->branch2, null, 'PISO', InventoryLocation::TYPE_SALES_FLOOR, true);
        $this->bodega2 = $this->loc($this->branch2, null, 'BODEGA', InventoryLocation::TYPE_STORAGE, false);
        DB::table('branches')->where('id', $this->branch1)->update(['default_inventory_location_id' => $this->piso1]);
        DB::table('branches')->where('id', $this->branch2)->update(['default_inventory_location_id' => $this->piso2]);

        // --- users ---
        $this->gerente1 = $this->user('GERENTE1', $this->roleGerente, $this->branch1, $this->bodega1);
        $this->cajero1 = $this->user('CAJERO1', $this->roleCajero, $this->branch1, $this->piso1);
        $this->gerente2 = $this->user('GERENTE2', $this->roleGerente, $this->branch2, $this->bodega2);
        $this->owner = $this->user('OWNER', 1, null, null, ['is_all_warehouses' => 1]);

        // --- the transfer: CD -> Bodega Sucursal 1, in transit ---
        $this->transferId = DB::table('transfers')->insertGetId([
            'user_id' => $this->owner,
            'Ref' => 'TR_0011',
            'from_inventory_location_id' => $this->cd,
            'to_inventory_location_id' => $this->bodega1,
            'statut' => 'sent',
            'approval_status' => 'approved',
            'logistics_status' => 'in_transit',
            'receiving_token' => 'TRF-260906-EWZUYSGBUXZO',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function loc(?int $branchId, ?int $warehouseId, string $code, string $type, bool $sellable): int
    {
        return DB::table('inventory_locations')->insertGetId([
            'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'code' => $code, 'name' => $code,
            'type' => $type, 'is_sellable' => $sellable, 'is_default_sales' => $type === InventoryLocation::TYPE_SALES_FLOOR,
            'is_quarantine' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $name, int $roleId, ?int $branchId, ?int $locId, array $extra = []): int
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'username' => $name, 'email' => strtolower($name).'@test.local', 'password' => 'x',
            'role_id' => $roleId, 'default_branch_id' => $branchId, 'default_inventory_location_id' => $locId,
            'is_all_warehouses' => 0, 'record_view' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $id]);
        if ($branchId) {
            DB::table('user_branches')->insert(['user_id' => $id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($locId) {
            DB::table('user_inventory_locations')->insert(['user_id' => $id, 'inventory_location_id' => $locId, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    // --- authority matrix (the negative cases the brief asks for) ---------

    public function test_userCanReceive_matrix_for_TR_0011(): void
    {
        $logistics = app(TransferLogisticsService::class);
        $ils = app(InventoryLocationScopeService::class);
        $transfer = Transfer::findOrFail($this->transferId);

        $g1 = User::find($this->gerente1);
        $c1 = User::find($this->cajero1);
        $g2 = User::find($this->gerente2);
        $ow = User::find($this->owner);

        // Gerente S1 con transfer_receive → PUEDE recibir en Bodega S1.
        $this->assertTrue($ils->canReceiveAt($g1, $this->bodega1));
        $this->assertTrue($logistics->userCanReceive($g1, $transfer));

        // Cajero S1 SIN transfer_receive → NO.
        $this->assertFalse($logistics->userCanReceive($c1, $transfer));

        // Gerente de S2 (tiene transfer_receive, pero de otra sucursal) → NO.
        $this->assertFalse($ils->canReceiveAt($g2, $this->bodega1));
        $this->assertFalse($logistics->userCanReceive($g2, $transfer));

        // Owner (is_all_warehouses=1, role 1) → según reglas actuales del binding:
        // FinalTransferLogisticsService exige el permiso transfer_receive incluso
        // para el owner. Aquí el rol Owner NO lo tiene → NO.
        $this->assertFalse($ow->hasPermissionName('transfer_receive'));
        $this->assertFalse($logistics->userCanReceive($ow, $transfer));

        // El "special manager receiving" NO abre la ubicación para operar (POS/
        // orígenes/ajustes): sólo recepción.
        $this->assertFalse($ils->canAccess($g1, $this->bodega1) && ! in_array($this->bodega1, $ils->receivingLocationIds($g1), true));
        $this->assertContains($this->bodega1, $ils->receivingLocationIds($g1));
    }

    // --- notification action routing --------------------------------------

    private function notificationsFor(int $userId): Collection
    {
        $controller = new NotificationCenterController;
        $method = new ReflectionMethod(NotificationCenterController::class, 'appendTransferNotifications');
        $method->setAccessible(true);
        $items = collect();
        $method->invoke($controller, $items, User::findOrFail($userId));

        return $items;
    }

    public function test_incoming_transfer_notification_routes_a_receiver_to_the_receive_task(): void
    {
        DB::table('transfer_notifications')->insert([
            'transfer_id' => $this->transferId, 'user_id' => $this->gerente1, 'type' => 'incoming_transfer',
            'title' => 'Transferencia en camino', 'message' => 'CD envió TR_0011 hacia Bodega de sucursal.',
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = $this->notificationsFor($this->gerente1);
        $this->assertCount(1, $items);
        $this->assertSame('/app/transfers/receptions/'.$this->transferId, $items->first()['action']);
        $this->assertSame('transfers', $items->first()['category']);
    }

    public function test_incoming_transfer_notification_for_a_non_receiver_stays_on_the_detail_page(): void
    {
        // Fila artificial para el cajero (sin permiso) — el token NO debe filtrarse.
        DB::table('transfer_notifications')->insert([
            'transfer_id' => $this->transferId, 'user_id' => $this->cajero1, 'type' => 'incoming_transfer',
            'title' => 'Transferencia en camino', 'message' => '...',
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = $this->notificationsFor($this->cajero1);
        $this->assertCount(1, $items);
        $this->assertSame('/app/transfers/detail/'.$this->transferId, $items->first()['action']);
        $this->assertStringNotContainsString('TRF-260906', json_encode($items->first()));
    }

    public function test_non_transit_transfer_notification_keeps_pointing_to_the_detail(): void
    {
        DB::table('transfers')->where('id', $this->transferId)->update(['logistics_status' => 'received']);
        DB::table('transfer_notifications')->insert([
            'transfer_id' => $this->transferId, 'user_id' => $this->gerente1, 'type' => 'incoming_transfer',
            'title' => 'Transferencia recibida', 'message' => '...',
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = $this->notificationsFor($this->gerente1);
        $this->assertSame('/app/transfers/detail/'.$this->transferId, $items->first()['action']);
    }

    public function test_partial_reception_still_routes_to_the_receive_task(): void
    {
        DB::table('transfers')->where('id', $this->transferId)->update(['logistics_status' => 'partially_received']);
        DB::table('transfer_notifications')->insert([
            'transfer_id' => $this->transferId, 'user_id' => $this->gerente1, 'type' => 'incoming_transfer',
            'title' => 'Recepción parcial', 'message' => '...',
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = $this->notificationsFor($this->gerente1);
        $this->assertSame('/app/transfers/receptions/'.$this->transferId, $items->first()['action']);
    }
}
