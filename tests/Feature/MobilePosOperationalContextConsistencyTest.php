<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobilePosCatalogController;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use App\Services\Mobile\MobileBootstrapService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobilePosOperationalContextConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/pos/catalog-context-test',
            MobilePosCatalogController::class
        );

        Gate::before(fn ($user = null) => true);
    }

    public function test_valid_sellable_effective_location_is_kept_and_catalog_accepts_it(): void
    {
        $branch = $this->branch();
        $location = $this->location($branch, ['code' => 'PISO', 'is_sellable' => true, 'is_default_sales' => true]);
        $drawer = $this->drawer($branch, $location, ['code' => 'CAJA-PISO']);
        $branch->update(['default_inventory_location_id' => $location->id]);
        $user = $this->user([
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $location->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);

        $bootstrap = app(MobileBootstrapService::class)->forUser($user);
        $effectiveLocationId = $bootstrap['operational_context']['effective']['inventory_location_id'];

        $this->assertSame($location->id, $effectiveLocationId);
        $this->assertTrue($bootstrap['operational_context']['ready_for_location_pos']);
        $this->assertContains($effectiveLocationId, $bootstrap['operational_context']['inventory_locations']->pluck('id')->all());

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/catalog-context-test?inventory_location_id='.$effectiveLocationId)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_active_but_not_sellable_effective_location_falls_back_to_default_sales_location(): void
    {
        $branch = $this->branch();
        $storage = $this->location($branch, ['code' => 'BODEGA', 'type' => InventoryLocation::TYPE_STORAGE, 'is_sellable' => false]);
        $salesFloor = $this->location($branch, ['code' => 'PISO', 'is_sellable' => true, 'is_default_sales' => true]);
        $wrongDrawer = $this->drawer($branch, $storage, ['code' => 'CAJA-BODEGA']);
        $rightDrawer = $this->drawer($branch, $salesFloor, ['code' => 'CAJA-PISO']);
        $branch->update(['default_inventory_location_id' => $salesFloor->id]);
        $user = $this->user([
            'role_id' => 2,
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $storage->id,
            'default_cash_drawer_id' => $wrongDrawer->id,
        ]);
        $this->allowLocation($user, $salesFloor);

        $context = app(MobileBootstrapService::class)->forUser($user)['operational_context'];

        $this->assertSame($salesFloor->id, $context['effective']['inventory_location_id']);
        $this->assertSame($rightDrawer->id, $context['effective']['cash_drawer_id']);
        $this->assertTrue($context['ready_for_location_pos']);
        $this->assertContains($context['effective']['inventory_location_id'], $context['inventory_locations']->pluck('id')->all());
        $this->assertNotContains($storage->id, $context['inventory_locations']->pluck('id')->all());
    }

    public function test_fallback_stays_inside_effective_branch_and_user_scope(): void
    {
        $branch = $this->branch(['code' => 'TGU']);
        $otherBranch = $this->branch(['code' => 'SPS']);
        $bad = $this->location($branch, ['code' => 'BODEGA', 'type' => InventoryLocation::TYPE_STORAGE, 'is_sellable' => false]);
        $branchSales = $this->location($branch, ['code' => 'PISO-TGU', 'is_sellable' => true]);
        $otherSales = $this->location($otherBranch, ['code' => 'PISO-SPS', 'is_sellable' => true, 'is_default_sales' => true]);
        $drawer = $this->drawer($branch, $branchSales);
        $this->drawer($otherBranch, $otherSales);
        $user = $this->user([
            'role_id' => 2,
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $bad->id,
        ]);
        $this->allowLocation($user, $branchSales);
        $this->allowLocation($user, $otherSales);

        $context = app(MobileBootstrapService::class)->forUser($user)['operational_context'];

        $this->assertSame($branch->id, $context['effective']['branch_id']);
        $this->assertSame($branchSales->id, $context['effective']['inventory_location_id']);
        $this->assertSame($drawer->id, $context['effective']['cash_drawer_id']);
        $this->assertNotContains($otherSales->id, $context['inventory_locations']->pluck('id')->all());
    }

    public function test_without_any_sellable_location_effective_location_is_null_and_pos_is_not_ready(): void
    {
        $branch = $this->branch();
        $storage = $this->location($branch, ['code' => 'BODEGA', 'type' => InventoryLocation::TYPE_STORAGE, 'is_sellable' => false]);
        $this->drawer($branch, $storage);
        $user = $this->user([
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $storage->id,
        ]);

        $context = app(MobileBootstrapService::class)->forUser($user)['operational_context'];

        $this->assertSame($branch->id, $context['effective']['branch_id']);
        $this->assertNull($context['effective']['inventory_location_id']);
        $this->assertNull($context['effective']['cash_drawer_id']);
        $this->assertFalse($context['ready_for_location_pos']);
        $this->assertTrue($context['inventory_locations']->isEmpty());
    }

    public function test_ready_for_location_pos_requires_a_cash_drawer_for_the_effective_location(): void
    {
        $branch = $this->branch();
        $location = $this->location($branch, ['is_sellable' => true, 'is_default_sales' => true]);
        $branch->update(['default_inventory_location_id' => $location->id]);
        $user = $this->user([
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $location->id,
        ]);

        $context = app(MobileBootstrapService::class)->forUser($user)['operational_context'];

        $this->assertSame($location->id, $context['effective']['inventory_location_id']);
        $this->assertNull($context['effective']['cash_drawer_id']);
        $this->assertFalse($context['ready_for_location_pos']);
    }

    private function user(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'firstname' => 'Mobile',
            'lastname' => 'Cashier',
            'username' => uniqid('cashier-', false),
            'email' => uniqid('mobile-', false).'@example.test',
            'password' => bcrypt('secret'),
            'statut' => 1,
            'role_id' => 1,
            'is_all_warehouses' => 0,
            'default_branch_id' => null,
            'default_inventory_location_id' => null,
            'default_cash_drawer_id' => null,
        ], $overrides))->save();

        return $user;
    }

    private function branch(array $overrides = []): Branch
    {
        return Branch::create(array_merge([
            'code' => uniqid('BR-', false),
            'name' => 'Sucursal',
            'default_inventory_location_id' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function location(Branch $branch, array $overrides = []): InventoryLocation
    {
        return InventoryLocation::create(array_merge([
            'branch_id' => $branch->id,
            'warehouse_id' => null,
            'code' => uniqid('LOC-', false),
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_default_sales' => false,
            'is_quarantine' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function drawer(Branch $branch, InventoryLocation $location, array $overrides = []): CashDrawer
    {
        return CashDrawer::create(array_merge([
            'branch_id' => $branch->id,
            'inventory_location_id' => $location->id,
            'warehouse_id' => null,
            'code' => uniqid('CAJA-', false),
            'name' => 'Caja',
            'is_active' => true,
        ], $overrides));
    }

    private function allowLocation(User $user, InventoryLocation $location): void
    {
        \DB::table('user_inventory_locations')->insert([
            'user_id' => $user->id,
            'inventory_location_id' => $location->id,
        ]);
    }

    private function createSchema(): void
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
            $table->boolean('is_all_warehouses')->default(false);
            $table->integer('default_branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->integer('default_cash_drawer_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function ($table) {
            $table->integer('id', true);
            $table->string('code')->nullable();
            $table->string('name');
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->integer('id', true);
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cash_drawers', function ($table) {
            $table->integer('id', true);
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_inventory_locations', function ($table) {
            $table->integer('user_id');
            $table->integer('inventory_location_id');
        });

        Schema::create('roles', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function ($table) {
            $table->integer('id', true);
            $table->string('name')->unique();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('role_user', function ($table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('role_id');
            $table->timestamps();
        });
        Schema::create('permission_role', function ($table) {
            $table->integer('id', true);
            $table->integer('permission_id');
            $table->integer('role_id');
        });

        Schema::create('units', function ($table) {
            $table->integer('id', true);
            $table->string('ShortName')->nullable();
            $table->timestamps();
        });
        \DB::table('units')->insert(['id' => 1, 'ShortName' => 'u', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('products', function ($table) {
            $table->integer('id', true);
            $table->string('type')->default('is_single');
            $table->string('name');
            $table->string('code', 192);
            $table->string('gtin', 64)->nullable();
            $table->string('Type_barcode', 192);
            $table->decimal('cost', 15)->default(0);
            $table->decimal('price', 15)->default(0);
            $table->decimal('wholesale_price', 15)->default(0);
            $table->decimal('min_price', 15)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('unit_sale_id')->nullable();
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('2');
            $table->decimal('stock_alert', 15)->nullable()->default(0);
            $table->boolean('is_variant')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('not_selling')->default(false);
            $table->string('image')->default('no-image.png');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function ($table) {
            $table->integer('id', true);
            $table->integer('product_id')->nullable();
            $table->string('name', 192)->nullable();
            $table->decimal('cost', 15)->default(0);
            $table->decimal('price', 15)->default(0);
            $table->decimal('wholesale', 15)->nullable()->default(0);
            $table->decimal('min_price', 15)->nullable()->default(0);
            $table->string('code', 192);
            $table->string('gtin', 64)->nullable();
            $table->string('image')->default('no-image.png');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_location_stocks', function ($table) {
            $table->integer('id', true);
            $table->integer('inventory_location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('variant_key')->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_settings', function ($table) {
            $table->integer('id', true);
            $table->boolean('allow_overselling')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        \DB::table('pos_settings')->insert(['allow_overselling' => false, 'created_at' => now(), 'updated_at' => now()]);

        Product::query()->delete();
    }
}
