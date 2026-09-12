<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MobileCashRegisterTestSchema
{
    private function user(array $overrides = [], array $permissionNames = []): User
    {
        $user = (new User())->forceFill(array_merge([
            'email' => 'user'.uniqid('', true).'@test.com',
            'password' => 'x',
            'role_id' => 2,
            'record_view' => true,
        ], $overrides));
        $user->save();

        $role = Role::create(['name' => 'role_'.uniqid('', true), 'status' => 1, 'label' => 'Role']);
        DB::table('role_user')->insert(['role_id' => $role->id, 'user_id' => $user->id]);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            DB::table('permission_role')->insert(['permission_id' => $permission->id, 'role_id' => $role->id]);
        }

        return $user;
    }

    private function branch(array $overrides = []): object
    {
        $id = DB::table('branches')->insertGetId(array_merge([
            'name' => 'Branch '.uniqid('', true), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function location(int $branchId, array $overrides = []): object
    {
        $id = DB::table('inventory_locations')->insertGetId(array_merge([
            'branch_id' => $branchId, 'name' => 'Location '.uniqid('', true), 'type' => 'sales', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function paymentMethod(string $name): object
    {
        $id = DB::table('payment_methods')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return (object) ['id' => $id];
    }

    private function cashRegister(array $overrides = []): object
    {
        $id = DB::table('cash_registers')->insertGetId(array_merge([
            'user_id' => null,
            'branch_id' => null,
            'inventory_location_id' => null,
            'warehouse_id' => null,
            'cash_drawer_id' => null,
            'opening_balance' => 500,
            'cash_in' => 0,
            'cash_out' => 0,
            'status' => 'open',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function sale(array $overrides = []): void
    {
        DB::table('sales')->insert(array_merge([
            'user_id' => null,
            'is_pos' => 1,
            'branch_id' => null,
            'inventory_location_id' => null,
            'cash_drawer_id' => null,
            'warehouse_id' => null,
            'GrandTotal' => 100,
            'statut' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function paymentSale(int $saleId, int $paymentMethodId, float $amount): void
    {
        DB::table('payment_sales')->insert([
            'sale_id' => $saleId, 'payment_method_id' => $paymentMethodId, 'montant' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createMobileCashRegisterSchema(): void
    {
        Schema::create('users', function ($table) {
            $table->integer('id', true);
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->integer('statut')->default(1);
            $table->integer('role_id')->default(1);
            $table->boolean('record_view')->nullable();
            $table->boolean('is_all_warehouses')->default(false);
            $table->integer('default_branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_cash_drawer_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('roles', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('status')->default(1);
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function ($table) {
            $table->integer('role_id');
            $table->integer('user_id');
        });

        Schema::create('permission_role', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
        });

        Schema::create('user_warehouse', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('warehouse_id');
        });

        Schema::create('user_branches', function ($table) {
            $table->integer('user_id');
            $table->integer('branch_id');
        });

        Schema::create('branches', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->integer('id', true);
            $table->integer('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouses', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cash_drawers', function ($table) {
            $table->integer('id', true);
            $table->integer('branch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cash_registers', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('cash_drawer_id')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->decimal('total_sales', 15, 2)->default(0);
            $table->decimal('cash_in', 15, 2)->default(0);
            $table->decimal('cash_out', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('counted_denominations')->nullable();
            $table->text('sales_by_payment_method')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('cash_difference', 15, 2)->nullable();
            $table->decimal('card_system_total', 15, 2)->nullable();
            $table->decimal('card_terminal_total', 15, 2)->nullable();
            $table->decimal('card_difference', 15, 2)->nullable();
            $table->string('card_batch_number')->nullable();
            $table->string('card_reference')->nullable();
            $table->text('card_notes')->nullable();
            $table->decimal('transfer_total', 15, 2)->nullable();
            $table->boolean('transfers_verified')->nullable();
            $table->text('transfer_notes')->nullable();
            $table->decimal('cash_withdrawn_at_close', 15, 2)->nullable();
            $table->decimal('next_opening_float', 15, 2)->nullable();
            $table->string('register_number_snapshot')->nullable();
            $table->integer('opened_by_user_id_snapshot')->nullable();
            $table->string('opened_by_user_name_snapshot')->nullable();
            $table->integer('closed_by_user_id')->nullable();
            $table->string('closed_by_user_name_snapshot')->nullable();
            $table->integer('warehouse_id_snapshot')->nullable();
            $table->string('warehouse_name_snapshot')->nullable();
            $table->string('tenant_id_snapshot')->nullable();
            $table->string('opened_date_snapshot')->nullable();
            $table->string('opened_time_snapshot')->nullable();
            $table->string('closed_date_snapshot')->nullable();
            $table->string('closed_time_snapshot')->nullable();
            $table->integer('session_duration_seconds')->nullable();
            $table->string('closing_status')->nullable();
            $table->integer('branch_id_snapshot')->nullable();
            $table->string('branch_name_snapshot')->nullable();
            $table->integer('inventory_location_id_snapshot')->nullable();
            $table->string('inventory_location_name_snapshot')->nullable();
            $table->string('cash_drawer_name_snapshot')->nullable();
            $table->string('cash_drawer_code_snapshot')->nullable();
            $table->text('closing_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_sales', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->integer('payment_method_id')->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable();
            $table->boolean('is_pos')->default(false);
            $table->integer('branch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->integer('cash_drawer_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->string('statut')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_returns', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_sale_returns', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_return_id');
            $table->integer('payment_method_id')->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cash_register_operations', function ($table) {
            $table->integer('id', true);
            $table->char('operation_uuid', 36)->unique();
            $table->integer('cash_register_id')->nullable();
            $table->integer('user_id');
            $table->string('operation_type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->string('payload_fingerprint', 64);
            $table->string('source', 20)->default('mobile');
            $table->timestamps();
        });

    }

    private function assignOperationalContext(User $user, int $branchId, int $locationId, ?int $cashDrawerId = null): void
    {
        $user->forceFill([
            'default_branch_id' => $branchId,
            'default_inventory_location_id' => $locationId,
            'default_cash_drawer_id' => $cashDrawerId,
        ])->save();

        DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $branchId]);
    }
}
