<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferLogisticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferLogisticsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_only_authorized_user_assigned_to_destination_can_receive(): void
    {
        [$origin, $destination, $other] = $this->warehouses();
        $authorized = $this->userWithPermission($destination, TransferLogisticsService::RECEIVE_PERMISSION);
        $wrongWarehouse = $this->userWithPermission($other, TransferLogisticsService::RECEIVE_PERMISSION);
        $withoutPermission = $this->userAtWarehouse($destination);
        $transfer = $this->transfer($origin, $destination, 5);

        $service = app(TransferLogisticsService::class);

        $this->assertTrue($service->userCanReceive($authorized, $transfer));
        $this->assertFalse($service->userCanReceive($wrongWarehouse, $transfer));
        $this->assertFalse($service->userCanReceive($withoutPermission, $transfer));
    }

    public function test_complete_good_receipt_credits_destination_once_and_completes_transfer(): void
    {
        [$origin, $destination] = $this->warehouses();
        $receiver = $this->userWithPermission($destination, TransferLogisticsService::RECEIVE_PERMISSION);
        $transfer = $this->transfer($origin, $destination, 10);
        $detailId = $this->detail($transfer->id, 10);

        $updated = app(TransferLogisticsService::class)->receive($transfer, $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 10,
            'quantity_defective' => 0,
            'quantity_missing' => 0,
        ]]);

        $this->assertSame('received', $updated->logistics_status);
        $this->assertSame('completed', $updated->statut);
        $this->assertEquals(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', $destination)->value('qte'));
        $this->assertDatabaseCount('transfer_receipts', 1);
        $this->assertDatabaseCount('transfer_receipt_items', 1);

        $this->expectException(ValidationException::class);
        app(TransferLogisticsService::class)->receive($updated->fresh(), $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 10,
        ]]);
    }

    public function test_partial_receipts_accumulate_without_duplicate_stock(): void
    {
        [$origin, $destination] = $this->warehouses();
        $receiver = $this->userWithPermission($destination, TransferLogisticsService::RECEIVE_PERMISSION);
        $transfer = $this->transfer($origin, $destination, 10);
        $detailId = $this->detail($transfer->id, 10);
        $service = app(TransferLogisticsService::class);

        $first = $service->receive($transfer, $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 4,
        ]]);

        $this->assertSame('partially_received', $first->logistics_status);
        $this->assertSame('sent', $first->statut);
        $this->assertEquals(4.0, (float) DB::table('product_warehouse')->where('warehouse_id', $destination)->value('qte'));

        $second = $service->receive($first->fresh(), $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 6,
        ]]);

        $this->assertSame('received', $second->logistics_status);
        $this->assertEquals(10.0, (float) DB::table('product_warehouse')->where('warehouse_id', $destination)->value('qte'));
        $this->assertDatabaseCount('transfer_receipts', 2);
    }

    public function test_defective_and_missing_quantities_never_enter_sellable_stock(): void
    {
        [$origin, $destination] = $this->warehouses();
        $receiver = $this->userWithPermission($destination, TransferLogisticsService::RECEIVE_PERMISSION);
        $transfer = $this->transfer($origin, $destination, 10);
        $detailId = $this->detail($transfer->id, 10);

        $updated = app(TransferLogisticsService::class)->receive($transfer, $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 7,
            'quantity_defective' => 2,
            'quantity_missing' => 1,
        ]]);

        $this->assertSame('received_with_issues', $updated->logistics_status);
        $this->assertEquals(7.0, (float) DB::table('product_warehouse')->where('warehouse_id', $destination)->value('qte'));
        $this->assertEquals(2.0, (float) DB::table('transfer_quarantine_stock')->where('transfer_id', $transfer->id)->sum('quantity'));
        $this->assertEquals(2, DB::table('transfer_discrepancies')->where('transfer_id', $transfer->id)->count());
        $this->assertEquals(3.0, (float) DB::table('transfer_discrepancies')->where('transfer_id', $transfer->id)->sum('quantity'));
    }

    public function test_receipt_cannot_account_for_more_than_remaining_quantity(): void
    {
        [$origin, $destination] = $this->warehouses();
        $receiver = $this->userWithPermission($destination, TransferLogisticsService::RECEIVE_PERMISSION);
        $transfer = $this->transfer($origin, $destination, 5);
        $detailId = $this->detail($transfer->id, 5);

        $this->expectException(ValidationException::class);
        app(TransferLogisticsService::class)->receive($transfer, $receiver, [[
            'transfer_detail_id' => $detailId,
            'quantity_good' => 4,
            'quantity_defective' => 1,
            'quantity_missing' => 1,
        ]]);
    }

    private function warehouses(): array
    {
        $ids = [];
        foreach (['Principal', 'Sucursal 30', 'Otra'] as $name) {
            $ids[] = DB::table('warehouses')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $ids;
    }

    private function userAtWarehouse(int $warehouseId): User
    {
        $user = User::create([
            'firstname' => 'Receiver',
            'lastname' => 'Test',
            'username' => 'u'.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'secret',
            'statut' => 1,
            'is_all_warehouses' => 0,
            'default_warehouse_id' => $warehouseId,
        ]);
        DB::table('user_warehouse')->insert([
            'user_id' => $user->id,
            'warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $user;
    }

    private function userWithPermission(int $warehouseId, string $permissionName): User
    {
        $user = $this->userAtWarehouse($warehouseId);
        $role = Role::create(['name' => 'Role '.uniqid(), 'status' => 1]);
        $permission = Permission::create(['name' => $permissionName]);
        DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        DB::table('permission_role')->insert(['permission_id' => $permission->id, 'role_id' => $role->id]);
        return $user;
    }

    private function transfer(int $origin, int $destination, float $items): Transfer
    {
        $id = DB::table('transfers')->insertGetId([
            'date' => now()->toDateString(),
            'time' => now()->format('H:i:s'),
            'Ref' => 'TR_TEST_'.uniqid(),
            'user_id' => 1,
            'from_warehouse_id' => $origin,
            'to_warehouse_id' => $destination,
            'items' => $items,
            'statut' => 'sent',
            'approval_status' => 'approved',
            'receiving_token' => 'TRF-TEST-'.strtoupper(uniqid()),
            'logistics_status' => 'in_transit',
            'dispatched_at' => now(),
            'GrandTotal' => 0,
            'discount' => 0,
            'shipping' => 0,
            'TaxNet' => 0,
            'tax_rate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return Transfer::findOrFail($id);
    }

    private function detail(int $transferId, float $quantity): int
    {
        return DB::table('transfer_details')->insertGetId([
            'transfer_id' => $transferId,
            'quantity' => $quantity,
            'purchase_unit_id' => null,
            'product_id' => 1,
            'product_variant_id' => null,
            'cost' => 0,
            'TaxNet' => 0,
            'discount' => 0,
            'discount_method' => '1',
            'tax_method' => '1',
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('warehouses', function ($t) {
            $t->increments('id'); $t->string('name'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('users', function ($t) {
            $t->increments('id'); $t->string('firstname')->nullable(); $t->string('lastname')->nullable();
            $t->string('username')->nullable(); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->integer('statut')->default(1); $t->integer('is_all_warehouses')->default(0);
            $t->unsignedInteger('default_warehouse_id')->nullable(); $t->unsignedInteger('default_cash_drawer_id')->nullable();
            $t->boolean('record_view')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('roles', function ($t) {
            $t->increments('id'); $t->string('name'); $t->integer('status')->default(1); $t->string('label')->nullable(); $t->text('description')->nullable(); $t->timestamps();
        });
        Schema::create('permissions', function ($t) {
            $t->increments('id'); $t->string('name'); $t->string('label')->nullable(); $t->text('description')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('role_user', function ($t) { $t->increments('id'); $t->unsignedInteger('user_id'); $t->unsignedInteger('role_id'); });
        Schema::create('permission_role', function ($t) { $t->increments('id'); $t->unsignedInteger('permission_id'); $t->unsignedInteger('role_id'); });
        Schema::create('user_warehouse', function ($t) { $t->increments('id'); $t->unsignedInteger('user_id'); $t->unsignedInteger('warehouse_id'); $t->timestamps(); });

        Schema::create('products', function ($t) { $t->increments('id'); $t->string('name')->nullable(); $t->string('code')->nullable(); $t->boolean('is_batch_tracked')->default(false); $t->timestamps(); $t->softDeletes(); });
        DB::table('products')->insert(['id' => 1, 'name' => 'Producto', 'code' => 'P001', 'is_batch_tracked' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('units', function ($t) { $t->increments('id'); $t->string('ShortName')->nullable(); $t->string('operator')->nullable(); $t->decimal('operator_value', 20, 6)->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('product_warehouse', function ($t) { $t->increments('id'); $t->integer('product_id'); $t->integer('warehouse_id'); $t->integer('product_variant_id')->nullable(); $t->decimal('qte', 20, 6)->default(0); $t->integer('manage_stock')->default(1); $t->timestamps(); $t->softDeletes(); });

        Schema::create('transfers', function ($t) {
            $t->increments('id'); $t->date('date')->nullable(); $t->time('time')->nullable(); $t->string('Ref')->nullable(); $t->integer('user_id')->nullable();
            $t->integer('from_warehouse_id'); $t->integer('to_warehouse_id'); $t->decimal('items', 20, 6)->default(0);
            $t->string('statut')->default('sent'); $t->string('approval_status')->nullable(); $t->string('receiving_token')->nullable();
            $t->string('logistics_status')->default('pending'); $t->timestamp('dispatched_at')->nullable(); $t->integer('dispatched_by_user_id')->nullable();
            $t->timestamp('received_at')->nullable(); $t->integer('received_by_user_id')->nullable(); $t->text('notes')->nullable();
            $t->decimal('GrandTotal', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->decimal('shipping', 20, 6)->default(0); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('tax_rate', 20, 6)->default(0);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('transfer_details', function ($t) {
            $t->increments('id'); $t->integer('transfer_id'); $t->decimal('quantity', 20, 6); $t->integer('purchase_unit_id')->nullable(); $t->integer('product_id'); $t->integer('product_variant_id')->nullable();
            $t->decimal('cost', 20, 6)->default(0); $t->decimal('TaxNet', 20, 6)->default(0); $t->decimal('discount', 20, 6)->default(0); $t->string('discount_method')->nullable(); $t->string('tax_method')->nullable(); $t->decimal('total', 20, 6)->default(0); $t->timestamps();
        });
        Schema::create('transfer_receipts', function ($t) { $t->id(); $t->integer('transfer_id'); $t->integer('warehouse_id'); $t->integer('received_by_user_id'); $t->string('status'); $t->text('notes')->nullable(); $t->timestamp('received_at'); $t->timestamps(); });
        Schema::create('transfer_receipt_items', function ($t) { $t->id(); $t->unsignedBigInteger('transfer_receipt_id'); $t->integer('transfer_detail_id'); $t->decimal('quantity_good', 20, 6)->default(0); $t->decimal('quantity_defective', 20, 6)->default(0); $t->decimal('quantity_missing', 20, 6)->default(0); $t->text('notes')->nullable(); $t->timestamps(); });
        Schema::create('transfer_discrepancies', function ($t) { $t->id(); $t->integer('transfer_id'); $t->integer('transfer_detail_id'); $t->integer('warehouse_id'); $t->integer('reported_by_user_id'); $t->string('type'); $t->decimal('quantity', 20, 6); $t->string('resolution_status')->default('open'); $t->text('notes')->nullable(); $t->timestamp('reported_at'); $t->timestamp('resolved_at')->nullable(); $t->integer('resolved_by_user_id')->nullable(); $t->timestamps(); });
        Schema::create('transfer_quarantine_stock', function ($t) { $t->id(); $t->integer('transfer_id'); $t->integer('transfer_detail_id'); $t->integer('warehouse_id'); $t->integer('product_id'); $t->integer('product_variant_id')->nullable(); $t->decimal('quantity', 20, 6); $t->string('status')->default('quarantined'); $t->text('notes')->nullable(); $t->integer('created_by_user_id'); $t->timestamps(); });
        Schema::create('transfer_events', function ($t) { $t->id(); $t->integer('transfer_id'); $t->string('event_type'); $t->integer('actor_user_id')->nullable(); $t->integer('warehouse_id')->nullable(); $t->json('payload')->nullable(); $t->timestamp('created_at')->nullable(); });
        Schema::create('transfer_notifications', function ($t) { $t->id(); $t->integer('transfer_id'); $t->integer('user_id'); $t->string('type'); $t->string('title'); $t->text('message'); $t->timestamp('read_at')->nullable(); $t->timestamps(); });
    }
}
