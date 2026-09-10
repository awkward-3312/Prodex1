<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\MobileTenantResolverController;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\Currency;
use App\Models\InventoryLocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class MobileAuthBackendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createCentralSchema();
        $this->createTenantAuthSchema();

        Route::post('/api/mobile/tenants/resolve-test', [MobileTenantResolverController::class, 'resolve'])
            ->middleware('throttle:mobile-tenant-resolve');
        Route::post('/api/mobile/auth/login-test', [MobileAuthController::class, 'login'])
            ->middleware('throttle:mobile-login');
        Route::middleware('auth:api')->get('/api/mobile/auth/bootstrap-test', [MobileAuthController::class, 'bootstrap']);
        Route::middleware('auth:api')->post('/api/mobile/auth/logout-test', [MobileAuthController::class, 'logout']);
    }

    public function test_central_resolver_returns_tenant_base_url_for_valid_workspace(): void
    {
        $this->centralTenant('tenant-a', 'prueba02');

        $response = $this->postJson('/api/mobile/tenants/resolve-test', [
            'workspace' => '  PRUEBA02  ',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workspace', 'prueba02')
            ->assertJsonPath('data.base_url', 'https://prueba02.prodexhub.cloud')
            ->assertJsonMissingPath('data.database')
            ->assertJsonMissingPath('data.tenancy_db_name')
            ->assertJsonMissingPath('data.id');
    }

    public function test_central_resolver_rejects_unknown_inactive_invalid_and_url_workspaces(): void
    {
        $this->centralTenant('tenant-suspended', 'apagado', Tenant::STATUS_SUSPENDED);

        $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'missing'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'workspace_not_found');

        $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'apagado'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'workspace_not_found');

        $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'https://sitio-malicioso.com'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_workspace');

        $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'bad_workspace'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_workspace');
    }

    public function test_central_resolver_is_rate_limited(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'limited'])
                ->assertStatus(404);
        }

        $this->postJson('/api/mobile/tenants/resolve-test', ['workspace' => 'limited'])
            ->assertStatus(429);
    }

    public function test_mobile_login_issues_passport_token_without_cookie_or_sensitive_fields(): void
    {
        $this->seedOperationalData();
        $this->user(['email' => 'cashier@example.test']);

        $response = $this->postJson('/api/mobile/auth/login-test', [
            'email' => 'cashier@example.test',
            'password' => 'secret',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.session.idle_timeout_seconds', null)
            ->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_at', 'session']])
            ->assertJsonMissingPath('data.client_secret')
            ->assertJsonMissingPath('data.password')
            ->assertCookieMissing('Stocky_token');

        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
    }

    public function test_mobile_login_uses_generic_invalid_credentials_and_typed_inactive_response(): void
    {
        $this->seedOperationalData();
        $this->user(['email' => 'cashier@example.test']);
        $this->user(['email' => 'inactive@example.test', 'statut' => 0]);

        $this->postJson('/api/mobile/auth/login-test', [
            'email' => 'cashier@example.test',
            'password' => 'wrong',
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');

        $this->postJson('/api/mobile/auth/login-test', [
            'email' => 'nobody@example.test',
            'password' => 'secret',
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');

        $this->postJson('/api/mobile/auth/login-test', [
            'email' => 'inactive@example.test',
            'password' => 'secret',
        ])->assertStatus(403)->assertJsonPath('error.code', 'user_inactive');
    }

    public function test_mobile_login_is_rate_limited(): void
    {
        $this->seedOperationalData();
        $this->user(['email' => 'rate@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mobile/auth/login-test', [
                'email' => 'rate@example.test',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->postJson('/api/mobile/auth/login-test', [
            'email' => 'rate@example.test',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_mobile_bootstrap_returns_user_preferences_permissions_and_scoped_operational_context(): void
    {
        [$branch, $allowed, $forbidden, $drawer] = $this->seedOperationalData();
        $user = $this->user([
            'email' => 'cashier@example.test',
            'role_id' => 2,
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $allowed->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);
        $this->attachPermissions($user, ['view_pos', 'create_sales'], ['inventory_view']);

        $token = $this->loginToken('cashier@example.test');

        $response = $this->withToken($token)->getJson('/api/mobile/auth/bootstrap-test');

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'cashier@example.test')
            ->assertJsonPath('data.preferences.currency_code', 'HNL')
            ->assertJsonPath('data.preferences.currency_symbol', 'L')
            ->assertJsonPath('data.preferences.locale', 'es')
            ->assertJsonPath('data.preferences.timezone', 'America/Tegucigalpa')
            ->assertJsonPath('data.operational_context.effective.inventory_location_id', $allowed->id)
            ->assertJsonPath('data.operational_context.ready_for_location_pos', true);

        $this->assertEqualsCanonicalizing(
            ['view_pos', 'create_sales', 'inventory_view'],
            $response->json('data.permissions')
        );
        $this->assertSame([$allowed->id], collect($response->json('data.operational_context.inventory_locations'))->pluck('id')->all());
        $this->assertNotContains($forbidden->id, collect($response->json('data.operational_context.inventory_locations'))->pluck('id')->all());
    }

    public function test_mobile_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/mobile/auth/bootstrap-test')
            ->assertStatus(401);
    }

    public function test_mobile_logout_revokes_only_current_access_token(): void
    {
        $this->seedOperationalData();
        $this->user(['email' => 'cashier@example.test']);

        $firstToken = $this->loginToken('cashier@example.test');
        $secondToken = $this->loginToken('cashier@example.test');

        $this->withToken($firstToken)->getJson('/api/mobile/auth/bootstrap-test')->assertOk();
        $this->withToken($secondToken)->getJson('/api/mobile/auth/bootstrap-test')->assertOk();

        $this->withToken($firstToken)->postJson('/api/mobile/auth/logout-test')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        app('auth')->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/mobile/auth/bootstrap-test')->assertStatus(401);
        app('auth')->forgetGuards();
        $this->withToken($secondToken)->getJson('/api/mobile/auth/bootstrap-test')->assertOk();

        $this->assertSame(1, DB::table('oauth_access_tokens')->where('revoked', true)->count());
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('revoked', false)->count());
    }

    private function centralTenant(string $id, string $workspace, string $status = Tenant::STATUS_ACTIVE): Tenant
    {
        $tenant = Tenant::create([
            'id' => $id,
            'status' => $status,
        ]);

        Domain::create([
            'tenant_id' => $tenant->id,
            'domain' => $workspace,
        ]);

        return $tenant;
    }

    private function seedOperationalData(): array
    {
        $currency = Currency::create(['code' => 'HNL', 'name' => 'Lempira', 'symbol' => 'L']);
        Setting::query()->delete();
        Setting::create([
            'currency_id' => $currency->id,
            'CompanyName' => 'PRODEX Honduras',
            'logo' => 'logo.png',
            'default_language' => 'es',
            'timezone' => 'America/Tegucigalpa',
            'date_format' => 'YYYY-MM-DD',
        ]);

        $branch = Branch::create([
            'code' => 'TGU',
            'name' => 'Tegucigalpa',
            'default_inventory_location_id' => null,
            'is_active' => true,
        ]);

        $allowed = InventoryLocation::create([
            'branch_id' => $branch->id,
            'warehouse_id' => null,
            'code' => 'PISO',
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_default_sales' => true,
            'is_quarantine' => false,
            'is_active' => true,
        ]);
        $forbidden = InventoryLocation::create([
            'branch_id' => $branch->id,
            'warehouse_id' => null,
            'code' => 'BODEGA',
            'name' => 'Bodega',
            'type' => InventoryLocation::TYPE_STORAGE,
            'is_sellable' => true,
            'is_default_sales' => false,
            'is_quarantine' => false,
            'is_active' => true,
        ]);
        $branch->update(['default_inventory_location_id' => $allowed->id]);

        $drawer = CashDrawer::create([
            'branch_id' => $branch->id,
            'inventory_location_id' => $allowed->id,
            'warehouse_id' => null,
            'code' => 'CAJA-1',
            'name' => 'Caja 1',
            'is_active' => true,
        ]);

        return [$branch, $allowed, $forbidden, $drawer];
    }

    private function user(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'firstname' => 'Mobile',
            'lastname' => 'Cashier',
            'username' => 'mobile-cashier',
            'email' => uniqid('mobile-', false).'@example.test',
            'password' => Hash::make('secret'),
            'phone' => '5555-5555',
            'statut' => 1,
            'role_id' => 1,
            'is_all_warehouses' => 0,
            'record_view' => false,
            'default_branch_id' => null,
            'default_inventory_location_id' => null,
            'default_cash_drawer_id' => null,
        ], $overrides))->save();

        return $user;
    }

    private function attachPermissions(User $user, array ...$permissionGroups): void
    {
        foreach ($permissionGroups as $index => $permissions) {
            $role = Role::create(['name' => 'role-'.$index, 'status' => 1]);
            $user->roles()->attach($role->id);

            foreach ($permissions as $permissionName) {
                $permission = Permission::firstOrCreate(['name' => $permissionName]);
                $role->permissions()->attach($permission->id);
            }
        }
    }

    private function loginToken(string $email): string
    {
        return $this->postJson('/api/mobile/auth/login-test', [
            'email' => $email,
            'password' => 'secret',
        ])->assertOk()->json('data.access_token');
    }

    private function createCentralSchema(): void
    {
        config(['app.url' => 'https://prodexhub.cloud']);

        if (! Schema::connection('central')->hasColumn('tenants', 'status')) {
            Schema::connection('central')->table('tenants', function ($table) {
                $table->string('status', 32)->default(Tenant::STATUS_ACTIVE)->after('id');
            });
        }

        if (! Schema::connection('central')->hasTable('domains')) {
            Schema::connection('central')->create('domains', function ($table) {
                $table->increments('id');
                $table->string('domain', 255)->unique();
                $table->string('tenant_id');
                $table->timestamps();
            });
        }
    }

    private function createTenantAuthSchema(): void
    {
        if (! Schema::hasColumn('settings', 'date_format')) {
            Schema::table('settings', function ($table) {
                $table->string('date_format')->nullable();
            });
        }
        if (! Schema::hasColumn('settings', 'price_format')) {
            Schema::table('settings', function ($table) {
                $table->string('price_format')->nullable();
            });
        }

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('firstname');
            $table->string('lastname');
            $table->string('username', 192);
            $table->string('email', 192)->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('avatar')->nullable();
            $table->string('phone', 192)->nullable();
            $table->integer('role_id')->default(1);
            $table->boolean('statut')->default(true);
            $table->boolean('is_all_warehouses')->default(false);
            $table->boolean('record_view')->default(false);
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->integer('default_cash_drawer_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('currencies', function ($table) {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
            $table->string('symbol');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
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
            $table->increments('id');
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

        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name')->unique();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('role_user', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('role_id');
            $table->timestamps();
        });
        Schema::create('permission_role', function ($table) {
            $table->increments('id');
            $table->integer('permission_id');
            $table->integer('role_id');
        });

        Schema::create('oauth_clients', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('secret', 100)->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect');
            $table->boolean('personal_access_client');
            $table->boolean('password_client');
            $table->boolean('revoked');
            $table->timestamps();
        });
        Schema::create('oauth_personal_access_clients', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('client_id');
            $table->timestamps();
        });
        Schema::create('oauth_access_tokens', function ($table) {
            $table->string('id', 100)->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });
        Schema::create('oauth_refresh_tokens', function ($table) {
            $table->string('id', 100)->primary();
            $table->string('access_token_id', 100)->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });

        $clientId = DB::table('oauth_clients')->insertGetId([
            'name' => 'Prodex Personal Access Client',
            'secret' => 'test-secret',
            'redirect' => 'http://localhost',
            'personal_access_client' => true,
            'password_client' => false,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('oauth_personal_access_clients')->insert([
            'client_id' => $clientId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
