<?php

namespace Tests\Unit;

use App\Models\InventoryLocation;
use App\Models\InventoryLocationStock;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\InventoryService;
use App\Services\PosLocationSaleStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosLocationSaleStockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('units', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('ShortName')->nullable();
            $table->integer('base_unit')->nullable();
            $table->string('operator')->nullable();
            $table->decimal('operator_value', 12, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type')->default('is_single');
            $table->integer('unit_sale_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_locations', function ($table) {
            $table->increments('id');
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('sales_floor');
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inventory_location_stocks', function ($table) {
            $table->increments('id');
            $table->integer('inventory_location_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('variant_key')->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->boolean('manage_stock')->default(true);
            $table->timestamps();
            $table->unique(['inventory_location_id', 'product_id', 'variant_key'], 'ils_unique');
        });

        Schema::create('inventory_location_movements', function ($table) {
            $table->increments('id');
            $table->string('movement_type');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('from_inventory_location_id')->nullable();
            $table->integer('to_inventory_location_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->integer('user_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('idempotency_fingerprint', 64)->nullable();
            $table->string('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_sale_deducts_directly_from_location_without_warehouse_row(): void
    {
        $product = Product::create(['name' => 'Producto', 'type' => 'is_single']);
        $location = $this->location();
        app(InventoryService::class)->increase($location->id, $product->id, 10);

        $sale = new Sale();
        $sale->id = 501;
        $sale->branch_id = 10;
        $sale->inventory_location_id = $location->id;
        $sale->cash_drawer_id = 3;
        $sale->warehouse_id = 1;
        $sale->user_id = 9;
        $sale->sale_uuid = 'sale-test-501';

        $request = Request::create('/api/pos/create_pos', 'POST', [
            'details' => [[
                'product_id' => $product->id,
                'product_variant_id' => null,
                'product_type' => 'is_single',
                'quantity' => 3,
                'pack_multiplier' => 1,
            ]],
        ]);

        app(PosLocationSaleStockService::class)->apply($sale, $request);

        $this->assertSame(7.0, app(InventoryService::class)->available($location->id, $product->id));
        $this->assertTrue($request->attributes->get('prodex_location_stock_preapplied'));
        $this->assertSame(1, \DB::table('inventory_location_movements')->where('reference_type', 'pos_sale')->count());
    }

    public function test_pack_and_sale_unit_are_converted_to_base_quantity_once(): void
    {
        $unit = Unit::create([
            'name' => 'Caja de 2',
            'ShortName' => 'cj',
            'operator' => '/',
            'operator_value' => 2,
            'is_active' => true,
        ]);
        $product = Product::create([
            'name' => 'Producto caja',
            'type' => 'is_single',
            'unit_sale_id' => $unit->id,
        ]);
        $location = $this->location();
        app(InventoryService::class)->increase($location->id, $product->id, 20);

        $sale = new Sale();
        $sale->id = 502;
        $sale->branch_id = 10;
        $sale->inventory_location_id = $location->id;
        $sale->user_id = 9;

        $request = Request::create('/api/pos/create_pos', 'POST', [
            'details' => [[
                'product_id' => $product->id,
                'product_type' => 'is_single',
                'quantity' => 2,
                'pack_multiplier' => 3,
                'sale_unit_id' => $unit->id,
            ]],
        ]);

        app(PosLocationSaleStockService::class)->apply($sale, $request);

        // 2 sold packs x multiplier 3 = 6 sale units; operator '/' by 2 = 3 base units.
        $this->assertSame(17.0, app(InventoryService::class)->available($location->id, $product->id));
    }

    private function location(): InventoryLocation
    {
        return InventoryLocation::create([
            'branch_id' => 10,
            'code' => 'PISO',
            'name' => 'Piso de venta',
            'type' => InventoryLocation::TYPE_SALES_FLOOR,
            'is_sellable' => true,
            'is_active' => true,
        ]);
    }
}
