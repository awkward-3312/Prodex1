<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileSaleReceiptController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileSaleReceiptEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createReceiptSchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/sales-test/{id}/receipt',
            MobileSaleReceiptController::class
        );
    }

    // ------------------------------------------------------------------
    // AUTH / PERMISSIONS / TENANCY
    // ------------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $sale = $this->sale();

        $this->getJson("/api/mobile/sales-test/{$sale->id}/receipt")->assertStatus(401);
    }

    public function test_denies_user_without_sales_view_permission(): void
    {
        $user = $this->user(['role_id' => 2], withSalesView: false);
        $sale = $this->sale();

        $this->actingAs($user, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(403);
    }

    public function test_allows_user_with_sales_view_permission(): void
    {
        $branch = $this->branch();
        $user = $this->user(['role_id' => 2], branchIds: [$branch->id]);
        $sale = $this->sale(['branch_id' => $branch->id]);

        $this->actingAs($user, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);
    }

    public function test_returns_404_for_a_sale_outside_the_users_branch_scope(): void
    {
        $branchA = $this->branch();
        $branchB = $this->branch();
        $user = $this->user(['role_id' => 2], branchIds: [$branchA->id]);

        $visible = $this->sale(['branch_id' => $branchA->id, 'Ref' => 'A-VISIBLE']);
        $hidden = $this->sale(['branch_id' => $branchB->id, 'Ref' => 'B-HIDDEN']);

        $this->actingAs($user, 'api')
            ->getJson("/api/mobile/sales-test/{$visible->id}/receipt")
            ->assertStatus(200);

        $this->actingAs($user, 'api')
            ->getJson("/api/mobile/sales-test/{$hidden->id}/receipt")
            ->assertStatus(404);
    }

    public function test_non_owner_without_record_view_only_sees_their_own_sale(): void
    {
        $branch = $this->branch();
        $owner = $this->user(['role_id' => 1]);
        $cashier = $this->user(['role_id' => 2, 'record_view' => false], branchIds: [$branch->id]);

        $ownSale = $this->sale(['branch_id' => $branch->id, 'user_id' => $cashier->id, 'Ref' => 'OWN-SALE']);
        $othersSale = $this->sale(['branch_id' => $branch->id, 'user_id' => $owner->id, 'Ref' => 'OTHER-SALE']);

        $this->actingAs($cashier, 'api')
            ->getJson("/api/mobile/sales-test/{$ownSale->id}/receipt")
            ->assertStatus(200);

        $this->actingAs($cashier, 'api')
            ->getJson("/api/mobile/sales-test/{$othersSale->id}/receipt")
            ->assertStatus(404);
    }

    public function test_owner_can_view_any_sale_in_the_tenant(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $other = $this->user(['role_id' => 2]);
        $sale = $this->sale(['user_id' => $other->id]);

        $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);
    }

    public function test_returns_404_for_a_nonexistent_sale(): void
    {
        $owner = $this->user(['role_id' => 1]);

        $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/sales-test/999999/receipt')
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // RECEIPT CONTENT / RENDERER REUSE
    // ------------------------------------------------------------------

    public function test_receipt_belongs_to_the_exact_sale_requested(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $saleA = $this->sale(['Ref' => 'REF-AAA', 'GrandTotal' => 111.11]);
        $saleB = $this->sale(['Ref' => 'REF-BBB', 'GrandTotal' => 222.22]);
        $this->saleDetail($saleA->id);
        $this->saleDetail($saleB->id);

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$saleA->id}/receipt")
            ->assertStatus(200);

        $this->assertSame($saleA->id, $response->json('data.sale_id'));
        $this->assertSame('REF-AAA', $response->json('data.reference'));
        $this->assertStringContainsString('REF-AAA', $response->json('data.html'));
        $this->assertStringNotContainsString('REF-BBB', $response->json('data.html'));
    }

    public function test_renders_the_same_invoice_template_and_data_used_by_the_web_print_flow(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $sale = $this->sale(['Ref' => 'WEB-PARITY']);
        $this->saleDetail($sale->id);

        $expectedHtml = app(\App\Http\Controllers\SalesController::class)->renderSaleInvoiceHtml($sale->id);

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);

        $this->assertSame($expectedHtml, $response->json('data.html'));
    }

    public function test_fiscal_data_is_present_on_the_rendered_receipt_when_the_sale_has_a_sar_document(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $sale = $this->sale(['Ref' => 'FISCAL-REF']);
        $this->saleDetail($sale->id);

        DB::table('sar_fiscal_documents')->insert([
            'sale_id' => $sale->id,
            'fiscal_number' => '000-001-01-00000123',
            'cai' => 'ABC123-CAI',
            'status' => 'issued',
            'issuer_snapshot' => json_encode(['invoice_settings' => ['document_title' => 'FACTURA']]),
            'customer_snapshot' => json_encode([]),
            'sale_snapshot' => json_encode(['grand_total' => 100]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);

        $this->assertStringContainsString('000-001-01-00000123', $response->json('data.html'));
        $this->assertStringContainsString('ABC123-CAI', $response->json('data.html'));
    }

    public function test_receipt_renders_without_a_sar_document_too(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $sale = $this->sale(['Ref' => 'NO-FISCAL-REF']);
        $this->saleDetail($sale->id);

        $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200)
            ->assertJsonPath('data.reference', 'NO-FISCAL-REF');
    }

    // ------------------------------------------------------------------
    // SAFETY: READ-ONLY
    // ------------------------------------------------------------------

    public function test_endpoint_never_mutates_the_sale_stock_payments_or_fiscal_counters(): void
    {
        $owner = $this->user(['role_id' => 1]);
        $sale = $this->sale(['Ref' => 'IMMUTABLE-REF', 'GrandTotal' => 300.00, 'paid_amount' => 300.00, 'statut' => 'completed']);
        $this->saleDetail($sale->id);
        DB::table('sar_fiscal_documents')->insert([
            'sale_id' => $sale->id,
            'fiscal_number' => 'KEEP-AS-IS',
            'status' => 'issued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = [
            'sale' => (array) DB::table('sales')->where('id', $sale->id)->first(),
            'fiscal' => (array) DB::table('sar_fiscal_documents')->where('sale_id', $sale->id)->first(),
            'detailCount' => DB::table('sale_details')->where('sale_id', $sale->id)->count(),
            'paymentCount' => DB::table('payment_sales')->where('sale_id', $sale->id)->count(),
        ];

        $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);
        // Call it twice: a second identical read must not re-issue anything either.
        $this->actingAs($owner, 'api')
            ->getJson("/api/mobile/sales-test/{$sale->id}/receipt")
            ->assertStatus(200);

        $after = [
            'sale' => (array) DB::table('sales')->where('id', $sale->id)->first(),
            'fiscal' => (array) DB::table('sar_fiscal_documents')->where('sale_id', $sale->id)->first(),
            'detailCount' => DB::table('sale_details')->where('sale_id', $sale->id)->count(),
            'paymentCount' => DB::table('payment_sales')->where('sale_id', $sale->id)->count(),
        ];

        $this->assertSame($before['sale'], $after['sale']);
        $this->assertSame($before['fiscal'], $after['fiscal']);
        $this->assertSame($before['detailCount'], $after['detailCount']);
        $this->assertSame($before['paymentCount'], $after['paymentCount']);
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

    private function sale(array $overrides = []): object
    {
        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Cliente Final',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table('sales')->insertGetId(array_merge([
            'sale_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'Ref' => 'REF-'.uniqid('', true),
            'date' => now()->toDateString(),
            'time' => now()->toTimeString(),
            'client_id' => $clientId,
            'branch_id' => null,
            'TaxNet' => 15,
            'discount' => 0,
            'shipping' => 0,
            'GrandTotal' => 100.00,
            'paid_amount' => 100.00,
            'payment_statut' => 'paid',
            'statut' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return (object) ['id' => $id];
    }

    private function saleDetail(int $saleId): void
    {
        $unitId = DB::table('units')->insertGetId(['name' => 'Unidad', 'ShortName' => 'Un', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'code' => 'SKU-1', 'name' => 'Producto de prueba', 'is_imei' => false, 'unit_sale_id' => $unitId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sale_details')->insert([
            'date' => now()->toDateString(),
            'sale_id' => $saleId,
            'product_id' => $productId,
            'product_variant_id' => null,
            'price' => 100,
            'sale_unit_id' => $unitId,
            'TaxNet' => 15,
            'tax_method' => '1',
            'discount' => 0,
            'discount_method' => '2',
            'total' => 100,
            'quantity' => 1,
            'pack_name' => null,
            'pack_multiplier' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createReceiptSchema(): void
    {
        Schema::table('settings', function ($table) {
            $table->timestamp('deleted_at')->nullable();
            $table->string('price_format')->nullable();
        });
        DB::table('settings')->insert(['CompanyName' => 'PRODEX Test', 'created_at' => now(), 'updated_at' => now()]);

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
            $table->decimal('TaxNet', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->string('discount_Method')->nullable();
            $table->decimal('discount_from_points', 15, 2)->nullable();
            $table->decimal('promotion_discount', 15, 2)->nullable();
            $table->string('promotion_code')->nullable();
            $table->decimal('shipping', 15, 2)->default(0);
            $table->decimal('GrandTotal', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('payment_statut')->nullable();
            $table->string('statut')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_details', function ($table) {
            $table->integer('id', true);
            $table->date('date')->nullable();
            $table->integer('sale_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->text('imei_number')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            $table->integer('sale_unit_id')->nullable();
            $table->decimal('TaxNet', 15, 2)->nullable();
            $table->string('tax_method')->nullable()->default('1');
            $table->decimal('discount', 15, 2)->nullable();
            $table->string('discount_method')->nullable()->default('2');
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->string('pack_name')->nullable();
            $table->decimal('pack_multiplier', 10, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('units', function ($table) {
            $table->integer('id', true);
            $table->string('name')->nullable();
            $table->string('ShortName')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function ($table) {
            $table->integer('id', true);
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_imei')->default(false);
            $table->integer('unit_sale_id')->nullable();
            $table->integer('unit_purchase_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function ($table) {
            $table->integer('id', true);
            $table->integer('product_id');
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_usages', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->integer('promotion_id')->nullable();
            $table->string('code')->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payment_sales', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->string('Ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sar_fiscal_documents', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id');
            $table->integer('authorization_id')->nullable();
            $table->string('sequence')->nullable();
            $table->string('fiscal_number')->nullable();
            $table->string('cai')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->integer('voided_by')->nullable();
            $table->text('issuer_snapshot')->nullable();
            $table->text('customer_snapshot')->nullable();
            $table->text('sale_snapshot')->nullable();
            $table->timestamps();
        });
    }
}
