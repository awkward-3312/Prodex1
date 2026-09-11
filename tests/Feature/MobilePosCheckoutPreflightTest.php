<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobilePosCheckoutContextController;
use App\Http\Controllers\Mobile\MobilePosClientsController;
use App\Http\Controllers\Mobile\MobilePosSalePreflightController;
use App\Http\Controllers\Mobile\MobilePosSaleSubmitController;
use App\Models\Branch;
use App\Models\CashDrawer;
use App\Models\Client;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobilePosCheckoutPreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();

        Route::middleware('auth:api')->get('/api/mobile/pos/checkout-context-test', MobilePosCheckoutContextController::class);
        Route::middleware('auth:api')->get('/api/mobile/pos/clients-test', MobilePosClientsController::class);
        Route::middleware('auth:api')->post('/api/mobile/pos/sale-preflight-test', MobilePosSalePreflightController::class);
        Route::middleware('auth:api')->post('/api/mobile/sales-test', MobilePosSaleSubmitController::class);

        Gate::before(fn ($user = null) => true);
        Queue::fake();
    }

    public function test_real_routes_are_inside_mobile_pos_feature_group(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_api.php'));

        $this->assertMatchesRegularExpression(
            "/tenant\\.feature:pos.*mobile\\/pos\\/checkout-context.*mobile\\/pos\\/clients.*mobile\\/pos\\/sale-preflight/s",
            $routes
        );
    }

    public function test_checkout_context_requires_auth_and_returns_ready_context(): void
    {
        $setup = $this->readySetup();
        $this->getJson('/api/mobile/pos/checkout-context-test')->assertStatus(401);

        $response = $this->actingAs($setup['user'], 'api')
            ->getJson('/api/mobile/pos/checkout-context-test')
            ->assertOk();

        $response
            ->assertJsonPath('data.operational_context.ready_for_location_pos', true)
            ->assertJsonPath('data.operational_context.branch.id', $setup['branch']->id)
            ->assertJsonPath('data.operational_context.inventory_location.id', $setup['location']->id)
            ->assertJsonPath('data.operational_context.cash_drawer.id', $setup['drawer']->id)
            ->assertJsonPath('data.customer.default.id', $setup['client']->id)
            ->assertJsonPath('data.payment_methods.0.is_cash', true)
            ->assertJsonPath('data.accounts.0.id', $setup['accountId'])
            ->assertJsonPath('data.tax.country', 'HN')
            ->assertJsonPath('data.currency.code', 'HNL')
            ->assertJsonPath('data.capabilities.can_create_sale', true)
            ->assertJsonPath('data.capabilities.overselling', false)
            ->assertJsonPath('data.capabilities.manual_price', false)
            ->assertJsonPath('data.capabilities.packs', false)
            ->assertJsonPath('data.capabilities.weighted_quantity', true);
    }

    public function test_checkout_context_reports_incomplete_context_without_inventing_drawer(): void
    {
        $branch = $this->branch();
        $location = $this->location($branch);
        $user = $this->user([
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $location->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/checkout-context-test')
            ->assertOk()
            ->assertJsonPath('data.operational_context.ready_for_location_pos', false)
            ->assertJsonPath('data.operational_context.cash_drawer', null)
            ->assertJsonPath('data.capabilities.can_create_sale', false)
            ->assertJsonPath('data.capabilities.reason', 'operational_context_incomplete');
    }

    public function test_client_search_filters_by_name_phone_tax_and_excludes_deleted(): void
    {
        $user = $this->readySetup()['user'];
        $matchName = $this->client(['name' => 'Ana Rivera', 'phone' => '111', 'tax_number' => 'RTN-1']);
        $matchPhone = $this->client(['name' => 'Cliente Dos', 'phone' => '555-000', 'tax_number' => 'RTN-2']);
        $matchTax = $this->client(['name' => 'Cliente Tres', 'phone' => '333', 'tax_number' => 'TAX-MATCH']);
        $deleted = $this->client(['name' => 'Ana Borrada']);
        $deleted->forceFill(['deleted_at' => now()])->save();

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/clients-test?search=Ana')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $matchName->id)
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/clients-test?search=555')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $matchPhone->id);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/clients-test?search=TAX-MATCH&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $matchTax->id)
            ->assertJsonPath('data.pagination.per_page', 1);
    }

    public function test_sale_preflight_simple_variant_service_cash_change_and_no_side_effects(): void
    {
        $setup = $this->readySetup();
        $simple = $this->product(['name' => 'Cafe', 'price' => 100, 'TaxNet' => 15, 'tax_method' => '1']);
        $this->stock($setup['location']->id, $simple->id, null, 5, 0);
        $parent = $this->product(['name' => 'Camisa', 'is_variant' => 1, 'type' => 'is_variant']);
        $variant = $this->variant($parent, ['name' => 'Azul', 'price' => 50]);
        $this->stock($setup['location']->id, $parent->id, $variant->id, 2, 0);
        $service = $this->product(['name' => 'Instalacion', 'type' => 'is_service', 'price' => 25]);

        $before = $this->mutationSnapshot($setup['accountId'], $setup['client']->id, $setup['location']->id, $simple->id);

        $response = $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [
                ['product_id' => $simple->id, 'quantity' => '1'],
                ['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'quantity' => '1'],
                ['product_id' => $service->id, 'quantity' => '2'],
            ],
            'payment_intent' => [
                ['payment_method_id' => $setup['cashId'], 'account_id' => $setup['accountId'], 'amount' => '250.00'],
            ],
        ])->assertOk();

        $response
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.context.inventory_location_id', $setup['location']->id)
            ->assertJsonPath('data.lines.0.unit_price', '100.00')
            ->assertJsonPath('data.lines.0.tax.amount', '15.00')
            ->assertJsonPath('data.lines.0.subtotal', '115.00')
            ->assertJsonPath('data.lines.1.product_variant_id', $variant->id)
            ->assertJsonPath('data.lines.2.type', 'is_service')
            ->assertJsonPath('data.totals.grand_total', '215.00')
            ->assertJsonPath('data.payments.applied_total', '215.00')
            ->assertJsonPath('data.payments.change', '35.00')
            ->assertJsonPath('data.requirements.sale_uuid_required', true);

        $this->assertSame($before, $this->mutationSnapshot($setup['accountId'], $setup['client']->id, $setup['location']->id, $simple->id));
    }

    public function test_sale_preflight_rejects_client_totals_and_prices_as_authority(): void
    {
        $setup = $this->readySetup();
        $product = $this->product(['price' => 100]);
        $this->stock($setup['location']->id, $product->id, null, 5, 0);

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'GrandTotal' => '0.01',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => '1', 'Unit_price' => '0.01'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    }

    public function test_sale_preflight_blocks_insufficient_stock_even_when_overselling_is_enabled(): void
    {
        $setup = $this->readySetup();
        DB::table('pos_settings')->update(['allow_overselling' => true]);
        $product = $this->product(['price' => 10]);
        $this->stock($setup['location']->id, $product->id, null, 1, 0);

        $response = $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_intent' => [['payment_method_id' => $setup['cashId'], 'amount' => '20.00']],
        ])->assertOk();

        $response
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.errors.0.code', 'insufficient_stock')
            ->assertJsonPath('data.lines.0.inventory.overselling_allowed', false);
    }

    public function test_sale_preflight_blocks_combo_and_detects_serial_and_batch_requirements(): void
    {
        $setup = $this->readySetup();
        $combo = $this->product(['type' => 'is_combo', 'price' => 20]);
        $serial = $this->product(['is_imei' => 1, 'price' => 10]);
        $batch = $this->product(['is_batch_tracked' => 1, 'price' => 15]);
        $this->stock($setup['location']->id, $combo->id, null, 5, 0);
        $this->stock($setup['location']->id, $serial->id, null, 5, 0);
        $this->stock($setup['location']->id, $batch->id, null, 5, 0);

        $response = $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [
                ['product_id' => $combo->id, 'quantity' => '1'],
                ['product_id' => $serial->id, 'quantity' => '1'],
                ['product_id' => $batch->id, 'quantity' => '1'],
            ],
            'payment_intent' => [['payment_method_id' => $setup['cashId'], 'amount' => '100.00']],
        ])->assertOk();

        $codes = collect($response->json('data.errors'))->pluck('code')->all();
        $this->assertContains('combo_not_supported', $codes);
        $this->assertContains('serial_selection_required', $codes);
        $this->assertContains('batch_selection_required', $codes);
        $this->assertFalse($response->json('data.can_submit'));
    }

    public function test_mobile_sale_success_simple_cash_change_and_idempotent_retry(): void
    {
        $setup = $this->readySetup();
        $product = $this->product(['name' => 'Cafe', 'price' => 100, 'TaxNet' => 15, 'tax_method' => '1']);
        $this->stock($setup['location']->id, $product->id, null, 5, 0);
        $uuid = '123e4567-e89b-42d3-a456-426614174000';

        $payload = [
            'sale_uuid' => $uuid,
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '1.000']],
            'payments' => [['payment_method_id' => $setup['cashId'], 'account_id' => $setup['accountId'], 'amount' => '150.00']],
        ];

        $first = $this->actingAs($setup['user'], 'api')
            ->postJson('/api/mobile/sales-test', $payload)
            ->assertOk();

        $first
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.sale.sale_uuid', $uuid)
            ->assertJsonPath('data.sale.grand_total', '115.00')
            ->assertJsonPath('data.sale.payment_status', 'paid')
            ->assertJsonPath('data.stock.0.available_quantity', '4.000');

        $saleId = $first->json('data.sale.id');
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('sale_details')->count());
        $this->assertSame(1, DB::table('payment_sales')->count());
        $this->assertSame('4', (string) DB::table('inventory_location_stocks')->where('product_id', $product->id)->value('quantity'));
        $this->assertSame('215', (string) DB::table('accounts')->where('id', $setup['accountId'])->value('balance'));
        $this->assertSame('35', (string) DB::table('payment_sales')->value('change'));

        $retry = $this->actingAs($setup['user'], 'api')
            ->postJson('/api/mobile/sales-test', $payload)
            ->assertOk();

        $retry
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.sale.id', $saleId);
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('sale_details')->count());
        $this->assertSame(1, DB::table('payment_sales')->count());
        $this->assertSame('4', (string) DB::table('inventory_location_stocks')->where('product_id', $product->id)->value('quantity'));
    }

    public function test_mobile_sale_variant_weighted_and_service_stock_semantics(): void
    {
        $setup = $this->readySetup();
        $parent = $this->product(['name' => 'Queso', 'is_variant' => 1, 'type' => 'is_variant']);
        $variant = $this->variant($parent, ['name' => 'Libra', 'price' => 10]);
        $service = $this->product(['name' => 'Corte', 'type' => 'is_service', 'price' => 5]);
        $this->stock($setup['location']->id, $parent->id, $variant->id, 2, 0);

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/sales-test', [
            'sale_uuid' => '123e4567-e89b-42d3-a456-426614174001',
            'client_id' => $setup['client']->id,
            'lines' => [
                ['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'quantity' => '0.735'],
                ['product_id' => $service->id, 'quantity' => '1'],
            ],
            'payments' => [['payment_method_id' => $setup['cashId'], 'account_id' => $setup['accountId'], 'amount' => '20.00']],
        ])->assertOk()
            ->assertJsonPath('data.stock.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.stock.0.available_quantity', '1.265');

        $this->assertSame('0.735', number_format((float) DB::table('sale_details')->where('product_variant_id', $variant->id)->value('quantity'), 3, '.', ''));
        $this->assertSame('1.265', number_format((float) DB::table('inventory_location_stocks')->where('product_id', $parent->id)->where('variant_key', $variant->id)->value('quantity'), 3, '.', ''));
        $this->assertNull(DB::table('inventory_location_stocks')->where('product_id', $service->id)->value('id'));
    }

    public function test_mobile_sale_rejects_tampering_stock_and_unsupported_requirements(): void
    {
        $setup = $this->readySetup();
        $product = $this->product(['price' => 10]);
        $combo = $this->product(['type' => 'is_combo', 'price' => 10]);
        $serial = $this->product(['is_imei' => 1, 'price' => 10]);
        $batch = $this->product(['is_batch_tracked' => 1, 'price' => 10]);
        foreach ([$product, $combo, $serial, $batch] as $item) {
            $this->stock($setup['location']->id, $item->id, null, 1, 0);
        }

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/sales-test', [
            'sale_uuid' => 'not-a-uuid',
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['payment_method_id' => $setup['cashId'], 'amount' => '10.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'sale_uuid_invalid');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/sales-test', [
            'sale_uuid' => '123e4567-e89b-42d3-a456-426614174002',
            'client_id' => $setup['client']->id,
            'warehouse_id' => 999,
            'inventory_location_id' => 999,
            'cash_drawer_id' => 999,
            'GrandTotal' => '0.01',
            'lines' => [['product_id' => $product->id, 'quantity' => '1', 'Unit_price' => '0.01']],
            'payments' => [['payment_method_id' => $setup['cashId'], 'amount' => '10.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/sales-test', [
            'sale_uuid' => '123e4567-e89b-42d3-a456-426614174003',
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '2']],
            'payments' => [['payment_method_id' => $setup['cashId'], 'amount' => '20.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'insufficient_stock');

        foreach ([[$combo, 'combo_not_supported'], [$serial, 'serial_selection_required'], [$batch, 'batch_selection_required']] as [$item, $code]) {
            $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/sales-test', [
                'sale_uuid' => '123e4567-e89b-42d3-a456-'.str_pad((string) $item->id, 12, '0', STR_PAD_LEFT),
                'client_id' => $setup['client']->id,
                'lines' => [['product_id' => $item->id, 'quantity' => '1']],
                'payments' => [['payment_method_id' => $setup['cashId'], 'amount' => '10.00']],
            ])->assertStatus(422)->assertJsonPath('error.code', $code);
        }
    }

    public function test_sale_preflight_validates_client_product_variant_quantity_payment_and_account(): void
    {
        $setup = $this->readySetup();
        $parent = $this->product(['is_variant' => 1, 'type' => 'is_variant']);
        $other = $this->product();
        $wrongVariant = $this->variant($other);

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => 999,
            'lines' => [['product_id' => $parent->id, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_client');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => 999, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'product_not_found');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $parent->id, 'product_variant_id' => $wrongVariant->id, 'quantity' => '1']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'variant_not_found');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $other->id, 'quantity' => '0']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_quantity');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $other->id, 'quantity' => '1']],
            'payment_intent' => [['payment_method_id' => 999, 'amount' => '10.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_payment_method');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $other->id, 'quantity' => '1']],
            'payment_intent' => [['payment_method_id' => $setup['cashId'], 'account_id' => 999, 'amount' => '10.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_account');
    }

    public function test_decimal_quantity_is_supported_to_three_places_but_not_more(): void
    {
        $setup = $this->readySetup();
        $product = $this->product(['price' => 10]);
        $this->stock($setup['location']->id, $product->id, null, 1, 0);

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '0.735']],
            'payment_intent' => [['payment_method_id' => $setup['cashId'], 'amount' => '7.35']],
        ])
            ->assertOk()
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.lines.0.quantity', '0.735');

        $this->actingAs($setup['user'], 'api')->postJson('/api/mobile/pos/sale-preflight-test', [
            'client_id' => $setup['client']->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '0.7351']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_quantity');
    }

    private function readySetup(): array
    {
        $client = $this->client(['name' => 'Cliente Default']);
        $cashId = $this->paymentMethod('Efectivo');
        $this->paymentMethod('Tarjeta');
        $accountId = $this->account('Caja principal', 100);
        $this->settings($client->id, $accountId, $cashId);

        $branch = $this->branch();
        $location = $this->location($branch);
        $drawer = $this->drawer($branch, $location);
        $user = $this->user([
            'default_branch_id' => $branch->id,
            'default_inventory_location_id' => $location->id,
            'default_cash_drawer_id' => $drawer->id,
        ]);

        return compact('client', 'cashId', 'accountId', 'branch', 'location', 'drawer', 'user');
    }

    private function mutationSnapshot(int $accountId, int $clientId, int $locationId, int $productId): array
    {
        return [
            'sales' => DB::table('sales')->count(),
            'sale_details' => DB::table('sale_details')->count(),
            'payment_sales' => DB::table('payment_sales')->count(),
            'inventory_location_movements' => DB::table('inventory_location_movements')->count(),
            'product_batch_location_movements' => DB::table('product_batch_location_movements')->count(),
            'product_serial_movements' => DB::table('product_serial_movements')->count(),
            'store_credit_voucher_transactions' => DB::table('store_credit_voucher_transactions')->count(),
            'promotion_usages' => DB::table('promotion_usages')->count(),
            'sar_fiscal_documents' => DB::table('sar_fiscal_documents')->count(),
            'account_balance' => (string) DB::table('accounts')->whereKey($accountId)->value('balance'),
            'client_points' => (string) DB::table('clients')->whereKey($clientId)->value('points'),
            'stock_quantity' => (string) DB::table('inventory_location_stocks')
                ->where('inventory_location_id', $locationId)
                ->where('product_id', $productId)
                ->value('quantity'),
        ];
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
        ], $overrides))->save();

        return $user;
    }

    private function branch(array $overrides = []): Branch
    {
        return Branch::create(array_merge([
            'code' => uniqid('BR-', false),
            'name' => 'Sucursal',
            'default_warehouse_id' => null,
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
            'is_default_sales' => true,
            'is_quarantine' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function drawer(Branch $branch, InventoryLocation $location): CashDrawer
    {
        return CashDrawer::create([
            'branch_id' => $branch->id,
            'inventory_location_id' => $location->id,
            'warehouse_id' => null,
            'code' => uniqid('CAJA-', false),
            'name' => 'Caja',
            'is_active' => true,
        ]);
    }

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'name' => uniqid('Cliente ', false),
            'code' => random_int(1000, 9999),
            'phone' => '9999-0000',
            'tax_number' => '0801',
            'points' => 50,
            'is_royalty_eligible' => true,
        ], $overrides));
    }

    private function product(array $overrides = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'type' => 'is_single',
            'name' => uniqid('Producto ', false),
            'code' => uniqid('SKU-', false),
            'gtin' => null,
            'Type_barcode' => 'CODE128',
            'cost' => 10,
            'price' => 20,
            'wholesale_price' => 0,
            'min_price' => 0,
            'unit_sale_id' => 1,
            'TaxNet' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_method' => '2',
            'stock_alert' => 0,
            'is_variant' => 0,
            'is_imei' => 0,
            'is_batch_tracked' => 0,
            'is_active' => 1,
            'not_selling' => 0,
            'image' => 'no-image.png',
        ], $overrides))->save();

        return $product;
    }

    private function variant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'name' => 'Variant',
            'cost' => 10,
            'price' => 30,
            'wholesale' => 0,
            'min_price' => 0,
            'code' => uniqid('VAR-', false),
            'gtin' => null,
            'image' => 'no-image.png',
        ], $overrides));
    }

    private function stock(int $locationId, int $productId, ?int $variantId, float $quantity, float $reserved): void
    {
        DB::table('inventory_location_stocks')->insert([
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_key' => $variantId ?: 0,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'manage_stock' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function paymentMethod(string $name): int
    {
        return (int) DB::table('payment_methods')->insertGetId([
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(string $name, float $balance): int
    {
        return (int) DB::table('accounts')->insertGetId([
            'account_num' => uniqid('ACC-', false),
            'account_name' => $name,
            'initial_balance' => $balance,
            'balance' => $balance,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settings(int $clientId, int $accountId, int $paymentMethodId): void
    {
        DB::table('currencies')->insert([
            'id' => 1,
            'code' => 'HNL',
            'name' => 'Lempira',
            'symbol' => 'L',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->insert([
            'email' => 'test@example.test',
            'currency_id' => 1,
            'CompanyName' => 'PRODEX',
            'CompanyPhone' => '2222-0000',
            'CompanyAdress' => 'TGU',
            'client_id' => $clientId,
            'default_account_id' => $accountId,
            'default_payment_method_id' => $paymentMethodId,
            'default_language' => 'es',
            'country_code' => 'HN',
            'tax_rate' => 15,
            'locale' => 'es-HN',
            'point_to_amount_rate' => 1,
            'enable_3_decimal_pricing' => false,
            'zatca_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'product_serials',
            'product_batches',
            'product_batch_location_stocks',
            'payment_with_credit_card',
            'sar_fiscal_profiles',
            'sar_fiscal_documents',
            'promotion_usages',
            'store_credit_voucher_transactions',
            'product_serial_movements',
            'product_batch_location_movements',
            'inventory_location_movements',
            'payment_sales',
            'sale_details',
            'sales',
            'pos_settings',
            'inventory_location_stocks',
            'product_variants',
            'products',
            'product_warehouse',
            'warehouses',
            'payment_settings',
            'payment_methods',
            'accounts',
            'settings',
            'currencies',
            'clients',
            'units',
            'permission_role',
            'role_user',
            'permissions',
            'roles',
            'cash_drawers',
            'inventory_locations',
            'branches',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->softDeletes();
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
        DB::table('units')->insert(['id' => 1, 'ShortName' => 'u', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('clients', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->integer('code')->nullable();
            $table->string('phone')->nullable();
            $table->string('tax_number')->nullable();
            $table->boolean('is_royalty_eligible')->default(true);
            $table->float('points')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('currencies', function ($table) {
            $table->integer('id', true);
            $table->string('code');
            $table->string('name');
            $table->string('symbol');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('settings', function ($table) {
            $table->integer('id', true);
            $table->string('email')->nullable();
            $table->integer('currency_id')->nullable();
            $table->string('CompanyName')->nullable();
            $table->string('CompanyPhone')->nullable();
            $table->string('CompanyAdress')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('default_account_id')->nullable();
            $table->integer('default_payment_method_id')->nullable();
            $table->string('default_language')->nullable();
            $table->string('country_code', 3)->nullable();
            $table->decimal('tax_rate', 10, 2)->nullable();
            $table->string('locale')->nullable();
            $table->float('point_to_amount_rate')->default(1);
            $table->boolean('enable_3_decimal_pricing')->default(false);
            $table->boolean('zatca_enabled')->default(false);
            $table->boolean('enable_kitchen_display')->default(false);
            $table->string('sale_prefix', 10)->nullable()->default('SL');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accounts', function ($table) {
            $table->integer('id', true);
            $table->string('account_num');
            $table->string('account_name');
            $table->decimal('initial_balance', 15)->default(0);
            $table->decimal('balance', 15)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payment_methods', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_settings', function ($table) {
            $table->integer('id', true);
            $table->string('stripe_key')->nullable();
            $table->string('stripe_secret')->nullable();
            $table->string('card_processing_mode')->nullable()->default('external_terminal');
            $table->timestamps();
        });
        DB::table('payment_settings')->insert(['card_processing_mode' => 'external_terminal', 'created_at' => now(), 'updated_at' => now()]);

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
            $table->integer('unit_sale_id')->nullable();
            $table->decimal('TaxNet', 15)->nullable()->default(0);
            $table->string('tax_method', 192)->nullable()->default('1');
            $table->decimal('discount', 15)->nullable()->default(0);
            $table->string('discount_method', 192)->nullable()->default('2');
            $table->decimal('stock_alert', 15)->nullable()->default(0);
            $table->boolean('is_variant')->default(false);
            $table->boolean('is_imei')->default(false);
            $table->boolean('is_batch_tracked')->default(false);
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

        Schema::create('warehouses', function ($table) {
            $table->integer('id', true);
            $table->integer('branch_id')->nullable();
            $table->integer('default_inventory_location_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_warehouse', function ($table) {
            $table->integer('id', true);
            $table->integer('product_id');
            $table->integer('warehouse_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('qte', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_settings', function ($table) {
            $table->integer('id', true);
            $table->boolean('allow_overselling')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('pos_settings')->insert(['allow_overselling' => false, 'created_at' => now(), 'updated_at' => now()]);

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
            $table->decimal('tax_rate', 15, 3)->nullable()->default(0);
            $table->decimal('TaxNet', 15, 3)->nullable()->default(0);
            $table->decimal('discount', 15, 3)->nullable()->default(0);
            $table->string('discount_Method', 10)->default('2');
            $table->decimal('shipping', 15, 3)->nullable()->default(0);
            $table->decimal('GrandTotal', 15, 3)->default(0);
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->string('payment_statut')->nullable();
            $table->string('statut')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('used_points', 15, 3)->default(0);
            $table->decimal('earned_points', 15, 3)->default(0);
            $table->decimal('discount_from_points', 15, 3)->default(0);
            $table->decimal('promotion_discount', 15, 3)->default(0);
            $table->string('promotion_code')->nullable();
            $table->decimal('store_credit_amount', 15, 3)->default(0);
            $table->json('inventory_effect_snapshot')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->string('quickbooks_invoice_id')->nullable();
            $table->string('quickbooks_realm_id')->nullable();
            $table->timestamp('quickbooks_synced_at')->nullable();
            $table->text('quickbooks_sync_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sale_details', function ($table) {
            $table->integer('id', true);
            $table->date('date')->nullable();
            $table->integer('sale_id');
            $table->integer('sale_unit_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('total', 15, 3)->default(0);
            $table->decimal('price', 15, 3)->default(0);
            $table->decimal('TaxNet', 15, 3)->default(0);
            $table->string('tax_method')->nullable();
            $table->decimal('discount', 15, 3)->default(0);
            $table->string('discount_method')->nullable();
            $table->text('imei_number')->nullable();
            $table->string('price_type')->nullable();
            $table->integer('product_pack_id')->nullable();
            $table->decimal('pack_multiplier', 12, 3)->nullable();
            $table->string('pack_name')->nullable();
            $table->date('warranty_date')->nullable();
            $table->date('guarantee_date')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_sales', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id')->nullable();
            $table->integer('account_id')->nullable();
            $table->string('Ref')->nullable();
            $table->date('date')->nullable();
            $table->integer('payment_method_id')->nullable();
            $table->decimal('montant', 15, 3)->default(0);
            $table->decimal('change', 15, 3)->default(0);
            $table->text('notes')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('card_processor')->nullable();
            $table->string('card_reference')->nullable();
            $table->string('authorization_code')->nullable();
            $table->string('card_last4')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('inventory_location_movements', function ($table) {
            $table->integer('id', true);
            $table->string('movement_type')->nullable();
            $table->integer('product_id')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('idempotency_fingerprint')->nullable();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('product_batch_location_movements', function ($table) {
            $table->integer('id', true);
            $table->timestamps();
        });
        Schema::create('product_serial_movements', function ($table) {
            $table->integer('id', true);
            $table->timestamps();
        });
        Schema::create('store_credit_voucher_transactions', function ($table) {
            $table->integer('id', true);
            $table->timestamps();
        });
        Schema::create('promotion_usages', function ($table) {
            $table->integer('id', true);
            $table->timestamps();
        });
        Schema::create('sar_fiscal_documents', function ($table) {
            $table->integer('id', true);
            $table->integer('sale_id')->nullable();
            $table->string('fiscal_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
        Schema::create('sar_fiscal_profiles', function ($table) {
            $table->integer('id', true);
            $table->boolean('enabled')->default(false);
            $table->string('rtn')->nullable();
            $table->string('legal_name')->nullable();
            $table->text('head_office_address')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_with_credit_card', function ($table) {
            $table->integer('id', true);
            $table->integer('payment_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->string('customer_stripe_id')->nullable();
            $table->string('charge_id')->nullable();
            $table->timestamps();
        });
        Schema::create('product_batch_location_stocks', function ($table) {
            $table->integer('id', true);
            $table->integer('product_batch_id');
            $table->integer('inventory_location_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('product_batches', function ($table) {
            $table->integer('id', true);
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->string('status')->default('active');
            $table->decimal('qty', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_serials', function ($table) {
            $table->integer('id', true);
            $table->string('serial_number');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('inventory_location_id')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }
}
