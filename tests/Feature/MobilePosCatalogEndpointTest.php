<?php

namespace Tests\Feature;

use App\Http\Controllers\Mobile\MobilePosCatalogController;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Mobile\MobileProductCodeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobilePosCatalogEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createCatalogSchema();

        Route::middleware('auth:api')->get(
            '/api/mobile/pos/catalog-test',
            MobilePosCatalogController::class
        );

        Gate::before(fn ($user = null) => true);
    }

    public function test_requires_authentication_and_inventory_location(): void
    {
        $this->getJson('/api/mobile/pos/catalog-test?inventory_location_id=1')
            ->assertStatus(401);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    public function test_real_tenant_route_is_inside_pos_feature_group(): void
    {
        $routes = file_get_contents(base_path('routes/tenant_api.php'));

        $this->assertMatchesRegularExpression(
            "/Route::middleware\\('tenant\\.feature:pos'\\)->group\\(function \\(\\) \\{.*mobile\\/products\\/resolve.*mobile\\/pos\\/catalog/s",
            $routes
        );
    }

    public function test_rejects_invalid_or_forbidden_location(): void
    {
        $allowed = $this->location(['code' => 'ALLOWED']);
        $forbidden = $this->location(['code' => 'FORBID']);
        $inactive = $this->location(['code' => 'OFF', 'is_active' => false]);
        $user = $this->user(['role_id' => 2, 'default_inventory_location_id' => $allowed->id]);

        $this->actingAs($user, 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$forbidden->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden_location');

        $this->actingAs($this->user(['role_id' => 1]), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$inactive->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_location');
    }

    public function test_returns_empty_catalog_with_categories_and_pagination(): void
    {
        $location = $this->location();

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id)
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.categories', [])
            ->assertJsonPath('data.pagination.page', 1)
            ->assertJsonPath('data.pagination.per_page', 30)
            ->assertJsonPath('data.pagination.total', 0)
            ->assertJsonPath('data.pagination.last_page', 1)
            ->assertJsonPath('data.pagination.has_more', false);
    }

    public function test_returns_simple_product_with_location_stock_price_category_and_unit(): void
    {
        $location = $this->location();
        $category = $this->category('Bebidas');
        $product = $this->product([
            'name' => 'Café molido',
            'code' => '000123',
            'gtin' => '7501234567890',
            'price' => 148.5,
            'category_id' => $category,
            'stock_alert' => 5,
        ]);
        $this->stock($location->id, $product->id, null, 10, 2);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id);

        $response->assertOk()
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_variant_id', null)
            ->assertJsonPath('data.items.0.display_name', 'Café molido')
            ->assertJsonPath('data.items.0.code', '000123')
            ->assertJsonPath('data.items.0.gtin', '7501234567890')
            ->assertJsonPath('data.items.0.pricing.price', '148.50')
            ->assertJsonPath('data.items.0.inventory.available_quantity', 8)
            ->assertJsonPath('data.items.0.inventory.low_stock', false)
            ->assertJsonPath('data.items.0.sellability.can_sell', true)
            ->assertJsonPath('data.items.0.category.name', 'Bebidas')
            ->assertJsonPath('data.items.0.image_url', null)
            ->assertJsonPath('data.items.0.unit', 'u')
            ->assertJsonPath('data.categories.0.name', 'Bebidas');
    }

    public function test_returns_one_row_per_variant_and_never_the_variant_parent(): void
    {
        $location = $this->location();
        $parent = $this->product(['name' => 'Camisa', 'code' => 'CAM-PARENT', 'is_variant' => 1, 'price' => 100]);
        $small = $this->variant($parent, ['name' => 'S', 'code' => 'CAM-S', 'price' => 90]);
        $large = $this->variant($parent, ['name' => 'L', 'code' => 'CAM-L', 'price' => 110]);
        $this->stock($location->id, $parent->id, $small->id, 3, 1);
        $this->stock($location->id, $parent->id, $large->id, 7, 0);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id);

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.pagination.total', 2);

        $items = collect($response->json('data.items'))->keyBy('code');
        $this->assertSame($small->id, $items['CAM-S']['product_variant_id']);
        $this->assertSame('Camisa · S', $items['CAM-S']['display_name']);
        $this->assertSame('90.00', $items['CAM-S']['pricing']['price']);
        $this->assertEquals(2.0, $items['CAM-S']['inventory']['available_quantity']);
        $this->assertSame($large->id, $items['CAM-L']['product_variant_id']);
    }

    public function test_filters_by_search_category_and_preserves_leading_zeroes(): void
    {
        $location = $this->location();
        $bebidas = $this->category('Bebidas');
        $otros = $this->category('Otros');
        $this->product(['name' => 'Agua', 'code' => '000001', 'gtin' => 'GTIN-A', 'category_id' => $bebidas]);
        $this->product(['name' => 'Galleta', 'code' => 'SNACK-1', 'category_id' => $otros]);
        $parent = $this->product(['name' => 'Zapato', 'code' => 'ZAP', 'category_id' => $bebidas, 'is_variant' => 1]);
        $this->variant($parent, ['name' => 'Azul', 'code' => '000VAR']);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&search=000')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&search=Agua')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&search=GTIN-A')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&category_id='.$otros);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Galleta');
    }

    public function test_excludes_inactive_deleted_and_not_selling_products(): void
    {
        $location = $this->location();
        $visible = $this->product(['name' => 'Visible', 'code' => 'VISIBLE']);
        $this->product(['name' => 'Inactive', 'code' => 'INACTIVE', 'is_active' => 0]);
        $this->product(['name' => 'Not selling', 'code' => 'NOSELL', 'not_selling' => 1]);
        $deleted = $this->product(['name' => 'Deleted', 'code' => 'DELETED']);
        $deleted->forceFill(['deleted_at' => now()])->save();

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_id', $visible->id);
    }

    public function test_service_and_overselling_follow_scanner_sellability_semantics(): void
    {
        $location = $this->location();
        $service = $this->product(['type' => 'is_service', 'name' => 'Instalación', 'code' => 'SERV']);
        $empty = $this->product(['name' => 'Sin stock', 'code' => 'EMPTY']);
        $this->stock($location->id, $empty->id, null, 0, 0);

        $response = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id);

        $items = collect($response->json('data.items'))->keyBy('code');
        $this->assertTrue($items['SERV']['sellability']['can_sell']);
        $this->assertFalse($items['SERV']['inventory']['manage_stock']);
        $this->assertFalse($items['EMPTY']['sellability']['can_sell']);

        $this->setOverselling(true);
        $overselling = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id);

        $this->assertTrue(collect($overselling->json('data.items'))->keyBy('code')['EMPTY']['sellability']['can_sell']);
    }

    public function test_paginates_with_max_per_page_and_does_not_duplicate_items(): void
    {
        $location = $this->location();
        for ($i = 1; $i <= 55; $i++) {
            $this->product(['name' => sprintf('Producto %02d', $i), 'code' => sprintf('P%02d', $i)]);
        }

        $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&per_page=51')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');

        $first = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&per_page=50&page=1');
        $second = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&per_page=50&page=2');

        $first->assertOk()
            ->assertJsonCount(50, 'data.items')
            ->assertJsonPath('data.pagination.per_page', 50)
            ->assertJsonPath('data.pagination.total', 55)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.has_more', true);

        $second->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination.has_more', false);

        $codes = collect($first->json('data.items'))->pluck('code')
            ->merge(collect($second->json('data.items'))->pluck('code'));
        $this->assertCount(55, $codes->unique());
    }

    public function test_catalog_item_matches_product_code_resolver_for_same_product_and_location(): void
    {
        $location = $this->location();
        $product = $this->product(['name' => 'Café', 'code' => 'MATCH', 'price' => 30, 'stock_alert' => 10]);
        $this->stock($location->id, $product->id, null, 6, 2);

        $catalog = $this->actingAs($this->user(), 'api')
            ->getJson('/api/mobile/pos/catalog-test?inventory_location_id='.$location->id.'&search=MATCH')
            ->json('data.items.0');

        $resolved = app(MobileProductCodeResolver::class)->resolve('MATCH', $location->id);

        $this->assertSame($resolved['pricing'], $catalog['pricing']);
        $this->assertEquals($resolved['inventory'], $catalog['inventory']);
        $this->assertSame($resolved['sellability'], $catalog['sellability']);
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

    private function category(string $name): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => $name,
            'code' => uniqid('CAT-', false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'type' => 'is_single',
            'name' => 'Café',
            'code' => uniqid('SKU-', false),
            'gtin' => null,
            'Type_barcode' => 'CODE128',
            'cost' => 10,
            'price' => 20,
            'wholesale_price' => 0,
            'min_price' => 0,
            'category_id' => null,
            'unit_sale_id' => 1,
            'TaxNet' => 0,
            'tax_method' => '1',
            'discount' => 0,
            'discount_method' => '2',
            'stock_alert' => 0,
            'is_variant' => 0,
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

    private function setOverselling(bool $enabled): void
    {
        \DB::table('pos_settings')->delete();
        \DB::table('pos_settings')->insert([
            'allow_overselling' => $enabled,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCatalogSchema(): void
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

        Schema::create('categories', function ($table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('code')->nullable();
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
            $table->decimal('qty')->nullable()->default(0);
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
        $this->setOverselling(false);
    }
}
