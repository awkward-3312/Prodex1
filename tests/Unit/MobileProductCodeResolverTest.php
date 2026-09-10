<?php

namespace Tests\Unit;

use App\Exceptions\Mobile\MobileProductResolveException;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Mobile\MobileProductCodeResolver;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileProductCodeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createResolverSchema();
    }

    public function test_resolves_simple_product_by_code(): void
    {
        $product = $this->product(['code' => 'ABC123', 'price' => 148.5]);
        $this->stock($product->id, null, 10, 2);

        $data = $this->resolver()->resolve('ABC123', 1, 'code128');

        $this->assertSame('code', $data['match']['field']);
        $this->assertSame('product', $data['match']['type']);
        $this->assertSame($product->id, $data['product']['id']);
        $this->assertNull($data['product']['variant_id']);
        $this->assertSame('148.50', $data['pricing']['price']);
        $this->assertSame(8.0, $data['inventory']['available_quantity']);
    }

    public function test_resolves_variant_by_code_without_losing_variant_identity(): void
    {
        $product = $this->product(['code' => 'PARENT']);
        $variant = $this->variant($product, ['code' => 'VAR-500', 'name' => '500 g', 'price' => 77.25]);
        $this->stock($product->id, $variant->id, 5, 0);

        $data = $this->resolver()->resolve('VAR-500', 1);

        $this->assertSame('product_variant', $data['match']['type']);
        $this->assertSame($product->id, $data['product']['product_id']);
        $this->assertSame($variant->id, $data['product']['product_variant_id']);
        $this->assertSame('500 g', $data['product']['variant_name']);
        $this->assertSame('77.25', $data['pricing']['price']);
    }

    public function test_resolves_product_and_variant_by_gtin_as_fallback(): void
    {
        $product = $this->product(['code' => 'SKU-1', 'gtin' => '7501234567890']);
        $parent = $this->product(['code' => 'SKU-2']);
        $variant = $this->variant($parent, ['code' => 'VAR-1', 'gtin' => '00012345678905']);

        $byProductGtin = $this->resolver()->resolve('7501234567890', 1);
        $byVariantGtin = $this->resolver()->resolve('00012345678905', 1);

        $this->assertSame($product->id, $byProductGtin['product']['id']);
        $this->assertSame('gtin', $byProductGtin['match']['field']);
        $this->assertSame($variant->id, $byVariantGtin['product']['variant_id']);
        $this->assertSame('gtin', $byVariantGtin['match']['field']);
    }

    public function test_code_has_priority_over_gtin(): void
    {
        $codeProduct = $this->product(['code' => 'MATCH-ME']);
        $this->product(['code' => 'OTHER', 'gtin' => 'MATCH-ME']);

        $data = $this->resolver()->resolve('MATCH-ME', 1);

        $this->assertSame($codeProduct->id, $data['product']['id']);
        $this->assertSame('code', $data['match']['field']);
    }

    public function test_duplicate_code_or_gtin_returns_ambiguous_code(): void
    {
        $this->product(['code' => 'DUP']);
        $this->product(['code' => 'DUP']);

        try {
            $this->resolver()->resolve('DUP', 1);
            $this->fail('Expected ambiguous code exception.');
        } catch (MobileProductResolveException $e) {
            $this->assertSame('ambiguous_code', $e->errorCode());
            $this->assertSame(409, $e->statusCode());
        }

        $this->product(['code' => 'A', 'gtin' => 'GTIN-DUP']);
        $this->product(['code' => 'B', 'gtin' => 'GTIN-DUP']);

        try {
            $this->resolver()->resolve('GTIN-DUP', 1);
            $this->fail('Expected ambiguous gtin exception.');
        } catch (MobileProductResolveException $e) {
            $this->assertSame('ambiguous_code', $e->errorCode());
        }
    }

    public function test_not_found_deleted_parent_and_inactive_product_do_not_resolve(): void
    {
        $deleted = $this->product(['code' => 'DELETED']);
        $deleted->forceFill(['deleted_at' => now()])->save();
        $this->variant($deleted, ['code' => 'DELETED-VAR']);
        $this->product(['code' => 'INACTIVE', 'is_active' => 0]);

        foreach (['NOPE', 'DELETED-VAR', 'INACTIVE'] as $value) {
            try {
                $this->resolver()->resolve($value, 1);
                $this->fail("Expected product_not_found for {$value}.");
            } catch (MobileProductResolveException $e) {
                $this->assertSame('product_not_found', $e->errorCode());
                $this->assertSame(404, $e->statusCode());
            }
        }
    }

    public function test_preserves_leading_zeroes_special_characters_and_trims_exterior_spaces(): void
    {
        $leading = $this->product(['code' => '00012345']);
        $special = $this->product(['code' => 'A/B C-#1']);

        $this->assertSame($leading->id, $this->resolver()->resolve('  00012345  ', 1)['product']['id']);
        $this->assertSame($special->id, $this->resolver()->resolve('A/B C-#1', 1)['product']['id']);
    }

    public function test_scanner_type_does_not_block_match_when_symbology_differs(): void
    {
        $product = $this->product(['code' => '012345678905', 'Type_barcode' => 'UPC']);

        $data = $this->resolver()->resolve('012345678905', 1, 'ean13');

        $this->assertSame($product->id, $data['product']['id']);
        $this->assertSame('ean13', $data['match']['scanner_type']);
        $this->assertSame('UPC', $data['product']['barcode_symbology']);
    }

    public function test_stock_reserved_manage_stock_service_low_stock_and_overselling(): void
    {
        $product = $this->product(['code' => 'LOW', 'stock_alert' => 5]);
        $this->stock($product->id, null, 5, 2);

        $data = $this->resolver()->resolve('LOW', 1);

        $this->assertSame(3.0, $data['inventory']['available_quantity']);
        $this->assertTrue($data['inventory']['low_stock']);
        $this->assertTrue($data['inventory']['manage_stock']);
        $this->assertTrue($data['sellability']['can_sell']);

        $service = $this->product(['code' => 'SERV', 'type' => 'is_service']);
        $serviceData = $this->resolver()->resolve('SERV', 1);
        $this->assertFalse($serviceData['inventory']['manage_stock']);
        $this->assertTrue($serviceData['sellability']['can_sell']);

        $empty = $this->product(['code' => 'EMPTY']);
        $this->stock($empty->id, null, 0, 0);
        $emptyData = $this->resolver()->resolve('EMPTY', 1);
        $this->assertTrue($emptyData['inventory']['out_of_stock']);
        $this->assertFalse($emptyData['sellability']['can_sell']);

        $this->setOverselling(true);
        $oversoldData = $this->resolver()->resolve('EMPTY', 1);
        $this->assertTrue($oversoldData['inventory']['out_of_stock']);
        $this->assertTrue($oversoldData['sellability']['can_sell']);
    }

    public function test_weighted_code_uses_pos_indexes_after_exact_matches(): void
    {
        $weighted = $this->product(['code' => '1234567']);
        $exact = $this->product(['code' => '1234567073500']);

        $exactData = $this->resolver()->resolve('1234567073500', 1);
        $this->assertSame($exact->id, $exactData['product']['id']);
        $this->assertFalse($exactData['match']['weighted']);

        $this->assertSame($weighted->id, $this->resolver()->resolve('1234567073501', 1)['product']['id']);
        $weightedData = $this->resolver()->resolve('1234567073501', 1);
        $this->assertTrue($weightedData['match']['weighted']);
        $this->assertSame('1234567', $weightedData['match']['base_code']);
        $this->assertSame(7.35, $weightedData['scan_quantity']);
    }

    private function resolver(): MobileProductCodeResolver
    {
        return app(MobileProductCodeResolver::class);
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

    private function stock(int $productId, ?int $variantId, float $quantity, float $reserved): void
    {
        InventoryLocationStock::create([
            'inventory_location_id' => 1,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'variant_key' => $variantId ?: 0,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'manage_stock' => true,
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

    private function createResolverSchema(): void
    {
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
