<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobileProductResolveController;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileProductResolveEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createEndpointSchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/products/resolve-test',
            MobileProductResolveController::class
        );

        Gate::before(fn ($user = null) => true);
    }

    public function test_endpoint_resolves_product_for_authenticated_user_with_location_scope(): void
    {
        $location = $this->location();
        $user = $this->user(['role_id' => 1]);
        $product = $this->product(['code' => 'KEEP INTERNAL SPACES']);
        $this->stock($location->id, $product->id, null, 12, 3);

        $response = $this->actingAs($user, 'api')->getJson(
            '/api/mobile/products/resolve-test?value='.urlencode('  KEEP INTERNAL SPACES  ').'&inventory_location_id='.$location->id.'&scanner_type=ean13'
        );

        $response->assertOk()
            ->assertJsonPath('data.match.field', 'code')
            ->assertJsonPath('data.match.scanner_type', 'ean13')
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.inventory.available_quantity', 9)
            ->assertJsonPath('data.sellability.can_sell', true);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $location = $this->location();

        $this->getJson('/api/mobile/products/resolve-test?value=ABC&inventory_location_id='.$location->id)
            ->assertStatus(401);
    }

    public function test_endpoint_returns_typed_validation_errors(): void
    {
        $user = $this->user(['role_id' => 1]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=%20%20%20&inventory_location_id=1')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_scan_value');

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=ABC')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    public function test_endpoint_rejects_location_outside_user_scope_and_invalid_location(): void
    {
        $allowed = $this->location(['code' => 'ALLOWED']);
        $forbidden = $this->location(['code' => 'FORBID']);
        $user = $this->user(['role_id' => 2, 'default_inventory_location_id' => $allowed->id]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=ABC&inventory_location_id='.$forbidden->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden_location');

        $owner = $this->user(['role_id' => 1]);
        $inactive = $this->location(['code' => 'OFF', 'is_active' => false]);

        $this->actingAs($owner, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=ABC&inventory_location_id='.$inactive->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    public function test_endpoint_returns_not_found_and_ambiguous_code(): void
    {
        $location = $this->location();
        $user = $this->user(['role_id' => 1]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=NOPE&inventory_location_id='.$location->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'product_not_found');

        $this->product(['code' => 'DUP']);
        $this->product(['code' => 'DUP']);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/products/resolve-test?value=DUP&inventory_location_id='.$location->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ambiguous_code');
    }

    private function user(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'firstname' => 'Mobile',
            'lastname' => 'Cashier',
            'email' => uniqid('mobile-', false).'@example.test',
            'password' => bcrypt('secret'),
            'statut' => 1,
            'role_id' => 1,
            'default_inventory_location_id' => null,
        ], $overrides))->save();

        return $user;
    }

    private function location(array $overrides = []): InventoryLocation
    {
        return InventoryLocation::create(array_merge([
            'branch_id' => 1,
            'warehouse_id' => null,
            'code' => uniqid('PISO-', false),
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_default_sales' => true,
            'is_quarantine' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'type' => 'is_single',
            'name' => 'Café',
            'code' => uniqid('SKU-', false),
            'gtin' => null,
            'Type_barcode' => 'CODE128',
            'cost' => 10,
            'price' => 20,
            'wholesale_price' => 0,
            'min_price' => 0,
            'category_id' => 1,
            'unit_sale_id' => 1,
            'TaxNet' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_method' => '2',
            'stock_alert' => 0,
            'is_active' => 1,
            'not_selling' => 0,
        ], $overrides));
    }

    private function stock(int $locationId, int $productId, ?int $variantId, float $quantity, float $reserved): void
    {
        \DB::table('inventory_location_stocks')->insert([
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

    private function createEndpointSchema(): void
    {
        Schema::create('users', function ($table) {
            $table->integer('id', true);
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->integer('statut')->default(1);
            $table->integer('role_id')->default(1);
            $table->integer('default_inventory_location_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('units', function ($table) {
            $table->integer('id', true);
            $table->string('ShortName')->nullable();
            $table->timestamps();
        });
        \DB::table('units')->insert(['id' => 1, 'ShortName' => 'u', 'created_at' => now(), 'updated_at' => now()]);

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
            $table->boolean('is_active')->default(true);
            $table->boolean('not_selling')->default(false);
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
        \DB::table('pos_settings')->insert([
            'allow_overselling' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
