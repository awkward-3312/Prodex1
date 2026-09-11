<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileSalesHistoryController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileSalesHistoryEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSalesHistorySchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/sales-test',
            MobileSalesHistoryController::class
        );
    }

    // ------------------------------------------------------------------
    // AUTH / TENANCY
    // ------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/mobile/sales-test')->assertStatus(401);
    }

    public function test_denies_user_without_sales_view_permission(): void
    {
        $user = $this->user(['role_id' => 2], withSalesView: false);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(403);
    }

    public function test_allows_user_with_sales_view_permission(): void
    {
        $user = $this->user(['role_id' => 2]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);
    }

    public function test_restricts_non_owner_to_assigned_branch_sales(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $user = $this->user(['role_id' => 2], branchIds: [$branchA->id]);

        $this->sale(['branch_id' => $branchA->id, 'Ref' => 'A-VISIBLE']);
        $this->sale(['branch_id' => $branchB->id, 'Ref' => 'B-HIDDEN']);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('A-VISIBLE'));
        $this->assertFalse($refs->contains('B-HIDDEN'));
    }

    public function test_owner_sees_sales_across_all_branches(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $owner = $this->user(['role_id' => 1]);

        $this->sale(['branch_id' => $branchA->id, 'Ref' => 'A-OWNER']);
        $this->sale(['branch_id' => $branchB->id, 'Ref' => 'B-OWNER']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('A-OWNER'));
        $this->assertTrue($refs->contains('B-OWNER'));
    }

    // ------------------------------------------------------------------
    // LISTING
    // ------------------------------------------------------------------

    public function test_lists_newest_sales_first_deterministically(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'FIRST']);
        $this->sale(['Ref' => 'SECOND']);
        $this->sale(['Ref' => 'THIRD']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference')->all();
        $this->assertSame(['THIRD', 'SECOND', 'FIRST'], $refs);
    }

    public function test_maps_customer_name_and_handles_missing_customer(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $client = $this->client(['name' => 'Cliente Final']);
        $this->sale(['Ref' => 'WITH-CLIENT', 'client_id' => $client->id]);
        $this->sale(['Ref' => 'NO-CLIENT', 'client_id' => null]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $items = collect($response->json('data.items'))->keyBy('reference');
        $this->assertSame('Cliente Final', $items['WITH-CLIENT']['customer']['name']);
        $this->assertNull($items['NO-CLIENT']['customer']);
    }

    public function test_exposes_exact_monetary_values_for_paid_sale(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'PAID-SALE', 'GrandTotal' => 150.50, 'paid_amount' => 150.50, 'payment_statut' => 'paid']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $item = collect($response->json('data.items'))->firstWhere('reference', 'PAID-SALE');
        $this->assertSame('150.50', $item['grand_total']);
        $this->assertSame('150.50', $item['paid_amount']);
        $this->assertSame('0.00', $item['due_amount']);
        $this->assertSame('paid', $item['payment_status']);
    }

    public function test_exposes_due_amount_for_partial_sale(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'PARTIAL-SALE', 'GrandTotal' => 200.00, 'paid_amount' => 75.00, 'payment_statut' => 'partial']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $item = collect($response->json('data.items'))->firstWhere('reference', 'PARTIAL-SALE');
        $this->assertSame('200.00', $item['grand_total']);
        $this->assertSame('75.00', $item['paid_amount']);
        $this->assertSame('125.00', $item['due_amount']);
        $this->assertSame('partial', $item['payment_status']);
    }

    public function test_exposes_fiscal_document_when_present_and_null_when_absent(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $withFiscal = $this->sale(['Ref' => 'FISCAL-SALE']);
        $this->sale(['Ref' => 'NO-FISCAL']);
        DB::table('sar_fiscal_documents')->insert([
            'sale_id' => $withFiscal->id,
            'fiscal_number' => 'SAR-0001',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test')
            ->assertStatus(200);

        $items = collect($response->json('data.items'))->keyBy('reference');
        $this->assertSame('SAR-0001', $items['FISCAL-SALE']['fiscal']['number']);
        $this->assertSame('issued', $items['FISCAL-SALE']['fiscal']['status']);
        $this->assertNull($items['NO-FISCAL']['fiscal']);
    }

    // ------------------------------------------------------------------
    // SEARCH
    // ------------------------------------------------------------------

    public function test_search_matches_sale_reference(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'INV-2026-001']);
        $this->sale(['Ref' => 'OTHER-999']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?search=2026-001')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('INV-2026-001'));
        $this->assertFalse($refs->contains('OTHER-999'));
    }

    public function test_search_matches_customer_name(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $client = $this->client(['name' => 'Juan Perez']);
        $this->sale(['Ref' => 'JUAN-SALE', 'client_id' => $client->id]);
        $this->sale(['Ref' => 'OTHER-SALE']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?search=Juan')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('JUAN-SALE'));
        $this->assertFalse($refs->contains('OTHER-SALE'));
    }

    // ------------------------------------------------------------------
    // FILTERS
    // ------------------------------------------------------------------

    public function test_filters_by_payment_status(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'PAID-1', 'payment_statut' => 'paid']);
        $this->sale(['Ref' => 'UNPAID-1', 'payment_statut' => 'unpaid']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?payment_status=unpaid')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('UNPAID-1'));
        $this->assertFalse($refs->contains('PAID-1'));
    }

    public function test_rejects_invalid_payment_status(): void
    {
        $owner = $this->user(['role_id' => 1]);

        $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?payment_status=bogus')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_filters_by_date_range(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $this->sale(['Ref' => 'IN-RANGE', 'date' => '2026-05-15']);
        $this->sale(['Ref' => 'BEFORE-RANGE', 'date' => '2026-01-01']);
        $this->sale(['Ref' => 'AFTER-RANGE', 'date' => '2026-12-31']);

        $response = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?date_from=2026-05-01&date_to=2026-05-31')
            ->assertStatus(200);

        $refs = collect($response->json('data.items'))->pluck('reference');
        $this->assertTrue($refs->contains('IN-RANGE'));
        $this->assertFalse($refs->contains('BEFORE-RANGE'));
        $this->assertFalse($refs->contains('AFTER-RANGE'));
    }

    // ------------------------------------------------------------------
    // PAGINATION
    // ------------------------------------------------------------------

    public function test_pagination_totals_and_no_duplicates_across_pages(): void
    {
        $owner = $this->user(['role_id' => 1]);
        for ($i = 1; $i <= 5; $i++) {
            $this->sale(['Ref' => "PAGE-SALE-{$i}"]);
        }

        $page1 = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?per_page=2&page=1')
            ->assertStatus(200);
        $page2 = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?per_page=2&page=2')
            ->assertStatus(200);
        $page3 = $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test?per_page=2&page=3')
            ->assertStatus(200);

        $this->assertSame(5, $page1->json('data.pagination.total'));
        $this->assertSame(3, $page1->json('data.pagination.last_page'));
        $this->assertTrue($page1->json('data.pagination.has_more'));
        $this->assertFalse($page3->json('data.pagination.has_more'));

        $allRefs = collect($page1->json('data.items'))
            ->concat($page2->json('data.items'))
            ->concat($page3->json('data.items'))
            ->pluck('reference');
        $this->assertCount(5, $allRefs->unique());
    }

    // ------------------------------------------------------------------
    // PERFORMANCE
    // ------------------------------------------------------------------

    public function test_query_count_stays_flat_as_sale_count_grows(): void
    {
        $owner = $this->user(['role_id' => 1]);
        for ($i = 1; $i <= 3; $i++) {
            $this->sale(['Ref' => "N1-SMALL-{$i}"]);
        }

        DB::enableQueryLog();
        $this->actingAs($owner, 'api')->getJson('/api/mobile/sales-test')->assertStatus(200);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        for ($i = 1; $i <= 30; $i++) {
            $this->sale(['Ref' => "N1-LARGE-{$i}"]);
        }

        DB::enableQueryLog();
        $this->actingAs($owner, 'api')->getJson('/api/mobile/sales-test?per_page=50')->assertStatus(200);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($smallCount, $largeCount, 'Query count must not grow with row count (N+1 guard).');
    }

    // ------------------------------------------------------------------
    // REGRESSION
    // ------------------------------------------------------------------

    public function test_real_tenant_route_is_inside_pos_feature_group(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_api.php'));

        $this->assertMatchesRegularExpression(
            "/Route::middleware\\('tenant\\.feature:pos'\\)->group\\(function \\(\\) \\{.*mobile\\/sales.*mobile\\/sales/s",
            $routes
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function user(array $overrides = [], bool $withSalesView = true, array $branchIds = []): User
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

        if ($withSalesView) {
            $permission = Permission::create(['name' => 'Sales_view', 'label' => 'Sales_view']);
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
            'GrandTotal' => 100.00,
            'paid_amount' => 100.00,
            'payment_statut' => 'paid',
            'statut' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function createSalesHistorySchema(): void
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
            $table->string('adresse')->nullable();
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
            $table->integer('inventory_location_id')->nullable();
            $table->integer('cash_drawer_id')->nullable();
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('payment_statut')->nullable();
            $table->string('statut')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_details', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->timestamps();
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
