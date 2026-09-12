<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MobileClientsReportsTestSchema
{
    private function user(array $overrides = [], array $permissionNames = [], array $branchIds = []): User
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

        foreach ($branchIds as $branchId) {
            DB::table('user_branches')->insert(['user_id' => $user->id, 'branch_id' => $branchId]);
        }

        return $user;
    }

    private function branch(array $overrides = []): object
    {
        $id = DB::table('branches')->insertGetId(array_merge([
            'name' => 'Branch '.uniqid('', true),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function client(array $overrides = []): object
    {
        $id = DB::table('clients')->insertGetId(array_merge([
            'name' => 'Client '.uniqid('', true),
            'tax_number' => null,
            'phone' => null,
            'email' => null,
            'adresse' => null,
            'opening_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function sale(array $overrides = []): object
    {
        $id = DB::table('sales')->insertGetId(array_merge([
            'sale_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'Ref' => 'REF-'.uniqid('', true),
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'client_id' => null,
            'branch_id' => null,
            'TaxNet' => 0,
            'GrandTotal' => 100.00,
            'paid_amount' => 100.00,
            'payment_statut' => 'paid',
            'statut' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function saleDetail(int $saleId, array $overrides = []): void
    {
        $productId = $overrides['product_id'] ?? DB::table('products')->insertGetId(['name' => 'Producto '.uniqid('', true), 'created_at' => now(), 'updated_at' => now()]);

        DB::table('sale_details')->insert(array_merge([
            'date' => now()->toDateString(),
            'sale_id' => $saleId,
            'product_id' => $productId,
            'price' => 10,
            'total' => 10,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function paymentSale(int $saleId, array $overrides = []): void
    {
        $methodId = $overrides['payment_method_id'] ?? DB::table('payment_methods')->insertGetId(['name' => 'Efectivo', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('payment_sales')->insert(array_merge([
            'sale_id' => $saleId,
            'date' => now()->toDateString(),
            'montant' => 100,
            'payment_method_id' => $methodId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createMobileClientsReportsSchema(): void
    {
        Schema::create('users', function ($table) {
            $table->integer('id', true);
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->integer('statut')->default(1);
            $table->integer('role_id')->default(1);
            $table->boolean('record_view')->nullable();
            $table->integer('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('roles', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('status')->default(1);
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('description')->nullable();
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

        Schema::create('user_branches', function ($table) {
            $table->integer('user_id');
            $table->integer('branch_id');
        });

        Schema::create('user_warehouse', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('warehouse_id');
        });

        Schema::create('branches', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('clients', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('code')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('adresse')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales', function ($table) {
            $table->integer('id', true);
            $table->char('sale_uuid', 36)->nullable()->unique();
            $table->integer('user_id')->nullable();
            $table->date('date')->nullable();
            $table->time('time')->nullable();
            $table->string('Ref')->nullable();
            $table->boolean('is_pos')->default(false);
            $table->integer('client_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->decimal('TaxNet', 15, 2)->default(0);
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('payment_statut')->nullable();
            $table->string('statut')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_details', function ($table) {
            $table->integer('id', true);
            $table->date('date')->nullable();
            $table->integer('sale_id');
            $table->integer('product_id');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_returns', function ($table) {
            $table->integer('id', true);
            $table->integer('client_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_sales', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->date('date')->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->integer('payment_method_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sar_fiscal_documents', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->string('fiscal_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
}
